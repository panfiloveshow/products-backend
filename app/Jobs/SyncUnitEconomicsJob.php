<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Services\UnitEconomicsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncUnitEconomicsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;

    public function __construct(
        public int $integrationId,
        public ?string $periodStart = null,
        public ?string $periodEnd = null
    ) {}

    public function handle(UnitEconomicsService $unitEconomicsService): void
    {
        $integration = Integration::find($this->integrationId);
        
        if (!$integration) {
            Log::error('SyncUnitEconomicsJob: Integration not found', [
                'integration_id' => $this->integrationId
            ]);
            return;
        }
        
        Log::info('SyncUnitEconomicsJob started', [
            'integration_id' => $this->integrationId,
            'marketplace' => $integration->marketplace,
        ]);
        
        try {
            $result = $unitEconomicsService->syncFromRealData(
                $integration,
                $this->periodStart,
                $this->periodEnd
            );
            
            Log::info('SyncUnitEconomicsJob completed', [
                'integration_id' => $this->integrationId,
                'synced' => $result['synced'],
                'errors' => $result['errors'],
            ]);
            
            // Запускаем пересчёт кэша ПОСЛЕ завершения синхронизации UnitEconomics
            RecalculateUnitEconomicsCacheJob::dispatch($this->integrationId);
            
        } catch (\Exception $e) {
            Log::error('SyncUnitEconomicsJob failed', [
                'integration_id' => $this->integrationId,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }
}
