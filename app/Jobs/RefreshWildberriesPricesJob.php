<?php

namespace App\Jobs;

use App\Domains\Wildberries\Api\WildberriesRateLimitException;
use App\Models\SyncLog;
use App\Services\Wildberries\WildberriesPriceRefreshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class RefreshWildberriesPricesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 12;

    public int $timeout = 180;

    public int $uniqueFor = 14400;

    public function __construct(public int $integrationId) {}

    public function uniqueId(): string
    {
        return 'wildberries-prices:'.$this->integrationId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 180, 300, 900, 900, 1800, 1800, 3600];
    }

    public function handle(WildberriesPriceRefreshService $service): void
    {
        $syncLog = SyncLog::query()
            ->where('integration_id', $this->integrationId)
            ->where('marketplace', 'wildberries')
            ->where('sync_type', 'products')
            ->where('status', SyncLog::STATUS_COMPLETED)
            ->latest('created_at')
            ->first();
        $credentials = $syncLog?->credentials ?? [];
        $apiKey = (string) ($credentials['api_key'] ?? '');

        if ($apiKey === '') {
            throw new RuntimeException('WB API key is unavailable for delayed price refresh');
        }

        try {
            $stats = $service->refresh($this->integrationId, $apiKey);
        } catch (WildberriesRateLimitException $e) {
            $delay = min(max($e->retryAfterSeconds + 1, 5), 3600);

            Log::info('Delayed WB price refresh released after rate limit', [
                'integration_id' => $this->integrationId,
                'attempt' => $this->attempts(),
                'delay_s' => $delay,
            ]);

            $this->release($delay);

            return;
        }

        Log::info('Delayed WB price refresh completed', [
            'integration_id' => $this->integrationId,
            'stats' => $stats,
        ]);

        if ($stats['updated'] > 0) {
            RecalculateUnitEconomicsCacheJob::dispatch($this->integrationId)
                ->onQueue('unit-economics');
        }
    }
}
