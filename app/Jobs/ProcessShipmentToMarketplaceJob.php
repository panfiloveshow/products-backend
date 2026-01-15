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
 * Job для отправки поставки в маркетплейс
 * 
 * Создаёт поставку на стороне маркетплейса после submit().
 * Поддерживает ретраи с экспоненциальным backoff.
 * 
 * Ozon: полная поддержка создания поставок через API
 * WB: не поддерживает создание через API (только чтение)
 */
class ProcessShipmentToMarketplaceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $maxExceptions = 3;
    public int $backoff = 60; // секунды

    public function __construct(
        private readonly string $shipmentId,
    ) {}

    /**
     * Рассчитать время до следующей попытки
     */
    public function backoff(): array
    {
        return [60, 300, 900]; // 1 мин, 5 мин, 15 мин
    }

    public function handle(): void
    {
        $shipment = Shipment::with('items')->find($this->shipmentId);
        
        if (!$shipment) {
            Log::warning('ProcessShipmentToMarketplaceJob: Shipment not found', [
                'shipment_id' => $this->shipmentId,
            ]);
            return;
        }

        // Проверяем статус — должен быть SUBMITTED или PENDING_LOGISTICS
        if (!in_array($shipment->status, [Shipment::STATUS_PENDING_LOGISTICS, Shipment::STATUS_SUBMITTED])) {
            Log::info('ProcessShipmentToMarketplaceJob: Shipment not in submittable status', [
                'shipment_id' => $this->shipmentId,
                'status' => $shipment->status,
            ]);
            return;
        }

        try {
            $result = match ($shipment->marketplace) {
                'ozon' => $this->processOzon($shipment),
                'wildberries' => $this->processWildberries($shipment),
                default => throw new \RuntimeException("Unsupported marketplace: {$shipment->marketplace}"),
            };

            if ($result['success']) {
                $oldStatus = $shipment->status;
                
                $shipment->update([
                    'external_supply_id' => $result['external_id'] ?? null,
                    'external_status' => $result['external_status'] ?? 'created',
                    'status' => Shipment::STATUS_SUBMITTED,
                    'synced_at' => now(),
                ]);

                // Сохраняем мета-данные
                $meta = $shipment->meta ?? [];
                $meta['submitted_to_marketplace_at'] = now()->toIso8601String();
                $meta['marketplace_response'] = $result['response'] ?? null;
                $shipment->update(['meta' => $meta]);

                Log::info('ProcessShipmentToMarketplaceJob: Successfully submitted to marketplace', [
                    'shipment_id' => $this->shipmentId,
                    'marketplace' => $shipment->marketplace,
                    'external_id' => $result['external_id'] ?? null,
                ]);

                // Отправляем событие о смене статуса
                if ($oldStatus !== $shipment->status) {
                    event(new ShipmentStatusChanged($shipment, $oldStatus, $shipment->status));
                }
            }

        } catch (\Exception $e) {
            Log::error('ProcessShipmentToMarketplaceJob: Failed to submit', [
                'shipment_id' => $this->shipmentId,
                'marketplace' => $shipment->marketplace,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // Если это последняя попытка — помечаем как ошибку
            if ($this->attempts() >= $this->tries) {
                $meta = $shipment->meta ?? [];
                $meta['last_error'] = $e->getMessage();
                $meta['last_error_at'] = now()->toIso8601String();
                $shipment->update(['meta' => $meta]);
            }

            throw $e; // Для ретрая
        }
    }

    /**
     * Обработка поставки для Ozon
     */
    private function processOzon(Shipment $shipment): array
    {
        // Получаем интеграцию для credentials
        $integration = $this->getIntegration($shipment);
        
        if (!$integration) {
            throw new \RuntimeException('Integration not found for Ozon shipment');
        }

        $marketplace = OzonMarketplace::fromIntegration($integration);
        $suppliesApi = $marketplace->supplies();

        // Проверяем поддержку создания поставок
        if (!$suppliesApi->supportsFeature('create_supply')) {
            throw new \RuntimeException('Ozon API does not support supply creation');
        }

        // Подготавливаем данные для создания
        $items = $shipment->items->map(fn($item) => [
            'offer_id' => $item->sku,
            'quantity' => $item->quantity,
        ])->toArray();

        // Создаём черновик поставки
        $draft = $suppliesApi->createSupplyDraft([
            'warehouse_id' => $shipment->warehouse_id ?? $this->getDefaultWarehouseId($integration),
            'items' => $items,
        ]);

        // Если есть забронированный слот — применяем его
        if (!empty($shipment->slot['id'])) {
            try {
                $suppliesApi->bookAcceptanceSlot($draft['id'], $shipment->slot['id']);
            } catch (\Exception $e) {
                Log::warning('Failed to book slot for Ozon supply', [
                    'supply_id' => $draft['id'],
                    'slot_id' => $shipment->slot['id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'success' => true,
            'external_id' => $draft['id'],
            'external_status' => $draft['status'] ?? 'draft',
            'response' => $draft,
        ];
    }

    /**
     * Обработка поставки для Wildberries
     * 
     * WB не поддерживает создание поставок через API.
     * Возвращаем успех, но без external_id.
     */
    private function processWildberries(Shipment $shipment): array
    {
        Log::info('ProcessShipmentToMarketplaceJob: WB does not support API supply creation', [
            'shipment_id' => $this->shipmentId,
        ]);

        // WB не поддерживает создание через API
        // Поставка должна быть создана в ЛК WB вручную
        return [
            'success' => true,
            'external_id' => null,
            'external_status' => 'manual_required',
            'response' => [
                'message' => 'Wildberries не поддерживает создание поставок через API. Создайте поставку в личном кабинете WB.',
            ],
        ];
    }

    /**
     * Получить интеграцию для поставки
     */
    private function getIntegration(Shipment $shipment): ?Integration
    {
        // Если есть integration_id в поставке
        if ($shipment->integration_id) {
            return Integration::find($shipment->integration_id);
        }

        // Иначе ищем по маркетплейсу
        return Integration::where('marketplace', $shipment->marketplace)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Получить ID склада по умолчанию
     */
    private function getDefaultWarehouseId(Integration $integration): ?int
    {
        $marketplace = OzonMarketplace::fromIntegration($integration);
        $warehouses = $marketplace->supplies()->getAvailableWarehouses();
        
        return !empty($warehouses) ? (int) $warehouses[0]['id'] : null;
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
        return [
            'shipment:' . $this->shipmentId,
            'job:process_shipment',
        ];
    }
}
