<?php

namespace App\Events;

use App\Models\Shipment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Событие изменения статуса поставки
 * 
 * Используется для:
 * - Отправки уведомлений (email, Telegram, webhook)
 * - Логирования изменений
 * - Триггера дополнительных действий
 */
class ShipmentStatusChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Shipment $shipment,
        public readonly string $oldStatus,
        public readonly string $newStatus,
    ) {}

    /**
     * Проверить, является ли новый статус критическим
     */
    public function isCritical(): bool
    {
        return in_array($this->newStatus, [
            Shipment::STATUS_REJECTED,
        ]);
    }

    /**
     * Проверить, является ли это завершением поставки
     */
    public function isCompleted(): bool
    {
        return $this->newStatus === Shipment::STATUS_DELIVERED;
    }

    /**
     * Получить описание изменения
     */
    public function getDescription(): string
    {
        $statusLabels = [
            Shipment::STATUS_DRAFT => 'Черновик',
            Shipment::STATUS_PENDING_LOGISTICS => 'Ожидает согласования',
            Shipment::STATUS_APPROVED => 'Согласована',
            Shipment::STATUS_REJECTED => 'Отклонена',
            Shipment::STATUS_SENT => 'Отправлена',
            Shipment::STATUS_IN_TRANSIT => 'В пути',
            Shipment::STATUS_DELIVERED => 'Доставлена',
        ];

        $oldLabel = $statusLabels[$this->oldStatus] ?? $this->oldStatus;
        $newLabel = $statusLabels[$this->newStatus] ?? $this->newStatus;

        return "Статус поставки изменён: {$oldLabel} → {$newLabel}";
    }
}
