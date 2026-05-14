<?php

namespace Tests\Unit;

use App\Domains\Ozon\UnitEconomics\OzonUnitEconomicsCalculator;
use App\Domains\UnitEconomics\DTO\CalculationInput;
use PHPUnit\Framework\TestCase;

class OzonUnitEconomicsCalculatorTest extends TestCase
{
    public function test_fbo_local_sale_without_markup(): void
    {
        $calculator = new OzonUnitEconomicsCalculator();
        $input = CalculationInput::fromArray([
            'sku' => 'sku-1',
            'integration_id' => 1,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'price' => 1000,
            'cost_price' => 300,
            'length' => 10,
            'width' => 10,
            'height' => 10,
            'route_key' => 'cluster_msk',
        ]);

        $result = $calculator->calculate($input)->toArray();

        $this->assertSame(true, $result['is_local_sale']);
        $this->assertEquals(0.0, $result['non_local_markup_percent']);
        $this->assertSame('cluster_msk', $result['route_key']);
    }

    public function test_fbo_non_local_sale_has_markup(): void
    {
        $calculator = new OzonUnitEconomicsCalculator();
        $input = CalculationInput::fromArray([
            'sku' => 'sku-2',
            'integration_id' => 1,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'price' => 1000,
            'cost_price' => 300,
            'length' => 10,
            'width' => 10,
            'height' => 10,
            'route_key' => 'cluster_far',
        ]);

        $result = $calculator->calculate($input)->toArray();

        $this->assertSame(false, $result['is_local_sale']);
        $this->assertGreaterThan(0, $result['non_local_markup_percent']);
        $this->assertGreaterThan(0, $result['non_local_markup_amount']);
    }

    public function test_fbo_profile_markup_is_disabled_below_fifty_sales_last_7_days(): void
    {
        $calculator = new OzonUnitEconomicsCalculator();
        $input = CalculationInput::fromArray([
            'sku' => 'sku-3',
            'integration_id' => 1,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'price' => 500,
            'cost_price' => 200,
            'length' => 5,
            'width' => 5,
            'height' => 8,
            'sales_7_days' => 40,
            'route_key' => 'cluster_regional',
            'route_label' => 'Казань',
            'stock_profile' => [
                ['cluster_name' => 'Казань', 'share_percent' => 100],
            ],
            'clusters_summary' => [
                ['cluster_id' => '154', 'cluster_name' => 'Москва, МО и Дальние регионы', 'orders_percent' => 100],
            ],
        ]);

        $result = $calculator->calculate($input)->toArray();

        $this->assertSame(0.0, $result['non_local_markup_percent']);
        $this->assertSame(60.0, $result['base_logistics']);
    }

    public function test_fbo_profile_markup_uses_destination_cluster_rate_when_threshold_passed(): void
    {
        $calculator = new OzonUnitEconomicsCalculator();
        $input = CalculationInput::fromArray([
            'sku' => 'sku-4',
            'integration_id' => 1,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'price' => 500,
            'cost_price' => 200,
            'length' => 5,
            'width' => 5,
            'height' => 8,
            'sales_7_days' => 80,
            'route_key' => 'cluster_regional',
            'route_label' => 'Казань',
            'stock_profile' => [
                ['cluster_name' => 'Казань', 'share_percent' => 100],
            ],
            'clusters_summary' => [
                ['cluster_id' => '154', 'cluster_name' => 'Москва, МО и Дальние регионы', 'orders_percent' => 100],
            ],
        ]);

        $result = $calculator->calculate($input)->toArray();

        $this->assertSame(8.0, $result['non_local_markup_percent']);
        $this->assertSame(40.0, $result['non_local_markup_amount']);
        $this->assertSame(60.0, $result['base_logistics']);
    }

    public function test_fbs_profile_never_applies_non_local_markup(): void
    {
        $calculator = new OzonUnitEconomicsCalculator();
        $input = CalculationInput::fromArray([
            'sku' => 'sku-fbs',
            'integration_id' => 1,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBS',
            'price' => 500,
            'cost_price' => 200,
            'length' => 5,
            'width' => 5,
            'height' => 8,
            'sales_7_days' => 80,
            'route_key' => 'cluster_regional',
            'route_label' => 'Казань',
            'stock_profile' => [
                ['cluster_name' => 'Казань', 'share_percent' => 100],
            ],
            'clusters_summary' => [
                ['cluster_id' => '154', 'cluster_name' => 'Москва, МО и Дальние регионы', 'orders_percent' => 100],
            ],
        ]);

        $result = $calculator->calculate($input)->toArray();

        $this->assertSame(0.0, $result['non_local_markup_percent']);
        $this->assertSame(0.0, $result['non_local_markup_amount']);
        $this->assertSame($result['base_logistics'], $result['costs']['logistics']);
    }

