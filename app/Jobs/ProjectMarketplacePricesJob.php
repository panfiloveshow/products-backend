<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\UnitEconomicsSyncRun;
use App\Services\UnitEconomics\UnitEconomicsSyncCoordinator;
use App\Services\UnitEconomicsCacheService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/** At most 100 SKUs per lease. The cursor and aggregate counts survive worker loss. */
class ProjectMarketplacePricesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 300;

    public int $uniqueFor = 21600;

    public bool $failOnTimeout = true;

    public function __construct(public string $runId, public int $integrationId, public int $version, public string $observationId, public ?string $afterId = null)
    {
        $this->onQueue(config('unit_economics_sync.price_queue', 'price-refresh'));
    }

    public function uniqueId(): string
    {
        return 'ue-project:'.$this->runId.':'.$this->version.':'.$this->observationId.':'.$this->afterId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('ue-project:'.$this->integrationId))->shared()->expireAfter(360)->releaseAfter(15)];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return UnitEconomicsSyncRun::find($this->runId)?->deadline_at ?? now();
    }

    public function handle(UnitEconomicsSyncCoordinator $coordinator, UnitEconomicsCacheService $cache): void
    {
        if (! $coordinator->enabled($this->integrationId)) {
            $coordinator->expire($this->runId, 'pipeline_disabled');

            return;
        }
        $run = $coordinator->latest($this->integrationId);
        if (! $run || $run->id !== $this->runId || $run->requested_version !== $this->version
            || ($run->stages['projection']['observation_id'] ?? null) !== $this->observationId
            || ($run->stages['projection']['cursor'] ?? null) !== $this->afterId
            || ! $coordinator->updateStage($run->id, 'projection', 'running', version: $this->version)) {
            return;
        }
        if ($run->deadline_at->isPast()) {
            $coordinator->expire($run->id);

            return;
        }
        $field = $run->integration->marketplace === 'wildberries' ? 'wb_data' : 'ozon_data';
        $products = Product::where('integration_id', $this->integrationId)
            ->where($field.'->price_version', $this->version)
            ->when($this->afterId, fn ($q) => $q->where('id', '>', $this->afterId))->orderBy('id')->limit(101)->get();
        $more = $products->count() > 100;
        $stats = ['total' => 0, 'success' => 0, 'errors' => 0];
        foreach ($products->take(100) as $product) {
            try {
                $result = $coordinator->withCurrentVersion($run->id, $this->version,
                    function ($state, $current) use ($cache, $product): void {
                        if (($current->stages['projection']['observation_id'] ?? null) !== $this->observationId) {
                            throw new \RuntimeException('Price snapshot was superseded');
                        }
                        $cache->publishObservedProduct($product, $this->version);
                    });
                if (! $result['accepted']) {
                    return;
                }
                $stats['success']++;
            } catch (Throwable $e) {
                $stats['errors']++;
                $coordinator->withCurrentVersion($run->id, $this->version,
                    fn () => $cache->markProjectionFailure($product, $this->version));
                Log::warning('Confirmed price projection failed; previous SKU cache retained', [
                    'integration_id' => $this->integrationId, 'sku' => $product->sku, 'error_class' => $e::class]);
            }
            $stats['total']++;
        }
        $cursor = $products->take(100)->last()?->id;
        if ($coordinator->projectionBatch($run->id, $this->version, $this->observationId, $this->afterId, $cursor, $stats, ! $more) && $more) {
            self::dispatch($run->id, $this->integrationId, $this->version, $this->observationId, $cursor);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(UnitEconomicsSyncCoordinator::class)->expire($this->runId, 'projection_worker_failed');
    }
}
