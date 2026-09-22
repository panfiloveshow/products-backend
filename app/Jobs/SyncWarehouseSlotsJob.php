<?php

namespace App\Jobs;

use App\Domains\Ozon\OzonMarketplace;
use App\Domains\Wildberries\Api\SuppliesApi as WbSuppliesApi;
use App\Models\Integration;
use App\Models\WarehouseSlot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job для синхронизации слотов приёмки с маркетплейсов
 * 
 * Синхронизирует:
 * - Ozon: нечего — /v1/supply/timeslot/list отключён, слоты только у черновика — пропуск
 * - Wildberries: коэффициенты приёмки временно отключены WB (с 15.08.2026) — пропуск
 */
class SyncWarehouseSlotsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        private ?string $integrationId = null,
        private ?string $warehouseId = null
    ) {}

    public function handle(): void
    {
        Log::info('SyncWarehouseSlotsJob started', [
            'integration_id' => $this->integrationId,
            'warehouse_id' => $this->warehouseId,
        ]);

        $integrations = $this->integrationId
            ? Integration::where('id', $this->integrationId)->where('is_active', true)->get()
            : Integration::where('is_active', true)
                ->whereIn('marketplace', ['ozon', 'wildberries'])
                ->get();

        foreach ($integrations as $integration) {
            try {
                $this->syncIntegration($integration);
            } catch (\Exception $e) {
                Log::error('Failed to sync slots for integration', [
                    'integration_id' => $integration->id,
                    'marketplace' => $integration->marketplace,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('SyncWarehouseSlotsJob completed');
    }

    private function syncIntegration(Integration $integration): void
    {
        match ($integration->marketplace) {
            'ozon' => $this->syncOzonSlots($integration),
            'wildberries' => $this->syncWildberriesSlots($integration),
            default => null,
        };
    }

    /**
     * Синхронизация слотов Ozon
     */
    private function syncOzonSlots(Integration $integration): void
    {
        $marketplace = OzonMarketplace::fromIntegration($integration);
        $suppliesApi = $marketplace->supplies();

        // /v1/supply/timeslot/list Ozon отключил: слоты теперь есть только у черновика
        // (/v2/draft/timeslot/info, SupplyService::getAvailableTimeslots) — синкать нечего.
        if (! $suppliesApi->supportsFeature('get_acceptance_slots')) {
            Log::warning('Ozon slots sync skipped: слоты склада без черновика API больше не отдаёт', [
                'integration_id' => $integration->id,
            ]);
            return;
        }

        // Получаем склады
        $warehouses = $suppliesApi->getAvailableWarehouses();
        
        if (empty($warehouses)) {
            Log::warning('No Ozon warehouses found', ['integration_id' => $integration->id]);
            return;
        }

        $dateFrom = now()->toDateString();
        $dateTo = now()->addDays(14)->toDateString();

        $synced = 0;
        $created = 0;

        foreach ($warehouses as $warehouse) {
            $warehouseId = $warehouse['id'] ?? null;
            if (!$warehouseId) continue;

            // Если указан конкретный склад — синхронизируем только его
            if ($this->warehouseId && $this->warehouseId !== $warehouseId) {
                continue;
            }

            try {
                $slots = $suppliesApi->getAcceptanceSlots($warehouseId, $dateFrom, $dateTo);

                foreach ($slots as $slotData) {
                    $slot = WarehouseSlot::updateOrCreate(
                        [
                            'marketplace' => 'ozon',
                            'warehouse_id' => $warehouseId,
                            'date' => $slotData['date'],
                            'time_from' => $slotData['time_from'],
                            'time_to' => $slotData['time_to'],
                        ],
                        [
                            'external_slot_id' => $slotData['id'] ?? null,
                            'warehouse_name' => $warehouse['name'] ?? null,
                            'from_datetime' => $slotData['from_datetime'] ?? null,
                            'to_datetime' => $slotData['to_datetime'] ?? null,
                            'is_available' => $slotData['is_available'] ?? true,
                            'capacity' => $slotData['capacity'] ?? null,
                            'capacity_used' => $slotData['capacity_used'] ?? 0,
                            'synced_at' => now(),
                        ]
                    );

                    $synced++;
                    if ($slot->wasRecentlyCreated) {
                        $created++;
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Failed to sync Ozon slots for warehouse', [
                    'warehouse_id' => $warehouseId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Ozon slots synced', [
            'integration_id' => $integration->id,
            'synced' => $synced,
            'created' => $created,
        ]);
    }

    /**
     * Синхронизация слотов Wildberries
     *
     * Слоты WB — это коэффициенты приёмки (GET /api/tariffs/v1/acceptance/coefficients),
     * а метод WB временно отключил с 15.08.2026 (RN-570), замены пока нет. Не зовём
     * его и не трогаем сохранённые слоты — только фиксируем причину в логе.
     */
    private function syncWildberriesSlots(Integration $integration): void
    {
        Log::info('WB slots sync skipped: '.WbSuppliesApi::DISABLED_REASON, [
            'integration_id' => $integration->id,
            'warehouse_id' => $this->warehouseId,
        ]);
    }
}
