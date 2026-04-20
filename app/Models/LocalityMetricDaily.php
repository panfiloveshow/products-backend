<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LocalityMetricDaily extends Model
{
    use HasFactory;

    public const CONFIDENCE_LOW = 'low';
    public const CONFIDENCE_MEDIUM = 'medium';
    public const CONFIDENCE_HIGH = 'high';

    protected $table = 'locality_metrics_daily';

    protected $fillable = [
        'integration_id',
        'sku',
        'snapshot_date',
        'period_days',
        'orders_count',
        'local_orders_count',
        'non_local_orders_count',
        'local_share_percent',
        'revenue_total',
        'base_logistics_total',
        'non_local_markup_total',
        'overpayment_amount',
        'lost_margin_amount',
        'avg_base_tariff',
        'avg_markup_percent',
        'factual_orders_count',
        'estimate_orders_count',
        'calculation_confidence',
        'tariff_version_used',
        'meta',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'period_days' => 'integer',
        'orders_count' => 'integer',
        'local_orders_count' => 'integer',
        'non_local_orders_count' => 'integer',
        'local_share_percent' => 'decimal:2',
        'revenue_total' => 'decimal:2',
        'base_logistics_total' => 'decimal:2',
        'non_local_markup_total' => 'decimal:2',
        'overpayment_amount' => 'decimal:2',
        'lost_margin_amount' => 'decimal:2',
        'avg_base_tariff' => 'decimal:2',
        'avg_markup_percent' => 'decimal:2',
        'factual_orders_count' => 'integer',
        'estimate_orders_count' => 'integer',
        'meta' => 'array',
    ];
}
