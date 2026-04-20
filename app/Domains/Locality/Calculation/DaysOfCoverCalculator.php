<?php

namespace App\Domains\Locality\Calculation;

class DaysOfCoverCalculator
{
    private const MIN_DAILY_DEMAND = 0.1;

    public function compute(int $stockQty, float $dailyDemand): ?float
    {
        if ($stockQty <= 0) {
            return 0.0;
        }

        $protected = max($dailyDemand, self::MIN_DAILY_DEMAND);
        return round($stockQty / $protected, 2);
    }

    public function dailyDemand(int $ordersCount, int $periodDays): float
    {
        if ($periodDays <= 0) {
            return 0.0;
        }

        return round($ordersCount / $periodDays, 3);
    }
}
