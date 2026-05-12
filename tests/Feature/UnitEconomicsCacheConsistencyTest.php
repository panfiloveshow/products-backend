<?php

namespace Tests\Feature;

use App\Models\UnitEconomicsCache;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Ловит класс багов «бэк записал в БД рассинхронизированные поля».
 *
 * Если кто-то добавит параллельный путь расчёта effective_logistics или
 * expected_return_cost с кривой формулой, все записанные через него строки
 * провалят этот тест.
 *
 * Проверки идут напрямую на таблицу unit_economics_cache — без мокирования
 * калькулятора, чтобы ловить проблемы в реальном pipeline (orchestrator →
 * calculator → cache).
 */
class UnitEconomicsCacheConsistencyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const TOLERANCE = 0.02; // копейки округлений

    /**
     * @dataProvider fixtureCases
     */
    public function test_effective_logistics_equals_delivery_plus_expected_return(
        float $logistics,
        float $lastMile,
        float $processing,
        float $returnLogistics,
        float $returnProcessing,
        float $redemption,
    ): void {
        $expectedReturn = $redemption >= 100
            ? 0.0
            : ($returnLogistics + $returnProcessing) * (100 - $redemption) / 100;
        $expectedEffective = $logistics + $lastMile + $processing + $expectedReturn;

        $cache = UnitEconomicsCache::create([
            'integration_id' => 999999,
            'product_id' => 1,
            'sku' => 'TEST-' . uniqid(),
            'product_name' => 'test',
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'price' => 1000,
            'logistics_cost' => $logistics,
            'last_mile_cost' => $lastMile,
            'processing_cost' => $processing,
            'return_logistics_cost' => $returnLogistics,
            'return_processing_cost' => $returnProcessing,
            'expected_return_cost' => $expectedReturn,
            'effective_logistics' => $expectedEffective,
            'redemption_rate' => $redemption,
            'redemption_source' => 'test',
            'calculated_at' => now(),
        ]);

        $row = UnitEconomicsCache::find($cache->id);

        $actualDelivery = (float) $row->logistics_cost + (float) $row->last_mile_cost + (float) $row->processing_cost;
        $actualEffective = (float) $row->effective_logistics;

        $this->assertEqualsWithDelta(
            $actualDelivery + (float) $row->expected_return_cost,
            $actualEffective,
            self::TOLERANCE,
            sprintf(
                'effective_logistics (%.2f) должен быть равен delivery (%.2f) + expected_return (%.2f) = %.2f',
                $actualEffective,
                $actualDelivery,
                $row->expected_return_cost,
                $actualDelivery + (float) $row->expected_return_cost,
            ),
        );

        $expectedReturnFromRow = (float) $row->redemption_rate >= 100
            ? 0.0
            : ((float) $row->return_logistics_cost + (float) $row->return_processing_cost)
                * (100 - (float) $row->redemption_rate) / 100;

        $this->assertEqualsWithDelta(
            $expectedReturnFromRow,
            (float) $row->expected_return_cost,
            self::TOLERANCE,
            sprintf(
                'expected_return_cost (%.2f) должен быть (return_log %.2f + return_proc %.2f) × (100 − выкуп %.2f) / 100 = %.2f',
                (float) $row->expected_return_cost,
                (float) $row->return_logistics_cost,
                (float) $row->return_processing_cost,
                (float) $row->redemption_rate,
                $expectedReturnFromRow,
            ),
        );
    }

    public static function fixtureCases(): array
    {
        return [
            'Ozon FBO 100% выкуп — возвраты 0' => [
                'logistics' => 1247.0, 'lastMile' => 25.0, 'processing' => 0.0,
                'returnLogistics' => 0.0, 'returnProcessing' => 0.0,
                'redemption' => 100.0,
            ],
            'Ozon FBO 66.67% выкуп — стандартный кейс' => [
                'logistics' => 449.13, 'lastMile' => 25.0, 'processing' => 0.0,
                'returnLogistics' => 176.75, 'returnProcessing' => 15.0,
                'redemption' => 66.67,
            ],
            'Ozon FBS 50% выкуп — с processing' => [
                'logistics' => 300.0, 'lastMile' => 25.0, 'processing' => 45.0,
                'returnLogistics' => 300.0, 'returnProcessing' => 50.0,
                'redemption' => 50.0,
            ],
            'Ozon FBO 0% выкуп — полные возвраты' => [
                'logistics' => 200.0, 'lastMile' => 25.0, 'processing' => 0.0,
                'returnLogistics' => 200.0, 'returnProcessing' => 15.0,
                'redemption' => 0.0,
            ],
        ];
    }

    /**
     * Запускается на реальной БД: пробегает до 1000 записей и проверяет консистентность.
     * Падает если хотя бы одна строка рассинхронизирована. Служит детектором
     * проникнувших в прод кривых расчётов.
     *
     * @group integration
     */
    public function test_production_cache_rows_are_mathematically_consistent(): void
    {
        if (UnitEconomicsCache::count() === 0) {
            $this->markTestSkipped('unit_economics_cache пуст — нечего проверять (dev/CI без данных).');
        }

        $drifts = [];
        UnitEconomicsCache::query()->orderBy('id')->limit(1000)->chunk(200, function ($rows) use (&$drifts) {
            foreach ($rows as $row) {
                $expectedEffective = (float) $row->logistics_cost
                    + (float) $row->last_mile_cost
                    + (float) $row->processing_cost
                    + (float) $row->expected_return_cost;

                if (abs((float) $row->effective_logistics - $expectedEffective) > self::TOLERANCE) {
                    $drifts[] = sprintf(
                        '[%s] %s %s: effective=%.2f, ожидалось=%.2f (Δ=%.2f)',
                        $row->marketplace,
                        $row->sku,
                        $row->fulfillment_type,
                        $row->effective_logistics,
                        $expectedEffective,
                        (float) $row->effective_logistics - $expectedEffective,
                    );
                }
            }
        });

        $this->assertEmpty(
            $drifts,
            "Найдено " . count($drifts) . " рассинхронизированных строк в unit_economics_cache:\n"
                . implode("\n", array_slice($drifts, 0, 20))
                . (count($drifts) > 20 ? "\n... и ещё " . (count($drifts) - 20) : ''),
        );
    }
}
