<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutoSupplyPlanLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'auto_supply_plan_id',
        'tenant_id',
        'sku',
        'offer_id',
        'product_name',
        'barcode',
        'price',
        'cost_price',
        'warehouse_id',
        'warehouse_name',
        'cluster_id',
        'cluster_name',
        'region',
        'own_stock',
        'own_stock_reserved',
        'deficit',
        'destination',
        'destination_id',
        'destination_type',
        'qty_recommended',
        'qty_rounded',
        'current_stock',
        'in_transit',
        'avg_daily_sales',
        'ewma_daily_sales',
        'demand_daily',
        'sales_trend',
        'sales_trend_percent',
        'cover_days_before',
        'cover_days_after',
        'oos_date',
        'surplus_days',
        'storage_cost_daily',
        'storage_cost_monthly',
        'lost_revenue_daily',
        'supply_cost_estimate',
        'expected_revenue',
        'expected_profit',
        'roi_percent',
        'priority_score',
        'priority',
        'turnover_days',
        'explain_json',
        'risk_level',
        'simulation_json',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'qty_recommended' => 'decimal:2',
        'qty_rounded' => 'integer',
        'current_stock' => 'integer',
        'in_transit' => 'integer',
        'avg_daily_sales' => 'decimal:4',
        'ewma_daily_sales' => 'decimal:4',
        'demand_daily' => 'decimal:4',
        'sales_trend_percent' => 'decimal:2',
        'cover_days_before' => 'decimal:2',
        'cover_days_after' => 'decimal:2',
        'surplus_days' => 'integer',
        'storage_cost_daily' => 'decimal:2',
        'storage_cost_monthly' => 'decimal:2',
        'lost_revenue_daily' => 'decimal:2',
        'supply_cost_estimate' => 'decimal:2',
        'expected_revenue' => 'decimal:2',
        'expected_profit' => 'decimal:2',
        'roi_percent' => 'decimal:2',
        'priority_score' => 'decimal:2',
        'turnover_days' => 'decimal:1',
        'explain_json' => 'array',
        'simulation_json' => 'array',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(AutoSupplyPlan::class, 'auto_supply_plan_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'sku');
    }

    public function isHighRisk(): bool
    {
        return $this->risk_level === 'high';
    }
}
