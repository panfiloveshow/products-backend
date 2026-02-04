<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Модель поставки Ozon FBO
 * 
 * Основная сущность для отслеживания жизненного цикла поставки
 */
class Supply extends Model
{
    use HasFactory, SoftDeletes;

    // Статусы поставки
    public const STATUS_DRAFT = 'draft';
    public const STATUS_DRAFT_OZON = 'draft_ozon';
    public const STATUS_SLOT_PENDING = 'slot_pending';
    public const STATUS_SLOT_BOOKED = 'slot_booked';
    public const STATUS_PREPARING = 'preparing';
    public const STATUS_READY_TO_SHIP = 'ready_to_ship';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_AT_WAREHOUSE = 'at_warehouse';
    public const STATUS_ACCEPTED_PARTIAL = 'accepted_partial';
    public const STATUS_ACCEPTED_FULL = 'accepted_full';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_ERROR = 'error';

    // Типы поставки
    public const TYPE_FBO = 'fbo';
    public const TYPE_FBS = 'fbs';
    public const TYPE_REALFBS = 'realfbs';

    // Методы поставки
    public const METHOD_DIRECT = 'direct';
    public const METHOD_CROSSDOCK = 'crossdock';
    public const METHOD_MULTI_CLUSTER = 'multi_cluster';

    // Схемы доставки
    public const SCHEME_DROP_OFF = 'drop_off';
    public const SCHEME_PICK_UP = 'pick_up';

    protected $fillable = [
        'integration_id',
        'crm_number',
        'ozon_supply_id',
        'ozon_draft_id',
        'supply_type',
        'supply_method',
        'delivery_scheme',
        'cluster_id',
        'cluster_name',
        'warehouse_id',
        'warehouse_name',
        'drop_off_point_id',
        'drop_off_point_type',
        'seller_warehouse_id',
        'timeslot_id',
        'timeslot_from',
        'timeslot_to',
        'planned_delivery_date',
        'items_count',
        'total_quantity',
        'total_boxes',
        'total_weight',
        'total_volume',
        'status',
        'ozon_status',
        'ozon_status_description',
        'created_in_ozon_at',
        'slot_booked_at',
        'preparing_started_at',
        'ready_to_ship_at',
        'shipped_at',
        'arrived_at',
        'accepted_at',
        'closed_at',
        'accepted_quantity',
        'rejected_quantity',
        'acceptance_discrepancies',
        'created_by',
        'responsible_id',
        'supply_plan_id',
        'comment',
        'meta',
        'ozon_response',
    ];

