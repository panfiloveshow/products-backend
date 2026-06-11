<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Пер-строка оценка факта плана (этап 2, план-факт).
 * Считается через горизонт N дней после построения плана:
 *  A) прогноз спроса (demand_daily·N) vs реальные продажи (postings);
 *  B) план поставки (qty_rounded) vs принято (supply_items.accepted_qty).
 *
 * Правило: нет факта → status=insufficient_data и метрики null (никогда не accuracy=100).
 */
class PlanLineEvaluation extends Model
{
    public const STATUS_OK = 'ok';
    public const STATUS_INSUFFICIENT = 'insufficient_data';
    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'auto_supply_plan_id',
        'auto_supply_plan_line_id',
        'integration_id',
        'marketplace',
        'sku',
        'cluster_id',
        'warehouse_id',
        'plan_created_at',
        'horizon_days',
        'evaluated_at',
        'forecast_demand_qty',
        'actual_sales_qty',
        'abs_pct_error',
        'bias_pct',
        'demand_fact_source',
        'planned_qty',
        'accepted_qty',
        'rejected_qty',
        'acceptance_rate',
        'status',
        'details_json',
    ];

    protected $casts = [
        'plan_created_at' => 'datetime',
        'evaluated_at' => 'datetime',
        'horizon_days' => 'integer',
        'forecast_demand_qty' => 'float',
        'actual_sales_qty' => 'float',
        'abs_pct_error' => 'float',
        'bias_pct' => 'float',
        'planned_qty' => 'integer',
        'accepted_qty' => 'integer',
        'rejected_qty' => 'integer',
        'acceptance_rate' => 'float',
        'details_json' => 'array',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(AutoSupplyPlan::class, 'auto_supply_plan_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(AutoSupplyPlanLine::class, 'auto_supply_plan_line_id');
    }
}
