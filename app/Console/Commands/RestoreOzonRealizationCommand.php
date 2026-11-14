<?php

namespace App\Console\Commands;

use App\Domains\Locality\Ingestion\FinanceTransactionSyncer;
use App\Models\Integration;
use App\Models\OzonFinanceTransaction;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RestoreOzonRealizationCommand extends Command
{
    protected $signature = 'ozon:restore-realization
        {--integration=* : Limit to integration IDs}
        {--from= : First day, defaults to the first stored daily realization}
        {--to= : Last day, defaults to yesterday}
        {--report= : Append a JSONL result for every day}
        {--resume : Skip restored days already recorded in the report}
        {--dry-run : Show the scope without contacting Ozon or changing data}';

    protected $description = 'Restore complete Ozon daily sales/returns without reloading or deleting fee transactions';

    public function handle(FinanceTransactionSyncer $syncer): int
    {
        try {
            $to = $this->option('to') ? Carbon::createFromFormat('!Y-m-d', $this->option('to')) : now()->subDay()->startOfDay();
            $fromOption = $this->option('from') ? Carbon::createFromFormat('!Y-m-d', $this->option('from')) : null;
            if (($this->option('to') && $to->toDateString() !== $this->option('to')) || ($fromOption && $fromOption->toDateString() !== $this->option('from'))) {
                throw new \InvalidArgumentException('Dates must be valid calendar dates in Y-m-d format.');
            }
            if ($to->gte(now()->startOfDay()) || ($fromOption && $fromOption->gt($to))) {
                throw new \InvalidArgumentException('Use a completed date range ending before today.');
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $query = Integration::where('marketplace', 'ozon');
        $ids = $this->option('integration');
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        } else {
            $query->whereIn('id', OzonFinanceTransaction::query()->where('raw->source', 'realization_by_day')->select('integration_id')->distinct());
        }
        $completed = [];
        $report = $this->option('report');
        if ($this->option('resume') && ! $report) {
            $this->error('--resume requires --report.');

            return self::FAILURE;
        }
        if ($report && (! is_dir(dirname($report)) || ! is_writable(dirname($report)) || (is_file($report) && ! is_writable($report)))) {
            $this->error('Recovery report is not writable.');

            return self::FAILURE;
        }
        if ($this->option('resume') && $report && is_file($report)) {
            foreach (file($report, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $row = json_decode($line, true);
                if (($row['status'] ?? '') === 'restored') {
                    $completed[$row['integration_id'].':'.$row['date']] = true;
                } elseif (($row['status'] ?? '') === 'monthly_restored') {
                    $completed[$row['integration_id'].':month:'.substr($row['date'], 0, 7)] = true;
                }
            }
        }

        $errors = 0;
        $count = 0;
        foreach ($query->orderBy('id')->get() as $integration) {
            $first = OzonFinanceTransaction::where('integration_id', $integration->id)
                ->where('raw->source', 'realization_by_day')->min('operation_date');
            $from = $fromOption?->copy() ?? ($first ? Carbon::parse($first)->startOfDay() : $to->copy());
            $this->info("Integration {$integration->id}: {$from->toDateString()} .. {$to->toDateString()}");
            if ($this->option('dry-run')) {
                continue;
            }

            // Ozon retains daily realization for 32 days. Older sales can be
            // recovered only at the granularity of the official monthly report.
            $dailyFrom = $from->copy()->max(now()->subDays(32)->startOfDay());
            $restoredMonths = [];
            if ($from->lt($dailyFrom)) {
                $archiveThrough = $to->copy()->min($dailyFrom->copy()->subDay())->startOfMonth();
                for ($month = $from->copy()->startOfMonth(); $month->lte($archiveThrough) && $month->lt(now()->startOfMonth()); $month->addMonth()) {
                    if (isset($completed[$integration->id.':month:'.$month->format('Y-m')])) {
                        $restoredMonths[$month->format('Y-m')] = true;

                        continue;
                    }
                    try {
                        $monthly = $syncer->restoreMonthlyRealization($integration, $month);
                        $result = ['status' => array_sum($monthly) > 0 ? 'monthly_restored' : 'monthly_unavailable', 'inserted' => $monthly[0], 'updated' => $monthly[1]];
                        $restoredMonths[$month->format('Y-m')] = true;
                    } catch (\Throwable $e) {
                        $result = ['status' => 'error', 'error' => $e->getMessage()];
                        $errors++;
                    }
                    $this->record($report, ['integration_id' => (int) $integration->id, 'date' => $month->copy()->endOfMonth()->toDateString(), 'granularity' => 'month'] + $result);
                }
            }
            if ($dailyFrom->gt($to)) {
                continue;
            }

            // Probe the latest report first; a revoked Premium subscription must
            // not cause hundreds of identical 403 requests for historical days.
            $dates = [$to->copy()];
            for ($day = $dailyFrom->copy(); $day->lt($to); $day->addDay()) {
                $dates[] = $day->copy();
            }
            foreach ($dates as $date) {
                $key = $integration->id.':'.$date->toDateString();
                if (isset($completed[$key])) {
                    continue;
                }
                try {
                    $result = $syncer->restoreRealizationDay($integration, $date);
                    if ($result['status'] === 'premium_required') {
                        $result['months'] = [];
                        $month = now()->subMonthNoOverflow()->startOfMonth();
                        if (! isset($restoredMonths[$month->format('Y-m')])) {
                            $monthly = $syncer->restoreMonthlyRealization($integration, $month);
                            $result['months'][$month->format('Y-m')] = ['inserted' => $monthly[0], 'updated' => $monthly[1]];
                        }
                    }
                } catch (\Throwable $e) {
                    $result = ['status' => 'error', 'error' => $e->getMessage()];
                    $errors++;
                }
                $row = ['integration_id' => (int) $integration->id, 'date' => $date->toDateString()] + $result;
                $this->record($report, $row);
                $count++;
                if (in_array($result['status'], ['premium_required', 'error'], true)) {
                    break;
                }
            }
        }
        $this->info("Days checked: {$count}; errors: {$errors}");

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function record(?string $report, array $row): void
    {
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($report && file_put_contents($report, $json.PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write recovery report.');
        }
        $this->line($json);
    }
}
