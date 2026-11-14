<?php

namespace App\Jobs;

use App\Models\Supply;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Dispatches bounded, independently retryable work; never calls the seller API. */
class SyncSupplyStatusesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    // Keep the original constructor/property compatible with already queued jobs.
    public function __construct(public ?int $integrationId = null)
    {
        $this->onQueue(config('autoplanning.execution.status_queue', 'supply-statuses'));
    }

    public function uniqueId(): string
    {
        return 'supply-status-dispatch:'.($this->integrationId ?? 'all');
    }

    public function handle(): void
    {
        $scheduled = 0;
        Supply::trackableOzon()
            ->when($this->integrationId, fn ($query) => $query->where('integration_id', $this->integrationId))
            ->where(fn ($query) => $query->whereNull('external_last_synced_at')->orWhere('external_last_synced_at', '<', now()->subMinutes(5)))
            ->select(['id', 'integration_id'])
            ->chunkById(100, function ($supplies) use (&$scheduled): void {
                $supplies = $supplies->filter(fn ($s) => (int) \Illuminate\Support\Facades\Cache::get('supply-status-cooldown:'.$s->id, 0) <= time());
                foreach ($supplies->groupBy('integration_id') as $integrationId => $group) {
                    foreach ($group->chunk(50) as $batch) {
                        SyncSupplyStatusBatchJob::dispatch((int) $integrationId, $batch->pluck('id')->map(fn ($id) => (int) $id)->all(), time());
                        $scheduled += $batch->count();
                    }
                }
            });

        Log::info('Supply status work dispatched', [
            'integration_id' => $this->integrationId,
            'supplies' => $scheduled,
            'queue' => $this->queue,
        ]);
    }
}
