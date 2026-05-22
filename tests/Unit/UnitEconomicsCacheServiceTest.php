<?php

namespace Tests\Unit;

use App\Domains\UnitEconomics\DTO\CalculationInput;
use App\Domains\UnitEconomics\DTO\CostBreakdown;
use App\Domains\UnitEconomics\DTO\UnitEconomicsResult;
use App\Domains\UnitEconomics\UnitEconomicsOrchestrator;
use App\Domains\Wildberries\UnitEconomics\WildberriesUnitEconomicsCalculator;
use App\Models\Product;
use App\Services\UnitEconomicsCacheService;
use PHPUnit\Framework\TestCase;

class UnitEconomicsCacheServiceTest extends TestCase
{
    public function test_real_order_clusters_keep_markup_fields_from_delivery_profile(): void
    {
        $service = new UnitEconomicsCacheService(
            $this->createMock(UnitEconomicsOrchestrator::class)
        );

        $method = new \ReflectionMethod(UnitEconomicsCacheService::class, 'mergeOzonRealOrdersClustersSummary');
        $method->setAccessible(true);

        $merged = $method->invoke(
            $service,
            [
                [
                    'cluster_name' => 'Екатеринбург',
                    'orders_count' => 3,
                    'orders_percent' => 21.43,
                ],
                [
                    'cluster_name' => 'Москва, МО и Дальние регионы',
                    'orders_count' => 3,
                    'orders_percent' => 21.43,
                ],
            ],
            [
                [
                    'cluster_id' => '13',
                    'cluster_name' => 'Екатеринбург',
                    'non_local_markup_percent' => 8.0,
                    'effective_markup_percent' => 8.0,
                    'markup_reason' => 'non_local_markup_applied',
                    'is_local_cluster' => false,
                    'route_key' => 'cluster_far',
                    'route_label' => 'Екатеринбург',
                ],
                [
                    'cluster_id' => '154',
                    'cluster_name' => 'Москва, МО и Дальние регионы',
                    'non_local_markup_percent' => 0.0,
                    'effective_markup_percent' => 0.0,
                    'markup_reason' => 'local_cluster',
                    'is_local_cluster' => true,
                    'route_key' => 'cluster_msk',
                    'route_label' => 'Москва, МО и Дальние регионы',
                ],
            ],
            [
                [
                    'cluster_name' => 'Москва, МО и Дальние регионы',
                    'share_percent' => 100,
                ],
            ]
        );

        $this->assertCount(2, $merged);
        $this->assertSame(8.0, $merged[0]['effective_markup_percent']);
        $this->assertSame('non_local_markup_applied', $merged[0]['markup_reason']);
        $this->assertFalse($merged[0]['is_local_cluster']);
        $this->assertSame(0.0, $merged[1]['effective_markup_percent']);
        $this->assertSame('local_cluster', $merged[1]['markup_reason']);
        $this->assertTrue($merged[1]['is_local_cluster']);
    }

    public function test_sales_profile_is_enriched_with_cluster_markup_fields(): void
    {
        $service = new UnitEconomicsCacheService(
            $this->createMock(UnitEconomicsOrchestrator::class)
        );

        $method = new \ReflectionMethod(UnitEconomicsCacheService::class, 'mergeOzonSalesProfileWithClustersSummary');
        $method->setAccessible(true);

        $merged = $method->invoke(
            $service,
            [
                'clusters' => [
                    [
                        'cluster_name' => 'Екатеринбург',
                        'sales_30_days' => 3,
                        'sales_share_percent' => 21.43,
                    ],
                ],
            ],
            [
                [
                    'cluster_id' => '13',
                    'cluster_name' => 'Екатеринбург',
                    'non_local_markup_percent' => 8.0,
                    'effective_markup_percent' => 8.0,
                    'markup_reason' => 'non_local_markup_applied',
                    'is_local_cluster' => false,
                    'route_key' => 'cluster_far',
                    'route_label' => 'Екатеринбург',
                ],
            ]
        );

        $this->assertSame('13', $merged['clusters'][0]['cluster_id']);
        $this->assertSame(8.0, $merged['clusters'][0]['effective_markup_percent']);
        $this->assertSame(8.0, $merged['clusters'][0]['non_local_markup_percent']);
        $this->assertSame('non_local_markup_applied', $merged['clusters'][0]['markup_reason']);
    }

    public function test_real_order_clusters_fallback_to_pricing_matrix_when_delivery_profile_has_no_match(): void
    {
        $service = new UnitEconomicsCacheService(
            $this->createMock(UnitEconomicsOrchestrator::class)
        );

        $method = new \ReflectionMethod(UnitEconomicsCacheService::class, 'mergeOzonRealOrdersClustersSummary');
        $method->setAccessible(true);

        $merged = $method->invoke(
            $service,
            [
                [
                    'cluster_name' => 'Екатеринбург',
                    'orders_count' => 3,
                    'orders_percent' => 21.43,
                ],
            ],
            [],
            [
                [
                    'cluster_name' => 'Москва, МО и Дальние регионы',
                    'share_percent' => 100,
                ],
            ],
            true
        );

        $this->assertSame(8.0, $merged[0]['non_local_markup_percent']);
        $this->assertSame(8.0, $merged[0]['effective_markup_percent']);
        $this->assertSame('non_local_markup_applied', $merged[0]['markup_reason']);
    }

    public function test_cache_conversion_calculates_tax_from_price_when_metadata_has_no_tax_amount(): void
    {
        $service = new UnitEconomicsCacheService(
            $this->createMock(UnitEconomicsOrchestrator::class)
        );

        $result = new UnitEconomicsResult(
            sku: 'sku-tax',
            marketplace: 'yandex_market',
            fulfillmentType: 'FBS',
            price: 1000.0,
            costs: new CostBreakdown(
                commission: 100.0,
                acquiring: 10.0,
                logistics: 50.0,
                deliveryCost: 50.0,
                costPrice: 300.0,
            ),
            revenue: 1000.0,
            totalCosts: 460.0,
            netProfit: 540.0,
            marginPercent: 54.0,
            marginAbsolute: 540.0,
            commissionPercent: 10.0,
            acquiringPercent: 1.0,
            isProfitable: true,
            hasCostPrice: true,
            productName: 'Tax Product',
        );

        $method = new \ReflectionMethod(UnitEconomicsCacheService::class, 'convertResultToCacheData');
        $method->setAccessible(true);

        $cacheData = $method->invoke(
            $service,
            new Product(['id' => 'product-tax', 'name' => 'Tax Product']),
            null,
            $result,
            [
                '_extra' => [
                    'tax_percent' => 6,
                    'drr_percent' => 0,
                    'our_share_percent' => 0,
                    'vat_percent' => 0,
                ],
            ]
        );

        $this->assertSame(60.0, $cacheData['tax_amount']);
    }

    public function test_wildberries_calculator_calculates_tax_from_price_not_settlement_profit(): void
    {
        $calculator = new WildberriesUnitEconomicsCalculator();

        $result = $calculator->calculate(CalculationInput::fromArray([
            'sku' => 'wb-tax',
            'integration_id' => 1,
            'marketplace' => 'wildberries',
            'fulfillment_type' => 'FBO',
            'price' => 1000,
            'cost_price' => 300,
            'tax_percent' => 6,
            'commission_rate' => 15,
            'length' => 10,
            'width' => 10,
            'height' => 10,
            'redemption_rate' => 100,
            'storage_cost' => 0,
            'acquiring_percent' => 0,
        ]));

        $this->assertSame(60.0, $result->metadata['tax_amount']);
    }
}
