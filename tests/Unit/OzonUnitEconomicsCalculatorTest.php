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
            // Наценка отменена Ozon с 09.07.2026 — механика проверяется на дате,
            // когда она действовала (исторический заказ / активная фиксация).
            'order_date' => '2026-06-20',
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
            'order_date' => '2026-06-20',
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

    public function test_fbo_profile_recalculates_stale_effective_markup_from_current_tariff_date(): void
    {
        $calculator = new OzonUnitEconomicsCalculator();
        $input = CalculationInput::fromArray([
            'sku' => 'sku-4-stale-profile',
            'integration_id' => 1,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'price' => 500,
            'cost_price' => 200,
            'length' => 5,
            'width' => 5,
            'height' => 8,
            'sales_7_days' => 80,
            'tariff_effective_from' => '2026-06-16',
            'order_date' => '2026-06-20',
            'stock_profile' => [
                ['cluster_name' => 'Казань', 'share_percent' => 100],
            ],
            'clusters_summary' => [
                [
                    'cluster_id' => 'rostov',
                    'cluster_name' => 'Ростов',
                    'orders_percent' => 100,
                    'effective_markup_percent' => 0.0,
                ],
            ],
        ]);

        $result = $calculator->calculate($input)->toArray();

        $this->assertSame(8.0, $result['non_local_markup_percent']);
        $this->assertSame(40.0, $result['non_local_markup_amount']);
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

    public function test_no_sales_real_fbs_schemes_use_own_delivery_as_return_logistics_fallback(): void
    {
        $calculator = new OzonUnitEconomicsCalculator();

        foreach (['RFBS', 'EXPRESS'] as $scheme) {
            $input = CalculationInput::fromArray([
                'sku' => 'sku-no-sales-' . strtolower($scheme),
                'integration_id' => 1,
                'marketplace' => 'ozon',
                'fulfillment_type' => $scheme,
                'price' => 1000,
                'cost_price' => 200,
                'length' => 20,
                'width' => 20,
                'height' => 20,
                'own_delivery_cost' => 250,
                'redemption_rate' => 0,
                'redemption_source' => 'no_sales_28d',
                'orders_count' => 0,
            ]);

            $result = $calculator->calculate($input)->toArray();

            $this->assertSame(250.0, $result['return_logistics'], $scheme);
            $this->assertSame(250.0, $result['expected_return_cost'], $scheme);
            $this->assertSame(
                $result['costs']['logistics'] + $result['expected_return_cost'],
                $result['effective_logistics'],
                $scheme
            );
        }
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

    public function test_full_buyout_with_real_returns_still_adds_return_to_logistics(): void
    {
        // Регресс: постинги показывают 100% выкупа (все заказы delivered),
        // но по /v1/returns/* есть реальные возвраты. Раньше expected_return_cost
        // занулялся (rate >= 100) и возвраты не попадали в эффективную логистику.
        $calculator = new OzonUnitEconomicsCalculator();
        $input = CalculationInput::fromArray([
            'sku' => 'sku-full-buyout-returns',
            'integration_id' => 1,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBS',
            'price' => 1000,
            'cost_price' => 200,
            'length' => 20,
            'width' => 20,
            'height' => 20,
            'redemption_rate' => 100,
            'redemption_source' => 'postings_28d',
            'orders_count' => 10,
            'returns_count' => 2,
            'delivered_count' => 10,
            'cancelled_count' => 0,
        ]);

        $result = $calculator->calculate($input)->toArray();

        // 2 возврата из 10 заказов → доля 0.2 → (return_logistics + return_processing) * 0.2.
        $expected = round(($result['return_logistics'] + $result['return_processing']) * 0.2, 2);
        $this->assertGreaterThan(0.0, $result['expected_return_cost']);
        $this->assertSame($expected, $result['expected_return_cost']);
        $this->assertSame(
            $result['costs']['logistics'] + $result['last_mile'] + $result['processing_fee'] + $result['expected_return_cost'],
            $result['effective_logistics']
        );
    }

    public function test_partial_buyout_and_real_returns_are_summed(): void
    {
        // 80% выкупа (20% не доехало) + 1 пост-доставочный возврат из 10 заказов (10%)
        // → суммарная доля возвратной логистики 0.3.
        $calculator = new OzonUnitEconomicsCalculator();
        $input = CalculationInput::fromArray([
            'sku' => 'sku-partial-and-returns',
            'integration_id' => 1,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBS',
            'price' => 1000,
            'cost_price' => 200,
            'length' => 20,
            'width' => 20,
            'height' => 20,
            'redemption_rate' => 80,
            'redemption_source' => 'postings_28d',
            'orders_count' => 10,
            'returns_count' => 1,
            'delivered_count' => 8,
            'cancelled_count' => 2,
        ]);

        $result = $calculator->calculate($input)->toArray();

        $expected = round(($result['return_logistics'] + $result['return_processing']) * 0.3, 2);
        $this->assertSame($expected, $result['expected_return_cost']);
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
            'order_date' => '2026-06-20',
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
            'order_date' => '2026-06-20',
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

    public function test_fbo_uses_enriched_cluster_locality_with_multi_cluster_stock(): void
    {
        $calculator = new OzonUnitEconomicsCalculator();
        $input = CalculationInput::fromArray([
            'sku' => '9217/newdarkgreen',
            'integration_id' => 55,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'price' => 14500,
            'cost_price' => 5000,
            'length' => 10,
            'width' => 10,
            'height' => 10,
            'sales_7_days' => 344,
            'stock_profile' => [
                ['cluster_name' => 'Казань', 'share_percent' => 60],
                ['cluster_name' => 'Москва, МО и Дальние регионы', 'share_percent' => 40],
            ],
            'clusters_summary' => [[
                'cluster_id' => '154',
                'cluster_name' => 'Москва, МО и Дальние регионы',
                'orders_count' => 1,
                'orders_percent' => 100,
                'is_local_cluster' => true,
                'effective_markup_percent' => 0,
            ]],
            // Более полный sales_profile должен быть выбран без потери locality-флагов.
            'sales_profile' => [[
                'cluster_id' => '154',
                'cluster_name' => 'Москва, МО и Дальние регионы',
                'sales_30_days' => 3,
                'sales_share_percent' => 100,
                'is_local_cluster' => true,
                'effective_markup_percent' => 0,
            ]],
        ]);

        $result = $calculator->calculate($input)->toArray();

        $this->assertSame(100.0, $result['expected_locality_rate']);
        $this->assertSame(true, $result['is_local_sale']);
        $this->assertSame(0.0, $result['non_local_markup_percent']);
        $this->assertSame($result['base_logistics'], $result['costs']['logistics']);
    }

    public function test_unknown_seller_sales_do_not_enable_non_local_markup(): void
    {
        $calculator = new OzonUnitEconomicsCalculator();
        $input = CalculationInput::fromArray([
            'sku' => 'sku-unknown-sales',
            'integration_id' => 1,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'price' => 500,
            'cost_price' => 200,
            'length' => 5,
            'width' => 5,
            'height' => 8,
            // sales_7_days неизвестен: порог Ozon (50+ заказов) не подтверждён,
            // значит наценку не применяем — иначе один артикул показывал бы
            // разные проценты у SKU с данными и без.
            'order_date' => '2026-06-20',
            'stock_profile' => [
                ['cluster_name' => 'Казань', 'share_percent' => 100],
            ],
            'clusters_summary' => [
                ['cluster_id' => '154', 'cluster_name' => 'Москва, МО и Дальние регионы', 'orders_percent' => 100],
            ],
        ]);

        $result = $calculator->calculate($input)->toArray();

        $this->assertSame(false, $result['is_local_sale']);
        $this->assertSame(0.0, $result['non_local_markup_percent']);
    }

    public function test_non_local_markup_is_not_applied_after_ozon_cancelled_it(): void
    {
        $calculator = new OzonUnitEconomicsCalculator();
        $input = CalculationInput::fromArray([
            'sku' => 'sku-markup-cancelled',
            'integration_id' => 1,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'price' => 1000,
            'cost_price' => 300,
            'length' => 10,
            'width' => 10,
            'height' => 10,
            'route_key' => 'cluster_far',
            'order_date' => '2026-07-09',
        ]);

        $result = $calculator->calculate($input)->toArray();

        $this->assertSame(0.0, $result['non_local_markup_percent']);
        $this->assertSame(0.0, $result['non_local_markup_amount']);
        $this->assertSame($result['base_logistics'], $result['costs']['logistics']);
    }

    public function test_stale_tariff_effective_from_does_not_revive_cancelled_markup(): void
    {
        $calculator = new OzonUnitEconomicsCalculator();
        $input = CalculationInput::fromArray([
            'sku' => 'sku-stale-effective-from',
            'integration_id' => 1,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'price' => 500,
            'cost_price' => 200,
            'length' => 5,
            'width' => 5,
            'height' => 8,
            'sales_7_days' => 80,
            // Дата из старого кэша: раньше по ней считались уже отменённые правила.
            'tariff_effective_from' => '2026-06-16',
            'stock_profile' => [
                ['cluster_name' => 'Казань', 'share_percent' => 100],
            ],
            'clusters_summary' => [
                ['cluster_id' => '154', 'cluster_name' => 'Москва, МО и Дальние регионы', 'orders_percent' => 100],
            ],
        ]);

        $result = $calculator->calculate($input)->toArray();

        $this->assertSame(0.0, $result['non_local_markup_percent']);
        $this->assertSame(0.0, $result['weighted_non_local_markup_percent']);
    }

    public function test_active_supply_fixation_keeps_markup_rules_of_its_base_date(): void
    {
        $calculator = new OzonUnitEconomicsCalculator();
        $base = [
            'sku' => 'sku-fixation',
            'integration_id' => 1,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'price' => 500,
            'cost_price' => 200,
            'length' => 5,
            'width' => 5,
            'height' => 8,
            'sales_7_days' => 80,
            'fixation_applied' => true,
            'fixation_base_date' => '2026-06-20',
            'stock_profile' => [
                ['cluster_name' => 'Казань', 'share_percent' => 100],
            ],
            'clusters_summary' => [
                ['cluster_id' => '154', 'cluster_name' => 'Москва, МО и Дальние регионы', 'orders_percent' => 100],
            ],
        ];

        $active = $calculator->calculate(CalculationInput::fromArray(
            $base + ['fixed_until' => date('Y-m-d', strtotime('+10 days'))]
        ))->toArray();
        $expired = $calculator->calculate(CalculationInput::fromArray(
            $base + ['fixed_until' => date('Y-m-d', strtotime('-1 day'))]
        ))->toArray();

        $this->assertSame(8.0, $active['non_local_markup_percent']);
        $this->assertSame(0.0, $expired['non_local_markup_percent']);
    }

    public function test_local_fbo_sale_gets_commission_discount(): void
    {
        $calculator = new OzonUnitEconomicsCalculator();
        $input = CalculationInput::fromArray([
            'sku' => 'sku-locality-discount',
            'integration_id' => 1,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'price' => 500,
            'cost_price' => 200,
            'length' => 5,
            'width' => 5,
            'height' => 8,
            'sales_7_days' => 80,
            'order_date' => '2026-08-30',
            'stock_profile' => [
                ['cluster_name' => 'Казань', 'share_percent' => 100],
            ],
            'clusters_summary' => [[
                'cluster_id' => 'kazan',
                'cluster_name' => 'Казань',
                'orders_percent' => 100,
                'is_local_cluster' => true,
            ]],
        ]);

        $result = $calculator->calculate($input)->toArray();

        $this->assertSame(true, $result['is_local_sale']);
        $this->assertSame(3.0, $result['locality_commission_discount_pp']);
        $this->assertSame(15.0, $result['locality_commission_discount_amount']);
        $this->assertSame(
            round($result['commission_rate_base'] - 3.0, 2),
            $result['commission_percent']
        );
    }

    public function test_no_locality_commission_discount_before_august_30(): void
    {
        $calculator = new OzonUnitEconomicsCalculator();
        $input = CalculationInput::fromArray([
            'sku' => 'sku-locality-before-start',
            'integration_id' => 1,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'price' => 500,
            'cost_price' => 200,
            'length' => 5,
            'width' => 5,
            'height' => 8,
            'sales_7_days' => 80,
            // Повышение комиссий и скидку 6 п.п. с 31.07 Ozon отменил 27.07.
            'order_date' => '2026-08-29',
            'stock_profile' => [
                ['cluster_name' => 'Казань', 'share_percent' => 100],
            ],
            'clusters_summary' => [[
                'cluster_id' => 'kazan',
                'cluster_name' => 'Казань',
                'orders_percent' => 100,
                'is_local_cluster' => true,
            ]],
        ]);

        $result = $calculator->calculate($input)->toArray();

        $this->assertSame(true, $result['is_local_sale']);
        $this->assertSame(0.0, $result['locality_commission_discount_pp']);
        $this->assertSame($result['commission_rate_base'], $result['commission_percent']);
    }

    public function test_factual_commission_rate_is_not_discounted_twice(): void
    {
        $calculator = new OzonUnitEconomicsCalculator();
        $base = [
            'sku' => 'sku-factual-commission',
            'integration_id' => 1,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'price' => 500,
            'cost_price' => 200,
            'length' => 5,
            'width' => 5,
            'height' => 8,
            'sales_7_days' => 80,
            'order_date' => '2026-08-30',
            // Ставка посчитана из отчёта о реализации — скидка Ozon уже внутри.
            'commission_rate' => 7.0,
            'stock_profile' => [
                ['cluster_name' => 'Казань', 'share_percent' => 100],
            ],
            'clusters_summary' => [[
                'cluster_id' => 'kazan',
                'cluster_name' => 'Казань',
                'orders_percent' => 100,
                'is_local_cluster' => true,
            ]],
        ];

        $factual = $calculator->calculate(CalculationInput::fromArray(
            $base + ['commission_rate_is_effective' => true]
        ))->toArray();
        $tariff = $calculator->calculate(CalculationInput::fromArray($base))->toArray();

        $this->assertSame(0.0, $factual['locality_commission_discount_pp']);
        $this->assertSame(7.0, $factual['commission_percent']);

        // Та же ставка, но заявленная как тарифная, скидку получает.
        $this->assertSame(3.0, $tariff['locality_commission_discount_pp']);
        $this->assertSame(4.0, $tariff['commission_percent']);
    }

    public function test_seller_below_fifty_units_gets_discount_on_non_local_orders_too(): void
    {
        $calculator = new OzonUnitEconomicsCalculator();
        $base = [
            'sku' => 'sku-low-volume',
            'integration_id' => 1,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'price' => 500,
            'cost_price' => 200,
            'length' => 5,
            'width' => 5,
            'height' => 8,
            'order_date' => '2026-08-30',
            'stock_profile' => [
                ['cluster_name' => 'Казань', 'share_percent' => 100],
            ],
            'clusters_summary' => [[
                'cluster_id' => 'kazan-far',
                'cluster_name' => 'Новосибирск',
                'orders_percent' => 100,
                'is_local_cluster' => false,
            ]],
        ];

        // Меньше 50 единиц за 7 дней — Ozon даёт скидку и на нелокальные заказы.
        $lowVolume = $calculator->calculate(CalculationInput::fromArray($base + ['sales_7_days' => 10]))->toArray();
        // Порог пройден — скидка только за локальность, а заказы нелокальные.
        $highVolume = $calculator->calculate(CalculationInput::fromArray($base + ['sales_7_days' => 80]))->toArray();

        $this->assertSame(3.0, $lowVolume['locality_commission_discount_pp']);
        $this->assertSame(0.0, $highVolume['locality_commission_discount_pp']);
    }

    public function test_exposes_upcoming_commission_rate_from_official_table(): void
    {
        $calculator = new OzonUnitEconomicsCalculator();
        $input = CalculationInput::fromArray([
            'sku' => 'sku-upcoming-commission',
            'integration_id' => 1,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'price' => 1500,
            'cost_price' => 200,
            'length' => 5,
            'width' => 5,
            'height' => 8,
            'category_id' => 'Автозвук',
            'commission_rate' => 12.0,
            'order_date' => '2026-08-01',
        ]);

        $result = $calculator->calculate($input)->toArray();

        // Сегодняшняя ставка — из интеграции, будущая — из таблицы с 28.08.
        $this->assertSame(12.0, $result['commission_percent']);
        $this->assertSame(50.0, $result['commission_rate_from_2026_08_28']);
    }

    public function test_locality_commission_discount_is_weighted_by_local_orders_share(): void
    {
        $calculator = new OzonUnitEconomicsCalculator();
        $input = CalculationInput::fromArray([
            'sku' => 'sku-locality-partial',
            'integration_id' => 1,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'price' => 500,
            'cost_price' => 200,
            'length' => 5,
            'width' => 5,
            'height' => 8,
            'sales_7_days' => 80,
            'order_date' => '2026-08-30',
            'stock_profile' => [
                ['cluster_name' => 'Казань', 'share_percent' => 100],
            ],
            'clusters_summary' => [
                [
                    'cluster_id' => 'kazan',
                    'cluster_name' => 'Казань',
                    'orders_percent' => 50,
                    'is_local_cluster' => true,
                ],
                [
                    'cluster_id' => '154',
                    'cluster_name' => 'Москва, МО и Дальние регионы',
                    'orders_percent' => 50,
                    'is_local_cluster' => false,
                ],
            ],
        ]);

        $result = $calculator->calculate($input)->toArray();

        $this->assertSame(50.0, $result['expected_locality_rate']);
        // Скидку получают только локальные заказы: 3 п.п. × 50% = 1.5 п.п.
        $this->assertSame(1.5, $result['locality_commission_discount_pp']);
    }

    public function test_excluded_clusters_get_no_locality_discount(): void
    {
        $calculator = new OzonUnitEconomicsCalculator();
        $base = [
            'integration_id' => 1,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'price' => 500,
            'cost_price' => 200,
            'length' => 5,
            'width' => 5,
            'height' => 8,
            'sales_7_days' => 80,
            'order_date' => '2026-08-30',
        ];
        $local = static fn (string $cluster): array => [
            'stock_profile' => [
                ['cluster_name' => $cluster, 'share_percent' => 100],
            ],
            'clusters_summary' => [[
                'cluster_id' => $cluster,
                'cluster_name' => $cluster,
                'orders_percent' => 100,
                'is_local_cluster' => true,
            ]],
        ];

        $kazan = $calculator->calculate(CalculationInput::fromArray(
            $base + ['sku' => 'sku-locality-kazan'] + $local('Казань')
        ))->toArray();
        $moscow = $calculator->calculate(CalculationInput::fromArray(
            $base + ['sku' => 'sku-locality-moscow'] + $local('Москва, МО и Дальние регионы')
        ))->toArray();

        // Ozon: у «Москва, МО и Дальние регионы», «СПб и СЗО» и Беларуси скидка 0%.
        $this->assertSame(3.0, $kazan['locality_commission_discount_pp']);
        $this->assertSame(0.0, $moscow['locality_commission_discount_pp']);
        $this->assertSame($moscow['commission_rate_base'], $moscow['commission_percent']);
    }

    public function test_fbs_local_sale_has_no_locality_commission_discount(): void
    {
        $calculator = new OzonUnitEconomicsCalculator();
        $input = CalculationInput::fromArray([
            'sku' => 'sku-locality-fbs',
            'integration_id' => 1,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBS',
            'price' => 500,
            'cost_price' => 200,
            'length' => 5,
            'width' => 5,
            'height' => 8,
            'sales_7_days' => 80,
            'order_date' => '2026-08-01',
            'stock_profile' => [
                ['cluster_name' => 'Казань', 'share_percent' => 100],
            ],
            'clusters_summary' => [[
                'cluster_id' => 'kazan',
                'cluster_name' => 'Казань',
                'orders_percent' => 100,
                'is_local_cluster' => true,
            ]],
        ]);

        $result = $calculator->calculate($input)->toArray();

        $this->assertSame(0.0, $result['locality_commission_discount_pp']);
        $this->assertSame($result['commission_rate_base'], $result['commission_percent']);
    }

    public function test_logistics_metadata_exposes_estimate_reserve_and_basis(): void
    {
        $calculator = new OzonUnitEconomicsCalculator();

        $withoutClusters = $calculator->calculate(CalculationInput::fromArray([
            'sku' => 'sku-universal-estimate',
            'integration_id' => 1,
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'price' => 500,
            'cost_price' => 200,
            'length' => 5,
            'width' => 5,
            'height' => 8,
            'redemption_source' => 'no_sales_28d',
            'orders_count' => 0,
        ]))->toArray();

        $withProfile = $calculator->calculate(CalculationInput::fromArray([
            'sku' => 'sku-weighted-basis',
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
                ['cluster_name' => 'Казань', 'share_percent' => 100],
            ],
            'clusters_summary' => [
                ['cluster_id' => '154', 'cluster_name' => 'Москва, МО и Дальние регионы', 'orders_percent' => 100],
            ],
        ]))->toArray();

        // Кластеры неизвестны — в цифре сидит консервативный запас 50%.
        $this->assertSame('universal_estimate', $withoutClusters['logistics_basis']);
        $this->assertSame(50.0, $withoutClusters['logistics_estimate_markup_percent']);

        // Есть профиль спроса — это средневзвешенная по кластерам, а не тариф маршрута.
        $this->assertSame('weighted_clusters', $withProfile['logistics_basis']);
        $this->assertSame(0.0, $withProfile['logistics_estimate_markup_percent']);
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
