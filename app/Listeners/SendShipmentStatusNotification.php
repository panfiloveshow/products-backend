<?php

namespace App\Listeners;

use App\Events\ShipmentStatusChanged;
use App\Models\Shipment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Слушатель для отправки уведомлений о смене статуса поставки
 * 
 * Отправляет уведомления через:
 * - Логирование (всегда)
 * - Email (для критических статусов)
 * - Telegram (опционально)
 * - Webhook (опционально)
 */
class SendShipmentStatusNotification implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(ShipmentStatusChanged $event): void
    {
        $shipment = $event->shipment;

        // Всегда логируем изменение статуса
        Log::info('Shipment status changed', [
            'shipment_id' => $shipment->id,
            'shipment_name' => $shipment->name,
            'marketplace' => $shipment->marketplace,
            'old_status' => $event->oldStatus,
            'new_status' => $event->newStatus,
            'description' => $event->getDescription(),
        ]);

        // Критические уведомления для отклонённых поставок
        if ($event->isCritical()) {
            $this->sendCriticalNotification($event);
        }

        // Уведомление о завершении поставки
        if ($event->isCompleted()) {
            $this->sendCompletionNotification($event);
        }

        // Webhook уведомления (если настроены)
        $this->sendWebhookNotification($event);
    }

    /**
     * Отправка критического уведомления
     */
    private function sendCriticalNotification(ShipmentStatusChanged $event): void
    {
        $shipment = $event->shipment;

        Log::warning('CRITICAL: Shipment rejected', [
            'shipment_id' => $shipment->id,
            'shipment_name' => $shipment->name,
            'marketplace' => $shipment->marketplace,
            'rejection_reason' => $shipment->logistics_approval['comment'] ?? 'Не указана',
        ]);

        // TODO: Отправка email уведомления
        // Notification::send($users, new ShipmentRejectedNotification($shipment));

        // TODO: Отправка в Telegram
        // TelegramService::sendAlert("🚨 Поставка отклонена: {$shipment->name}");
    }

    /**
     * Отправка уведомления о завершении
     */
    private function sendCompletionNotification(ShipmentStatusChanged $event): void
    {
        $shipment = $event->shipment;

        Log::info('Shipment delivered successfully', [
            'shipment_id' => $shipment->id,
            'shipment_name' => $shipment->name,
            'marketplace' => $shipment->marketplace,
            'delivered_at' => $shipment->delivered_at?->toIso8601String(),
            'total_items' => $shipment->total_items,
            'total_quantity' => $shipment->total_quantity,
        ]);

        // TODO: Отправка email уведомления о доставке
        // Notification::send($users, new ShipmentDeliveredNotification($shipment));
    }

    /**
     * Отправка webhook уведомления
     */
    private function sendWebhookNotification(ShipmentStatusChanged $event): void
    {
        $webhookUrl = config('services.shipments.webhook_url');
        
        if (!$webhookUrl) {
            return;
        }

        $shipment = $event->shipment;

        try {
            $payload = [
                'event' => 'shipment.status_changed',
                'timestamp' => now()->toIso8601String(),
                'data' => [
                    'shipment_id' => $shipment->id,
                    'shipment_name' => $shipment->name,
                    'marketplace' => $shipment->marketplace,
                    'old_status' => $event->oldStatus,
                    'new_status' => $event->newStatus,
                    'external_supply_id' => $shipment->external_supply_id,
                ],
            ];

            \Illuminate\Support\Facades\Http::timeout(10)
                ->post($webhookUrl, $payload);

        } catch (\Exception $e) {
            Log::error('Failed to send webhook notification', [
                'shipment_id' => $shipment->id,
                'webhook_url' => $webhookUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