    protected $casts = [
        'timeslot_from' => 'datetime',
        'timeslot_to' => 'datetime',
        'planned_delivery_date' => 'date',
        'items_count' => 'integer',
        'total_quantity' => 'integer',
        'total_boxes' => 'integer',
        'total_weight' => 'decimal:3',
        'total_volume' => 'decimal:6',
        'created_in_ozon_at' => 'datetime',
        'slot_booked_at' => 'datetime',
        'preparing_started_at' => 'datetime',
        'ready_to_ship_at' => 'datetime',
        'shipped_at' => 'datetime',
        'arrived_at' => 'datetime',
        'accepted_at' => 'datetime',
        'closed_at' => 'datetime',
        'accepted_quantity' => 'integer',
        'rejected_quantity' => 'integer',
        'acceptance_discrepancies' => 'array',
        'meta' => 'array',
        'ozon_response' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($supply) {
            if (empty($supply->crm_number)) {
                $supply->crm_number = self::generateCrmNumber();
            }
        });
    }

    public static function generateCrmNumber(): string
    {
        $prefix = 'SUP';
        $date = now()->format('ymd');
        $random = strtoupper(substr(uniqid(), -4));
        return "{$prefix}-{$date}-{$random}";
    }

    // === Relationships ===

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplyItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SupplyEvent::class)->orderByDesc('created_at');
    }

    public function supplyPlan(): BelongsTo
    {
        return $this->belongsTo(SupplyPlan::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(SupplyRecommendation::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(SupplyPackage::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(SupplyDocument::class);
    }

    // === Scopes ===

    public function scopeStatus($query, $status)
    {
        if (is_array($status)) {
            return $query->whereIn('status', $status);
        }
        return $query->where('status', $status);
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [
            self::STATUS_CLOSED,
            self::STATUS_CANCELLED,
        ]);
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', [
            self::STATUS_DRAFT,
            self::STATUS_DRAFT_OZON,
            self::STATUS_SLOT_PENDING,
        ]);
    }

    public function scopeInProgress($query)
    {
        return $query->whereIn('status', [
            self::STATUS_SLOT_BOOKED,
            self::STATUS_PREPARING,
            self::STATUS_READY_TO_SHIP,
            self::STATUS_SHIPPED,
            self::STATUS_IN_TRANSIT,
            self::STATUS_AT_WAREHOUSE,
        ]);
    }

    public function scopeWithErrors($query)
    {
        return $query->where('status', self::STATUS_ERROR);
    }

    public function scopeForCluster($query, string $clusterId)
    {
        return $query->where('cluster_id', $clusterId);
    }

    public function scopeForWarehouse($query, string $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    public function scopePlannedFor($query, $date)
    {
        return $query->whereDate('planned_delivery_date', $date);
    }

    // === Status transitions ===

    public function updateStatus(string $newStatus, ?array $eventData = null): void
    {
        $oldStatus = $this->status;
        
        $this->update(['status' => $newStatus]);
        
        // Обновляем временные метки
        $this->updateStatusTimestamp($newStatus);
        
        // Логируем событие
        $this->logEvent('status_changed', [
            'old_value' => $oldStatus,
            'new_value' => $newStatus,
            ...$eventData ?? [],
        ]);
    }

    protected function updateStatusTimestamp(string $status): void
    {
        $field = match ($status) {
            self::STATUS_DRAFT_OZON => 'created_in_ozon_at',
            self::STATUS_SLOT_BOOKED => 'slot_booked_at',
            self::STATUS_PREPARING => 'preparing_started_at',
            self::STATUS_READY_TO_SHIP => 'ready_to_ship_at',
            self::STATUS_SHIPPED => 'shipped_at',
            self::STATUS_AT_WAREHOUSE => 'arrived_at',
            self::STATUS_ACCEPTED_PARTIAL, self::STATUS_ACCEPTED_FULL => 'accepted_at',
            self::STATUS_CLOSED, self::STATUS_CANCELLED => 'closed_at',
            default => null,
        };

        if ($field && !$this->$field) {
            $this->update([$field => now()]);
        }
    }

    // === Event logging ===

    public function logEvent(string $type, array $data = []): SupplyEvent
    {
        return $this->events()->create([
            'event_type' => $type,
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'old_value' => $data['old_value'] ?? null,
            'new_value' => $data['new_value'] ?? null,
            'changes' => $data['changes'] ?? null,
            'api_method' => $data['api_method'] ?? null,
            'api_endpoint' => $data['api_endpoint'] ?? null,
            'api_request_body' => $data['api_request_body'] ?? null,
            'api_response_body' => $data['api_response_body'] ?? null,
            'api_response_code' => $data['api_response_code'] ?? null,
            'api_duration_ms' => $data['api_duration_ms'] ?? null,
            'error_code' => $data['error_code'] ?? null,
            'error_message' => $data['error_message'] ?? null,
            'error_context' => $data['error_context'] ?? null,
            'is_critical' => $data['is_critical'] ?? false,
            'initiated_by' => $data['initiated_by'] ?? 'system',
            'user_id' => $data['user_id'] ?? null,
        ]);
    }

    // === Helpers ===

    public function recalculateTotals(): void
    {
        $this->update([
            'items_count' => $this->items()->count(),
            'total_quantity' => $this->items()->sum('planned_qty'),
            'total_boxes' => $this->items()->sum('boxes_count'),
            'total_weight' => $this->items()->sum(\DB::raw('weight * planned_qty')),
        ]);
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Черновик',
            self::STATUS_DRAFT_OZON => 'Черновик в Ozon',
            self::STATUS_SLOT_PENDING => 'Ожидает слот',
            self::STATUS_SLOT_BOOKED => 'Слот забронирован',
            self::STATUS_PREPARING => 'Сборка',
            self::STATUS_READY_TO_SHIP => 'Готово к отгрузке',
            self::STATUS_SHIPPED => 'Отгружено',
            self::STATUS_IN_TRANSIT => 'В пути',
            self::STATUS_AT_WAREHOUSE => 'На приёмке',
            self::STATUS_ACCEPTED_PARTIAL => 'Принято частично',
            self::STATUS_ACCEPTED_FULL => 'Принято полностью',
            self::STATUS_CLOSED => 'Закрыто',
            self::STATUS_CANCELLED => 'Отменено',
            self::STATUS_ERROR => 'Ошибка',
            default => $this->status,
        };
    }

    public function getIsEditableAttribute(): bool
    {
        return in_array($this->status, [
            self::STATUS_DRAFT,
            self::STATUS_DRAFT_OZON,
            self::STATUS_SLOT_PENDING,
        ]);
    }

    public function getCanBookSlotAttribute(): bool
    {
        return in_array($this->status, [
            self::STATUS_DRAFT_OZON,
            self::STATUS_SLOT_PENDING,
        ]);
    }

    public function getAcceptanceRateAttribute(): ?float
    {
        if (!$this->accepted_quantity && !$this->rejected_quantity) {
            return null;
        }
        
        $total = $this->accepted_quantity + $this->rejected_quantity;
        return $total > 0 ? round(($this->accepted_quantity / $total) * 100, 2) : null;
    }
}
