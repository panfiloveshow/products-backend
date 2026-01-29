<?php

namespace App\Jobs;

use App\Domains\Ozon\OzonMarketplace;
use App\Domains\Wildberries\WildberriesMarketplace;
use App\Events\ShipmentStatusChanged;
use App\Models\Integration;
use App\Models\Shipment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job для синхронизации статусов поставок с маркетплейсами
 * 
 * Запускается по расписанию (каждые 15 минут) для обновления
 * статусов активных поставок.
 */
class SyncShipmentStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300; // 5 минут

    public function __construct(
        private readonly ?string $shipmentId = null
    ) {}

    public function handle(): void
    {
        if ($this->shipmentId) {
            // Синхронизация конкретной поставки
            $this->syncSingleShipment($this->shipmentId);
        } else {
            // Синхронизация всех активных поставок
            $this->syncAllActiveShipments();
        }
    }

    /**
     * Синхронизация всех активных поставок
     */
    private function syncAllActiveShipments(): void
    {
        $activeStatuses = [
            Shipment::STATUS_SUBMITTED,
            Shipment::STATUS_APPROVED,
            Shipment::STATUS_SENT,
            Shipment::STATUS_IN_TRANSIT,
        ];

        $shipments = Shipment::whereIn('status', $activeStatuses)
            ->whereNotNull('external_supply_id')
            ->get();

        Log::info('SyncShipmentStatusJob: Starting sync for active shipments', [
            'count' => $shipments->count(),
        ]);

        foreach ($shipments as $shipment) {
            try {
                $this->syncSingleShipment($shipment->id);
            } catch (\Exception $e) {
                Log::error('SyncShipmentStatusJob: Failed to sync shipment', [
                    'shipment_id' => $shipment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Синхронизация одной поставки
     */
    private function syncSingleShipment(string $shipmentId): void
    {
        $shipment = Shipment::find($shipmentId);
        
        if (!$shipment) {
            Log::warning('SyncShipmentStatusJob: Shipment not found', [
                'shipment_id' => $shipmentId,
            ]);
            return;
        }

        if (!$shipment->external_supply_id) {
            Log::debug('SyncShipmentStatusJob: No external_supply_id, skipping', [
                'shipment_id' => $shipmentId,
            ]);
            return;
        }

        try {
            $result = match ($shipment->marketplace) {
                'ozon' => $this->syncOzonStatus($shipment),
                'wildberries' => $this->syncWildberriesStatus($shipment),
                default => null,
            };

            if ($result && $result['status_changed']) {
                $oldStatus = $shipment->status;
                
                $shipment->update([
                    'external_status' => $result['external_status'],
                    'status' => $result['internal_status'],
                    'synced_at' => now(),
                ]);

                Log::info('SyncShipmentStatusJob: Status updated', [
                    'shipment_id' => $shipmentId,
                    'old_status' => $oldStatus,
                    'new_status' => $result['internal_status'],
                    'external_status' => $result['external_status'],
                ]);

                // Отправляем событие о смене статуса
                event(new ShipmentStatusChanged($shipment, $oldStatus, $result['internal_status']));

                // Обновляем delivered_at если поставка доставлена
                if ($result['internal_status'] === Shipment::STATUS_DELIVERED && !$shipment->delivered_at) {
                    $shipment->update(['delivered_at' => now()]);
                }
            } else {
                // Обновляем только время синхронизации
                $shipment->update(['synced_at' => now()]);
            }

        } catch (\Exception $e) {
            Log::error('SyncShipmentStatusJob: Sync failed', [
                'shipment_id' => $shipmentId,
                'marketplace' => $shipment->marketplace,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Синхронизация статуса Ozon
     */
    private function syncOzonStatus(Shipment $shipment): ?array
    {
        $integration = $this->getIntegration($shipment);
        
        if (!$integration) {
            return null;
        }

        $marketplace = OzonMarketplace::fromIntegration($integration);
        $suppliesApi = $marketplace->supplies();

        $details = $suppliesApi->getSupplyDetails($shipment->external_supply_id);
        
        if (!$details) {
            return null;
        }

        $externalStatus = $details['status'] ?? $details['status_code'] ?? null;
        $internalStatus = $this->mapOzonStatusToInternal($externalStatus);

        return [
            'status_changed' => $shipment->status !== $internalStatus,
            'external_status' => $externalStatus,
            'internal_status' => $internalStatus,
            'details' => $details,
        ];
    }

    /**
     * Синхронизация статуса Wildberries
     */
    private function syncWildberriesStatus(Shipment $shipment): ?array
    {
        $integration = $this->getIntegration($shipment);
        
        if (!$integration) {
            return null;
        }

        $marketplace = WildberriesMarketplace::fromIntegration($integration);
        $suppliesApi = $marketplace->supplies();

        $details = $suppliesApi->getSupplyDetails($shipment->external_supply_id);
        
        if (!$details) {
            return null;
        }

        $externalStatus = $details['status'] ?? $details['status_code'] ?? null;
        $internalStatus = $this->mapWildberriesStatusToInternal($externalStatus);

        return [
            'status_changed' => $shipment->status !== $internalStatus,
            'external_status' => $externalStatus,
            'internal_status' => $internalStatus,
            'details' => $details,
        ];
    }

    /**
     * Маппинг статусов Ozon → внутренние
     */
    private function mapOzonStatusToInternal(string $ozonStatus): string
    {
        return match (strtoupper($ozonStatus)) {
            'DRAFT' => Shipment::STATUS_DRAFT,
            'AWAITING_CONFIRMATION' => Shipment::STATUS_PENDING_CONFIRMATION,
            'CONFIRMED' => Shipment::STATUS_CONFIRMED,
            'READY_TO_SUPPLY' => Shipment::STATUS_APPROVED,
            'IN_TRANSIT' => Shipment::STATUS_IN_TRANSIT,
            'AT_WAREHOUSE' => Shipment::STATUS_ARRIVED,
            'ACCEPTING' => Shipment::STATUS_PROCESSING,
            'ACCEPTANCE' => Shipment::STATUS_PROCESSING,
            'ACCEPTED' => Shipment::STATUS_DELIVERED,
            'PARTIALLY_ACCEPTED' => Shipment::STATUS_PARTIALLY_ACCEPTED,
            'CANCELLED' => Shipment::STATUS_CANCELLED,
            default => Shipment::STATUS_PENDING_LOGISTICS,
        };
    }

    /**
     * Маппинг статусов WB → внутренние
     */
    private function mapWildberriesStatusToInternal(string|int $wbStatus): string
    {
        // WB использует числовые коды
        $statusCode = is_numeric($wbStatus) ? (int) $wbStatus : $wbStatus;
        
        return match ($statusCode) {
            1, 'not_planned' => Shipment::STATUS_DRAFT,
            2, 'planned' => Shipment::STATUS_APPROVED,
            3, 'unloading_allowed' => Shipment::STATUS_SENT,
            4, 'accepting' => Shipment::STATUS_IN_TRANSIT,
            5, 'accepted' => Shipment::STATUS_DELIVERED,
            6, 'unloaded_at_gate' => Shipment::STATUS_IN_TRANSIT,
            default => Shipment::STATUS_PENDING_LOGISTICS,
        };
    }

    /**
     * Получить интеграцию для поставки
     */
    private function getIntegration(Shipment $shipment): ?Integration
    {
        if ($shipment->integration_id) {
            return Integration::find($shipment->integration_id);
        }

        return Integration::where('marketplace', $shipment->marketplace)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Определить очередь для job
     */
    public function queue(): string
    {
        return 'shipments';
    }

    /**
     * Теги для мониторинга
     */
    public function tags(): array
    {
        $tags = ['job:sync_shipment_status'];
        
        if ($this->shipmentId) {
            $tags[] = 'shipment:' . $this->shipmentId;
        }
        
        return $tags;
    }
}
