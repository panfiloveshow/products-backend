<?php

namespace Tests\Unit;

use App\Domains\UnitEconomics\UnitEconomicsOrchestrator;
use App\Http\Controllers\Api\UnitEconomicsCacheController;
use App\Models\Integration;
use App\Models\LocalityMetricDaily;
use App\Models\Product;
use App\Models\UnitEconomicsCache;
use App\Models\UnitEconomicsSettings;
use App\Services\IntegrationAccessService;
use App\Services\UnitEconomicsCacheService;
use App\Services\UnitEconomicsService;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class UnitEconomicsCacheControllerTest extends TestCase
{
    public function test_wildberries_commissions_static_fallback_is_marked_deprecated(): void
    {
        $controller = new UnitEconomicsCacheController(
            $this->createMock(UnitEconomicsCacheService::class),
            $this->createMock(UnitEconomicsService::class),
            $this->createMock(UnitEconomicsOrchestrator::class),
            $this->createMock(IntegrationAccessService::class),
        );

        $response = $controller->commissions('wb');
        $data = $response->getData(true)['data'];

        $this->assertSame('wildberries', $data['marketplace']);
        $this->assertSame('wildberries_legacy_static_fallback', $data['source']);
        $this->assertTrue($data['deprecated']);
        $this->assertNotEmpty($data['categories']);
    }

    public function test_wildberries_tariffs_static_fallback_is_marked_deprecated(): void
    {
        $orchestrator = $this->createMock(UnitEconomicsOrchestrator::class);
        $orchestrator->method('getSupportedSchemes')
            ->with('wildberries')
            ->willReturn(['FBO', 'FBS', 'DBS', 'EDBS', 'DBW']);

        $controller = new UnitEconomicsCacheController(
            $this->createMock(UnitEconomicsCacheService::class),
            $this->createMock(UnitEconomicsService::class),
            $orchestrator,
            $this->createMock(IntegrationAccessService::class),
        );

        $response = $controller->tariffs('wb');
        $data = $response->getData(true)['data'];

        $this->assertSame('wildberries', $data['marketplace']);
        $this->assertSame('wildberries_legacy_static_fallback', $data['source']);
        $this->assertTrue($data['deprecated']);
        $this->assertSame(['FBO', 'FBS', 'DBS', 'EDBS', 'DBW'], $data['schemes']);
    }

    public function test_wildberries_commissions_snapshots_require_integration_access(): void
    {
        $access = $this->createMock(IntegrationAccessService::class);
        $access->expects($this->once())
            ->method('ensureAccessibleIntegration')
            ->with($this->anything(), 13, 'wildberries')
            ->willReturn([
                'success' => false,
                'status' => 403,
                'message' => 'Интеграция не принадлежит текущему workspace',
            ]);

        $controller = new UnitEconomicsCacheController(
            $this->createMock(UnitEconomicsCacheService::class),
            $this->createMock(UnitEconomicsService::class),
            $this->createMock(UnitEconomicsOrchestrator::class),
            $access,
        );

        $originalRequest = app('request');
        app()->instance('request', \Illuminate\Http\Request::create(
            '/api/unit-economics/commissions/wildberries',
            'GET',
            ['integration_id' => 13]
        ));

        try {
            $response = $controller->commissions('wildberries');
        } finally {
            app()->instance('request', $originalRequest);
        }

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Интеграция не принадлежит текущему workspace', $response->getData(true)['message']);
    }

    public function test_wildberries_live_calculate_is_deprecated(): void
    {
        $controller = new UnitEconomicsCacheController(
            $this->createMock(UnitEconomicsCacheService::class),
            $this->createMock(UnitEconomicsService::class),
            $this->createMock(UnitEconomicsOrchestrator::class),
            $this->createMock(IntegrationAccessService::class),
        );

        $request = \App\Http\Requests\UnitEconomics\CalculateRequest::create(
            '/api/unit-economics/calculate/wildberries',
            'POST',
            []
        );

        $response = $controller->calculate($request, 'wildberries');
        $data = $response->getData(true);

        $this->assertSame(410, $response->getStatusCode());
        $this->assertTrue($data['data']['deprecated']);
        $this->assertSame('wildberries', $data['data']['marketplace']);
    }

    public function test_enrich_cache_item_recalculates_vat_amount_from_manual_settings_immediately(): void
    {
        $controller = new UnitEconomicsCacheController(
            $this->createMock(UnitEconomicsCacheService::class),
            $this->createMock(UnitEconomicsService::class),
            $this->createMock(UnitEconomicsOrchestrator::class),
            $this->createMock(IntegrationAccessService::class),
        );

        $product = new Product([
            'id' => 10,
            'integration_id' => 77,
            'marketplace' => 'ozon',
            'sku' => 'ozon-vat-22',
            'name' => 'Ozon VAT product',
        ]);
        $product->fulfillment_type = 'FBO';

        $cache = new UnitEconomicsCache([
            'integration_id' => 77,
            'product_id' => 10,
            'sku' => 'ozon-vat-22',
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'sales_count' => 1,
            'price' => 1000,
            'cost_price' => 300,
            'commission_percent' => 10,
            'commission_amount' => 100,
            'acquiring_percent' => 1.5,
            'acquiring_amount' => 15,
            'logistics_cost' => 50,
            'effective_logistics' => 50,
            'storage_cost' => 0,
            'drr_percent' => 0,
            'drr_amount' => 0,
            'our_share_percent' => 0,
            'our_share_amount' => 0,
            'tax_percent' => 0,
            'tax_amount' => 0,
            'vat_percent' => 0,
            'vat_amount' => 0,
            'total_costs' => 465,
            'net_profit' => 535,
            'to_settlement_account' => 835,
            'marketplace_data' => [],
        ]);
        $cache->setRelation('product', $product);

        $settings = new UnitEconomicsSettings([
            'integration_id' => 77,
            'sku' => 'ozon-vat-22',
            'drr_percent' => 0,
            'our_share_percent' => 0,
            'tax_percent' => 0,
            'vat_percent' => 22,
        ]);

        $method = new \ReflectionMethod(UnitEconomicsCacheController::class, 'enrichCacheItem');
        $method->setAccessible(true);

        $result = $method->invoke($controller, $cache, 'FBO', $settings, [
            'inventory_by_product_key' => collect([
                '10|77' => collect(),
            ]),
            'actual_schemes_by_product_key' => collect([
                '10|77' => 'FBO',
            ]),
        ]);

        $this->assertSame(22.0, $result['vat_percent']);
        $this->assertSame(220.0, $result['vat_amount']);
        // Единый источник истины (applyCanonicalProfitBreakdown): «На РС» не включает НДС,
        // он вычитается в прибыли. Числа совпадают с карточкой и Excel.
        $this->assertSame(835.0, $result['to_settlement_account']);
        $this->assertSame(685.0, $result['total_costs']);
        $this->assertSame(315.0, $result['net_profit']);
    }

    public function test_normalize_ozon_cluster_markup_data_enriches_summary_and_sales_profile(): void
    {
        $controller = new UnitEconomicsCacheController(
            $this->createMock(UnitEconomicsCacheService::class),
            $this->createMock(UnitEconomicsService::class),
            $this->createMock(UnitEconomicsOrchestrator::class),
            $this->createMock(IntegrationAccessService::class),
        );

        $method = new \ReflectionMethod(UnitEconomicsCacheController::class, 'normalizeOzonClusterMarkupData');
        $method->setAccessible(true);

        [$summary, $salesProfile] = $method->invoke(
            $controller,
            [
                [
                    'cluster_name' => 'Москва, МО и Дальние регионы',
                    'orders_percent' => 57.14,
                ],
                [
                    'cluster_name' => 'Самара',
                    'orders_percent' => 14.29,
                ],
            ],
            [
                [
                    'cluster_name' => 'Москва, МО и Дальние регионы',
                    'sales_share_percent' => 57.14,
                ],
                [
                    'cluster_name' => 'Самара',
                    'sales_share_percent' => 14.29,
                ],
            ],
            [
                ['cluster_name' => 'Омск'],
                ['cluster_name' => 'Москва, МО и Дальние регионы'],
            ],
            true
        );

        $this->assertSame(0.0, $summary[0]['effective_markup_percent']);
        $this->assertSame(8.0, $summary[0]['non_local_markup_percent']);
        $this->assertSame('local_cluster', $summary[0]['markup_reason']);
        $this->assertSame(12.0, $summary[1]['effective_markup_percent']);
        $this->assertSame(0.0, $salesProfile[0]['effective_markup_percent']);
        $this->assertSame(12.0, $salesProfile[1]['effective_markup_percent']);
        $this->assertSame('Самара', $salesProfile[1]['cluster_name']);
    }

    public function test_ozon_display_non_local_markup_prefers_factual_order_summary(): void
    {
        $controller = new UnitEconomicsCacheController(
            $this->createMock(UnitEconomicsCacheService::class),
            $this->createMock(UnitEconomicsService::class),
            $this->createMock(UnitEconomicsOrchestrator::class),
            $this->createMock(IntegrationAccessService::class),
        );

        $method = new \ReflectionMethod($controller, 'resolveOzonDisplayNonLocalMarkup');
        $method->setAccessible(true);

        $result = $method->invoke($controller, [
            'orders_count' => 14,
            'factual_orders_count' => 14,
            'estimate_orders_count' => 0,
            'avg_non_local_markup_percent' => 0.574,
            'avg_non_local_markup_amount' => 3.216,
        ], 8.0, 44.0);

        $this->assertSame([0.57, 3.22, true], $result);
    }

    public function test_ozon_display_non_local_markup_falls_back_to_expected_without_orders(): void
    {
        $controller = new UnitEconomicsCacheController(
            $this->createMock(UnitEconomicsCacheService::class),
            $this->createMock(UnitEconomicsService::class),
            $this->createMock(UnitEconomicsOrchestrator::class),
            $this->createMock(IntegrationAccessService::class),
        );

        $method = new \ReflectionMethod($controller, 'resolveOzonDisplayNonLocalMarkup');
        $method->setAccessible(true);

        $result = $method->invoke($controller, [
            'orders_count' => 0,
            'avg_non_local_markup_percent' => 0.0,
            'avg_non_local_markup_amount' => 0.0,
        ], 8.0, 44.0);

        $this->assertSame([8.0, 44.0, false], $result);
    }

    public function test_ozon_display_non_local_markup_does_not_treat_estimates_as_factual(): void
    {
        $controller = new UnitEconomicsCacheController(
            $this->createMock(UnitEconomicsCacheService::class),
            $this->createMock(UnitEconomicsService::class),
            $this->createMock(UnitEconomicsOrchestrator::class),
            $this->createMock(IntegrationAccessService::class),
        );

        $method = new \ReflectionMethod($controller, 'resolveOzonDisplayNonLocalMarkup');
        $method->setAccessible(true);

        $result = $method->invoke($controller, [
            'orders_count' => 28,
            'factual_orders_count' => 0,
            'estimate_orders_count' => 28,
            'avg_non_local_markup_percent' => 1.71,
            'avg_non_local_markup_amount' => 179.83,
        ], 0.0, 0.0);

        $this->assertSame([0.0, 0.0, false], $result);
    }

    public function test_ozon_enrich_reconciles_stale_logistics_with_zero_display_markup(): void
    {
        $controller = new UnitEconomicsCacheController(
            $this->createMock(UnitEconomicsCacheService::class),
            $this->createMock(UnitEconomicsService::class),
            $this->createMock(UnitEconomicsOrchestrator::class),
            $this->createMock(IntegrationAccessService::class),
        );

        $product = new Product([
            'id' => 10,
            'integration_id' => 55,
            'marketplace' => 'ozon',
            'sku' => '9217/newdarkgreen',
            'name' => 'Regression product',
        ]);
        $product->fulfillment_type = 'FBO';

        $cache = new UnitEconomicsCache([
            'integration_id' => 55,
            'product_id' => 10,
            'sku' => '9217/newdarkgreen',
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'sales_count' => 1,
            'price' => 14500,
            'cost_price' => 5000,
            'commission_percent' => 10,
            'commission_amount' => 1450,
            'acquiring_percent' => 1.5,
            'acquiring_amount' => 217.5,
            'base_logistics_cost' => 67.99,
            'logistics_cost' => 840.84,
            'last_mile_cost' => 25,
            'processing_cost' => 0,
            'return_logistics_cost' => 67.99,
            'return_processing_cost' => 15,
            'expected_return_cost' => 51.87,
            'effective_logistics' => 917.71,
            'non_local_markup_percent' => 5.33,
            'redemption_rate' => 37.5,
            'orders_count' => 8,
            'storage_cost' => 0,
            'drr_percent' => 0,
            'our_share_percent' => 0,
            'tax_percent' => 0,
            'vat_percent' => 0,
            'total_costs' => 7585.21,
            'net_profit' => 6914.79,
            'marketplace_data' => [
                'sales_7_days' => 344,
                'clusters_summary' => [[
                    'cluster_name' => 'Москва, МО и Дальние регионы',
                    'orders_count' => 3,
                    'orders_percent' => 100,
                    'is_local_cluster' => true,
                    'effective_markup_percent' => 0,
                ]],
                'stock_profile' => [[
                    'cluster_name' => 'Москва, МО и Дальние регионы',
                    'quantity' => 6,
                    'share_percent' => 100,
                ]],
                'order_economics_summary' => [
                    'orders_count' => 28,
                    'factual_orders_count' => 0,
                    'estimate_orders_count' => 28,
                    'avg_non_local_markup_percent' => 1.71,
                    'avg_non_local_markup_amount' => 179.83,
                ],
                'calculation_warnings' => [],
            ],
        ]);
        $cache->setRelation('product', $product);

        $method = new \ReflectionMethod(UnitEconomicsCacheController::class, 'enrichCacheItem');
        $method->setAccessible(true);
        $result = $method->invoke($controller, $cache, 'FBO', null, [
            'inventory_by_product_key' => collect(['10|55' => collect()]),
            'actual_schemes_by_product_key' => collect(['10|55' => 'FBO']),
        ]);

        $this->assertSame(0.0, $result['non_local_markup_percent']);
        $this->assertSame(0.0, $result['non_local_markup_amount']);
        $this->assertSame(67.99, $result['logistics_cost']);
        $this->assertSame(92.99, $result['delivery_cost']);
        $this->assertSame(144.86, $result['effective_logistics']);
        $this->assertSame('reconciled', $result['logistics_invariant_status']);
        $this->assertContains(
            'ozon_logistics_reconciled_with_display_markup',
            $result['calculation_warnings']
        );
        $this->assertSame(67.99, $result['marketplace_data']['logistics']);
        $this->assertSame(144.86, $result['marketplace_data']['effective_logistics']);
    }

    public function test_enrich_cache_item_exposes_volume_weight_and_chargeable_volume(): void
    {
        $controller = new UnitEconomicsCacheController(
            $this->createMock(UnitEconomicsCacheService::class),
            $this->createMock(UnitEconomicsService::class),
            $this->createMock(UnitEconomicsOrchestrator::class),
            $this->createMock(IntegrationAccessService::class),
        );

        $product = new Product([
            'marketplace' => 'wildberries',
            'volume_weight' => 0.4,
            'weight' => 350,
        ]);
        $product->fulfillment_type = 'FBO';

        $cache = new UnitEconomicsCache([
            'integration_id' => 1,
            'product_id' => 10,
            'sku' => 'sku-1',
            'marketplace' => 'wildberries',
            'fulfillment_type' => 'FBO',
            'sales_count' => 1,
            'price' => 250,
            'total_costs' => 100,
            'logistics_cost' => 29.48,
            'last_mile_cost' => 25,
            'commission_amount' => 10,
            'acquiring_amount' => 3,
            'storage_cost' => 0,
            'volume_liters' => 1.0,
            'volume_weight' => 0.4,
            'depth' => 100,
            'width' => 100,
            'height' => 100,
            'weight' => 350,
            'marketplace_data' => [
                'chargeable_volume_liters' => 2.0,
            ],
        ]);
        $cache->setRelation('product', $product);

        $method = new \ReflectionMethod(UnitEconomicsCacheController::class, 'enrichCacheItem');
        $method->setAccessible(true);

        \Illuminate\Support\Facades\Cache::shouldReceive('remember')->andReturn(null);

        $pageContext = [
            'wb_warehouses_by_product_key' => collect([
                '10|1' => collect(),
            ]),
            'integrations_by_id' => collect([
                1 => new Integration([
                    'localization_index' => 1.0,
                ]),
            ]),
        ];

        $result = $method->invoke($controller, $cache, 'FBO', null, $pageContext);

        $this->assertSame(0.4, $result['volume_weight']);
        $this->assertSame(2.0, $result['chargeable_volume_liters']);
        $this->assertSame('0.4000', $result['dimensions']['volume_weight']);
        $this->assertSame('2.0000', $result['dimensions']['chargeable_volume']);
    }

    public function test_wildberries_enrich_prefers_current_integration_localization_over_legacy_default_cache_value(): void
    {
        $controller = new UnitEconomicsCacheController(
            $this->createMock(UnitEconomicsCacheService::class),
            $this->createMock(UnitEconomicsService::class),
            $this->createMock(UnitEconomicsOrchestrator::class),
            $this->createMock(IntegrationAccessService::class),
        );

        $product = new Product([
            'marketplace' => 'wildberries',
        ]);
        $product->fulfillment_type = 'FBO';

        $cache = new UnitEconomicsCache([
            'integration_id' => 13,
            'product_id' => 10,
            'sku' => 'sku-il',
            'marketplace' => 'wildberries',
            'fulfillment_type' => 'FBO',
            'sales_count' => 1,
            'price' => 1000,
            'cost_price' => 0,
            'base_logistics_cost' => 100,
            'logistics_coefficient' => 1,
            'marketplace_data' => [],
        ]);
        $cache->setRelation('product', $product);

        $method = new \ReflectionMethod(UnitEconomicsCacheController::class, 'enrichCacheItem');
        $method->setAccessible(true);

        \Illuminate\Support\Facades\Cache::shouldReceive('remember')->andReturn(null);

        $result = $method->invoke($controller, $cache, 'FBO', null, [
            'wb_warehouses_by_product_key' => collect([
                '10|13' => collect(),
            ]),
            'integrations_by_id' => collect([
                13 => new Integration([
                    'settings' => ['wb_localization_index' => 1.4],
                    'localization_index' => 1.2,
                ]),
            ]),
        ]);

        $this->assertSame(1.4, $result['localization_index']);
        $this->assertSame(40.0, $result['localization_amount']);
    }

    public function test_wildberries_enrich_exposes_vendor_code_as_display_article_without_replacing_internal_sku(): void
    {
        $controller = new UnitEconomicsCacheController(
            $this->createMock(UnitEconomicsCacheService::class),
            $this->createMock(UnitEconomicsService::class),
            $this->createMock(UnitEconomicsOrchestrator::class),
            $this->createMock(IntegrationAccessService::class),
        );

        $product = new Product([
            'id' => 10,
            'integration_id' => 13,
            'marketplace' => 'wildberries',
            'sku' => '2038816371456',
            'vendor_code' => '8206/brown',
            'name' => 'Дорожная сумка из экокожи',
        ]);
        $product->fulfillment_type = 'FBO';

        $cache = new UnitEconomicsCache([
            'integration_id' => 13,
            'product_id' => 10,
            'sku' => '2038816371456',
            'marketplace' => 'wildberries',
            'fulfillment_type' => 'FBO',
            'sales_count' => 1,
            'price' => 4105.92,
            'cost_price' => 0,
            'base_logistics_cost' => 100,
            'logistics_coefficient' => 1,
            'marketplace_data' => [],
        ]);
        $cache->setRelation('product', $product);

        $method = new \ReflectionMethod(UnitEconomicsCacheController::class, 'enrichCacheItem');
        $method->setAccessible(true);

        \Illuminate\Support\Facades\Cache::shouldReceive('remember')->andReturn(null);

        $result = $method->invoke($controller, $cache, 'FBO', null, [
            'wb_warehouses_by_product_key' => collect([
                '10|13' => collect(),
            ]),
            'integrations_by_id' => collect([
                13 => new Integration([
                    'settings' => ['wb_localization_index' => 1.0],
                    'localization_index' => 1.0,
                ]),
            ]),
        ]);

        $this->assertSame('2038816371456', $result['sku']);
        $this->assertSame('8206/brown', $result['vendor_code']);
        $this->assertSame('8206/brown', $result['article']);
    }

    public function test_profit_range_is_aligned_with_current_net_profit(): void
    {
        $controller = new UnitEconomicsCacheController(
            $this->createMock(UnitEconomicsCacheService::class),
            $this->createMock(UnitEconomicsService::class),
            $this->createMock(UnitEconomicsOrchestrator::class),
            $this->createMock(IntegrationAccessService::class),
        );

        $method = new \ReflectionMethod(UnitEconomicsCacheController::class, 'normalizeProfitRangeForNetProfit');
        $method->setAccessible(true);

        $result = $method->invoke(
            $controller,
            [
                'profit_min' => 10,
                'profit_base' => 50,
                'profit_max' => 90,
            ],
            35.0
        );

        $this->assertSame(-5.0, $result['profit_min']);
        $this->assertSame(35.0, $result['profit_base']);
        $this->assertSame(75.0, $result['profit_max']);
    }

    public function test_enrich_cache_item_exposes_ozon_price_competitiveness_fields(): void
    {
        $controller = new UnitEconomicsCacheController(
            $this->createMock(UnitEconomicsCacheService::class),
            $this->createMock(UnitEconomicsService::class),
            $this->createMock(UnitEconomicsOrchestrator::class),
            $this->createMock(IntegrationAccessService::class),
        );

        $product = new Product([
            'marketplace' => 'ozon',
            'price' => 900,
            'ozon_data' => [],
        ]);
        $product->fulfillment_type = 'FBO';

        $cache = new UnitEconomicsCache([
            'integration_id' => 1,
            'product_id' => '00000000-0000-0000-0000-000000000001',
            'sku' => 'sku-1',
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'sales_count' => 1,
            'price' => 900,
            'cost_price' => 400,
            'total_costs' => 500,
            'net_profit' => 250,
            'logistics_cost' => 50,
            'last_mile_cost' => 25,
            'commission_amount' => 90,
            'acquiring_amount' => 13.5,
            'marketplace_data' => [
                'pricing_strategy' => [
                    'product_id' => 7856197312,
                    'competitor_price' => 1000,
                ],
                'competitor_price' => 1000,
                'current_price_index' => 0.9,
                'current_price_is_favorable' => true,
                'current_price_index_label' => 'Выгодно',
                'current_price_competitor_delta' => -100,
                'current_price_competitor_delta_percent' => -10,
            ],
        ]);
        $cache->setRelation('product', $product);

        $method = new \ReflectionMethod(UnitEconomicsCacheController::class, 'enrichCacheItem');
        $method->setAccessible(true);

        \Illuminate\Support\Facades\Cache::shouldReceive('remember')->andReturn(null);
        if (! \Illuminate\Support\Facades\Schema::hasTable('inventory_warehouses')) {
            \Illuminate\Support\Facades\Schema::create('inventory_warehouses', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('integration_id')->nullable();
                $table->string('sku')->nullable();
                $table->string('marketplace')->nullable();
                $table->integer('quantity')->default(0);
                $table->decimal('average_daily_sales', 12, 2)->nullable();
                $table->timestamp('last_updated')->nullable();
            });
        }

        $result = $method->invoke($controller, $cache, 'FBO');

        $this->assertSame(1000.0, $result['competitor_price']);
        $this->assertSame(0.9, $result['current_price_index']);
        $this->assertTrue($result['current_price_is_favorable']);
        $this->assertSame('Выгодно', $result['current_price_index_label']);
        $this->assertSame(-100.0, $result['current_price_competitor_delta']);
        $this->assertSame(-10.0, $result['current_price_competitor_delta_percent']);
    }

    public function test_excel_export_ozon_finance_formulas_match_screen_formula(): void
    {
        $controller = new UnitEconomicsCacheController(
            $this->createMock(UnitEconomicsCacheService::class),
            $this->createMock(UnitEconomicsService::class),
            $this->createMock(UnitEconomicsOrchestrator::class),
            $this->createMock(IntegrationAccessService::class),
        );

        $method = new \ReflectionMethod(UnitEconomicsCacheController::class, 'buildUnitEconomicsSpreadsheet');
        $method->setAccessible(true);

        $spreadsheet = $method->invoke($controller, [[
            'sku' => '9137/black',
            'product_name' => 'Test product',
            'price' => 1000,
            'cost_price' => 300,
            'sales_count' => 1,
            'revenue' => 1000,
            'commission_percent' => 10,
            'logistics_cost' => 40,
            'last_mile_cost' => 20,
            'effective_logistics' => 120,
            'expected_return_cost' => 60,
            'storage_cost' => 35,
            'acquiring_percent' => 1.5,
            'drr_percent' => 5,
            'our_share_percent' => 2,
            'tax_percent' => 6,
            'vat_percent' => 0,
            'non_local_markup_percent' => 0.57,
            'non_local_markup_amount' => 5.7,
            'weighted_non_local_markup_percent' => 8,
            'non_local_markup_source' => 'order_economics_summary',
        ]], 'Test', 'ozon', 'FBO');

        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame(120.0, $sheet->getCell('M5')->getValue());
        $this->assertSame('=D5+J5+M5+(C5*P5/100)+(C5*Q5/100)+(C5*R5/100)+(C5*S5/100)+(C5*T5/100)', $sheet->getCell('AA5')->getValue());
        $this->assertSame('=C5-J5-M5-(C5*P5/100)-(C5*Q5/100)-(C5*R5/100)-(C5*S5/100)-(C5*T5/100)', $sheet->getCell('AE5')->getValue());
        $this->assertSame('Индекс цены', $sheet->getCell('AF4')->getValue());
        $this->assertSame('', (string) $sheet->getCell('AG4')->getValue());
    }

    public function test_excel_export_wildberries_mirrors_web_layout_with_live_formulas(): void
    {
        $controller = new UnitEconomicsCacheController(
            $this->createMock(UnitEconomicsCacheService::class),
            $this->createMock(UnitEconomicsService::class),
            $this->createMock(UnitEconomicsOrchestrator::class),
            $this->createMock(IntegrationAccessService::class),
        );

        $method = new \ReflectionMethod(UnitEconomicsCacheController::class, 'buildUnitEconomicsSpreadsheet');
        $method->setAccessible(true);

        $spreadsheet = $method->invoke($controller, [[
            'sku' => 'wb-1',
            'product_name' => 'WB product',
            'price' => 1000,
            'cost_price' => 300,
            'sales_count' => 1,
            'revenue' => 1000,
            'commission_percent' => 25,
            'effective_logistics' => 90,
            'storage_cost' => 15,
            'spp_percent' => 7,
            'acquiring_percent' => 1.5,
            'drr_percent' => 5,
            'tax_percent' => 6,
            'vat_percent' => 20,
            'our_share_percent' => 4,
        ]], 'Test', 'wildberries', 'FBO');

        $sheet = $spreadsheet->getActiveSheet();

        // Заголовки в веб-порядке (WB-раскладка без ИРП — отключён WB с 13.07.2026)
        $this->assertSame('Цена покупателя, ₽', $sheet->getCell('H4')->getValue());
        $this->assertSame('СПП, %', $sheet->getCell('J4')->getValue());
        $this->assertSame('Логистика, ₽', $sheet->getCell('N4')->getValue());
        $this->assertSame('Чистая прибыль, ₽', $sheet->getCell('AF4')->getValue());
        $this->assertSame('Цена для цели, ₽', $sheet->getCell('AH4')->getValue());
        $this->assertSame('Артикул WB', $sheet->getCell('AI4')->getValue());

        // Значения
        $this->assertSame(1000.0, $sheet->getCell('F5')->getValue());
        $this->assertSame(7.0, $sheet->getCell('J5')->getValue());
        $this->assertSame(20, $sheet->getCell('E3')->getValue()); // целевая маржа для «Цены для цели»

        // Живые формулы. СПП НЕ вычитается из «На р/с» (его финансирует WB).
        $this->assertSame('=F5-(F5*I5/100)-R5-S5-(F5*T5/100)-Y5-AA5', $sheet->getCell('W5')->getValue());
        $this->assertSame('=W5-E5', $sheet->getCell('AF5')->getValue());
        $this->assertSame('=IF(F5>0,AF5/F5*100,0)', $sheet->getCell('AG5')->getValue());
    }

    public function test_excel_export_headers_include_version_format_and_source_contract(): void
    {
        $controller = new UnitEconomicsCacheController(
            $this->createMock(UnitEconomicsCacheService::class),
            $this->createMock(UnitEconomicsService::class),
            $this->createMock(UnitEconomicsOrchestrator::class),
            $this->createMock(IntegrationAccessService::class),
        );

        $method = new \ReflectionMethod(UnitEconomicsCacheController::class, 'buildExportHeaders');
        $method->setAccessible(true);

        /** @var array<string,string> $headers */
        $headers = $method->invoke($controller, 'unit-economics-test.xlsx');

        $this->assertSame('v2', $headers['X-Unit-Economics-Export-Format']);
        $this->assertSame('UnitEconomicsCacheController::exportExcel', $headers['X-Unit-Economics-Export-Source']);
        $this->assertSame('2026-07-15-01', $headers['X-Unit-Economics-Export-Version']);
        $this->assertStringContainsString('X-Unit-Economics-Export-Version', $headers['Access-Control-Expose-Headers']);
    }

    public function test_excel_export_spreadsheet_contains_version_markers_and_expected_columns(): void
    {
        $controller = new UnitEconomicsCacheController(
            $this->createMock(UnitEconomicsCacheService::class),
            $this->createMock(UnitEconomicsService::class),
            $this->createMock(UnitEconomicsOrchestrator::class),
            $this->createMock(IntegrationAccessService::class),
        );

        $method = new \ReflectionMethod(UnitEconomicsCacheController::class, 'buildUnitEconomicsSpreadsheet');
        $method->setAccessible(true);

        $spreadsheet = $method->invoke($controller, [[
            'sku' => '9137/black',
            'article' => '8206/brown',
            'product_name' => 'Test product',
            'price' => 1000,
            'cost_price' => 300,
            'sales_count' => 1,
            'revenue' => 1000,
            'commission_percent' => 10,
            'effective_logistics' => 120,
            'acquiring_percent' => 1.5,
            'drr_percent' => 5,
            'our_share_percent' => 2,
            'tax_percent' => 6,
            'vat_percent' => 0,
            'calculation_confidence' => 'medium',
            'current_price_index' => 0.9,
        ]], 'Test', 'ozon', 'FBO');

        $mainSheet = $spreadsheet->getSheetByName('Юнит-экономика');
        $this->assertNotNull($mainSheet);
        $this->assertSame('Статус данных', $mainSheet->getCell('Z4')->getValue());
        $this->assertSame('Индекс цены', $mainSheet->getCell('AF4')->getValue());
        $this->assertSame('8206/brown', $mainSheet->getCell('A5')->getValue());
        $this->assertSame('2026-07-15-01', $mainSheet->getCell('AZ1')->getValue());
        $this->assertFalse($mainSheet->getColumnDimension('AZ')->getVisible());

        $metadata = $spreadsheet->getSheetByName('Метаданные');
        $this->assertNotNull($metadata);

        $rows = $metadata->toArray(null, true, true, true);
        $templateVersion = null;
        foreach ($rows as $row) {
            if (($row['A'] ?? null) === 'Export template version') {
                $templateVersion = $row['B'] ?? null;
                break;
            }
        }

        $this->assertSame('2026-07-15-01', $templateVersion);
    }

    public function test_excel_export_uses_period_snapshot_revenue_without_price_times_sales_formula(): void
    {
        $controller = new UnitEconomicsCacheController(
            $this->createMock(UnitEconomicsCacheService::class),
            $this->createMock(UnitEconomicsService::class),
            $this->createMock(UnitEconomicsOrchestrator::class),
            $this->createMock(IntegrationAccessService::class),
        );

        $method = new \ReflectionMethod(UnitEconomicsCacheController::class, 'buildUnitEconomicsSpreadsheet');
        $method->setAccessible(true);

        $spreadsheet = $method->invoke($controller, [[
            'sku' => '9137/black',
            'product_name' => 'Test product',
            'price' => 1000,
            'cost_price' => 300,
            'sales_count' => 2,
            'revenue' => 1500,
            'export_revenue_is_period_snapshot' => true,
            'commission_percent' => 10,
            'effective_logistics' => 120,
            'acquiring_percent' => 1.5,
            'drr_percent' => 5,
            'our_share_percent' => 2,
            'tax_percent' => 6,
            'vat_percent' => 0,
        ]], 'Test', 'ozon', 'FBO');

        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame(2.0, $sheet->getCell('F5')->getValue());
        $this->assertSame(1500.0, $sheet->getCell('G5')->getValue());
    }

    public function test_resolve_locality_label_prefers_actual_locality_over_estimated_rate(): void
    {
        $controller = new UnitEconomicsCacheController(
            $this->createMock(UnitEconomicsCacheService::class),
            $this->createMock(UnitEconomicsService::class),
            $this->createMock(UnitEconomicsOrchestrator::class),
            $this->createMock(IntegrationAccessService::class),
        );

        $method = new \ReflectionMethod(UnitEconomicsCacheController::class, 'resolveLocalityLabel');
        $method->setAccessible(true);

        $this->assertSame('Нелокальная', $method->invoke($controller, [
            'is_local_sale' => false,
            'expected_locality_rate' => 75.0,
        ]));

        $this->assertSame('Локальная', $method->invoke($controller, [
            'is_local_sale' => true,
            'expected_locality_rate' => 20.0,
        ]));

        $this->assertSame('Оценка 75%', $method->invoke($controller, [
            'is_local_sale' => null,
            'expected_locality_rate' => 75.0,
        ]));
    }

    public function test_apply_ozon_locality_metrics_to_export_item_matches_ui_values(): void
    {
        $controller = new UnitEconomicsCacheController(
            $this->createMock(UnitEconomicsCacheService::class),
            $this->createMock(UnitEconomicsService::class),
            $this->createMock(UnitEconomicsOrchestrator::class),
            $this->createMock(IntegrationAccessService::class),
        );

        $method = new \ReflectionMethod(UnitEconomicsCacheController::class, 'applyOzonLocalityMetricsToExportItem');
        $method->setAccessible(true);

        $row = new LocalityMetricDaily([
            'sku' => '9137/black',
            'period_days' => 28,
            'orders_count' => 12,
            'revenue_total' => 3456.78,
            'local_share_percent' => 50.0,
            'avg_markup_percent' => 4.0,
            'calculation_confidence' => 'high',
        ]);

        $result = $method->invoke($controller, [
            'sku' => '9137/black',
            'sales_count' => 999,
            'orders_count' => 999,
            'revenue' => 999000,
            'is_local_sale' => false,
            'expected_locality_rate' => 75.0,
            'non_local_markup_percent' => 0.55,
            'calculation_confidence' => 'medium',
        ], $row);

        $this->assertSame(12, $result['sales_count']);
        $this->assertSame(12, $result['orders_count']);
        $this->assertSame(3456.78, $result['revenue']);
        $this->assertTrue($result['export_revenue_is_period_snapshot']);
        $this->assertSame(28, $result['export_sales_period_days']);
        $this->assertSame('locality_metrics_daily', $result['export_sales_source']);
        $this->assertSame(50.0, $result['expected_locality_rate']);
        $this->assertSame(4.0, $result['non_local_markup_percent']);
        $this->assertSame(4.0, $result['raw_non_local_markup_percent']);
        $this->assertNull($result['is_local_sale']);
        $this->assertSame('high', $result['calculation_confidence']);
        $this->assertSame('locality_metrics_daily', $result['non_local_markup_source']);
    }

    public function test_apply_ozon_locality_metrics_to_export_item_zeros_sales_when_snapshot_has_no_sku_row(): void
    {
        $controller = new UnitEconomicsCacheController(
            $this->createMock(UnitEconomicsCacheService::class),
            $this->createMock(UnitEconomicsService::class),
            $this->createMock(UnitEconomicsOrchestrator::class),
            $this->createMock(IntegrationAccessService::class),
        );

        $method = new \ReflectionMethod(UnitEconomicsCacheController::class, 'applyOzonLocalityMetricsToExportItem');
        $method->setAccessible(true);

        $result = $method->invoke($controller, [
            'sku' => '9137/black',
            'sales_count' => 999,
            'orders_count' => 999,
            'revenue' => 999000,
        ], null, true, 28, '2026-06-03');

        $this->assertSame(0, $result['sales_count']);
        $this->assertSame(0, $result['orders_count']);
        $this->assertSame(0.0, $result['revenue']);
        $this->assertTrue($result['export_revenue_is_period_snapshot']);
        $this->assertSame(28, $result['export_sales_period_days']);
        $this->assertSame('2026-06-03', $result['export_sales_snapshot_date']);
        $this->assertSame('locality_metrics_daily', $result['export_sales_source']);
    }
}
