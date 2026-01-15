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
        'name',
        'barcode',
        'price',
        'old_price',
        'stock',
        'sales_28_days',      // Продажи за 28 дней
        'avg_daily_sales',    // Среднедневные продажи
        'turnover_days',      // Оборачиваемость (дней)
        'storage_cost',       // Стоимость хранения за период
        'storage_cost_updated_at',
        'description',
        'images',
        'category',
        'brand',
        'rating',
        'reviews_count',
        'card_rating',
        'card_rating_details',
        'commission',
        'spp',
        'subject_id',
        'marketplace',
        'marketplace_id',
        'fulfillment_type', // FBO, FBS, REALFBS, EXPRESS
        'integration_id',
        'url',
        'characteristics',
        'wb_data',
        'ozon_data',
        'yandex_data',
        // Габариты
        'depth',         // Длина (мм)
        'width',         // Ширина (мм)
        'height',        // Высота (мм)
        'weight',        // Вес (г)
        'volume_weight', // Объём (л)
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'old_price' => 'decimal:2',
        'stock' => 'integer',
        'sales_28_days' => 'integer',
        'avg_daily_sales' => 'decimal:2',
        'turnover_days' => 'decimal:1',
        'storage_cost' => 'decimal:2',
        'storage_cost_updated_at' => 'datetime',
        'rating' => 'decimal:2',
        'reviews_count' => 'integer',
        'card_rating' => 'decimal:1',
        'card_rating_details' => 'array',
        'commission' => 'decimal:2',
        'spp' => 'decimal:2',
        'subject_id' => 'integer',
        'images' => 'array',
        'characteristics' => 'array',
        'wb_data' => 'array',
        'ozon_data' => 'array',
        'yandex_data' => 'array',
        // Габариты
        'depth' => 'decimal:2',
        'width' => 'decimal:2',
        'height' => 'decimal:2',
        'weight' => 'decimal:2',
        'volume_weight' => 'decimal:4',
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
        return $query->where('marketplace', $marketplace);
    }

    public function scopeInStock($query)
    {
        return $query->where('stock', '>', 0);
    }

    public function scopeOutOfStock($query)
    {
        return $query->where('stock', '<=', 0);
    }

    public function scopeSearch($query, ?string $search)
    {
        if (!$search) {
            return $query;
        }
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('sku', 'like', "%{$search}%")
              ->orWhere('barcode', 'like', "%{$search}%");
        });
    }

    public function getTotalMarketplaceStockAttribute(): int
    {
        return $this->inventoryWarehouses()->sum('quantity');
    }
    
    /**
     * Дата создания товара на маркетплейсе
     */
    public function getMarketplaceCreatedAtAttribute(): ?string
    {
        $data = match ($this->marketplace) {
            'ozon' => $this->ozon_data,
            'wildberries' => $this->wb_data,
            'yandex' => $this->yandex_data,
            default => null,
        };
        
        return $data['created_at'] ?? null;
    }
    
    /**
     * Дата обновления товара на маркетплейсе
     */
    public function getMarketplaceUpdatedAtAttribute(): ?string
    {
        $data = match ($this->marketplace) {
            'ozon' => $this->ozon_data,
            'wildberries' => $this->wb_data,
            'yandex' => $this->yandex_data,
            default => null,
        };
        
        return $data['updated_at'] ?? null;
    }
    
    /**
     * Характеристики в формате массива объектов для frontend
     * Приоритет: wb_data.characteristics > ozon_data.attributes > characteristics
     */
    public function getCharacteristicsListAttribute(): array
    {
        // WB: характеристики в wb_data.characteristics
        $wbData = $this->wb_data ?? [];
        if (!empty($wbData['characteristics'])) {
            $result = [];
            foreach ($wbData['characteristics'] as $char) {
                $value = $char['value'] ?? null;
                // Значение может быть массивом или скаляром
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                $result[] = [
                    'name' => $char['name'] ?? '',
                    'value' => $value,
                ];
            }
            return $result;
        }
        
        // Ozon: характеристики в ozon_data.attributes
        $ozonData = $this->ozon_data ?? [];
        if (!empty($ozonData['attributes'])) {
            $result = [];
            foreach ($ozonData['attributes'] as $attr) {
                $values = $attr['values'] ?? [];
                $value = !empty($values) ? ($values[0]['value'] ?? '') : '';
                $result[] = [
                    'name' => $attr['name'] ?? $attr['attribute_name'] ?? '',
                    'value' => $value,
                ];
            }
            return $result;
        }
        
        // Fallback: старый формат
        $characteristics = $this->characteristics ?? [];
        if (empty($characteristics)) {
            return [];
        }
        
        $result = [];
        foreach ($characteristics as $name => $value) {
            $result[] = [
                'name' => $name,
                'value' => is_array($value) ? implode(', ', $value) : $value,
            ];
        }
        
        return $result;
    }
    
    /**
     * Добавляем даты маркетплейса и характеристики в JSON
     */
    protected $appends = ['marketplace_created_at', 'marketplace_updated_at', 'total_marketplace_stock', 'characteristics_list'];
}
