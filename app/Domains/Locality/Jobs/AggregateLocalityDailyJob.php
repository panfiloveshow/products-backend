<?php

namespace App\Domains\Locality\Jobs;

use App\Domains\Locality\Calculation\LocalityAggregator;
use App\Models\Integration;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AggregateLocalityDailyJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 1800;

    public function __construct(
        private readonly ?int $integrationId = null,
        private readonly ?string $snapshotDate = null,
        private readonly ?int $periodDays = null,
    ) {
    }

    public function handle(LocalityAggregator $aggregator): void
    {
        $date = $this->snapshotDate !== null ? Carbon::parse($this->snapshotDate) : now();
        $period = $this->periodDays ?? (int) config('locality.period.default_days', 28);

        $query = Integration::query()->where('is_active', true)->where('marketplace', 'ozon');
        if ($this->integrationId !== null) {
            $query->where('id', $this->integrationId);
        }

        foreach ($query->get() as $integration) {
            $lockKey = sprintf('locality:aggregate:%d:%s:%d', $integration->id, $date->toDateString(), $period);
            $lock = Cache::lock($lockKey, (int) config('locality.cache.recompute_lock_seconds', 600));

            if (! $lock->get()) {
                Log::channel('locality')->warning('AggregateLocalityDailyJob lock busy', [
                    'integration_id' => $integration->id,
                    'key' => $lockKey,
                ]);
                continue;
            }

            try {
                $result = $aggregator->runDaily((int) $integration->id, $date, $period);
                Log::channel('locality')->info('AggregateLocalityDailyJob result', [
                    'integration_id' => $integration->id,
                    'snapshot_date' => $date->toDateString(),
                    'period_days' => $period,
                    'result' => $result,
                ]);
            } catch (\Throwable $e) {
                Log::channel('locality')->error('AggregateLocalityDailyJob failed', [
                    'integration_id' => $integration->id,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                $lock->release();
            }
        }
    }
}
