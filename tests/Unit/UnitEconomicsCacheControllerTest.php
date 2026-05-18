<?php

namespace Tests\Unit;

use App\Domains\UnitEconomics\UnitEconomicsOrchestrator;
use App\Http\Controllers\Api\UnitEconomicsCacheController;
use App\Models\Integration;
use App\Models\Product;
use App\Models\UnitEconomicsCache;
use App\Services\IntegrationAccessService;
use App\Services\UnitEconomicsCacheService;
use App\Services\UnitEconomicsService;
use Tests\TestCase;

class UnitEconomicsCacheControllerTest extends TestCase
{
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
}
