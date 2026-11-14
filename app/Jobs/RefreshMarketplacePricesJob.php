<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\UnitEconomicsSyncRun;
use App\Services\UnitEconomics\MarketplacePriceObservation;
use App\Services\UnitEconomics\MarketplacePriceSnapshotFetcher;
use App\Services\UnitEconomics\UnitEconomicsSyncCoordinator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RefreshMarketplacePricesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 300;

    public int $uniqueFor = 21600;

    public bool $failOnTimeout = true;

    public function __construct(public string $runId, public int $integrationId)
    {
        $this->onQueue(config('unit_economics_sync.price_queue', 'price-refresh'));
    }

    public function uniqueId(): string
    {
        return 'ue-prices:'.$this->integrationId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->shared()->expireAfter(360)->releaseAfter(15)];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return UnitEconomicsSyncRun::find($this->runId)?->deadline_at ?? now();
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(UnitEconomicsSyncCoordinator $coordinator, MarketplacePriceSnapshotFetcher $fetcher, MarketplacePriceObservation $writer): void
    {
        if (! $coordinator->enabled($this->integrationId)) {
            $coordinator->expire($this->runId, 'pipeline_disabled');

            return;
        }
        $run = $coordinator->latest($this->integrationId);
        if (! $run || $run->id !== $this->runId || in_array($run->status, ['completed', 'partial', 'failed', 'superseded'], true)) {
            return;
        }
        if ($run->deadline_at->isPast() || ! $run->integration?->is_active) {
            $coordinator->expire($run->id, $run->deadline_at->isPast() ? 'deadline_exceeded' : 'integration_inactive');

            return;
        }
        $version = $run->requested_version;
        if (! Product::where('integration_id', $this->integrationId)->exists()
            && in_array('catalog', $run->required_stages, true)
            && in_array($run->stages['catalog']['status'] ?? '', ['pending', 'queued', 'running'], true)) {
            $coordinator->updateStage($run->id, 'prices', 'pending', ['error_code' => 'catalog_required'], $version);

            return;
        }
        $scope = $fetcher->scopeKey($run->integration);
        $retryAt = (int) Cache::get($scope.':next', 0);
        if ($retryAt > time()) {
            $this->wait($coordinator, $version, $retryAt - time(), 'marketplace_cooldown');

            return;
        }
        $lock = Cache::lock($scope.':lock', 360);
        if (! $lock->get()) {
            $this->wait($coordinator, $version, 15, 'seller_price_request_in_progress');

            return;
        }
        try {
            if (! $coordinator->updateStage($run->id, 'prices', 'running', ['next_retry_at' => null, 'error_code' => null], $version)) {
                return;
            }
            $snapshot = $fetcher->fetch($run->integration);
            $status = $snapshot['http_status'] ?? null;
            $attempt = (int) ($run->stages['prices']['external_attempts'] ?? 0) + 1;
            $retry = $status === 429 ? max(1, (int) ($snapshot['retry_after'] ?? 60)) + 1
                : ((! $snapshot['complete'] && ! in_array($status, [400, 401, 403, 404], true)) ? min(1800, 60 * 2 ** min(5, $attempt - 1)) : null);
            $details = ['http_status' => $status, 'next_retry_at' => $retry ? now()->addSeconds($retry)->utc()->toIso8601String() : null,
                'response_received_at' => now()->utc()->toIso8601String(), 'external_attempts' => $snapshot['complete'] ? 0 : $attempt,
                'error_code' => $snapshot['complete'] ? null : ($status === 429 ? 'rate_limited' : (in_array($status, [401, 403], true) ? 'credentials_rejected' : 'price_snapshot_incomplete'))];
            if ($retry) {
                Cache::put($scope.':next', time() + $retry, now()->addSeconds($retry));
            }
            $result = $coordinator->observe($run->id, $version,
                fn ($integration) => $writer->apply($integration, $snapshot['prices'], $version), $snapshot['complete'], $details);
            if (! $result['accepted']) {
                self::dispatch($run->id, $this->integrationId);

                return;
            }
            if ($result['value']['matched'] > 0) {
                ProjectMarketplacePricesJob::dispatch($run->id, $this->integrationId, $version, $result['value']['observation_id']);
            }
            if ($retry) {
                $this->release($retry);
            }
        } finally {
            $lock->release();
        }
    }

    private function wait(UnitEconomicsSyncCoordinator $coordinator, int $version, int $seconds, string $reason): void
    {
        $coordinator->updateStage($this->runId, 'prices', 'waiting_external', [
            'next_retry_at' => now()->addSeconds($seconds)->utc()->toIso8601String(), 'error_code' => $reason], $version);
        $this->release($seconds);
    }

    public function failed(?Throwable $exception): void
    {
        app(UnitEconomicsSyncCoordinator::class)->expire($this->runId,
            $exception instanceof TimeoutExceededException ? 'worker_timeout' : 'price_worker_failed');
    }
}
