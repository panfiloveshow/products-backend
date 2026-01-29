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
        'price',
        'cost_price',
        'margin_percent',
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
        'sales_trend',
        'sales_trend_percent',
        'current_stock',
        'in_transit',
        'safety_stock',
        'sales_volatility',
        'safety_stock_dynamic',
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
        'oos_date',
        'days_until_oos',
        'overstock_risk',
        'lost_revenue_daily',
        'lost_revenue_potential',
        'supply_cost_estimate',
        'expected_revenue',
        'expected_profit',
        'roi_percent',
        'redemption_rate',
        'turnover_days_ue',
        'localization_index',
        'storage_cost',
        'delivery_cost',
        'drr_percent',
        // Данные Ozon аналитики
        'ozon_recommended_supply',
        'ozon_lost_profit',
        'ozon_avg_delivery_time',
        'ozon_attention_level',
        'ozon_impact_share',
        'delivery_cluster_id',
        'delivery_cluster_name',
        'ozon_clusters_data',
        'reasons',
        'explanations',
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
        'price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'margin_percent' => 'decimal:2',
        'avg_sales_7d' => 'decimal:4',
        'avg_sales_14d' => 'decimal:4',
        'avg_sales_28d' => 'decimal:4',
        'avg_sales_used' => 'decimal:4',
        'sales_trend_percent' => 'decimal:2',
        'current_stock' => 'integer',
        'in_transit' => 'integer',
        'safety_stock' => 'integer',
        'sales_volatility' => 'decimal:4',
        'safety_stock_dynamic' => 'integer',
        'target_days' => 'integer',
        'demand' => 'integer',
        'need_raw' => 'integer',
        'recommended_qty' => 'integer',
        'pack_multiple' => 'integer',
        'min_order_qty' => 'integer',
        'priority_score' => 'decimal:2',
        'days_of_stock' => 'integer',
        'days_until_oos' => 'integer',
        'oos_risk' => 'boolean',
        'oos_date' => 'date',
        'overstock_risk' => 'boolean',
        'lost_revenue_daily' => 'decimal:2',
        'lost_revenue_potential' => 'decimal:2',
        'supply_cost_estimate' => 'decimal:2',
        'expected_revenue' => 'decimal:2',
        'expected_profit' => 'decimal:2',
        'roi_percent' => 'decimal:2',
        'redemption_rate' => 'decimal:2',
        'turnover_days_ue' => 'decimal:2',
        'localization_index' => 'decimal:2',
        'storage_cost' => 'decimal:2',
        'delivery_cost' => 'decimal:2',
        'drr_percent' => 'decimal:2',
        // Данные Ozon аналитики
        'ozon_recommended_supply' => 'integer',
        'ozon_lost_profit' => 'decimal:2',
        'ozon_avg_delivery_time' => 'integer',
        'ozon_impact_share' => 'decimal:4',
        'delivery_cluster_id' => 'integer',
        'ozon_clusters_data' => 'array',
        'reasons' => 'array',
        'explanations' => 'array',
        'warnings' => 'array',
        'restrictions' => 'array',
        'recommended_create_date' => 'date',
        'recommended_delivery_date' => 'date',
        'lead_time_days' => 'integer',
        'user_qty' => 'integer',
        'processed_at' => 'datetime',
    ];

    protected $appends = [
        'avg_daily_sales',
        'recommended_quantity',
    ];

    // === Accessors ===

    /**
     * Возвращает название кластера
     * Приоритет: delivery_cluster_name (из маппинга Ozon) → cluster_name (старое поле)
     */
    public function getClusterNameAttribute($value): ?string
    {
        return $value ?? $this->attributes['delivery_cluster_name'] ?? null;
    }

    /**
     * Алиас для avg_sales_used → avg_daily_sales (совместимость с фронтендом)
     */
    public function getAvgDailySalesAttribute(): ?float
    {
        return $this->avg_sales_used;
    }

    /**
     * Алиас для recommended_qty → recommended_quantity (совместимость с фронтендом)
     */
    public function getRecommendedQuantityAttribute(): ?int
    {
        return $this->recommended_qty;
    }

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

    public function scopePriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeMarketplace($query, string $marketplace)
    {
        return $query->where('marketplace', $marketplace);
    }

    public function scopeWithDeadline($query)
    {
        return $query->whereNotNull('recommended_delivery_date');
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
