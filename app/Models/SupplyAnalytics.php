<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplyAnalytics extends Model
{
    protected $table = 'supply_analytics';

    // Поля соответствуют миграции 2026_01_22_140600_create_supply_analytics_table.
    protected $fillable = [
        'integration_id',
        'date',
        'period_type',
        'cluster_id',
        'warehouse_id',
        'sku',
        'recommendations_generated',
        'recommendations_accepted',
        'recommendations_rejected',
        'recommendations_expired',
        'fill_rate',
        'oos_skus_count',
        'oos_rate',
        'oos_revenue_lost',
        'overstock_skus_count',
        'overstock_rate',
        'overstock_value',
        'forecast_accuracy',
        'demand_vs_actual',
        'supplies_created',
        'supplies_completed',
        'supplies_cancelled',
        'supplies_with_errors',
        'slots_booked',
        'slots_changed',
        'slots_missed',
        'avg_lead_time_days',
        'planned_vs_actual_lead_time',
        'items_shipped',
        'items_accepted',
        'items_rejected',
        'acceptance_rate',
        'discrepancies_count',
        'sla_on_time_rate',
        'sla_violations',
        'total_supplied_value',
        'logistics_cost',
    ];

    protected $casts = [
        'date' => 'date',
        'fill_rate' => 'decimal:2',
        'oos_rate' => 'decimal:2',
        'oos_revenue_lost' => 'decimal:2',
        'overstock_rate' => 'decimal:2',
        'overstock_value' => 'decimal:2',
        'forecast_accuracy' => 'decimal:2',
        'demand_vs_actual' => 'decimal:2',
        'avg_lead_time_days' => 'decimal:2',
        'planned_vs_actual_lead_time' => 'decimal:2',
        'acceptance_rate' => 'decimal:2',
        'sla_on_time_rate' => 'decimal:2',
        'total_supplied_value' => 'decimal:2',
        'logistics_cost' => 'decimal:2',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
