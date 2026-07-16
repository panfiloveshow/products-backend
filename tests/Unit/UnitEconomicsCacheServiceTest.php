<?php

namespace Tests\Unit;

use App\Domains\UnitEconomics\DTO\CalculationInput;
use App\Domains\UnitEconomics\DTO\CostBreakdown;
use App\Domains\UnitEconomics\DTO\UnitEconomicsResult;
use App\Domains\UnitEconomics\UnitEconomicsOrchestrator;
use App\Domains\Wildberries\UnitEconomics\WildberriesUnitEconomicsCalculator;
use App\Models\Product;
use App\Services\UnitEconomicsCacheService;
use App\Services\UnitEconomicsService;
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

    public function test_wildberries_non_fbo_does_not_apply_wb_storage_cost(): void
    {
        $calculator = new WildberriesUnitEconomicsCalculator();

        $fbo = $calculator->calculate(CalculationInput::fromArray([
            'sku' => 'wb-storage',
            'integration_id' => 1,
            'marketplace' => 'wildberries',
            'fulfillment_type' => 'FBO',
            'price' => 1000,
            'cost_price' => 0,
            'commission_rate' => 0,
            'length' => 10,
            'width' => 10,
            'height' => 10,
            'redemption_rate' => 100,
            'storage_cost' => 100,
            'acquiring_percent' => 0,
            'tax_percent' => 0,
        ]));

        $fbs = $calculator->calculate(CalculationInput::fromArray([
            'sku' => 'wb-storage',
            'integration_id' => 1,
            'marketplace' => 'wildberries',
            'fulfillment_type' => 'FBS',
            'price' => 1000,
            'cost_price' => 0,
            'commission_rate' => 0,
            'length' => 10,
            'width' => 10,
            'height' => 10,
            'redemption_rate' => 100,
            'storage_cost' => 100,
            'acquiring_percent' => 0,
            'tax_percent' => 0,
        ]));

        $this->assertSame(100.0, $fbo->costs->storageCost);
        $this->assertSame(0.0, $fbs->costs->storageCost);
    }

    public function test_wildberries_calculator_uses_official_box_tariff_breakdown_for_logistics(): void
    {
        $calculator = new WildberriesUnitEconomicsCalculator();

        $result = $calculator->calculate(CalculationInput::fromArray([
            'sku' => 'wb-tariff',
            'integration_id' => 1,
            'marketplace' => 'wildberries',
            'fulfillment_type' => 'FBO',
            'price' => 1000,
            'cost_price' => 0,
            'commission_rate' => 0,
            'length' => 10,
            'width' => 10,
            'height' => 30,
            'warehouse_coefficient' => 1.5,
            'localization_index' => 1,
            'redemption_rate' => 100,
            'storage_cost' => 0,
            'acquiring_percent' => 0,
            'tax_percent' => 0,
            'tariff_breakdown' => [
                'source' => 'wildberries_tariff_snapshots',
                'effective_date' => '2026-05-23',
                'warehouse_name' => 'Коледино',
                'box' => [
                    'delivery_base' => 40,
                    'delivery_liter' => 10,
                    'delivery_coef_percent' => 150,
                    'delivery_marketplace_base' => 30,
                    'delivery_marketplace_liter' => 5,
                ],
            ],
        ]));

        $this->assertSame(40.0, $result->metadata['base_logistics']);
        $this->assertSame(60.0, $result->costs->logistics);
        $this->assertSame(150.0, $result->metadata['warehouse_coef_percent']);
        $this->assertSame(20.0, $result->metadata['warehouse_coef_amount']);
        $this->assertTrue($result->metadata['warehouse_coef_included_in_tariff']);
        $this->assertSame('wildberries_tariff_snapshots', $result->metadata['tariff_source']);
        $this->assertSame('Коледино', $result->metadata['tariff_warehouse_name']);
    }

    public function test_wildberries_official_box_tariff_applies_localization_without_double_warehouse_coef(): void
    {
        $calculator = new WildberriesUnitEconomicsCalculator();

        $result = $calculator->calculate(CalculationInput::fromArray([
            'sku' => 'wb-tariff-il',
            'integration_id' => 1,
            'marketplace' => 'wildberries',
            'fulfillment_type' => 'FBO',
            'price' => 1000,
            'cost_price' => 0,
            'commission_rate' => 0,
            'length' => 10,
            'width' => 10,
            'height' => 30,
            'warehouse_coefficient' => 1.5,
            'localization_index' => 1.2,
            'redemption_rate' => 100,
            'storage_cost' => 0,
            'acquiring_percent' => 0,
            'tax_percent' => 0,
            'tariff_breakdown' => [
                'box' => [
                    'delivery_base' => 40,
                    'delivery_liter' => 10,
                    'delivery_coef_percent' => 150,
                ],
            ],
        ]));

        $this->assertSame(40.0, $result->metadata['base_logistics']);
        $this->assertSame(60.0, $result->metadata['base_logistics'] + $result->metadata['warehouse_coef_amount']);
        $this->assertSame(12.0, $result->metadata['localization_amount']);
        $this->assertSame(72.0, $result->costs->logistics);
    }

    public function test_wildberries_dbs_does_not_apply_warehouse_or_localization_amounts_to_own_delivery(): void
    {
        $calculator = new WildberriesUnitEconomicsCalculator();

        $result = $calculator->calculate(CalculationInput::fromArray([
            'sku' => 'wb-dbs',
            'integration_id' => 1,
            'marketplace' => 'wildberries',
            'fulfillment_type' => 'DBS',
            'price' => 1000,
            'cost_price' => 0,
            'commission_rate' => 0,
            'length' => 10,
            'width' => 10,
            'height' => 10,
            'warehouse_coefficient' => 1.8,
            'localization_index' => 1.5,
            'own_delivery_cost' => 100,
            'redemption_rate' => 100,
            'storage_cost' => 0,
            'acquiring_percent' => 0,
            'tax_percent' => 0,
        ]));

        $this->assertSame(100.0, $result->costs->logistics);
        $this->assertSame(100.0, $result->metadata['warehouse_coef_percent']);
        $this->assertSame(0.0, $result->metadata['warehouse_coef_amount']);
        $this->assertSame(0.0, $result->metadata['localization_amount']);
    }

    public function test_wildberries_logistics_adds_sales_distribution_index_to_price_before_wb_discount(): void
    {
        $calculator = new WildberriesUnitEconomicsCalculator();

        $result = $calculator->calculate(CalculationInput::fromArray([
            'sku' => 'wb-irp',
            'integration_id' => 1,
            'marketplace' => 'wildberries',
            'fulfillment_type' => 'FBO',
            'price' => 1000,
            'old_price' => 1200,
            'cost_price' => 0,
            'commission_rate' => 0,
            'length' => 10,
            'width' => 10,
            'height' => 30,
            'warehouse_coefficient' => 1.5,
            'localization_index' => 1.2,
            'sales_distribution_index' => 1.15,
            'redemption_rate' => 100,
            'storage_cost' => 0,
            'acquiring_percent' => 0,
            'tax_percent' => 0,
            'tariff_breakdown' => [
                'box' => [
                    'delivery_base' => 40,
                    'delivery_liter' => 10,
                    'delivery_coef_percent' => 150,
                ],
            ],
        ]));

        $this->assertSame(40.0, $result->metadata['base_logistics']);
        $this->assertSame(12.0, $result->metadata['localization_amount']);
        $this->assertSame(1.15, $result->metadata['sales_distribution_index']);
        $this->assertSame(13.8, $result->metadata['sales_distribution_amount']);
        $this->assertSame(85.8, $result->costs->logistics);
    }

    public function test_wildberries_fbs_uses_marketplace_box_tariff_fields(): void
    {
        $calculator = new WildberriesUnitEconomicsCalculator();

        $result = $calculator->calculate(CalculationInput::fromArray([
            'sku' => 'wb-tariff-fbs',
            'integration_id' => 1,
            'marketplace' => 'wildberries',
            'fulfillment_type' => 'FBS',
            'price' => 1000,
            'cost_price' => 0,
            'commission_rate' => 0,
            'length' => 10,
            'width' => 10,
            'height' => 30,
            'redemption_rate' => 100,
            'storage_cost' => 0,
            'acquiring_percent' => 0,
            'tax_percent' => 0,
            'tariff_breakdown' => [
                'box' => [
                    'delivery_base' => 100,
                    'delivery_liter' => 100,
                    'delivery_marketplace_base' => 30,
                    'delivery_marketplace_liter' => 5,
                ],
            ],
        ]));

        $this->assertSame(40.0, $result->metadata['base_logistics']);
        $this->assertSame(40.0, $result->costs->logistics);
    }

    public function test_wildberries_calculator_uses_current_small_volume_tariff_tiers(): void
    {
        $calculator = new WildberriesUnitEconomicsCalculator();

        $result = $calculator->calculate(CalculationInput::fromArray([
            'sku' => 'wb-small-volume',
            'integration_id' => 1,
            'marketplace' => 'wildberries',
            'fulfillment_type' => 'FBO',
            'price' => 1000,
            'cost_price' => 0,
            'commission_rate' => 0,
            'length' => 10,
            'width' => 10,
            'height' => 5.5,
            'warehouse_coefficient' => 1,
            'localization_index' => 1,
            'redemption_rate' => 100,
            'storage_cost' => 0,
            'acquiring_percent' => 0,
            'tax_percent' => 0,
        ]));

        $this->assertSame(0.55, $result->metadata['volume_liters']);
        $this->assertSame(29.0, $result->metadata['base_logistics']);
        $this->assertSame(29.0, $result->costs->logistics);
    }

    public function test_wildberries_calculator_uses_fractional_additional_liter_after_one_liter(): void
    {
        $calculator = new WildberriesUnitEconomicsCalculator();

        $result = $calculator->calculate(CalculationInput::fromArray([
            'sku' => 'wb-fractional-volume',
            'integration_id' => 1,
            'marketplace' => 'wildberries',
            'fulfillment_type' => 'FBO',
            'price' => 1000,
            'cost_price' => 0,
            'commission_rate' => 0,
            'length' => 10,
            'width' => 10,
            'height' => 18,
            'warehouse_coefficient' => 1,
            'localization_index' => 1,
            'redemption_rate' => 100,
            'storage_cost' => 0,
            'acquiring_percent' => 0,
            'tax_percent' => 0,
        ]));

        $this->assertSame(1.8, $result->metadata['volume_liters']);
        $this->assertSame(57.2, $result->metadata['base_logistics']);
        $this->assertSame(57.2, $result->costs->logistics);
    }

    public function test_wildberries_box_tariff_snapshot_keeps_small_volume_tier_and_applies_warehouse_coef(): void
    {
        $calculator = new WildberriesUnitEconomicsCalculator();

        $result = $calculator->calculate(CalculationInput::fromArray([
            'sku' => 'wb-small-volume-snapshot',
            'integration_id' => 1,
            'marketplace' => 'wildberries',
            'fulfillment_type' => 'FBO',
            'price' => 1000,
            'cost_price' => 0,
            'commission_rate' => 0,
            'length' => 10,
            'width' => 10,
            'height' => 5.5,
            'warehouse_coefficient' => 1,
            'localization_index' => 1,
            'redemption_rate' => 100,
            'storage_cost' => 0,
            'acquiring_percent' => 0,
            'tax_percent' => 0,
            'tariff_breakdown' => [
                'source' => 'wildberries_tariff_snapshots',
                'box' => [
                    'delivery_base' => 46,
                    'delivery_liter' => 14,
                    'delivery_coef_percent' => 150,
                ],
            ],
        ]));

        $this->assertSame(29.0, $result->metadata['base_logistics']);
        $this->assertSame(43.5, $result->costs->logistics);
        $this->assertSame(150.0, $result->metadata['warehouse_coef_percent']);
        $this->assertTrue($result->metadata['warehouse_coef_included_in_tariff']);
    }

    public function test_wildberries_calculator_uses_box_storage_tariff_breakdown_when_storage_cost_missing(): void
    {
        $calculator = new WildberriesUnitEconomicsCalculator();

        $result = $calculator->calculate(CalculationInput::fromArray([
            'sku' => 'wb-storage-snapshot',
            'integration_id' => 1,
            'marketplace' => 'wildberries',
            'fulfillment_type' => 'FBO',
            'price' => 1000,
            'cost_price' => 0,
            'commission_rate' => 0,
            'length' => 10,
            'width' => 10,
            'height' => 18,
            'warehouse_coefficient' => 1,
            'localization_index' => 1,
            'redemption_rate' => 100,
            'acquiring_percent' => 0,
            'tax_percent' => 0,
            'tariff_breakdown' => [
                'box' => [
                    'storage_base' => 0.10,
                    'storage_liter' => 0.05,
                ],
            ],
        ]));

        $this->assertSame(4.2, $result->costs->storageCost);
    }

    public function test_wildberries_legacy_service_uses_current_small_volume_tariff_tiers(): void
    {
        $service = new UnitEconomicsService();

        $result = $service->calculate('wildberries', [
            'price' => 1000,
            'cost_price' => 0,
            'sales_count' => 1,
            'fulfillment_type' => 'FBO',
            'commission_percent' => 0,
            'volume_liters' => 0.55,
            'warehouse_coefficient' => 1,
            'localization_index' => 1,
            'redemption_rate' => 100,
            'storage_cost' => 0,
            'acquiring_percent' => 0,
            'tax_percent' => 0,
        ]);

        $this->assertSame(29.0, $result['base_logistics_cost']);
        $this->assertSame(29.0, $result['logistics_cost']);
    }

    public function test_wildberries_cache_conversion_persists_localization_index_in_logistics_coefficient(): void
    {
        $service = new UnitEconomicsCacheService(
            $this->createMock(UnitEconomicsOrchestrator::class)
        );

        $result = new UnitEconomicsResult(
            sku: 'wb-il',
            marketplace: 'wildberries',
            fulfillmentType: 'FBO',
            price: 1000.0,
            costs: new CostBreakdown(
                commission: 0.0,
                acquiring: 0.0,
                logistics: 72.0,
                deliveryCost: 72.0,
                costPrice: 0.0,
            ),
            revenue: 1000.0,
            totalCosts: 72.0,
            netProfit: 928.0,
            marginPercent: 92.8,
            marginAbsolute: 928.0,
            commissionPercent: 0.0,
            acquiringPercent: 0.0,
            isProfitable: true,
            hasCostPrice: false,
            productName: 'WB IL',
        );
        $result->metadata = [
            'base_logistics' => 40.0,
            'localization_index' => 1.2,
        ];

        $method = new \ReflectionMethod(UnitEconomicsCacheService::class, 'convertResultToCacheData');
        $method->setAccessible(true);

        $cacheData = $method->invoke(
            $service,
            new Product(['id' => 'wb-il-product', 'name' => 'WB IL']),
            null,
            $result,
            [
                'localization_index' => 1.2,
                '_extra' => [
                    'tax_percent' => 0,
                    'drr_percent' => 0,
                    'our_share_percent' => 0,
                    'vat_percent' => 0,
                ],
            ]
        );

        $this->assertSame(1.2, $cacheData['logistics_coefficient']);
    }

    public function test_delivery_profile_cache_precedence_scheme_then_all(): void
    {
        $service = new UnitEconomicsCacheService(
            $this->createMock(UnitEconomicsOrchestrator::class)
        );

        $schemeProfile = new \App\Models\OzonSkuDeliveryProfile(['scheme' => 'FBO']);
        $allProfile = new \App\Models\OzonSkuDeliveryProfile(['scheme' => 'ALL']);

        $ref = new \ReflectionProperty(UnitEconomicsCacheService::class, 'deliveryProfileCache');
        $ref->setAccessible(true);
        // Прогреты и точная схема, и ALL — точная схема должна победить; для SKU2 только ALL.
        $ref->setValue($service, [
            '5|SKU1|FBO' => $schemeProfile,
            '5|SKU1|ALL' => $allProfile,
            '5|SKU2|FBO' => null,
            '5|SKU2|ALL' => $allProfile,
        ]);

        $method = new \ReflectionMethod(UnitEconomicsCacheService::class, 'getDeliveryProfileCached');
        $method->setAccessible(true);

        $p1 = new Product(['integration_id' => 5, 'sku' => 'SKU1']);
        $p2 = new Product(['integration_id' => 5, 'sku' => 'SKU2']);

        $this->assertSame($schemeProfile, $method->invoke($service, $p1, 'FBO'));
        $this->assertSame($allProfile, $method->invoke($service, $p2, 'FBO'));
    }

    public function test_supply_fixation_cache_returns_warmed_value(): void
    {
        $service = new UnitEconomicsCacheService(
            $this->createMock(UnitEconomicsOrchestrator::class)
        );

        $fixation = new \App\Models\OzonSupplyFixation(['sku' => 'SKU1']);
        $ref = new \ReflectionProperty(UnitEconomicsCacheService::class, 'supplyFixationCache');
        $ref->setAccessible(true);
        $ref->setValue($service, ['5|SKU1' => $fixation, '5|SKU2' => null]);

        $method = new \ReflectionMethod(UnitEconomicsCacheService::class, 'getSupplyFixationCached');
        $method->setAccessible(true);

        // Прогретые ключи (в т.ч. закэшированный null) возвращаются без обращения к БД.
        $this->assertSame($fixation, $method->invoke($service, 5, 'SKU1'));
        $this->assertNull($method->invoke($service, 5, 'SKU2'));
    }

    public function test_legacy_ozon_period_total_is_normalized_to_per_unit_amount(): void
    {
        $service = new UnitEconomicsCacheService(
            $this->createMock(UnitEconomicsOrchestrator::class)
        );

        $method = new \ReflectionMethod(UnitEconomicsCacheService::class, 'legacyAggregatePerUnit');
        $method->setAccessible(true);

        // Production regression: P004 had 1 694 000 ₽ in the legacy aggregate
        // for 16 940 sales. RFBS must receive the 100 ₽ per-order amount.
        $this->assertSame(100.0, $method->invoke($service, 1694000.0, 16940));
        $this->assertSame(100.0, $method->invoke($service, 718200.0, 7182));
        $this->assertSame(0.0, $method->invoke($service, 0.0, 0));
    }
}
