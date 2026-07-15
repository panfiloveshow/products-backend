<?php

namespace Tests\Unit;

use App\Domains\Wildberries\UnitEconomics\WildberriesCommissionResolver;
use App\Http\Controllers\Api\CostPriceController;
use App\Models\Integration;
use App\Models\Product;
use App\Models\UnitEconomics;
use App\Models\UnitEconomicsCache;
use App\Models\UnitEconomicsSettings;
use App\Models\WildberriesTariffSnapshot;
use App\Services\CostPriceParserService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class CostPriceControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_commission_source_distinguishes_live_snapshot_from_default(): void
    {
        $this->assertSame(
            ['value' => 21.0, 'source' => 'wb_commission_snapshot_scheme'],
            WildberriesCommissionResolver::resolveWithSource(
                ['commissions' => ['fbo' => ['percent' => 19]]],
                ['fbo' => 21],
                'FBO'
            )
        );
        $this->assertSame(
            ['value' => 15.0, 'source' => 'default'],
            WildberriesCommissionResolver::resolveWithSource([], null, 'FBO')
        );
    }

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
        $this->assertNull($items[0]['drr_percent']);
    }

    public function test_unit_economics_export_includes_real_margin_ceiling_and_freshness(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 61006]);
        $this->recordWbSourceEvidence($integration->id);
        $product = Product::factory()->wildberries()->create([
            'integration_id' => $integration->id,
            'sku' => '2038816371458',
            'marketplace_id' => '184010773:2038816371458',
            'wb_data' => ['nmID' => 184010773],
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
            'price' => 1500,
            'commission_percent' => 20,
            'effective_logistics' => 120,
            'net_profit' => 150,
            'net_profit_per_unit' => 150,
            'margin_percent' => 10,
            'drr_percent' => 8,
            'tariff_source' => 'wildberries_tariff_snapshots',
            'redemption_source' => 'wb_sales_funnel',
            'marketplace_data' => [
                'commission_source' => 'wb_commission_api_scheme',
                'dimensions_source' => 'wb_product_characteristics',
                'acquiring_source' => 'wb_realization_report_sku',
            ],
        ]);

        $controller = new CostPriceController(new CostPriceParserService());
        $payload = $controller->unitEconomicsExport(Request::create(
            '/api/products/unit-economics/export?integration_id=' . $integration->id
        ))->getData(true);

        $this->assertSame(2, $payload['schema_version']);
        $this->assertSame($integration->id, $payload['integration_id']);
        $this->assertTrue($payload['complete']);
        $this->assertEquals(18.0, $payload['items'][0]['max_allowed_drr']);
        $this->assertEquals(270.0, $payload['items'][0]['margin_before_ads']);
        $this->assertEquals(20.0, $payload['items'][0]['other_costs']);
        $this->assertTrue($payload['items'][0]['ready']);
        $this->assertNotEmpty($payload['items'][0]['calculated_at']);
        $this->assertNotEmpty($payload['items'][0]['source_observed_at']['commission']);
        $this->assertNotEmpty($payload['items'][0]['source_observed_at']['tariff']);
    }

    public function test_calculated_zero_drr_is_not_treated_as_missing(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 61009]);
        $this->recordWbSourceEvidence($integration->id);
        $product = Product::factory()->wildberries()->create([
            'integration_id' => $integration->id,
            'sku' => '2038816371461',
            'marketplace_id' => '184010776:2038816371461',
            'wb_data' => ['nmID' => 184010776],
        ]);
        UnitEconomicsSettings::create([
            'integration_id' => $integration->id,
            'sku' => $product->sku,
            'cost_price' => 600,
            'tax_percent' => 6,
        ]);
        UnitEconomics::create([
            'integration_id' => $integration->id,
            'sku' => $product->sku,
            'marketplace' => 'wildberries',
            'fulfillment_type' => 'FBO',
            'is_actual_scheme' => true,
            'price' => 1400,
            'commission_percent' => 19,
            'effective_logistics' => 100,
            'net_profit' => 140,
            'net_profit_per_unit' => 140,
            'margin_percent' => 10,
            'drr_percent' => 0,
            'tariff_source' => 'wb_tariffs_api',
            'redemption_source' => 'wb_sales_funnel',
            'marketplace_data' => [
                'commission_source' => 'wb_commission_snapshot_scheme',
                'dimensions_source' => 'wb_product_characteristics',
                'acquiring_source' => 'wb_realization_report_sku',
            ],
        ]);

        $controller = new CostPriceController(new CostPriceParserService());
        $payload = $controller->unitEconomicsReadiness(Request::create(
            '/api/products/unit-economics/readiness',
            'POST',
            ['integration_id' => $integration->id, 'wb_product_ids' => [184010776]]
        ))->getData(true);

        $this->assertSame('ready', $payload['items'][0]['status']);
        $this->assertSame([], $payload['items'][0]['reasons']);
        $this->assertEquals(10.0, $payload['items'][0]['max_allowed_drr_percent']);

        $export = $controller->unitEconomicsExport(Request::create(
            '/api/products/unit-economics/export?integration_id=' . $integration->id
        ))->getData(true);
        $this->assertEquals(0.0, $export['items'][0]['drr_percent']);
    }

    public function test_missing_drr_uses_trustworthy_margin_as_break_even_ceiling(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 61013]);
        $this->recordWbSourceEvidence($integration->id);
        $product = Product::factory()->wildberries()->create([
            'integration_id' => $integration->id,
            'sku' => '2038816371464',
            'marketplace_id' => '184010779:2038816371464',
            'wb_data' => ['nmID' => 184010779],
        ]);
        UnitEconomicsSettings::create([
            'integration_id' => $integration->id,
            'sku' => $product->sku,
            'cost_price' => 600,
            'tax_percent' => 6,
        ]);
        UnitEconomics::create([
            'integration_id' => $integration->id,
            'sku' => $product->sku,
            'marketplace' => 'wildberries',
            'fulfillment_type' => 'FBO',
            'is_actual_scheme' => true,
            'price' => 1400,
            'commission_percent' => 19,
            'effective_logistics' => 100,
            'net_profit' => 140,
            'net_profit_per_unit' => 140,
            'margin_percent' => 10,
            'drr_percent' => null,
            'tariff_source' => 'wb_tariffs_api',
            'redemption_source' => 'wb_sales_funnel',
            'marketplace_data' => [
                'commission_source' => 'wb_commission_snapshot_scheme',
                'dimensions_source' => 'wb_product_characteristics',
                'acquiring_source' => 'wb_realization_report_sku',
            ],
        ]);

        $controller = new CostPriceController(new CostPriceParserService());
        $readiness = $controller->unitEconomicsReadiness(Request::create(
            '/api/products/unit-economics/readiness',
            'POST',
            ['integration_id' => $integration->id, 'wb_product_ids' => [184010779]]
        ))->getData(true);
        $export = $controller->unitEconomicsExport(Request::create(
            '/api/products/unit-economics/export?integration_id=' . $integration->id
        ))->getData(true);

        $this->assertSame('ready', $readiness['items'][0]['status']);
        $this->assertSame([], $readiness['items'][0]['reasons']);
        $this->assertEquals(10.0, $readiness['items'][0]['max_allowed_drr_percent']);
        $this->assertNull($export['items'][0]['drr_percent']);
        $this->assertEquals(10.0, $export['items'][0]['max_allowed_drr']);
    }

    public function test_zero_margin_ceiling_is_unprofitable_not_missing(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 61010]);
        $this->recordWbSourceEvidence($integration->id);
        $product = Product::factory()->wildberries()->create([
            'integration_id' => $integration->id,
            'sku' => '2038816371462',
            'marketplace_id' => '184010777:2038816371462',
            'wb_data' => ['nmID' => 184010777],
        ]);
        UnitEconomicsSettings::create([
            'integration_id' => $integration->id,
            'sku' => $product->sku,
            'cost_price' => 600,
            'tax_percent' => 6,
        ]);
        UnitEconomics::create([
            'integration_id' => $integration->id,
            'sku' => $product->sku,
            'marketplace' => 'wildberries',
            'fulfillment_type' => 'FBO',
            'is_actual_scheme' => true,
            'price' => 1400,
            'commission_percent' => 19,
            'effective_logistics' => 100,
            'net_profit' => 0,
            'net_profit_per_unit' => 0,
            'margin_percent' => 0,
            'drr_percent' => 0,
            'tariff_source' => 'wb_tariffs_api',
            'redemption_source' => 'wb_sales_funnel',
            'marketplace_data' => [
                'commission_source' => 'wb_commission_snapshot_scheme',
                'dimensions_source' => 'wb_product_characteristics',
                'acquiring_source' => 'wb_realization_report_sku',
            ],
        ]);

        $payload = (new CostPriceController(new CostPriceParserService()))
            ->unitEconomicsReadiness(Request::create(
                '/api/products/unit-economics/readiness',
                'POST',
                ['integration_id' => $integration->id, 'wb_product_ids' => [184010777]]
            ))->getData(true);

        $this->assertSame('unprofitable', $payload['items'][0]['status']);
        $this->assertContains('unprofitable', $payload['items'][0]['reasons']);
        $this->assertNotContains('missing_max_allowed_drr', $payload['items'][0]['reasons']);
    }

    public function test_unit_economics_cost_falls_back_to_vendor_code_only_within_integration(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 61011]);
        $this->recordWbSourceEvidence($integration->id);
        $foreign = Integration::factory()->wildberries()->create(['id' => 61012]);
        $product = Product::factory()->wildberries()->create([
            'integration_id' => $integration->id,
            'sku' => '2038816371463',
            'vendor_code' => 'WB-VENDOR-UE-1',
            'marketplace_id' => '184010778:2038816371463',
            'wb_data' => ['nmID' => 184010778],
        ]);
        UnitEconomicsSettings::create([
            'integration_id' => $integration->id,
            'sku' => $product->vendor_code,
            'cost_price' => 610,
            'tax_percent' => 6,
        ]);
        UnitEconomicsSettings::create([
            'integration_id' => $foreign->id,
            'sku' => $product->vendor_code,
            'cost_price' => 999,
            'tax_percent' => 20,
        ]);
        UnitEconomics::create([
            'integration_id' => $integration->id,
            'sku' => $product->sku,
            'marketplace' => 'wildberries',
            'fulfillment_type' => 'FBO',
            'is_actual_scheme' => true,
            'price' => 1400,
            'commission_percent' => 19,
            'effective_logistics' => 100,
            'net_profit' => 140,
            'net_profit_per_unit' => 140,
            'margin_percent' => 10,
            'drr_percent' => 0,
            'tariff_source' => 'wb_tariffs_api',
            'redemption_source' => 'wb_sales_funnel',
            'marketplace_data' => [
                'commission_source' => 'wb_commission_snapshot_scheme',
                'dimensions_source' => 'wb_product_characteristics',
                'acquiring_source' => 'wb_realization_report_sku',
            ],
        ]);

        $payload = (new CostPriceController(new CostPriceParserService()))
            ->unitEconomicsExport(Request::create(
                '/api/products/unit-economics/export?integration_id=' . $integration->id
            ))->getData(true);

        $this->assertCount(1, $payload['items']);
        $this->assertEquals(610.0, $payload['items'][0]['cost_price']);
        $this->assertEquals(6.0, $payload['items'][0]['tax_percent']);

        UnitEconomicsSettings::where('integration_id', $integration->id)
            ->where('sku', $product->vendor_code)
            ->delete();
        $readiness = (new CostPriceController(new CostPriceParserService()))
            ->unitEconomicsReadiness(Request::create(
                '/api/products/unit-economics/readiness',
                'POST',
                ['integration_id' => $integration->id, 'wb_product_ids' => [184010778]]
            ))->getData(true);

        $this->assertContains('missing_cost_price', $readiness['items'][0]['reasons']);
    }

    public function test_unit_economics_readiness_is_complete_only_for_requested_real_rows(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 61007]);
        $this->recordWbSourceEvidence($integration->id);
        $product = Product::factory()->wildberries()->create([
            'integration_id' => $integration->id,
            'sku' => '2038816371459',
            'marketplace_id' => '184010774:2038816371459',
            'wb_data' => ['nmID' => 184010774],
        ]);
        UnitEconomicsSettings::create([
            'integration_id' => $integration->id,
            'sku' => $product->sku,
            'cost_price' => 600,
            'tax_percent' => 6,
        ]);
        UnitEconomics::create([
            'integration_id' => $integration->id,
            'sku' => $product->sku,
            'marketplace' => 'wildberries',
            'fulfillment_type' => 'FBO',
            'is_actual_scheme' => true,
            'price' => 1400,
            'commission_percent' => 19,
            'effective_logistics' => 100,
            'net_profit' => 200,
            'net_profit_per_unit' => 200,
            'margin_percent' => 14.29,
            'drr_percent' => 5,
            'tariff_source' => 'wildberries_tariff_snapshots',
            'redemption_source' => 'api_orders_sku_sales',
            'marketplace_data' => [
                'commission_source' => 'wb_commission_snapshot',
                'dimensions_source' => 'wb_storage_api_volume',
                'acquiring_source' => 'wb_realization_report_store_avg',
            ],
        ]);

        $controller = new CostPriceController(new CostPriceParserService());
        $payload = $controller->unitEconomicsReadiness(Request::create(
            '/api/products/unit-economics/readiness',
            'POST',
            [
                'integration_id' => $integration->id,
                'wb_product_ids' => [184010774, 184010799],
            ]
        ))->getData(true);

        $this->assertTrue($payload['complete']);
        $this->assertSame((string) $integration->id, $payload['integration_id']);
        $this->assertSame([184010774, 184010799], $payload['checked_product_ids']);
        $this->assertSame([184010799], $payload['missing_economics_product_ids']);
        $this->assertSame('ready', $payload['items'][0]['status']);
        $this->assertSame('missing', $payload['items'][1]['status']);
    }

    public function test_unit_economics_readiness_blocks_fallback_sources_and_stale_rows(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 61008]);
        $product = Product::factory()->wildberries()->create([
            'integration_id' => $integration->id,
            'sku' => '2038816371460',
            'marketplace_id' => '184010775:2038816371460',
            'wb_data' => ['nmID' => 184010775],
        ]);
        UnitEconomicsSettings::create([
            'integration_id' => $integration->id,
            'sku' => $product->sku,
            'cost_price' => 600,
            'tax_percent' => 6,
        ]);
        $economics = UnitEconomics::create([
            'integration_id' => $integration->id,
            'sku' => $product->sku,
            'marketplace' => 'wildberries',
            'fulfillment_type' => 'FBO',
            'is_actual_scheme' => true,
            'price' => 1400,
            'commission_percent' => 15,
            'effective_logistics' => 100,
            'net_profit' => 100,
            'net_profit_per_unit' => 100,
            'margin_percent' => 7.14,
            'drr_percent' => 5,
            'tariff_source' => 'wildberries_tariff_snapshots_fallback',
            'redemption_source' => 'default',
            'marketplace_data' => ['commission_source' => 'default'],
        ]);
        $economics->timestamps = false;
        $economics->forceFill(['updated_at' => now()->subDays(4)])->saveQuietly();

        $controller = new CostPriceController(new CostPriceParserService());
        $payload = $controller->unitEconomicsReadiness(Request::create(
            '/api/products/unit-economics/readiness',
            'POST',
            [
                'integration_id' => $integration->id,
                'wb_product_ids' => [184010775],
            ]
        ))->getData(true);

        $this->assertSame([184010775], $payload['stale_product_ids']);
        $this->assertSame('stale', $payload['items'][0]['status']);
        $this->assertContains('untrusted_commission_source', $payload['items'][0]['reasons']);
        $this->assertContains('untrusted_tariff_source', $payload['items'][0]['reasons']);
        $this->assertContains('untrusted_redemption_source', $payload['items'][0]['reasons']);
    }

    public function test_unit_economics_readiness_blocks_stale_persisted_source_evidence(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 61014]);
        $this->recordWbSourceEvidence($integration->id, now()->subDays(4));
        $product = Product::factory()->wildberries()->create([
            'integration_id' => $integration->id,
            'sku' => '2038816371465',
            'marketplace_id' => '184010780:2038816371465',
            'wb_data' => ['nmID' => 184010780],
        ]);
        UnitEconomicsSettings::create([
            'integration_id' => $integration->id,
            'sku' => $product->sku,
            'cost_price' => 600,
            'tax_percent' => 6,
        ]);
        UnitEconomics::create([
            'integration_id' => $integration->id,
            'sku' => $product->sku,
            'marketplace' => 'wildberries',
            'fulfillment_type' => 'FBO',
            'is_actual_scheme' => true,
            'price' => 1400,
            'commission_percent' => 19,
            'effective_logistics' => 100,
            'net_profit' => 140,
            'net_profit_per_unit' => 140,
            'margin_percent' => 10,
            'drr_percent' => null,
            'tariff_source' => 'wb_tariffs_api',
            'redemption_source' => 'wb_sales_funnel',
            'marketplace_data' => [
                'commission_source' => 'wb_commission_snapshot_scheme',
                'dimensions_source' => 'wb_product_characteristics',
                'acquiring_source' => 'wb_realization_report_sku',
            ],
        ]);

        $payload = (new CostPriceController(new CostPriceParserService()))
            ->unitEconomicsReadiness(Request::create(
                '/api/products/unit-economics/readiness',
                'POST',
                ['integration_id' => $integration->id, 'wb_product_ids' => [184010780]]
            ))->getData(true);

        $this->assertSame('incomplete', $payload['items'][0]['status']);
        $this->assertContains('stale_commission_source', $payload['items'][0]['reasons']);
        $this->assertContains('stale_tariff_source', $payload['items'][0]['reasons']);
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

    private function recordWbSourceEvidence(int $integrationId, $fetchedAt = null): void
    {
        $fetchedAt ??= now();
        foreach (['commission', 'box'] as $type) {
            WildberriesTariffSnapshot::create([
                'integration_id' => $integrationId,
                'marketplace' => 'wildberries',
                'tariff_type' => $type,
                'effective_date' => $fetchedAt->toDateString(),
                'warehouse_id' => $type === 'box' ? 'test-warehouse' : '',
                'subject_id' => $type === 'commission' ? 'test-subject' : '',
                'scheme' => $type === 'commission' ? 'FBO' : '',
                'payload' => ['verified' => true],
                'fetched_at' => $fetchedAt,
            ]);
        }
    }
}
