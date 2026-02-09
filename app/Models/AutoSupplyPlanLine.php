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
        'warehouse_id',
        'warehouse_name',
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
        'cover_days_before',
        'cover_days_after',
        'oos_date',
        'surplus_days',
        'explain_json',
        'risk_level',
        'simulation_json',
    ];

    protected $casts = [
        'qty_recommended' => 'decimal:2',
        'qty_rounded' => 'integer',
        'current_stock' => 'integer',
        'in_transit' => 'integer',
        'avg_daily_sales' => 'decimal:4',
        'ewma_daily_sales' => 'decimal:4',
        'demand_daily' => 'decimal:4',
        'cover_days_before' => 'decimal:2',
        'cover_days_after' => 'decimal:2',
        'surplus_days' => 'integer',
        'oos_date' => 'date',
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
