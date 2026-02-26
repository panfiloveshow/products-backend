<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitEconomics extends Model
{
    use HasFactory;

    protected $table = 'unit_economics';

    protected $fillable = [
        'product_id',
        'product_name',
        'sku',
        'marketplace',
        'price',
        'cost_price',
        'sales_count',
        'revenue',
        'total_costs',
        'gross_profit',
        'net_profit',
        'margin_percent',
        'roi_percent',
        'period_start',
        'period_end',
        'marketplace_data',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'sales_count' => 'integer',
        'revenue' => 'decimal:2',
        'total_costs' => 'decimal:2',
        'gross_profit' => 'decimal:2',
        'net_profit' => 'decimal:2',
        'margin_percent' => 'decimal:2',
        'roi_percent' => 'decimal:2',
        'period_start' => 'date',
        'period_end' => 'date',
        'marketplace_data' => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'sku');
    }

    public function scopeMarketplace($query, string $marketplace)
    {
        return $query->where('marketplace', $marketplace);
    }

    public function scopeProfitable($query)
    {
        return $query->where('net_profit', '>', 0);
    }

    public function scopeUnprofitable($query)
    {
        return $query->where('net_profit', '<=', 0);
    }

    public function scopeMarginRange($query, ?float $min, ?float $max)
    {
        if ($min !== null) {
            $query->where('margin_percent', '>=', $min);
        }
        if ($max !== null) {
            $query->where('margin_percent', '<=', $max);
        }
        return $query;
    }

    public function scopePriceRange($query, ?float $min, ?float $max)
    {
        if ($min !== null) {
            $query->where('price', '>=', $min);
        }
        if ($max !== null) {
            $query->where('price', '<=', $max);
        }
        return $query;
    }

    public function scopePeriod($query, ?string $start, ?string $end)
    {
        if ($start) {
            $query->where('period_start', '>=', $start);
        }
        if ($end) {
            $query->where('period_end', '<=', $end);
        }
        return $query;
    }

    public function scopeSearch($query, ?string $search)
    {
        if (!$search) {
            return $query;
        }
        return $query->where(function ($q) use ($search) {
            $q->where('product_name', 'like', "%{$search}%")
              ->orWhere('sku', 'like', "%{$search}%");
        });
    }

    public function isProfitable(): bool
    {
        return $this->net_profit > 0;
    }
}
