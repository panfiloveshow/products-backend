<?php

namespace App\Jobs;

use App\Services\UnitEconomicsCacheService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Точечный пересчёт кэша юнит-экономики для одного SKU.
 *
 * Запускается из observer'ов (OzonSkuDeliveryProfile, OzonSupplyFixation)
 * при изменении данных, влияющих на расчёт.
 *
 * ShouldBeUnique защищает от бомбардировки очереди при bulk-sync —
 * несколько observer-триггеров на один SKU за короткое время схлопнутся в одну задачу.
 */
class RecalculateUnitEconomicsForSkuJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public int $uniqueFor = 30;

    public function __construct(
        public int $integrationId,
        public string $sku,
    ) {
    }

    public function uniqueId(): string
    {
        return "{$this->integrationId}:{$this->sku}";
    }

    public function handle(UnitEconomicsCacheService $cacheService): void
    {
        try {
            $cacheService->onSettingsChanged($this->integrationId, $this->sku);
        } catch (\Throwable $e) {
            Log::error('RecalculateUnitEconomicsForSkuJob failed', [
                'integration_id' => $this->integrationId,
                'sku' => $this->sku,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
