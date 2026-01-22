<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Модель рекомендации на поставку
 * 
 * Автоматически рассчитанные рекомендации по пополнению запасов
 */
class SupplyRecommendation extends Model
{
    use HasFactory, SoftDeletes;

    public const STATE_NEW = 'new';
    public const STATE_ACCEPTED = 'accepted';
    public const STATE_REJECTED = 'rejected';
    public const STATE_POSTPONED = 'postponed';
    public const STATE_IN_PLAN = 'in_plan';
    public const STATE_IN_SUPPLY = 'in_supply';
    public const STATE_COMPLETED = 'completed';
    public const STATE_EXPIRED = 'expired';

    public const PRIORITY_A = 'A';
    public const PRIORITY_B = 'B';
    public const PRIORITY_C = 'C';

    protected $fillable = [
        'integration_id',
        'marketplace',
        'product_id',
        'sku',
        'ozon_product_id',
        'product_name',
        'cluster_id',
        'cluster_name',
        'warehouse_id',
        'warehouse_name',
        'title',
        'avg_sales_7d',
        'avg_sales_14d',
        'avg_sales_28d',
        'avg_sales_used',
        'sales_window',
        'current_stock',
        'in_transit',
        'safety_stock',
        'target_days',
        'demand',
        'need_raw',
        'recommended_qty',
        'pack_multiple',
        'min_order_qty',
        'priority',
        'priority_score',
        'days_of_stock',
        'oos_risk',
        'overstock_risk',
        'reasons',
        'warnings',
        'restrictions',
        'recommended_create_date',
        'recommended_delivery_date',
        'lead_time_days',
        'state',
        'user_qty',
        'user_comment',
        'processed_by',
        'processed_at',
        'supply_plan_id',
        'supply_id',
    ];

    protected $casts = [
        'avg_sales_7d' => 'decimal:4',
        'avg_sales_14d' => 'decimal:4',
        'avg_sales_28d' => 'decimal:4',
        'avg_sales_used' => 'decimal:4',
        'current_stock' => 'integer',
        'in_transit' => 'integer',
        'safety_stock' => 'integer',
        'target_days' => 'integer',
        'demand' => 'integer',
        'need_raw' => 'integer',
        'recommended_qty' => 'integer',
        'pack_multiple' => 'integer',
        'min_order_qty' => 'integer',
        'priority_score' => 'decimal:2',
        'days_of_stock' => 'integer',
        'oos_risk' => 'boolean',
        'overstock_risk' => 'boolean',
        'reasons' => 'array',
        'warnings' => 'array',
        'restrictions' => 'array',
        'recommended_create_date' => 'date',
        'recommended_delivery_date' => 'date',
        'lead_time_days' => 'integer',
        'user_qty' => 'integer',
        'processed_at' => 'datetime',
    ];

    // === Relationships ===

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplyPlan(): BelongsTo
    {
        return $this->belongsTo(SupplyPlan::class);
    }

    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    // === Scopes ===

    public function scopeNew($query)
    {
        return $query->where('state', self::STATE_NEW);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('state', [self::STATE_NEW, self::STATE_ACCEPTED, self::STATE_POSTPONED]);
    }

    public function scopeOosRisk($query)
    {
        return $query->where('oos_risk', true);
    }

    public function scopePriorityA($query)
    {
        return $query->where('priority', self::PRIORITY_A);
    }

    public function scopeForCluster($query, string $clusterId)
    {
        return $query->where('cluster_id', $clusterId);
    }

    public function scopeForWarehouse($query, string $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    // === Actions ===

    public function accept(?int $userQty = null, ?int $userId = null): void
    {
        $this->update([
            'state' => self::STATE_ACCEPTED,
            'user_qty' => $userQty,
            'processed_by' => $userId,
            'processed_at' => now(),
        ]);
    }

    public function reject(?string $comment = null, ?int $userId = null): void
    {
        $this->update([
            'state' => self::STATE_REJECTED,
            'user_comment' => $comment,
            'processed_by' => $userId,
            'processed_at' => now(),
        ]);
    }

    public function postpone(?string $comment = null, ?int $userId = null): void
    {
        $this->update([
            'state' => self::STATE_POSTPONED,
            'user_comment' => $comment,
            'processed_by' => $userId,
            'processed_at' => now(),
        ]);
    }

    public function addToPlan(int $planId): void
    {
        $this->update([
            'state' => self::STATE_IN_PLAN,
            'supply_plan_id' => $planId,
        ]);
    }

    public function addToSupply(int $supplyId): void
    {
        $this->update([
            'state' => self::STATE_IN_SUPPLY,
            'supply_id' => $supplyId,
        ]);
    }

    // === Accessors ===

    public function getFinalQtyAttribute(): int
    {
        return $this->user_qty ?? $this->recommended_qty;
    }

    public function getIsUrgentAttribute(): bool
    {
        return $this->oos_risk && $this->days_of_stock <= 3;
    }
}
