<?php

namespace App\Jobs;

use App\Services\UnitEconomicsCacheService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job для пересчёта кэша юнит-экономики
 * 
 * Запускается автоматически после:
 * - Синхронизации товаров
 * - Изменения настроек пользователя
 */
class RecalculateUnitEconomicsCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 900; // 15 минут

    public function __construct(
        public int $integrationId
    ) {}

    public function handle(UnitEconomicsCacheService $cacheService): void
    {
        Log::info('RecalculateUnitEconomicsCacheJob started', [
            'integration_id' => $this->integrationId,
        ]);

        try {
            $stats = $cacheService->recalculateIntegration($this->integrationId);

            Log::info('RecalculateUnitEconomicsCacheJob completed', [
                'integration_id' => $this->integrationId,
                'stats' => $stats,
            ]);
        } catch (\Exception $e) {
            Log::error('RecalculateUnitEconomicsCacheJob failed', [
                'integration_id' => $this->integrationId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
