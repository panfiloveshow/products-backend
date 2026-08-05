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
        'storage_per_unit' => 25.19,
        'ad_percent' => 5.74,
    ];

    public function test_actual_rates_beat_stored_value_and_default(): void
    {
        $policy = new OzonRatePolicy();

        $this->assertSame(1.08, $policy->acquiringPercent(self::ACTUAL, 4.2));
        $this->assertSame(10.38, $policy->lastMileCost(self::ACTUAL));
        $this->assertSame(25.19, $policy->storageCost(self::ACTUAL, 8740.93));
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
        $this->assertSame(12.0, $policy->storageCost([], 12.0));
    }

    public function test_manual_drr_wins_over_actual_spend(): void
    {
        $policy = new OzonRatePolicy();

        // Продавец задал свой ДРР — уважаем его.
        $this->assertSame(9.0, $policy->drrPercent(9.0, self::ACTUAL));
        // Не задал — берём фактический расход магазина вместо нуля.
        $this->assertSame(5.74, $policy->drrPercent(null, self::ACTUAL));
        $this->assertSame(5.74, $policy->drrPercent(0.0, self::ACTUAL));
        // Нет ни того, ни другого — реклама не выдумывается.
        $this->assertSame(0.0, $policy->drrPercent(null, []));
    }

    public function test_actual_rates_are_ozon_only(): void
    {
        $policy = new OzonRatePolicy();

        $this->assertSame([], $policy->actualRates('wildberries', 47));
        $this->assertSame([], $policy->actualRates('ozon', null));
    }
}
