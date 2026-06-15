<?php

namespace App\Console\Commands;

use App\Services\AutoSupplyPlanning\DemandParityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Read-only сравнение дневного спроса между каноническим (DemandForecaster) и
 * legacy (inventory_warehouses) движками — данные для решения о допуске при
 * конвергенции движков (этап 3, docs/TZ_UNIFIED_DEMAND_ENGINE.md).
 *
 * Ничего не меняет. Только считает и печатает расхождение.
 *
 * Примеры:
 *   php artisan demand:parity --integration=76
 *   php artisan demand:parity --integration=76 --window=28 --top=20
 *   php artisan demand:parity --integration=76 --json=storage/app/demand-parity-76.json
 */
class DemandParityCommand extends Command
{
    protected $signature = 'demand:parity
        {--integration= : ID интеграции (обязательно)}
        {--window=28 : Окно истории, дней}
        {--top=20 : Сколько самых расходящихся SKU показать}
        {--json= : Путь для выгрузки полного отчёта (json)}';

    protected $description = 'Read-only: расхождение дневного спроса canonical vs legacy (этап 3, парити)';

    public function handle(DemandParityService $service): int
    {
        $integrationId = (int) $this->option('integration');
        if ($integrationId <= 0) {
            $this->error('Укажите --integration=<ID>.');

            return self::INVALID;
        }

        $window = max(7, (int) $this->option('window'));
        $report = $service->compare($integrationId, $window);
        $summary = $report['summary'];

        $this->info("Парити спроса (integration={$integrationId}, окно {$window} дн.)");
        $this->table(['метрика', 'значение'], [
            ['SKU всего', $summary['skus_total']],
            ['в обоих движках', $summary['skus_both']],
            ['только canonical', $summary['skus_only_canonical']],
            ['только legacy', $summary['skus_only_legacy']],
            ['сравнимо (legacy>0)', $summary['comparable']],
            ['median APE, %', $summary['median_ape'] ?? '—'],
            ['доля APE>25%, %', $summary['pct_over_25'] ?? '—'],
        ]);

        $top = max(0, (int) $this->option('top'));
        if ($top > 0) {
            $divergent = array_values(array_filter($report['rows'], static fn ($r) => $r['abs_pct_error'] !== null));
            usort($divergent, static fn ($a, $b) => $b['abs_pct_error'] <=> $a['abs_pct_error']);
            $divergent = array_slice($divergent, 0, $top);

            if ($divergent !== []) {
                $this->line('');
                $this->info("Топ-{$top} расхождений:");
                $this->table(
                    ['SKU', 'canonical/день', 'legacy/день', 'APE %'],
                    array_map(static fn ($r) => [$r['sku'], $r['canonical_daily'], $r['legacy_daily'], $r['abs_pct_error']], $divergent)
                );
            }
        }

        if ($path = $this->option('json')) {
            File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("Полный отчёт: {$path}");
        }

        return self::SUCCESS;
    }
}
