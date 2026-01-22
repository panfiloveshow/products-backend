<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplyAnalytics extends Model
{
    protected $table = 'supply_analytics';

    protected $fillable = [
        'integration_id',
        'period_start',
        'period_end',
        'oos_rate',
        'oos_days',
        'oos_skus',
        'forecast_accuracy',
        'forecast_mape',
        'forecast_bias',
        'avg_lead_time_hours',
        'min_lead_time_hours',
        'max_lead_time_hours',
        'total_supplies',
        'completed_supplies',
        'cancelled_supplies',
        'error_supplies',
        'total_items',
        'total_quantity',
        'acceptance_rate',
        'partial_acceptance_count',
        'rejection_rate',
        'metrics',
        'calculated_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'oos_rate' => 'decimal:2',
        'forecast_accuracy' => 'decimal:2',
        'forecast_mape' => 'decimal:2',
        'forecast_bias' => 'decimal:2',
        'avg_lead_time_hours' => 'decimal:1',
        'min_lead_time_hours' => 'decimal:1',
        'max_lead_time_hours' => 'decimal:1',
        'acceptance_rate' => 'decimal:2',
        'rejection_rate' => 'decimal:2',
        'metrics' => 'array',
        'calculated_at' => 'datetime',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
