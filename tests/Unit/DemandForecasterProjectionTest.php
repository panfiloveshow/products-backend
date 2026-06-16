<?php

namespace Tests\Unit;

use App\Domains\Locality\Recommendation\DemandForecaster;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Тест фикса EWMA-раскладки (этап 0): продажи раскладываются по реальным датам,
 * а не подряд от начала окна. projectDailyDemand приватный → вызов через рефлексию.
 */
class DemandForecasterProjectionTest extends TestCase
{
    private function project(array $dailyCounts, string $windowEnd): array
    {
        $method = new ReflectionMethod(DemandForecaster::class, 'projectDailyDemand');
        $method->setAccessible(true);

        $sales28d = array_sum($dailyCounts);

        return $method->invoke(
            new DemandForecaster(),
            $dailyCounts,
            28,           // windowDays
            0.3,          // alpha
            $sales28d,
            0,            // sales7d (не важен для ewma-ветки)
            $windowEnd,
            3,            // coldMin14
            5             // coldMin28
        );
    }

    public function test_recent_sales_weigh_more_than_old_sales(): void
    {
        // 60 продаж в самый свежий день окна.
        [$recentDemand, , $recentSource] = $this->project(['2026-06-28' => 60], '2026-06-28');
        // 60 продаж 27 дней назад (начало окна).
        [$oldDemand, , ] = $this->project(['2026-06-01' => 60], '2026-06-28');

        $this->assertSame('ewma', $recentSource);
        // Суть фикса: свежие продажи дают существенно больший EWMA-прогноз,
        // чем те же продажи в начале окна (старый баг давал одинаково).
        $this->assertGreaterThan($oldDemand * 10, $recentDemand);
    }

    public function test_gaps_between_sales_are_preserved(): void
    {
        // Продажи равномерно, но с реальными датами — EWMA не схлопывает нули.
        [$spreadDemand, , ] = $this->project([
            '2026-06-02' => 20,
            '2026-06-15' => 20,
            '2026-06-28' => 20,
        ], '2026-06-28');

        // Все 60 в последний день дают выше прогноз, чем те же 60 размазанные.
        [$concentratedDemand, , ] = $this->project(['2026-06-28' => 60], '2026-06-28');

        $this->assertGreaterThan($spreadDemand, $concentratedDemand);
    }
}
