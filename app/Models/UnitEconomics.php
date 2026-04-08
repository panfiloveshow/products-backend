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
        'integration_id',
        'product_name',
        'sku',
        'marketplace',
        'fulfillment_type',
        'price',
        'cost_price',
        'customer_price',
        'sales_count',
        'revenue',
        'total_costs',
        'gross_profit',
        'net_profit',
        'margin_percent',
        'markup_percent',
        'markup_multiplier',
        'roi_percent',
        'volume_liters',
        'volume_weight',
        'actual_weight',
        'length_mm',
        'width_mm',
        'height_mm',
        'weight_g',
        'commission_percent',
        'commission_amount',
        'spp_percent',
        'spp_amount',
        'logistics_cost',
        'base_logistics_cost',
        'logistics_with_coefficient',
        'logistics_with_warehouse',
        'logistics_per_unit',
        'logistics_coefficient',
        'localization_index',
        'additional_commission_percent',
        'additional_commission_amount',
        'tariff_version',
        'tariff_effective_from',
        'tariff_source',
        'route_key',
        'route_label',
        'is_local_sale',
        'non_local_markup_percent',
        'price_segment',
        'sales_fee_percent',
        'avg_delivery_time_hours',
        'last_mile_cost',
        'last_mile_per_unit',
        'processing_cost',
        'storage_cost',
        'turnover_days',
        'litrobonus',
        'return_logistics_cost',
        'return_processing_cost',
        'expected_return_cost',
        'effective_logistics',
        'warehouse_coefficient_percent',
        'warehouse_coefficient_amount',
        'total_expenses_percent',
        'redemption_rate',
        'redemption_source',
        'orders_count',
        'returns_count',
        'acquiring_percent',
        'acquiring_amount',
        'commission_per_unit',
        'acquiring_per_unit',
        'storage_per_unit',
        'is_actual_scheme',
        'total_costs_per_unit',
        'net_profit_per_unit',
        'to_settlement_account',
        'tax_percent',
        'tax_amount',
        'vat_percent',
        'vat_amount',
        'drr_percent',
        'drr_amount',
        'our_share_percent',
        'our_share_amount',
        'is_in_promotion',
        'promotion_discount',
        'seller_price',
        'marketing_seller_price',
        'own_delivery_cost',
        'ozon_compensation',
        'delivery_cost',
        'return_cost',
        'storage_days',
        'advertising_cost',
        'packaging_cost',
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
        'markup_percent' => 'decimal:2',
        'roi_percent' => 'decimal:2',
        'commission_percent' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'delivery_cost' => 'decimal:2',
        'logistics_cost' => 'decimal:2',
        'storage_cost' => 'decimal:2',
        'storage_days' => 'integer',
        'drr_percent' => 'decimal:2',
        'drr_amount' => 'decimal:2',
        'tax_percent' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'non_local_markup_percent' => 'decimal:2',
        'sales_fee_percent' => 'decimal:2',
        'advertising_cost' => 'decimal:2',
        'packaging_cost' => 'decimal:2',
        'redemption_rate' => 'decimal:2',
        'return_cost' => 'decimal:2',
        'vat_percent' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'our_share_percent' => 'decimal:2',
        'our_share_amount' => 'decimal:2',
        'acquiring_percent' => 'decimal:2',
        'acquiring_amount' => 'decimal:2',
        'is_in_promotion' => 'boolean',
        'is_actual_scheme' => 'boolean',
        'period_start' => 'date',
        'period_end' => 'date',
        'tariff_effective_from' => 'date',
        'is_local_sale' => 'boolean',
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
