<?php

namespace App\Domains\Locality\Console;

use App\Models\LocalityMetricDaily;
use App\Models\OzonOrderUnitEconomics;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Healthcheck инвариантов locality-пайплайна.
 * Предназначен для cron/alerting: exit != 0, если что-то не так.
 *
 * Проверяет:
 *  - дубликаты по бизнес-ключу в ozon_order_unit_economics (после миграции
 *    2026_04_22_140000 должен быть UNIQUE constraint; это guard от drop'а).
 *  - свежесть snapshot'ов в locality_metrics_daily (max updated_at < 36h).
 *  - расхождение orders_count в snapshot'е с реальным COUNT в
 *    ozon_order_unit_economics для того же окна (допуск 10%).
 */
class LocalityHealthCheckCommand extends Command
{
    protected $signature = 'locality:health-check
                            {--integration= : Integration ID (по умолчанию все active Ozon)}
                            {--freshness-hours=36 : порог устаревания snapshot}
                            {--count-tolerance=0.10 : допустимая разница orders_count, 0..1}';

    protected $description = 'Healthcheck инвариантов locality-пайплайна (для cron)';

    public function handle(): int
    {
        $errors = 0;

        $errors += $this->checkNoDuplicatesByBusinessKey();
        $errors += $this->checkSnapshotFreshness(
            (int) $this->option('freshness-hours'),
            $this->option('integration') !== null ? (int) $this->option('integration') : null
        );
        $errors += $this->checkSnapshotCountDrift(
            (float) $this->option('count-tolerance'),
            $this->option('integration') !== null ? (int) $this->option('integration') : null
        );

        if ($errors > 0) {
            $this->error("Health check failed: {$errors} issue(s)");
            return self::FAILURE;
        }

        $this->info('OK — все инварианты выполнены');
        return self::SUCCESS;
    }

    private function checkNoDuplicatesByBusinessKey(): int
    {
        $dupes = DB::table('ozon_order_unit_economics')
            ->selectRaw('integration_id, posting_number, sku, offer_id, COUNT(*) AS c')
            ->groupBy('integration_id', 'posting_number', 'sku', 'offer_id')
            ->havingRaw('COUNT(*) > 1')
            ->limit(5)
            ->get();

        if ($dupes->isEmpty()) {
            $this->line('  [OK] ozon_order_unit_economics: нет дубликатов по business-key');
            return 0;
        }

        $this->error('  [FAIL] ozon_order_unit_economics: найдены дубликаты по (integration_id, posting_number, sku, offer_id)');
        foreach ($dupes as $d) {
            $this->line(sprintf(
                '    int=%d posting=%s sku=%s offer_id=%s → %d строк',
                $d->integration_id,
                $d->posting_number,
                $d->sku,
                $d->offer_id,
                $d->c
            ));
        }
        $this->line('    Починить: php artisan migrate (если миграция ...dedup не применена)');

        return 1;
    }

    private function checkSnapshotFreshness(int $thresholdHours, ?int $integrationId): int
    {
        $q = LocalityMetricDaily::query();
        if ($integrationId !== null) {
            $q->where('integration_id', $integrationId);
        }
        $maxUpd = $q->max('updated_at');

        if ($maxUpd === null) {
            $this->error('  [FAIL] locality_metrics_daily пуст');
            return 1;
        }

        $ageHours = now()->diffInHours($maxUpd);
        if ($ageHours > $thresholdHours) {
            $this->error(sprintf(
                '  [FAIL] locality_metrics_daily: последний update %s (старше %d ч.)',
                $maxUpd,
                $thresholdHours
            ));
            $this->line('    Починить: php artisan locality:recompute --scope=aggregation');
            return 1;
        }

        $this->line(sprintf('  [OK] locality_metrics_daily: последний update %s (%d ч. назад)', $maxUpd, $ageHours));
        return 0;
    }

    private function checkSnapshotCountDrift(float $tolerance, ?int $integrationId): int
    {
        // Берём 10 случайных свежих snapshot'ов и сверяем их orders_count с
        // реальным COUNT в ozon_order_unit_economics за то же окно.
        $sample = LocalityMetricDaily::query()
            ->when($integrationId !== null, fn ($q) => $q->where('integration_id', $integrationId))
            ->where('period_days', 28)
            ->orderByDesc('snapshot_date')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        if ($sample->isEmpty()) {
            $this->line('  [SKIP] locality_metrics_daily: нечего сверять');
            return 0;
        }

        $failures = 0;
        foreach ($sample as $snap) {
            $snapDate = \Carbon\Carbon::parse($snap->snapshot_date);
            $from = $snapDate->copy()->subDays($snap->period_days)->startOfDay();
            $to = $snapDate->copy()->endOfDay();

            $realCount = OzonOrderUnitEconomics::query()
                ->where('integration_id', $snap->integration_id)
                ->where('sku', $snap->sku)
                ->whereBetween('order_date', [$from, $to])
                ->count();

            if ($snap->orders_count === 0 && $realCount === 0) {
                continue;
            }

            $diff = abs($snap->orders_count - $realCount) / max($snap->orders_count, $realCount, 1);
            if ($diff > $tolerance) {
                $this->error(sprintf(
                    '  [FAIL] drift int=%d sku=%s period=%d: snapshot orders_count=%d, реальных в таблице=%d (расхождение %.1f%%)',
                    $snap->integration_id,
                    $snap->sku,
                    $snap->period_days,
                    $snap->orders_count,
                    $realCount,
                    $diff * 100
                ));
                $failures++;
            }
        }

        if ($failures === 0) {
            $this->line(sprintf('  [OK] snapshot count drift <= %d%% на %d sample', (int) ($tolerance * 100), $sample->count()));
            return 0;
        }

        $this->line('    Починить: php artisan locality:recompute --scope=aggregation');
        return 1;
    }
}
