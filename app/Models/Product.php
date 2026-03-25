<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'sku',
        'vendor_code',
        'name',
        'barcode',
        'price',
        'old_price',
        'stock',
        'description',
        'images',
        'category',
        'brand',
        'rating',
        'reviews_count',
        'marketplace',
        'marketplace_id',
        'integration_id',
        'url',
        'characteristics',
        'wb_data',
        'ozon_data',
        'yandex_data',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'old_price' => 'decimal:2',
        'stock' => 'integer',
        'rating' => 'decimal:2',
        'reviews_count' => 'integer',
        'images' => 'array',
        'characteristics' => 'array',
        'wb_data' => 'array',
        'ozon_data' => 'array',
        'yandex_data' => 'array',
    ];

    public function inventoryWarehouses(): HasMany
    {
        return $this->hasMany(InventoryWarehouse::class, 'sku', 'sku');
    }

    public function inventoryHistory(): HasMany
    {
        return $this->hasMany(InventoryHistory::class, 'sku', 'sku');
    }

    public function unitEconomics(): HasMany
    {
        return $this->hasMany(UnitEconomics::class, 'sku', 'sku');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(InventoryAlert::class, 'sku', 'sku');
    }

    public function scopeMarketplace($query, string $marketplace)
    {
        return $query->where('products.marketplace', $marketplace);
    }

    public function scopeInStock($query)
    {
        return $query->where('products.stock', '>', 0);
    }

    public function scopeOutOfStock($query)
    {
        return $query->where('products.stock', '<=', 0);
    }

    public function scopeSearch($query, ?string $search)
    {
        if (! $search) {
            return $query;
        }

        return $query->where(function ($q) use ($search) {
            $q->where('products.name', 'like', "%{$search}%")
                ->orWhere('products.sku', 'like', "%{$search}%")
                ->orWhere('products.barcode', 'like', "%{$search}%");
        });
    }

    public function getTotalMarketplaceStockAttribute(): int
    {
        return $this->inventoryWarehouses()->sum('quantity');
    }
}
