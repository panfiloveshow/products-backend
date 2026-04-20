<?php

namespace Tests\Unit\Locality;

use App\Domains\Locality\Calculation\DaysOfCoverCalculator;
use PHPUnit\Framework\TestCase;

class DaysOfCoverCalculatorTest extends TestCase
{
    public function test_zero_stock_returns_zero(): void
    {
        $this->assertSame(0.0, (new DaysOfCoverCalculator())->compute(0, 10));
    }

    public function test_zero_demand_protected_by_floor(): void
    {
        $calc = new DaysOfCoverCalculator();
        // stock=10, demand=0 → 10/0.1 = 100 дней (пессимистичная верхняя граница)
        $this->assertSame(100.0, $calc->compute(10, 0));
    }

    public function test_normal_calculation(): void
    {
        $calc = new DaysOfCoverCalculator();
        // stock=280, demand=10/day → 28 дней
        $this->assertSame(28.0, $calc->compute(280, 10));
    }

    public function test_daily_demand_from_orders(): void
    {
        $calc = new DaysOfCoverCalculator();
        $this->assertSame(0.0, $calc->dailyDemand(0, 28));
        $this->assertSame(5.0, $calc->dailyDemand(140, 28));
    }
}