    public function test_no_sales_source_adds_expected_return_to_logistics_as_worst_case(): void
    {
        $calculator = new OzonUnitEconomicsCalculator();
        $input = CalculationInput::fromArray([
            'sku' => 'sku-no-sales',
            'integration_id' => 1,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBS',
            'price' => 1000,
            'cost_price' => 200,
            'length' => 20,
            'width' => 20,
            'height' => 20,
            'redemption_rate' => 0,
            'redemption_source' => 'no_sales_28d',
            'orders_count' => 0,
        ]);

        $result = $calculator->calculate($input)->toArray();

        $this->assertGreaterThan(0.0, $result['expected_return_cost']);
        $this->assertSame($result['base_logistics'], $result['return_logistics']);
        $this->assertSame(
            $result['costs']['logistics'] + $result['last_mile'] + $result['processing_fee'] + $result['expected_return_cost'],
            $result['effective_logistics']
        );
    }

    public function test_no_sales_source_ignores_stale_profile_and_uses_volume_tariff(): void
    {
        $calculator = new OzonUnitEconomicsCalculator();
        $input = CalculationInput::fromArray([
            'sku' => 'sku-no-sales-profile',
            'integration_id' => 1,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'price' => 1000,
            'cost_price' => 200,
            'length' => 43,
            'width' => 30.5,
            'height' => 10,
            'redemption_rate' => 0,
            'redemption_source' => 'no_sales_28d',
            'orders_count' => 0,
            'weighted_logistics_cost' => 999,
            'route_key' => 'cluster_far',
            'clusters_summary' => [
                ['cluster_name' => 'Дальний Восток', 'orders_count' => 1, 'orders_percent' => 100, 'effective_markup_percent' => 8],
            ],
        ]);

        $result = $calculator->calculate($input)->toArray();

        $this->assertNotSame(999.0, $result['base_logistics']);
        $this->assertSame($result['base_logistics'], $result['costs']['logistics']);
        $this->assertSame(0.0, $result['non_local_markup_percent']);
        $this->assertSame('unknown', $result['route_resolution_status']);
        $this->assertSame('unknown', $result['locality_resolution_status']);
    }

    public function test_cancelled_only_orders_do_not_add_expected_return_to_logistics(): void
    {
        $calculator = new OzonUnitEconomicsCalculator();
        $input = CalculationInput::fromArray([
            'sku' => 'sku-cancelled',
            'integration_id' => 1,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBS',
            'price' => 1000,
            'cost_price' => 200,
            'length' => 20,
            'width' => 20,
            'height' => 20,
            'redemption_rate' => 0,
            'redemption_source' => 'postings_28d',
            'orders_count' => 1,
            'cancelled_count' => 1,
            'delivered_count' => 0,
        ]);

        $result = $calculator->calculate($input)->toArray();

        $this->assertSame(0.0, $result['expected_return_cost']);
        $this->assertSame(0.0, $result['return_processing']);
    }

    public function test_cancelled_only_fbo_orders_do_not_apply_non_local_markup(): void
    {
        $calculator = new OzonUnitEconomicsCalculator();
        $input = CalculationInput::fromArray([
            'sku' => 'sku-cancelled-fbo',
            'integration_id' => 1,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'price' => 1000,
            'cost_price' => 200,
            'length' => 20,
            'width' => 20,
            'height' => 20,
            'redemption_rate' => 0,
            'redemption_source' => 'postings_28d',
            'orders_count' => 1,
            'cancelled_count' => 1,
            'delivered_count' => 0,
            'shipping_cluster_name' => 'Невинномысск',
            'destination_cluster_name' => 'Краснодар',
        ]);

        $result = $calculator->calculate($input)->toArray();

        $this->assertSame(0.0, $result['non_local_markup_percent']);
        $this->assertSame(0.0, $result['non_local_markup_amount']);
        $this->assertSame($result['base_logistics'], $result['costs']['logistics']);
    }

