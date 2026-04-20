<?php

namespace App\Domains\Locality\Console;

use App\Domains\Locality\Calculation\LocalityAggregator;
use App\Models\Integration;
use Carbon\Carbon;
use Illuminate\Console\Command;

class LocalityBackfillCommand extends Command
{
    protected $signature = 'locality:backfill
                            {--integration= : Integration ID}
                            {--from= : Дата начала (Y-m-d)}
                            {--to= : Дата конца (Y-m-d), по умолчанию сегодня}
                            {--period=* : Rolling window period_days (можно несколько; по умолчанию 7 и 28)}';

    protected $description = 'Backfill locality_metrics_daily за диапазон дат';

    public function handle(LocalityAggregator $aggregator): int
    {
        $integrationId = $this->option('integration');
        $fromOpt = $this->option('from');
        $toOpt = $this->option('to');
        $periods = (array) $this->option('period');
        $periods = array_values(array_unique(array_map('intval', array_filter($periods))));
        if (empty($periods)) {
            $periods = [7, 28];
        }

        if ($fromOpt === null) {
            $this->error('--from обязателен (Y-m-d)');
            return self::FAILURE;
        }

        $from = Carbon::parse($fromOpt)->startOfDay();
        $to = $toOpt !== null ? Carbon::parse($toOpt)->startOfDay() : now()->startOfDay();

        $query = Integration::query()->where('is_active', true)->where('marketplace', 'ozon');
        if ($integrationId !== null) {
            $query->where('id', (int) $integrationId);
        }

        $integrations = $query->get();
        if ($integrations->isEmpty()) {
            $this->warn('Нет активных Ozon-интеграций.');
            return self::SUCCESS;
        }

        foreach ($integrations as $integration) {
            foreach ($periods as $period) {
                $this->info("Backfill integration_id={$integration->id}, {$from->toDateString()} → {$to->toDateString()}, period={$period}d");
                for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addDay()) {
                    $result = $aggregator->runDaily((int) $integration->id, $cursor, $period);
                    $this->line(sprintf(
                        '  %s: skus=%d clusters=%d',
                        $cursor->toDateString(),
                        $result['skus_processed'],
                        $result['clusters_processed']
                    ));
                }
            }
        }

        return self::SUCCESS;
    }
}
