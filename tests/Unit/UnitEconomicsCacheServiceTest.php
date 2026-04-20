<?php

namespace Tests\Unit;

use App\Domains\UnitEconomics\UnitEconomicsOrchestrator;
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
}
