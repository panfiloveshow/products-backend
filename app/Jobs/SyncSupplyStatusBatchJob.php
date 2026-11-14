<?php

namespace App\Jobs;

use App\Models\Supply;
use App\Services\Supply\SupplyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncSupplyStatusBatchJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public int $uniqueFor = 10800;

    public function __construct(public int $integrationId, public array $supplyIds, public int $requestedAt)
    {
        if (count($supplyIds) > 50) {
            throw new \InvalidArgumentException('Supply batch exceeds 50 rows');
        }
        $this->onQueue('supply-status-batches');
    }

    public function uniqueId(): string { return $this->integrationId.':'.hash('sha256', implode(',', $this->supplyIds)); }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('supply-seller:'.$this->integrationId))->shared()->expireAfter(150)->releaseAfter(30)];
    }

    public function backoff(): array { return [60, 300, 900]; }

    public function handle(SupplyService $service): void
    {
        $supplies = Supply::trackableOzon()->where('integration_id', $this->integrationId)->whereIn('id', $this->supplyIds)->get()
            ->filter(fn ($s) => ($s->external_last_synced_at?->getTimestamp() ?? 0) < $this->requestedAt
                && (int) Cache::get('supply-status-cooldown:'.$s->id, 0) <= time());
        $stats = $service->syncStatusesBatch($supplies);
        Log::log($stats['failed'] > 0 ? 'warning' : 'info', 'Supply status batch processed', [
            'integration_id' => $this->integrationId, 'stats' => $stats]);
    }
}
