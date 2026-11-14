<?php

namespace App\Jobs;

use App\Models\Supply;
use App\Services\Supply\SupplyService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/** One supply is the checkpoint: a slow request cannot restart an entire catalog. */
class SyncSupplyStatusJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 10800;

    public bool $failOnTimeout = true;

    public ?int $requestedAt = null;

    public function __construct(public int $supplyId, public int $integrationId, ?int $requestedAt = null)
    {
        $this->requestedAt = $requestedAt ?? now()->getTimestamp();
        $this->onQueue(config('autoplanning.execution.status_queue', 'supply-statuses'));
    }

    public function uniqueId(): string
    {
        return 'supply-status:'.$this->supplyId;
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->shared()->releaseAfter(30)->expireAfter(180)];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return CarbonImmutable::createFromTimestampUTC($this->requestedAt ?? time())->addHours(2);
    }

    public function handle(SupplyService $service): void
    {
        if ((int) \Illuminate\Support\Facades\Cache::get('supply-status-cooldown:'.$this->supplyId, 0) > time()) {
            return;
        }
        $supply = Supply::trackableOzon()->whereKey($this->supplyId)
            ->where('integration_id', $this->integrationId)->first();
        if ($supply && ! ($this->requestedAt !== null && $supply->external_last_synced_at?->getTimestamp() >= $this->requestedAt)) {
            $service->syncStatus($supply);
        }
    }
}
