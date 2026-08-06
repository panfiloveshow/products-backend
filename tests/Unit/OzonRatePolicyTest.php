<?php

namespace Tests\Unit;

use App\Domains\Ozon\UnitEconomics\OzonRatePolicy;
use PHPUnit\Framework\TestCase;

/**
 * Политика ставок Ozon — единственное место, где решается, какое значение
 * попадёт в расчёт. Оба движка (синк и UnitEconomicsCacheService) зовут её,
 * поэтому расхождение между ними ловится здесь.
 */
class OzonRatePolicyTest extends TestCase
{
    private const ACTUAL = [
        'acquiring_percent' => 1.08,
        'last_mile_avg' => 10.38,
    ];

    public function test_actual_rates_beat_stored_value_and_default(): void
    {
        $policy = new OzonRatePolicy();

        $this->assertSame(1.08, $policy->acquiringPercent(self::ACTUAL, 4.2));
        $this->assertSame(10.38, $policy->lastMileCost(self::ACTUAL));
    }

    public function test_ozon_storage_is_never_a_shop_average(): void
    {
        $policy = new OzonRatePolicy();

        // Ozon per-SKU хранения не отдаёт: смир по магазину запрещён,
        // даже когда fallback принёс «месячную сумму по остаткам».
        $this->assertSame(0.0, $policy->storageCost(self::ACTUAL, 8740.93));
    }

    public function test_falls_back_when_store_has_no_transactions(): void
    {
        $policy = new OzonRatePolicy();

        // Сохранённое значение важнее дефолта, дефолт — последний рубеж.
        $this->assertSame(4.2, $policy->acquiringPercent([], 4.2));
        $this->assertSame(1.5, $policy->acquiringPercent([], null));
        $this->assertSame(1.5, $policy->acquiringPercent([], 0.0));

        // Нет факта последней мили — калькулятор возьмёт тариф схемы.
        $this->assertNull($policy->lastMileCost([]));
        // Пустые rates = не-Ozon движок: его хранение (WB/YM) живёт своим fallback.
        $this->assertSame(12.0, $policy->storageCost([], 12.0));
    }

    public function test_manual_drr_wins_over_actual_spend(): void
    {
        $policy = new OzonRatePolicy();

        // Продавец задал свой ДРР — уважаем его.
        $this->assertSame(9.0, $policy->drrPercent(9.0, self::ACTUAL));
        // Не задал — средний по магазину НЕ подставляем: одинаковый «рекламный
        // налог» на все SKU снимался отчётом Performance, и маржа росла от синка.
        $this->assertSame(0.0, $policy->drrPercent(null, self::ACTUAL));
        $this->assertSame(0.0, $policy->drrPercent(0.0, self::ACTUAL));
        $this->assertSame(0.0, $policy->drrPercent(null, []));
    }

    public function test_express_uses_its_own_commission_not_rfbs(): void
    {
        $policy = new OzonRatePolicy();
        $commissions = [
            'fbo' => ['percent' => 31],
            'fbs' => ['percent' => 31],
            'rfbs' => ['percent' => 31],
            'express' => ['percent' => 43],
        ];

        $this->assertSame(43.0, $policy->commissionPercentForScheme('EXPRESS', $commissions));
        $this->assertSame(31.0, $policy->commissionPercentForScheme('RFBS', $commissions));
        $this->assertSame(31.0, $policy->commissionPercentForScheme('FBO', $commissions));
        // realFBS и DBS сводятся к rfbs.
        $this->assertSame(31.0, $policy->commissionPercentForScheme('REALFBS', $commissions));
        $this->assertSame(31.0, $policy->commissionPercentForScheme('DBS', $commissions));

        // Ставка из API цен важнее сохранённой в ozon_data.
        $this->assertSame(28.0, $policy->commissionPercentForScheme('FBO', $commissions, ['fbo' => 28.0]));

        // Ozon не отдал express — добираем из rfbs, а не из fbs/fbo.
        $noExpress = ['fbo' => ['percent' => 10], 'fbs' => ['percent' => 20], 'rfbs' => ['percent' => 33]];
        $this->assertSame(33.0, $policy->commissionPercentForScheme('EXPRESS', $noExpress));

        $this->assertNull($policy->commissionPercentForScheme('FBO', []));
    }

    public function test_actual_rates_are_ozon_only(): void
    {
        $policy = new OzonRatePolicy();

        $this->assertSame([], $policy->actualRates('wildberries', 47));
        $this->assertSame([], $policy->actualRates('ozon', null));
    }
}
