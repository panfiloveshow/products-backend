<?php

namespace App\Console\Commands;

use App\Models\UnitEconomicsCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Проверка математической согласованности unit_economics_cache.
 *
 * Ловит баги где effective_logistics в БД не равен сумме компонентов
 * (например, если какой-то джоб/путь записал поле мимо актуальной формулы).
 *
 * Запуск:
 *   php artisan ue:sanity-check                           # по всем
 *   php artisan ue:sanity-check --integration=17          # по интеграции
 *   php artisan ue:sanity-check --marketplace=ozon --tolerance=0.05
 *   php artisan ue:sanity-check --fail-on-drift           # exit 1 при рассинхронах (для cron)
 *
 * Крон раз в день — fail-on-drift + алерт в Sentry/лог через Log::error.
 */
class UnitEconomicsSanityCheck extends Command
{
    protected $signature = 'ue:sanity-check
        {--integration= : Ограничить интеграцией}
        {--marketplace= : Ограничить маркетплейсом (ozon, wildberries, yandex_market)}
        {--tolerance=0.02 : Допуск округлений в рублях}
        {--limit=5000 : Сколько строк проверить}
        {--fail-on-drift : Вернуть exit-код 1 если найдены рассинхроны (для CI/cron)}
        {--log : Писать найденные рассинхроны в storage/logs/laravel.log через Log::error}';

    protected $description = 'Проверяет математическую согласованность полей в unit_economics_cache (effective_logistics, expected_return_cost)';

    public function handle(): int
    {
        $tolerance = (float) $this->option('tolerance');
        $limit = (int) $this->option('limit');

        $query = UnitEconomicsCache::query();
        if ($integrationId = $this->option('integration')) {
            $query->where('integration_id', (int) $integrationId);
        }
        if ($marketplace = $this->option('marketplace')) {
            $query->where('marketplace', $marketplace);
        }

        $total = $query->count();
        $this->info("Проверяю unit_economics_cache: всего записей {$total}, лимит {$limit}, допуск ±{$tolerance}₽");

        $drifts = [];
        $checked = 0;

        $query->orderBy('id')->limit($limit)->chunk(500, function ($rows) use (&$drifts, &$checked, $tolerance) {
            foreach ($rows as $row) {
                $checked++;

                $expectedDelivery = (float) $row->logistics_cost + (float) $row->last_mile_cost + (float) $row->processing_cost;
                $expectedEffective = $expectedDelivery + (float) $row->expected_return_cost;

                $actualEffective = (float) $row->effective_logistics;
                $effectiveDrift = abs($actualEffective - $expectedEffective);

                $redemption = (float) ($row->redemption_rate ?? 100);
                $returnBase = (float) $row->return_logistics_cost + (float) $row->return_processing_cost;
                $expectedReturnFromFormula = $redemption >= 100 ? 0.0 : $returnBase * (100 - $redemption) / 100;
                $returnDrift = abs((float) $row->expected_return_cost - $expectedReturnFromFormula);

                if ($effectiveDrift > $tolerance || $returnDrift > $tolerance) {
                    $drifts[] = [
                        'integration_id' => $row->integration_id,
                        'sku' => $row->sku,
                        'scheme' => $row->fulfillment_type,
                        'marketplace' => $row->marketplace,
                        'effective_actual' => round($actualEffective, 2),
                        'effective_expected' => round($expectedEffective, 2),
                        'effective_drift' => round($effectiveDrift, 2),
                        'return_actual' => round((float) $row->expected_return_cost, 2),
                        'return_expected' => round($expectedReturnFromFormula, 2),
                        'return_drift' => round($returnDrift, 2),
                        'calc_at' => optional($row->calculated_at)?->toDateTimeString(),
                    ];
                }
            }
        });

        $this->newLine();
        $this->info("Проверено: {$checked}. Найдено рассинхронов: " . count($drifts));

        if (empty($drifts)) {
            $this->info('✅ Все поля математически согласованы.');
            return self::SUCCESS;
        }

        $this->warn('⚠️  Найдены рассинхроны:');
        $this->table(
            ['integration', 'sku', 'scheme', 'eff (факт)', 'eff (ожид)', 'Δeff', 'ret (факт)', 'ret (ожид)', 'Δret', 'calc_at'],
            array_map(fn ($d) => [
                $d['integration_id'],
                $d['sku'],
                $d['scheme'],
                $d['effective_actual'],
                $d['effective_expected'],
                $d['effective_drift'],
                $d['return_actual'],
                $d['return_expected'],
                $d['return_drift'],
                $d['calc_at'],
            ], array_slice($drifts, 0, 30))
        );

        if (count($drifts) > 30) {
            $this->line('... и ещё ' . (count($drifts) - 30) . ' строк (показаны первые 30)');
        }

        if ($this->option('log')) {
            Log::error('ue:sanity-check — найдены рассинхроны в unit_economics_cache', [
                'total_drifts' => count($drifts),
                'sample' => array_slice($drifts, 0, 10),
            ]);
        }

        if ($this->option('fail-on-drift')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
