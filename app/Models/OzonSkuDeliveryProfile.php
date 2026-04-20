<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OzonSkuDeliveryProfile extends Model
{
    use HasFactory;

    protected $table = 'ozon_sku_delivery_profiles';

    protected $fillable = [
        'integration_id',
        'sku',
        'offer_id',
        'ozon_sku',
        'scheme',
        'stock_profile',
        'sales_profile',
        'cluster_profile',
        'dominant_stock_cluster_id',
        'dominant_stock_cluster_share',
        'dominant_sales_cluster_id',
        'dominant_sales_cluster_share',
        'dominant_demand_cluster_id',
        'dominant_demand_cluster_share',
        'expected_locality_rate',
        'weighted_non_local_markup_percent',
        'weighted_logistics_cost',
        'profile_source',
        'route_resolution_status',
        'locality_resolution_status',
        'calculation_confidence',
        'calculated_at',
    ];

    protected $casts = [
        'stock_profile' => 'array',
        'sales_profile' => 'array',
        'cluster_profile' => 'array',
        'dominant_stock_cluster_share' => 'decimal:2',
        'dominant_sales_cluster_share' => 'decimal:2',
        'dominant_demand_cluster_share' => 'decimal:2',
        'expected_locality_rate' => 'decimal:2',
        'weighted_non_local_markup_percent' => 'decimal:2',
        'weighted_logistics_cost' => 'decimal:2',
        'calculated_at' => 'datetime',
    ];

    public static function findForProduct(int|string $integrationId, string $sku, ?string $scheme = null): ?self
    {
        $query = self::where('integration_id', $integrationId)
            ->where('sku', $sku);

        if ($scheme !== null) {
            $query->where('scheme', strtoupper($scheme));
        }

        return $query->orderByDesc('calculated_at')->first();
    }
}
