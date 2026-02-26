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
        'average_daily_sales',
        'days_of_stock',
        'turnover_days',
        'recommended_quantity',
        'stock_status',
        'last_updated',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'average_daily_sales' => 'decimal:2',
        'days_of_stock' => 'integer',
        'recommended_quantity' => 'integer',
        'last_updated' => 'datetime',
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
