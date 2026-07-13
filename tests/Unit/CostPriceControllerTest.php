<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\CostPriceController;
use App\Models\Integration;
use App\Models\Product;
use App\Models\UnitEconomics;
use App\Models\UnitEconomicsCache;
use App\Models\UnitEconomicsSettings;
use App\Services\CostPriceParserService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class CostPriceControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_unit_economics_export_includes_calculated_spp_and_customer_price(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 61005]);
        $product = Product::factory()->wildberries()->create([
            'integration_id' => $integration->id,
            'sku' => '2038816371457',
            'marketplace_id' => '184010772:2038816371457',
            'commission' => 24.5,
            'wb_data' => ['nmID' => 184010772],
        ]);
        UnitEconomicsSettings::create([
            'integration_id' => $integration->id,
            'sku' => $product->sku,
            'cost_price' => 700,
            'tax_percent' => 6,
        ]);
        UnitEconomics::create([
            'integration_id' => $integration->id,
            'sku' => $product->sku,
            'marketplace' => 'wildberries',
            'fulfillment_type' => 'FBO',
            'is_actual_scheme' => true,
            'price' => 1581.38,
            'spp_percent' => 40.12,
            'customer_price' => 947,
        ]);

        $controller = new CostPriceController(new CostPriceParserService());
        $response = $controller->unitEconomicsExport(Request::create(
            '/api/products/unit-economics/export?integration_id=' . $integration->id
        ));

        $items = $response->getData(true)['items'];
        $this->assertCount(1, $items);
        $this->assertSame(184010772, $items[0]['nm_id']);
        $this->assertSame(40.12, $items[0]['spp_percent']);
        $this->assertSame(947, $items[0]['customer_price']);
    }

    public function test_template_uses_browser_safe_utf8_filename_and_excel_friendly_csv(): void
    {
        $controller = new CostPriceController(new CostPriceParserService());

        $response = $controller->template(Request::create('/api/products/cost-price/template'));

        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
        $this->assertSame(
            "attachment; filename=\"cost-price-template.csv\"; filename*=UTF-8''%D1%88%D0%B0%D0%B1%D0%BB%D0%BE%D0%BD_%D1%81%D0%B5%D0%B1%D0%B5%D1%81%D1%82%D0%BE%D0%B8%D0%BC%D0%BE%D1%81%D1%82%D1%8C.csv",
            $response->headers->get('Content-Disposition')
        );
        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->getContent());
        $this->assertStringContainsString("Артикул продавца;Себестоимость\r\n", $response->getContent());
    }

    public function test_template_filename_includes_marketplace_in_ascii_and_utf8_names(): void
    {
        $controller = new CostPriceController(new CostPriceParserService());

        $response = $controller->template(Request::create('/api/products/cost-price/template?marketplace=ozon'));

        $this->assertSame(
            "attachment; filename=\"cost-price-template-ozon.csv\"; filename*=UTF-8''%D1%88%D0%B0%D0%B1%D0%BB%D0%BE%D0%BD_%D1%81%D0%B5%D0%B1%D0%B5%D1%81%D1%82%D0%BE%D0%B8%D0%BC%D0%BE%D1%81%D1%82%D1%8C_ozon.csv",
            $response->headers->get('Content-Disposition')
        );
    }

    public function test_wildberries_template_uses_vendor_code_instead_of_barcode_sku(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 61002]);
        Product::factory()->wildberries()->create([
            'integration_id' => $integration->id,
            'sku' => '2038816371456',
            'barcode' => '2038816371456',
            'vendor_code' => '8206/brown',
            'cost_price' => 456.78,
            'wb_data' => ['vendorCode' => '8206/brown'],
        ]);

        $controller = new CostPriceController(new CostPriceParserService());
        $response = $controller->template(Request::create('/api/products/cost-price/template?marketplace=wildberries&integration_id=' . $integration->id));

        $content = $response->getContent();

        $this->assertStringContainsString("Артикул продавца;Себестоимость\r\n", $content);
        $this->assertStringContainsString("8206/brown;456.78\r\n", $content);
        $this->assertStringNotContainsString("2038816371456;456.78\r\n", $content);
    }

    public function test_bulk_updates_wildberries_cost_price_by_vendor_code_and_barcode(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 61001]);
        $product = Product::factory()->wildberries()->create([
            'integration_id' => $integration->id,
            'sku' => '4607000000001',
            'barcode' => '4607000000001',
            'vendor_code' => 'WB-ART-1',
            'cost_price' => 0,
            'wb_data' => ['vendorCode' => 'WB-ART-1'],
        ]);
        UnitEconomicsCache::create([
            'integration_id' => $integration->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'product_name' => $product->name,
            'marketplace' => 'wildberries',
            'fulfillment_type' => 'FBO',
            'cost_price' => 0,
        ]);

        $controller = new CostPriceController(new CostPriceParserService());
        $response = $controller->bulk(Request::create('/api/products/cost-price/bulk', 'POST', [
            'integration_id' => $integration->id,
            'items' => [
                ['sku' => 'WB-ART-1', 'cost_price' => 321.55],
            ],
        ]));

        $this->assertTrue($response->getData(true)['success']);
        $this->assertSame(1, $response->getData(true)['data']['updated']);
        $this->assertSame(321.55, (float) $product->refresh()->cost_price);
        $this->assertDatabaseHas('unit_economics_settings', [
            'integration_id' => $integration->id,
            'sku' => 'WB-ART-1',
            'cost_price' => 321.55,
        ]);
        $this->assertDatabaseHas('unit_economics_settings', [
            'integration_id' => $integration->id,
            'sku' => '4607000000001',
            'cost_price' => 321.55,
        ]);
        $this->assertSame(321.55, (float) UnitEconomicsCache::where('product_id', $product->id)->value('cost_price'));
    }

    public function test_bulk_accepts_marketplace_id_as_integration_alias(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 61003]);
        $other = Integration::factory()->wildberries()->create(['id' => 61004]);
        $product = Product::factory()->wildberries()->create([
            'integration_id' => $integration->id,
            'sku' => 'ALIAS-1',
            'vendor_code' => 'ALIAS-1',
            'cost_price' => 0,
        ]);

        $controller = new CostPriceController(new CostPriceParserService());
        $response = $controller->bulk(Request::create('/api/products/cost-price/bulk', 'POST', [
            'marketplace_id' => $integration->id,
            'items' => [
                ['sku' => 'ALIAS-1', 'cost_price' => 111.11],
            ],
        ]));

        $this->assertTrue($response->getData(true)['success']);
        $this->assertSame(1, $response->getData(true)['data']['updated']);
        $this->assertSame(111.11, (float) $product->refresh()->cost_price);

        // marketplace_id должен работать как фильтр интеграции: в чужом магазине товар не ищем
        $foreignResponse = $controller->bulk(Request::create('/api/products/cost-price/bulk', 'POST', [
            'marketplace_id' => $other->id,
            'items' => [
                ['sku' => 'ALIAS-1', 'cost_price' => 222.22],
            ],
        ]));

        $this->assertSame(['ALIAS-1'], $foreignResponse->getData(true)['data']['not_found']);
        $this->assertSame(111.11, (float) $product->refresh()->cost_price);
    }
}
