<?php

namespace Tests\Unit;

use App\Services\CostPriceParserService;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CostPriceParserServiceTest extends TestCase
{
    private CostPriceParserService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new CostPriceParserService();
    }

    public function test_parses_csv_with_semicolon_delimiter(): void
    {
        $content = "Артикул продавца;Себестоимость;Название\n";
        $content .= "3-02/3505;535,68;Товар 1\n";
        $content .= "3-02/3506;3014;Товар 2\n";

        $file = UploadedFile::fake()->createWithContent('test.csv', $content);
        
        $result = $this->parser->parse($file);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['data']['items']);
        $this->assertEquals('3-02/3505', $result['data']['items'][0]['sku']);
        $this->assertEquals(535.68, $result['data']['items'][0]['cost_price']);
        $this->assertEquals('valid', $result['data']['items'][0]['status']);
        $this->assertEquals(3014.0, $result['data']['items'][1]['cost_price']);
    }

    public function test_parses_csv_with_comma_delimiter(): void
    {
        $content = "sku,cost_price,name\n";
        $content .= "TEST-001,123.45,Product 1\n";
        $content .= "TEST-002,678.90,Product 2\n";

        $file = UploadedFile::fake()->createWithContent('test.csv', $content);
        
        $result = $this->parser->parse($file);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['data']['items']);
        $this->assertEquals(123.45, $result['data']['items'][0]['cost_price']);
        $this->assertEquals(678.90, $result['data']['items'][1]['cost_price']);
    }

    public function test_handles_russian_decimal_separator(): void
    {
        $content = "Артикул;Себестоимость\n";
        $content .= "SKU-1;1234,56\n";
        $content .= "SKU-2;789,00\n";

        $file = UploadedFile::fake()->createWithContent('test.csv', $content);
        
        $result = $this->parser->parse($file);

        $this->assertTrue($result['success']);
        $this->assertEquals(1234.56, $result['data']['items'][0]['cost_price']);
        $this->assertEquals(789.00, $result['data']['items'][1]['cost_price']);
    }

    public function test_handles_space_as_thousand_separator(): void
    {
        $content = "Артикул;Себестоимость\n";
        $content .= "SKU-1;1 234,56\n";
        $content .= "SKU-2;12 345 678,90\n";

        $file = UploadedFile::fake()->createWithContent('test.csv', $content);
        
        $result = $this->parser->parse($file);

        $this->assertTrue($result['success']);
        $this->assertEquals(1234.56, $result['data']['items'][0]['cost_price']);
        $this->assertEquals(12345678.90, $result['data']['items'][1]['cost_price']);
    }

    public function test_splits_numbers_imported_as_single_article_column(): void
    {
        $content = "Артикул продавца Себестоимость\n";
        $content .= "001/black 1290\n";
        $content .= "0010/black 3790\n";
        $content .= "003/black2 1190\n";
        $content .= "004/black 402\n";

        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $result = $this->parser->parse($file);

        $this->assertTrue($result['success']);
        $this->assertEquals('001/black', $result['data']['items'][0]['sku']);
        $this->assertEquals(1290.0, $result['data']['items'][0]['cost_price']);
        $this->assertEquals('0010/black', $result['data']['items'][1]['sku']);
        $this->assertEquals(3790.0, $result['data']['items'][1]['cost_price']);
        $this->assertEquals('003/black2', $result['data']['items'][2]['sku']);
        $this->assertEquals(1190.0, $result['data']['items'][2]['cost_price']);
        $this->assertEquals('004/black', $result['data']['items'][3]['sku']);
        $this->assertEquals(402.0, $result['data']['items'][3]['cost_price']);
    }

    public function test_marks_invalid_rows(): void
    {
        $content = "Артикул;Себестоимость\n";
        $content .= "SKU-1;100\n";
        $content .= "SKU-2;\n";  // пустая цена
        $content .= ";200\n";    // пустой артикул

        $file = UploadedFile::fake()->createWithContent('test.csv', $content);
        
        $result = $this->parser->parse($file);

        $this->assertTrue($result['success']);
        $this->assertEquals(3, $result['data']['summary']['total']);
        $this->assertEquals(1, $result['data']['summary']['valid']);
        $this->assertEquals(2, $result['data']['summary']['invalid']);
    }

    public function test_skips_duplicate_skus(): void
    {
        $content = "Артикул;Себестоимость\n";
        $content .= "SKU-1;100\n";
        $content .= "SKU-1;200\n";  // дубликат
        $content .= "SKU-2;300\n";

        $file = UploadedFile::fake()->createWithContent('test.csv', $content);
        
        $result = $this->parser->parse($file);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['data']['items']);
        // Первое значение должно остаться
        $this->assertEquals(100.0, $result['data']['items'][0]['cost_price']);
    }

    public function test_returns_error_for_empty_file(): void
    {
        $file = UploadedFile::fake()->createWithContent('test.csv', '');
        
        $result = $this->parser->parse($file);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_returns_error_for_unsupported_format(): void
    {
        $file = UploadedFile::fake()->createWithContent('test.pdf', 'some content');
        
        $result = $this->parser->parse($file);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Неподдерживаемый формат', $result['error']);
    }
}
