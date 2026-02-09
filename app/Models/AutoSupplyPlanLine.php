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
        'sku',
        'offer_id',
        'product_name',
        'barcode',
        'warehouse_id',
        'warehouse_name',
        'destination',
        'qty_raw',
        'qty_rounded',
        'current_stock',
        'in_transit',
        'avg_daily_sales',
        'ewma_daily_sales',
        'explain_json',
        'risk_level',
        'simulation_json',
    ];

    protected $casts = [
        'qty_raw' => 'decimal:2',
        'qty_rounded' => 'integer',
        'current_stock' => 'integer',
        'in_transit' => 'integer',
        'avg_daily_sales' => 'decimal:4',
        'ewma_daily_sales' => 'decimal:4',
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

    public function isCritical(): bool
    {
        return $this->risk_level === 'critical';
    }

    public function isHighRisk(): bool
    {
        return in_array($this->risk_level, ['critical', 'high']);
    }
}
