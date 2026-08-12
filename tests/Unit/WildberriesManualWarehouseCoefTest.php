<?php

namespace Tests\Unit;

use App\Domains\UnitEconomics\DTO\CalculationInput;
use App\Domains\Wildberries\UnitEconomics\WildberriesUnitEconomicsCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Ручной КС на FBS: тарифный коэффициент WB (boxDeliveryMarketplaceCoefExpr)
 * статичный и на схеме продавца не отражает реальность, поэтому ручное значение
 * должно и показываться, и реально пересчитывать логистику.
 */
class WildberriesManualWarehouseCoefTest extends TestCase
{
    /** Тариф FBS: база 60 ₽ уже включает коэффициент 150%, значит база без КС = 40 ₽. */
    private function input(?float $warehouseCoef, bool $isManual): CalculationInput
    {
        return CalculationInput::fromArray([
            'sku' => 'test',
            'integration_id' => 1,
            'marketplace' => 'wildberries',
            'fulfillment_type' => 'FBS',
            'price' => 1000,
            'length' => 10,
            'width' => 10,
            'height' => 10, // 1 литр
            'weight' => 0.5,
            'commission_rate' => 20,
            'redemption_rate' => 100,
            'warehouse_coefficient' => $warehouseCoef,
            'warehouse_coefficient_is_manual' => $isManual,
            'tariff_breakdown' => [
                'box' => [
                    'delivery_marketplace_base' => 60.0,
                    'delivery_marketplace_liter' => 10.0,
                    'delivery_marketplace_coef_percent' => 150,
                ],
            ],
        ]);
    }

    public function test_auto_coef_comes_from_tariff_and_keeps_logistics_unchanged(): void
    {
        $result = (new WildberriesUnitEconomicsCalculator)->calculate($this->input(1.0, false));

        // Ручного КС нет — берём коэффициент из тарифа, логистика равна тарифной.
        $this->assertSame(150.0, $result->metadata['warehouse_coef_percent']);
        $this->assertSame(60.0, round($result->costs->logistics, 2));
        $this->assertFalse($result->metadata['warehouse_coef_is_manual']);
    }

    public function test_manual_coef_overrides_tariff_and_recalculates_logistics(): void
    {
        $result = (new WildberriesUnitEconomicsCalculator)->calculate($this->input(2.0, true));

        // База без КС = 60 / 1.5 = 40 ₽; ручной КС 200% → 80 ₽.
        $this->assertSame(200.0, $result->metadata['warehouse_coef_percent']);
        $this->assertSame(80.0, round($result->costs->logistics, 2));
        $this->assertTrue($result->metadata['warehouse_coef_is_manual']);
        $this->assertSame(40.0, $result->metadata['base_logistics']);
    }

    /** Платная приёмка: удерживается только на складской схеме и уменьшает прибыль. */
    public function test_acceptance_cost_counts_on_fbo_and_is_dropped_on_fbs(): void
    {
        $calculator = new WildberriesUnitEconomicsCalculator;
        $base = [
            'sku' => 'test',
            'integration_id' => 1,
            'marketplace' => 'wildberries',
            'price' => 1000,
            'length' => 10,
            'width' => 10,
            'height' => 10,
            'weight' => 0.5,
            'commission_rate' => 20,
            'redemption_rate' => 100,
            'acceptance_cost' => 12.5,
        ];

        $fbo = $calculator->calculate(CalculationInput::fromArray($base + ['fulfillment_type' => 'FBO']));
        $fbs = $calculator->calculate(CalculationInput::fromArray($base + ['fulfillment_type' => 'FBS']));
        $fboNoAcceptance = $calculator->calculate(
            CalculationInput::fromArray(array_merge($base, ['fulfillment_type' => 'FBO', 'acceptance_cost' => 0]))
        );

        $this->assertSame(12.5, $fbo->metadata['acceptance_cost']);
        $this->assertSame(0.0, $fbs->metadata['acceptance_cost']);
        // Приёмка вычитается из суммы к перечислению, а не просто показывается.
        $this->assertSame(
            12.5,
            round($fboNoAcceptance->metadata['to_settlement_account'] - $fbo->metadata['to_settlement_account'], 2)
        );
    }
}
