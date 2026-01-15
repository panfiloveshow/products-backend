<?php

namespace App\Jobs;

use App\Domains\Ozon\OzonMarketplace;
use App\Domains\Wildberries\WildberriesMarketplace;
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
 * - Ozon: слоты из /v1/supply/timeslot/list
 * - Wildberries: коэффициенты из /api/tariffs/v1/acceptance/coefficients
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
     * WB использует коэффициенты приёмки вместо слотов
     * coefficient = 0 или 1 И allowUnload = true → слот доступен
     */
    private function syncWildberriesSlots(Integration $integration): void
    {
        $marketplace = WildberriesMarketplace::fromIntegration($integration);
        $suppliesApi = $marketplace->supplies();

        // Получаем коэффициенты приёмки на 14 дней
        $slots = $suppliesApi->getAvailableAcceptanceSlots($this->warehouseId);

        if (empty($slots)) {
            Log::warning('No WB acceptance slots found', ['integration_id' => $integration->id]);
            return;
        }

        $synced = 0;
        $created = 0;

        foreach ($slots as $slotData) {
            $warehouseId = $slotData['warehouse_id'] ?? null;
            $date = $slotData['date'] ?? null;
            
            if (!$warehouseId || !$date) continue;

            $slot = WarehouseSlot::updateOrCreate(
                [
                    'marketplace' => 'wildberries',
                    'warehouse_id' => $warehouseId,
                    'date' => $date,
                    'time_from' => '00:00',
                    'time_to' => '23:59',
                ],
                [
                    'warehouse_name' => $slotData['warehouse_name'] ?? null,
                    'coefficient' => $slotData['coefficient'] ?? null,
                    'is_available' => $slotData['is_available'] ?? false,
                    'allow_unload' => $slotData['allow_unload'] ?? false,
                    'box_type_id' => $slotData['box_type_id'] ?? null,
                    'is_sorting_center' => $slotData['is_sorting_center'] ?? false,
                    'storage_coefficient' => $slotData['storage_coefficient'] ?? null,
                    'delivery_coefficient' => $slotData['delivery_coefficient'] ?? null,
                    'synced_at' => now(),
                ]
            );

            $synced++;
            if ($slot->wasRecentlyCreated) {
                $created++;
            }
        }

        Log::info('WB slots synced', [
            'integration_id' => $integration->id,
            'synced' => $synced,
            'created' => $created,
        ]);
    }
}
