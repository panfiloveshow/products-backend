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
        'warehouse_coefficient',
        'integration_id',
        'marketplace',
        'fulfillment_type',
        'region',
        'quantity',
        'reserved',
        'in_transit',
        'in_way_to_client',
        'in_way_from_client',
        'cost_price',
        'sales_7_days',
        'sales_14_days',
        'sales_30_days',
        'average_daily_sales',
        'days_of_stock',
        'turnover_days',
        'days_in_stock_30',
        'effective_daily_sales',
        'effective_turnover_days',
        'last_stockout_date',
        'last_restock_date',
        'recommended_quantity',
        'stock_status',
        'storage_cost_per_day',
        'storage_cost_per_month',
        'storage_fee_total',
        'storage_fee_last_week',
        'storage_fee_report_from',
        'storage_fee_report_to',
        'last_updated',
        'target_days_of_stock',
        'safety_stock_days',
        'reorder_point',
        'lead_time_days',
        'optimal_order_quantity',
        'last_recommendation_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'reserved' => 'integer',
        'in_transit' => 'integer',
        'in_way_to_client' => 'integer',
        'in_way_from_client' => 'integer',
        'cost_price' => 'decimal:2',
        'sales_7_days' => 'integer',
        'sales_14_days' => 'integer',
        'sales_30_days' => 'integer',
        'average_daily_sales' => 'decimal:2',
        'days_of_stock' => 'integer',
        'turnover_days' => 'decimal:1',
        'days_in_stock_30' => 'integer',
        'effective_daily_sales' => 'decimal:2',
        'effective_turnover_days' => 'decimal:1',
        'last_stockout_date' => 'date',
        'last_restock_date' => 'date',
        'recommended_quantity' => 'integer',
        'storage_cost_per_day' => 'decimal:2',
        'storage_cost_per_month' => 'decimal:2',
        'storage_fee_total' => 'decimal:2',
        'storage_fee_last_week' => 'decimal:2',
        'storage_fee_report_from' => 'date',
        'storage_fee_report_to' => 'date',
        'last_updated' => 'datetime',
        'warehouse_coefficient' => 'decimal:3',
        'target_days_of_stock' => 'integer',
        'safety_stock_days' => 'integer',
        'reorder_point' => 'integer',
        'lead_time_days' => 'integer',
        'optimal_order_quantity' => 'integer',
        'last_recommendation_at' => 'datetime',
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
