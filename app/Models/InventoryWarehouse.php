<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryWarehouse extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'sku',
        'warehouse_id',
        'warehouse_name',
        'marketplace',
        'integration_id',
        'fulfillment_type',
        'region',
        'quantity',
        'reserved',
        'in_transit',
        'average_daily_sales',
        'effective_daily_sales',
        'days_in_stock_30',
        'days_of_stock',
        'turnover_days',
        'recommended_quantity',
        'stock_status',
        'last_updated',
        'sales_7_days',
        'sales_14_days',
        'sales_30_days',
        'storage_cost_per_day',
        'storage_cost_per_month',
        'storage_fee_total',
        'storage_fee_last_week',
        'storage_fee_report_from',
        'storage_fee_report_to',
        'storage_fee_prev_month',
        'storage_fee_prev_month_period',
        'storage_fee_all_time',
        'real_avg_daily_sales',
        'real_sales_period_days',
        'real_turnover_days',
        'real_days_of_stock',
        'sales_report_id',
        'paid_storage_penalty',
        'paid_storage_fee',
        'paid_storage_from',
        'paid_storage_to',
    ];

    protected $casts = [
        'quantity'            => 'integer',
        'average_daily_sales' => 'decimal:4',
        'days_of_stock'       => 'integer',
        'recommended_quantity'=> 'integer',
        'last_updated'        => 'datetime',
        'sales_7_days'        => 'integer',
        'sales_14_days'       => 'integer',
        'sales_30_days'       => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'sku');
    }

    public function history(): HasMany
    {
        return $this->hasMany(InventoryHistory::class, 'warehouse_id', 'warehouse_id')
            ->where('sku', $this->sku);
    }

    public function scopeMarketplace($query, string $marketplace)
    {
        return $query->where('marketplace', $marketplace);
    }

    public function scopeLowStock($query)
    {
        return $query->whereIn('stock_status', ['critical', 'low']);
    }

    public function scopeOutOfStock($query)
    {
        return $query->where('quantity', '<=', 0);
    }

    public function calculateStockStatus(): string
    {
        if ($this->quantity <= 0) {
            return 'critical';
        }
        
        if ($this->days_of_stock !== null) {
            if ($this->days_of_stock <= 7) {
                return 'critical';
            }
            if ($this->days_of_stock <= 14) {
                return 'low';
            }
            if ($this->days_of_stock > 60) {
                return 'excess';
            }
        }
        
        return 'optimal';
    }
}
