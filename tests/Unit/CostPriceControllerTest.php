<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\CostPriceController;
use App\Models\Integration;
use App\Models\Product;
use App\Models\UnitEconomicsCache;
use App\Models\UnitEconomicsSettings;
use App\Services\CostPriceParserService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class CostPriceControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_template_uses_browser_safe_utf8_filename_and_excel_friendly_csv(): void
    {
        $controller = new CostPriceController(new CostPriceParserService());

        $response = $controller->template(Request::create('/api/products/cost-price/template'));

        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
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
}
