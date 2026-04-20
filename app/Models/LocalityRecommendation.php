<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LocalityRecommendation extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATE_NEW = 'new';
    public const STATE_DISMISSED = 'dismissed';
    public const STATE_APPLIED = 'applied';
    public const STATE_SUPERSEDED = 'superseded_by_supply';
    public const STATE_STALE = 'stale';
    public const STATE_EXPIRED = 'expired';

    public const CONFIDENCE_LOW = 'low';
    public const CONFIDENCE_MEDIUM = 'medium';
    public const CONFIDENCE_HIGH = 'high';

    protected $table = 'locality_recommendations';

    protected $fillable = [
        'integration_id',
        'sku',
        'offer_id',
        'product_id',
        'target_cluster_id',
        'target_cluster_name',
        'recommended_qty_units',
        'pack_multiple',
        'current_stock_cluster',
        'in_transit_cluster',
        'daily_demand_cluster',
        'volatility_cluster',
        'gap_units',
        'expected_savings_rub',
        'expected_monthly_savings_rub',
        'expected_local_share_uplift_pp',
        'expected_days_of_cover',
        'avg_markup_amount_rub',
        'avg_base_logistics_rub',
        'confidence',
        'confidence_score',
        'rank_score',
        'rank_position',
        'reasoning_text',
        'warnings',
        'constraints_checked',
        'state',
        'dismissed_at',
        'dismissed_by',
        'dismiss_reason',
        'applied_at',
        'applied_by',
        'linked_supply_order_id',
        'linked_draft_id',
        'cohort_id',
        'computed_at',
        'period_from',
        'period_to',
        'basis_snapshot_date',
        'lead_time_days',
        'expires_at',
    ];

    protected $casts = [
        'recommended_qty_units' => 'integer',
        'pack_multiple' => 'integer',
        'current_stock_cluster' => 'integer',
        'in_transit_cluster' => 'integer',
        'daily_demand_cluster' => 'decimal:3',
        'volatility_cluster' => 'decimal:4',
        'gap_units' => 'integer',
        'expected_savings_rub' => 'decimal:2',
        'expected_monthly_savings_rub' => 'decimal:2',
        'expected_local_share_uplift_pp' => 'decimal:2',
        'expected_days_of_cover' => 'decimal:2',
        'avg_markup_amount_rub' => 'decimal:2',
        'avg_base_logistics_rub' => 'decimal:2',
        'confidence_score' => 'decimal:2',
        'rank_score' => 'decimal:2',
        'rank_position' => 'integer',
        'warnings' => 'array',
        'constraints_checked' => 'array',
        'dismissed_at' => 'datetime',
        'applied_at' => 'datetime',
        'computed_at' => 'datetime',
        'period_from' => 'date',
        'period_to' => 'date',
        'basis_snapshot_date' => 'date',
        'expires_at' => 'date',
        'lead_time_days' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class, 'integration_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('state', self::STATE_NEW);
    }

    public function scopeForIntegration(Builder $query, int $integrationId): Builder
    {
        return $query->where('integration_id', $integrationId);
    }

    public function scopeForCluster(Builder $query, string $clusterId): Builder
    {
        return $query->where('target_cluster_id', $clusterId);
    }

    public function scopeTopN(Builder $query, int $n): Builder
    {
        return $query->orderByDesc('rank_score')->limit($n);
    }
}
