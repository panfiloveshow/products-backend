<?php

namespace Tests\Unit;

use App\Domains\UnitEconomics\DTO\CalculationInput;
use App\Domains\UnitEconomics\ReturnEconomics;
use App\Domains\Wildberries\UnitEconomics\WildberriesUnitEconomicsCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Возвраты WB: невыкуп стоит ОБА плеча (WB списывает логистику за каждую
 * поездку к клиенту + обратную за возврат), а потери считаются на единицу
 * выкупа, а не на заказ — как у Ozon.
 */
class WildberriesReturnCostTest extends TestCase
{
    private function input(float $redemptionRate): CalculationInput
    {
        return CalculationInput::fromArray([
            'sku' => 'test-returns',
            'integration_id' => 1,
            'marketplace' => 'wildberries',
            'fulfillment_type' => 'FBS',
            'price' => 1000,
            'length' => 10,
            'width' => 10,
            'height' => 10,
            'weight' => 0.5,
            'commission_rate' => 20,
            'redemption_rate' => $redemptionRate,
            'tariff_breakdown' => [
                'box' => [
                    'delivery_marketplace_base' => 60.0,
                    'delivery_marketplace_liter' => 10.0,
                    'delivery_marketplace_coef_percent' => 150,
                ],
            ],
        ]);
    }

    public function test_full_redemption_has_no_return_cost(): void
    {
        $result = (new WildberriesUnitEconomicsCalculator)->calculate($this->input(100));

        $this->assertSame(0.0, round($result->costs->expectedReturnCost, 2));
        $this->assertSame(
            round($result->costs->logistics, 2),
            $result->metadata['effective_logistics']
        );
    }

    public function test_partial_redemption_charges_both_legs_per_sold_unit(): void
    {
        $result = (new WildberriesUnitEconomicsCalculator)->calculate($this->input(70));
        $m = $result->metadata;

        // 30% невыкупов на 70% продаж → 0.3/0.7 невыкупа на проданную штуку,
        // каждый стоит прямое плечо (логистика) + обратное (обр. логистика).
        $expected = round(($result->costs->logistics + $m['return_logistics']) * (0.3 / 0.7), 2);
        $this->assertGreaterThan(0.0, $m['expected_return_cost']);
        $this->assertSame($expected, $m['expected_return_cost']);
        $this->assertSame(
            round($result->costs->logistics + $m['expected_return_cost'], 2),
            round($m['effective_logistics'], 2)
        );
    }

    public function test_fbs_return_uses_pvz_tariff_not_fbo_magistral(): void
    {
        // FBS-возврат приезжает продавцу в ПВЗ: 25 ₽ первый литр + 4 ₽/л далее.
        $result = (new WildberriesUnitEconomicsCalculator)->calculate($this->input(100));

        $this->assertSame(25.0, $result->metadata['return_logistics']);
    }

    public function test_fbs_return_tariff_charges_extra_liters(): void
    {
        $input = CalculationInput::fromArray([
            'sku' => 'test-returns-5l',
            'integration_id' => 1,
            'marketplace' => 'wildberries',
            'fulfillment_type' => 'FBS',
            'price' => 1000,
            'length' => 10,
            'width' => 25,
            'height' => 20, // 5 литров
            'weight' => 0.5,
            'commission_rate' => 20,
            'redemption_rate' => 100,
        ]);

        $result = (new WildberriesUnitEconomicsCalculator)->calculate($input);

        // 25 + 4 × 4 доп. литра = 41 ₽.
        $this->assertSame(41.0, $result->metadata['return_logistics']);
    }

    public function test_fraction_is_capped_at_three_returns_per_sale(): void
    {
        $result = (new WildberriesUnitEconomicsCalculator)->calculate($this->input(10));
        $m = $result->metadata;

        // Выкуп 10% → 0.9/0.1 = 9 невыкупов на продажу, но кап = 3 (ReturnEconomics).
        $this->assertSame(3.0, ReturnEconomics::fractionPerSoldUnit(0.9));
        $expected = round(($result->costs->logistics + $m['return_logistics']) * 3.0, 2);
        $this->assertSame($expected, $m['expected_return_cost']);
    }
}
