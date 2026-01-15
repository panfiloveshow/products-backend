<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    // Базовые статусы
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_LOGISTICS = 'pending_logistics';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SENT = 'sent';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_REJECTED = 'rejected';
    
    // Расширенные статусы (P2)
    public const STATUS_SUBMITTED = 'submitted';              // Отправлена в МП
    public const STATUS_PENDING_CONFIRMATION = 'pending_confirmation'; // Ждём подтверждения от МП
    public const STATUS_CONFIRMED = 'confirmed';              // МП подтвердил
    public const STATUS_ARRIVED = 'arrived';                  // Прибыл на склад
    public const STATUS_PROCESSING = 'processing';            // Идёт приёмка
    public const STATUS_PARTIALLY_ACCEPTED = 'partially_accepted'; // Частично принят
    public const STATUS_CANCELLED = 'cancelled';              // Отменён

    /**
     * Получить все возможные статусы
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_DRAFT => 'Черновик',
            self::STATUS_PENDING_LOGISTICS => 'Ожидает согласования',
            self::STATUS_SUBMITTED => 'Отправлена в МП',
            self::STATUS_PENDING_CONFIRMATION => 'Ожидает подтверждения МП',
            self::STATUS_CONFIRMED => 'Подтверждена МП',
            self::STATUS_APPROVED => 'Согласована',
            self::STATUS_SENT => 'Отправлена',
            self::STATUS_IN_TRANSIT => 'В пути',
            self::STATUS_ARRIVED => 'Прибыла на склад',
            self::STATUS_PROCESSING => 'Идёт приёмка',
            self::STATUS_DELIVERED => 'Доставлена',
            self::STATUS_PARTIALLY_ACCEPTED => 'Частично принята',
            self::STATUS_REJECTED => 'Отклонена',
            self::STATUS_CANCELLED => 'Отменена',
        ];
    }

    /**
     * Получить активные статусы (для синхронизации)
     */
    public static function getActiveStatuses(): array
    {
        return [
            self::STATUS_SUBMITTED,
            self::STATUS_PENDING_CONFIRMATION,
            self::STATUS_CONFIRMED,
            self::STATUS_APPROVED,
            self::STATUS_SENT,
            self::STATUS_IN_TRANSIT,
            self::STATUS_ARRIVED,
            self::STATUS_PROCESSING,
        ];
    }

    protected $fillable = [
        'supply_plan_id',
        'integration_id',
        'warehouse_id',
        'external_supply_id',
        'external_status',
        'name',
        'status',
        'marketplace',
        'shipment_type',
        'warehouse_name',
        'meta',
        'planned_date',
        'supplier_id',
        'supplier_name',
        'supplier_address',
        'slot',
        'marketplace_requirements',
        'packaging',
        'total_items',
        'total_quantity',
        'total_cost',
        'total_volume',
        'total_weight',
        'truck_type',
        'truck_capacity',
        'delivery_cost',
        'delivery_cost_percent',
        'utilization_percent',
        'logistics_approval',
        'created_by',
        'created_by_name',
        'sent_at',
        'delivered_at',
        'synced_at',
        'description',
    ];

    protected $casts = [
        'slot' => 'array',
        'meta' => 'array',
        'marketplace_requirements' => 'array',
        'packaging' => 'array',
        'logistics_approval' => 'array',
        'total_items' => 'integer',
        'total_quantity' => 'integer',
        'total_cost' => 'decimal:2',
        'total_volume' => 'decimal:3',
        'total_weight' => 'decimal:3',
        'truck_capacity' => 'decimal:2',
        'delivery_cost' => 'decimal:2',
        'delivery_cost_percent' => 'decimal:2',
        'utilization_percent' => 'decimal:2',
        'planned_date' => 'date',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function supplyPlan(): BelongsTo
    {
        return $this->belongsTo(SupplyPlan::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

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

    public function scopeSupplier($query, string $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
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

    public function recalculateTotals(): void
    {
        $items = $this->items;
        
        $this->update([
            'total_items' => $items->count(),
            'total_quantity' => $items->sum('quantity'),
            'total_cost' => $items->sum('total_cost'),
            'total_volume' => $items->sum('total_volume'),
            'total_weight' => $items->sum('total_weight'),
        ]);
    }

    public function canBeEdited(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REJECTED]);
    }

    public function canBeSubmitted(): bool
    {
        return $this->status === self::STATUS_DRAFT && $this->items()->count() > 0;
    }

    public function canBeApproved(): bool
    {
        return $this->status === self::STATUS_PENDING_LOGISTICS;
    }

    public function canBeSent(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }
}
