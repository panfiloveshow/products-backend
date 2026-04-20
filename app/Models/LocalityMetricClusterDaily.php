<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LocalityMetricClusterDaily extends Model
{
    use HasFactory;

    protected $table = 'locality_metrics_cluster_daily';

    protected $fillable = [
        'integration_id',
        'destination_cluster_id',
        'destination_cluster_name',
        'snapshot_date',
        'period_days',
        'orders_count',
        'local_orders_count',
        'local_share_percent',
        'total_revenue',
        'total_overpayment',
        'lost_margin_amount',
        'distinct_skus_affected',
        'top_skus_by_loss',
        'shipping_cluster_breakdown',
        'meta',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'period_days' => 'integer',
        'orders_count' => 'integer',
        'local_orders_count' => 'integer',
        'local_share_percent' => 'decimal:2',
        'total_revenue' => 'decimal:2',
        'total_overpayment' => 'decimal:2',
        'lost_margin_amount' => 'decimal:2',
        'distinct_skus_affected' => 'integer',
        'top_skus_by_loss' => 'array',
        'shipping_cluster_breakdown' => 'array',
        'meta' => 'array',
    ];
}
