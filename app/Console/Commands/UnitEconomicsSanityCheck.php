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
        {--max-age-days=3 : Порог протухания источника unit_economics.updated_at (дни)}
        {--fail-on-drift : Вернуть exit-код 1 если найдены рассинхроны/аномалии (для CI/cron)}
        {--log : Писать найденные проблемы в storage/logs/laravel.log через Log::error}';

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

        // Проверка «синк не долил данные»: протухание источника + дефолтные комиссии/
        // пустой индекс у Ozon. Ловит именно тот класс проблем, из-за которого юнитка
        // молча показывала комиссию 15% и «Нет данных» по индексу (провалившийся/
        // недоехавший синк), — матпроверка выше его не видит.
        $anomalies = $this->checkSyncHealth(
            $this->option('integration'),
            $this->option('marketplace'),
            (int) $this->option('max-age-days')
        );

        if ($drifts !== []) {
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
        }

        if ($anomalies !== []) {
            $this->warn('⚠️  Аномалии синка (протухание/дефолты):');
            $this->table(
                ['type', 'integration', 'detail'],
                array_map(fn ($a) => [$a['type'], $a['integration_id'], $a['detail']], $anomalies)
            );
        }

        if ($drifts === [] && $anomalies === []) {
            $this->info('✅ Поля согласованы, синк свежий, дефолтов-массово нет.');
            return self::SUCCESS;
        }

        if ($this->option('log')) {
            Log::error('ue:sanity-check — найдены проблемы в юнит-экономике', [
                'total_drifts' => count($drifts),
                'drift_sample' => array_slice($drifts, 0, 10),
                'anomalies' => $anomalies,
            ]);
        }

        if ($this->option('fail-on-drift')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Здоровье синка: (1) протухание — источник unit_economics.updated_at старше
     * порога у активной интеграции; (2) Ozon-enrichment не долит — почти у всех SKU
     * индекс цены NULL И комиссия дефолтная (15/20/21). Это признак провалившегося
     * фетча цен/комиссий, который матпроверка не видит.
     *
     * @return array<int, array{type:string, integration_id:int|string, detail:string}>
     */
    private function checkSyncHealth(?string $integrationId, ?string $marketplace, int $maxAgeDays): array
    {
        $issues = [];

        // Только активные интеграции — не шумим по спящим/отключённым.
        $activeIds = \App\Models\Integration::query()
            ->where('is_active', true)
            ->when($integrationId, fn ($q) => $q->where('id', $integrationId))
            ->pluck('id')
            ->all();

        if ($activeIds === []) {
            return [];
        }

        // (1) Протухание источника
        $freshness = \App\Models\UnitEconomics::query()
            ->whereIn('integration_id', $activeIds)
            ->when($marketplace, fn ($q) => $q->where('marketplace', $marketplace))
            ->selectRaw('integration_id, marketplace, max(updated_at) as last_updated, count(*) as rows')
            ->groupBy('integration_id', 'marketplace')
            ->get();

        foreach ($freshness as $g) {
            $ageDays = $g->last_updated
                ? (int) \Illuminate\Support\Carbon::parse($g->last_updated)->diffInDays(now())
                : 99999;
            if ($ageDays > $maxAgeDays) {
                $issues[] = [
                    'type' => 'stale',
                    'integration_id' => $g->integration_id,
                    'detail' => "{$g->marketplace}: источник не обновлялся {$ageDays}д (порог {$maxAgeDays}д), строк {$g->rows}",
                ];
            }
        }

        // (2) Ozon: массовые дефолты (индекс NULL + комиссия 15/20/21) у активной интеграции
        if ($marketplace === null || $marketplace === 'ozon') {
            $ozon = UnitEconomicsCache::query()
                ->where('marketplace', 'ozon')
                ->whereIn('integration_id', $activeIds)
                ->selectRaw("integration_id,
                    count(*) as total,
                    count(*) filter (where (marketplace_data->>'current_price_index') is null) as null_idx,
                    count(*) filter (where commission_percent in (15, 20, 21)) as default_comm,
                    count(*) filter (
                        where (marketplace_data->>'marketing_seller_price') ~ '^[0-9.]+$'
                          and (marketplace_data->>'marketing_seller_price')::numeric > 0
                          and price < (marketplace_data->>'marketing_seller_price')::numeric
                    ) as underpriced")
                ->groupBy('integration_id')
                ->havingRaw('count(*) >= 20')
                ->get();

            foreach ($ozon as $g) {
                $nullPct = round($g->null_idx / $g->total * 100);
                $defPct = round($g->default_comm / $g->total * 100);
                if ($nullPct > 90 && $defPct > 90) {
                    $issues[] = [
                        'type' => 'ozon_enrichment',
                        'integration_id' => $g->integration_id,
                        'detail' => "индекс NULL {$nullPct}% + дефолт-комиссия {$defPct}% из {$g->total} SKU → фетч цен/комиссий не долил",
                    ];
                }

                // Инвариант цены: действующая цена не может быть НИЖЕ витрины Ozon
                // (marketing_seller_price). Если ниже — подхватили заниженную action-цену
                // из /v1/actions вместо витринной (баг A65, 2026-07-06). Ноль ложных
                // срабатываний: витринная цена — потолок для нашей действующей.
                if ((int) $g->underpriced > 0) {
                    $issues[] = [
                        'type' => 'price_below_showcase',
                        'integration_id' => $g->integration_id,
                        'detail' => "{$g->underpriced} SKU: действующая цена ниже витрины Ozon (marketing_seller_price) → занижена action-ценой",
                    ];
                }
            }
        }

        return $issues;
    }
}
