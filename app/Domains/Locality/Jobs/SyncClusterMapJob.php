<?php

namespace App\Domains\Locality\Jobs;

use App\Domains\Locality\Ingestion\OzonClusterMapSyncer;
use App\Models\Integration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncClusterMapJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;

    public function __construct(private readonly ?int $integrationId = null)
    {
    }

    public function handle(OzonClusterMapSyncer $syncer): void
    {
        $query = Integration::query()->where('is_active', true)->where('marketplace', 'ozon');
        if ($this->integrationId !== null) {
            $query->where('id', $this->integrationId);
        }

        foreach ($query->get() as $integration) {
            try {
                $result = $syncer->syncForIntegration($integration);
                Log::channel('locality')->info('SyncClusterMapJob integration result', [
                    'integration_id' => $integration->id,
                    'result' => $result->toArray(),
                ]);
            } catch (\Throwable $e) {
                Log::channel('locality')->error('SyncClusterMapJob failed', [
                    'integration_id' => $integration->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