    public function test_fbo_exact_cluster_fixation_context_uses_matrix_and_explicit_markup_disable(): void
    {
        $calculator = new OzonUnitEconomicsCalculator();
        $input = CalculationInput::fromArray([
            'sku' => 'sku-5',
            'integration_id' => 1,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'price' => 500,
            'cost_price' => 200,
            'length' => 5,
            'width' => 5,
            'height' => 8,
            'shipping_cluster_name' => 'Казань',
            'destination_cluster_name' => 'Москва, МО и Дальние регионы',
            'fixation_applied' => true,
            'fixation_id' => 10,
            'fixed_until' => '2026-06-05',
            'tariff_version_used' => '2026-04-06',
            'markup_version_used' => '2026-04-06',
            'markup_applied' => false,
            'markup_reason_code' => 'fbo_lt_50_orders_7d',
            'markup_reason_label' => 'Надбавка не применяется: за 7 дней по FBO меньше 50 заказов',
            'calculation_mode' => 'factual',
        ]);

        $result = $calculator->calculate($input)->toArray();

        $this->assertSame(60.0, $result['base_logistics']);
        $this->assertSame(0.0, $result['non_local_markup_percent']);
        $this->assertFalse($result['markup_applied']);
        $this->assertSame('fbo_lt_50_orders_7d', $result['markup_reason_code']);
        $this->assertSame('2026-06-05', $result['fixed_until']);
    }

    public function test_fbo_fixation_shipping_cluster_overrides_stale_profile_locality_flags(): void
    {
        $calculator = new OzonUnitEconomicsCalculator();
        $input = CalculationInput::fromArray([
            'sku' => 'sku-6',
            'integration_id' => 1,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'price' => 500,
            'cost_price' => 200,
            'length' => 5,
            'width' => 5,
            'height' => 8,
            'sales_7_days' => 80,
            'shipping_cluster_name' => 'Санкт-Петербург и СЗО',
            'fixation_applied' => true,
            'tariff_version_used' => '2026-04-06',
            'markup_version_used' => '2026-04-06',
            'stock_profile' => [
                ['cluster_name' => 'Москва, МО и Дальние регионы', 'share_percent' => 95],
                ['cluster_name' => 'Санкт-Петербург и СЗО', 'share_percent' => 5],
            ],
            'clusters_summary' => [
                [
                    'cluster_id' => '154',
                    'cluster_name' => 'Москва, МО и Дальние регионы',
                    'orders_percent' => 100,
                    'is_local_cluster' => true,
                ],
            ],
        ]);

        $result = $calculator->calculate($input)->toArray();

        $this->assertSame(0.0, $result['expected_locality_rate']);
        $this->assertSame(8.0, $result['non_local_markup_percent']);
        $this->assertSame(false, $result['is_local_sale']);
    }

    public function test_fbo_multi_cluster_stock_is_not_treated_as_fully_local_without_fixation(): void
    {
        $calculator = new OzonUnitEconomicsCalculator();
        $input = CalculationInput::fromArray([
            'sku' => 'sku-7',
            'integration_id' => 1,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'price' => 500,
            'cost_price' => 200,
            'length' => 5,
            'width' => 5,
            'height' => 8,
            'sales_7_days' => 80,
            'stock_profile' => [
                ['cluster_name' => 'Казань', 'share_percent' => 60],
                ['cluster_name' => 'Москва, МО и Дальние регионы', 'share_percent' => 40],
            ],
            'clusters_summary' => [
                ['cluster_id' => '154', 'cluster_name' => 'Москва, МО и Дальние регионы', 'orders_percent' => 100],
            ],
        ]);

        $result = $calculator->calculate($input)->toArray();

        $this->assertSame(0.0, $result['expected_locality_rate']);
        $this->assertSame(false, $result['is_local_sale']);
        $this->assertSame(8.0, $result['non_local_markup_percent']);
    }

    public function test_fbo_uses_volume_weight_for_chargeable_tariff_bucket(): void
    {
        $calculator = new OzonUnitEconomicsCalculator();
        $input = CalculationInput::fromArray([
            'sku' => 'sku-8',
            'integration_id' => 1,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'price' => 250,
            'cost_price' => 100,
            'length' => 10,
            'width' => 10,
            'height' => 10,
            'volume_weight' => 0.4,
            'shipping_cluster_name' => 'Воронеж',
            'destination_cluster_name' => 'Воронеж',
        ]);

        $result = $calculator->calculate($input)->toArray();

        $this->assertSame(2.0, $result['chargeable_volume_liters']);
        $this->assertSame(29.48, $result['base_logistics']);
    }
}
