<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Posting extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    // Статусы FBS отправлений
    public const STATUS_AWAITING_PACKAGING = 'awaiting_packaging';
    public const STATUS_AWAITING_DELIVER = 'awaiting_deliver';
    public const STATUS_DELIVERING = 'delivering';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_ARBITRATION = 'arbitration';
    public const STATUS_NOT_ACCEPTED = 'not_accepted';
    
    // Дополнительные статусы Ozon
    public const STATUS_ACCEPTANCE_IN_PROGRESS = 'acceptance_in_progress';
    public const STATUS_AWAITING_REGISTRATION = 'awaiting_registration';
    public const STATUS_DRIVER_PICKUP = 'driver_pickup';
    public const STATUS_SENT_BY_SELLER = 'sent_by_seller';

    /**
     * Получить все возможные статусы с метками
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_AWAITING_PACKAGING => [
                'label' => 'Ожидает упаковки',
                'color' => 'yellow',
            ],
            self::STATUS_AWAITING_DELIVER => [
                'label' => 'Ожидает отгрузки',
                'color' => 'blue',
            ],
            self::STATUS_DELIVERING => [
                'label' => 'Доставляется',
                'color' => 'indigo',
            ],
            self::STATUS_DELIVERED => [
                'label' => 'Доставлен',
                'color' => 'green',
            ],
            self::STATUS_CANCELLED => [
                'label' => 'Отменён',
                'color' => 'red',
            ],
            self::STATUS_ARBITRATION => [
                'label' => 'Арбитраж',
                'color' => 'orange',
            ],
            self::STATUS_NOT_ACCEPTED => [
                'label' => 'Не принят',
                'color' => 'gray',
            ],
            self::STATUS_ACCEPTANCE_IN_PROGRESS => [
                'label' => 'Идёт приёмка',
                'color' => 'purple',
            ],
            self::STATUS_AWAITING_REGISTRATION => [
                'label' => 'Ожидает регистрации',
                'color' => 'cyan',
            ],
            self::STATUS_DRIVER_PICKUP => [
                'label' => 'Ожидает курьера',
                'color' => 'teal',
            ],
            self::STATUS_SENT_BY_SELLER => [
                'label' => 'Отправлен продавцом',
                'color' => 'lime',
            ],
        ];
    }

    /**
     * Статусы, требующие действий продавца
     */
    public static function getActionableStatuses(): array
    {
        return [
            self::STATUS_AWAITING_PACKAGING,
            self::STATUS_AWAITING_DELIVER,
        ];
    }

    protected $fillable = [
        'integration_id',
        'marketplace',
        'posting_number',
        'order_id',
        'order_number',
        'status',
        'substatus',
        'external_status',
        'shipment_date',
        'delivering_date',
        'in_process_at',
        'packed_at',
        'shipped_at',
        'delivered_at',
        'cancelled_at',
        'warehouse_id',
        'warehouse_name',
        'delivery_method',
        'delivery_type',
        'tpl_integration_type',
        'customer',
        'total_price',
        'products_total',
        'commission',
        'delivery_cost',
        'payout',
        'financial_data',
        'items_count',
        'total_quantity',
        'cancel_reason_id',
        'cancel_reason_message',
        'meta',
        'analytics_data',
        'barcodes',
        'synced_at',
        'last_status_change_at',
    ];

    protected $casts = [
        'customer' => 'array',
        'financial_data' => 'array',
        'meta' => 'array',
        'analytics_data' => 'array',
        'barcodes' => 'array',
        'total_price' => 'decimal:2',
        'products_total' => 'decimal:2',
        'commission' => 'decimal:2',
        'delivery_cost' => 'decimal:2',
        'payout' => 'decimal:2',
        'items_count' => 'integer',
        'total_quantity' => 'integer',
        'cancel_reason_id' => 'integer',
        'shipment_date' => 'datetime',
        'delivering_date' => 'datetime',
        'in_process_at' => 'datetime',
        'packed_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'synced_at' => 'datetime',
        'last_status_change_at' => 'datetime',
    ];

    protected $appends = ['status_label', 'status_color', 'actions'];

    public function items(): HasMany
    {
        return $this->hasMany(PostingItem::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    // Accessors
    public function getStatusLabelAttribute(): string
    {
        return self::getStatuses()[$this->status]['label'] ?? $this->status;
    }

    public function getStatusColorAttribute(): string
    {
        return self::getStatuses()[$this->status]['color'] ?? 'gray';
    }

    public function getActionsAttribute(): array
    {
        return [
            'can_assemble' => $this->canAssemble(),
            'can_pack' => $this->canPack(),
            'can_ship' => $this->canShip(),
            'can_cancel' => $this->canCancel(),
        ];
    }

    // Action checks
    public function canAssemble(): bool
    {
        return $this->status === self::STATUS_AWAITING_PACKAGING;
    }

    public function canPack(): bool
    {
        return in_array($this->status, [
            self::STATUS_AWAITING_PACKAGING,
            self::STATUS_ACCEPTANCE_IN_PROGRESS,
        ]);
    }

    public function canShip(): bool
    {
        return $this->status === self::STATUS_AWAITING_DELIVER;
    }

    public function canCancel(): bool
    {
        return in_array($this->status, [
            self::STATUS_AWAITING_PACKAGING,
            self::STATUS_AWAITING_DELIVER,
        ]);
    }

    // Scopes
    public function scopeStatus($query, $status)
    {
        if (is_array($status)) {
            return $query->whereIn('status', $status);
        }
        return $query->where('status', $status);
    }

    public function scopeMarketplace($query, string $marketplace)
    {
        return $query->where('marketplace', $marketplace);
    }

    public function scopeIntegration($query, $integrationId)
    {
        return $query->where('integration_id', $integrationId);
    }

    public function scopeActionable($query)
    {
        return $query->whereIn('status', self::getActionableStatuses());
    }

    public function scopeToShipToday($query)
    {
        return $query->whereDate('shipment_date', today())
            ->whereIn('status', [self::STATUS_AWAITING_PACKAGING, self::STATUS_AWAITING_DELIVER]);
    }

    public function scopeOverdue($query)
    {
        return $query->where('shipment_date', '<', now())
            ->whereIn('status', [self::STATUS_AWAITING_PACKAGING, self::STATUS_AWAITING_DELIVER]);
    }

    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('posting_number', 'like', "%{$search}%")
              ->orWhere('order_number', 'like', "%{$search}%");
        });
    }

    public function scopeDateRange($query, ?string $from, ?string $to)
    {
        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }
        return $query;
    }

    // Methods
    public function recalculateTotals(): void
    {
        $items = $this->items;
        
        $this->update([
            'items_count' => $items->count(),
            'total_quantity' => $items->sum('quantity'),
            'products_total' => $items->sum('total_price'),
        ]);
    }

    public function markAsAssembled(): void
    {
        $this->update([
            'in_process_at' => now(),
        ]);
        
        $this->items()->update(['is_assembled' => true]);
    }

    public function markAsPacked(): void
    {
        $this->update([
            'status' => self::STATUS_AWAITING_DELIVER,
            'packed_at' => now(),
            'last_status_change_at' => now(),
        ]);
        
        $this->items()->update(['is_packed' => true]);
    }

    public function markAsShipped(): void
    {
        $this->update([
            'status' => self::STATUS_DELIVERING,
            'shipped_at' => now(),
            'last_status_change_at' => now(),
        ]);
    }

    public function markAsCancelled(int $reasonId, ?string $message = null): void
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancel_reason_id' => $reasonId,
            'cancel_reason_message' => $message,
            'last_status_change_at' => now(),
        ]);
    }
}
