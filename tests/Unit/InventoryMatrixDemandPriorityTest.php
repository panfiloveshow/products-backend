<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\InventoryController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Матрица должна предпочитать average_daily_sales (API-синк остатков) над
 * real_avg_daily_sales (ручной CSV-отчёт Ozon), иначе авто-синк не будет
 * питать матрицу — а застрявший CSV перекроет свежие данные.
 */
class InventoryMatrixDemandPriorityTest extends TestCase
{
    private function resolve(array $row): float
    {
        $rc = new ReflectionClass(InventoryController::class);
        // resolveInventoryMatrixDailyDemand чистый (только сам $row) → конструктор не нужен.
        $ctrl = $rc->newInstanceWithoutConstructor();
        $m = $rc->getMethod('resolveInventoryMatrixDailyDemand');
        $m->setAccessible(true);

        return $m->invoke($ctrl, (object) $row);
    }

    public function test_prefers_api_average_over_csv_real(): void
    {
        // wh_row_count=1 → берётся MAX-поле. avg_daily_sales (API) важнее real_avg (CSV).
        $demand = $this->resolve([
            'wh_row_count' => 1,
            'avg_daily_sales' => 5.0,
            'real_avg_daily_sales' => 3.0,
            'effective_daily_sales' => 9.0,
        ]);
        $this->assertSame(5.0, $demand);
    }

    public function test_falls_back_to_csv_when_api_absent(): void
    {
        // Нет API-данных (0) → откатываемся на CSV real_avg_daily_sales.
        $demand = $this->resolve([
            'wh_row_count' => 1,
            'avg_daily_sales' => 0.0,
            'real_avg_daily_sales' => 3.0,
        ]);
        $this->assertSame(3.0, $demand);
    }
}
