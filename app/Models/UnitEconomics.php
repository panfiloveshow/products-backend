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
        'price',
        'cost_price',
        'sales_count',
        'revenue',
        'total_costs',
        'gross_profit',
        'net_profit',
        'margin_percent',
        'markup_percent',
        'roi_percent',
        'period_start',
        'period_end',
        'marketplace_data',
        // Новые детализированные поля
        'commission_percent',
        'commission_amount',
        'delivery_cost',
        'return_cost',
        'last_mile_cost',
        'storage_cost',
        'storage_days',
        'advertising_cost',
        'drr_percent',
        'redemption_rate',
        'orders_count',
        'returns_count',
        'acquiring_percent',
        'acquiring_amount',
        'packaging_cost',
        'fulfillment_type',
        // Универсальные поля Ozon (FBO/FBS/realFBS/DBS)
        'volume_liters',
        'volume_weight',
        'actual_weight',
        'processing_cost',
        'logistics_cost',
        'turnover_days',
        'litrobonus',
        'return_logistics_cost',
        'own_delivery_cost',
        'ozon_compensation',
        // Тарифы FBO декабрь 2025 + индекс локализации
        'avg_delivery_time_hours',
        'localization_index',
        'logistics_coefficient',
        'additional_commission_percent',
        'additional_commission_amount',
        'base_logistics_cost',
        'logistics_with_coefficient',
        'return_processing_cost',
        'expected_return_cost',
        'effective_logistics',
        // Стоимость за единицу
        'logistics_per_unit',
        'last_mile_per_unit',
        'commission_per_unit',
        'acquiring_per_unit',
        'storage_per_unit',
        'total_costs_per_unit',
        'net_profit_per_unit',
        'redemption_source',
        'to_settlement_account',
        // Новые поля: налоги и наша часть
        'our_share_percent',
        'our_share_amount',
        'drr_amount',
        'tax_percent',
        'vat_percent',
        'tax_amount',
        'vat_amount',
        // Акции (marketing_seller_price)
        'is_in_promotion',
        'promotion_discount',
        'seller_price',
        'marketing_seller_price',
        // Фактическая схема работы
        'is_actual_scheme',
        // WB: Габариты
        'length_mm',
        'width_mm',
        'height_mm',
        'weight_g',
        // WB: Наценка множитель
        'markup_multiplier',
        // WB: Цена покупателя (с СПП)
        'customer_price',
        // WB: СПП (скидка постоянного покупателя)
        'spp_percent',
        'spp_amount',
        // WB: КС (коэффициент склада)
        'warehouse_coefficient_percent',
        'warehouse_coefficient_amount',
        // WB: Логистика + КС
        'logistics_with_warehouse',
        // WB: Итоговый % расходов
        'total_expenses_percent',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'integration_id' => 'integer',
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
        'period_start' => 'date',
        'period_end' => 'date',
        'marketplace_data' => 'array',
        // Новые поля
        'commission_percent' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'delivery_cost' => 'decimal:2',
        'return_cost' => 'decimal:2',
        'last_mile_cost' => 'decimal:2',
        'storage_cost' => 'decimal:2',
        'storage_days' => 'integer',
        'advertising_cost' => 'decimal:2',
        'drr_percent' => 'decimal:2',
        'redemption_rate' => 'decimal:2',
        'orders_count' => 'integer',
        'returns_count' => 'integer',
        'acquiring_percent' => 'decimal:2',
        'acquiring_amount' => 'decimal:2',
        'packaging_cost' => 'decimal:2',
        // Универсальные поля Ozon
        'volume_liters' => 'decimal:2',
        'volume_weight' => 'decimal:2',
        'actual_weight' => 'decimal:2',
        'processing_cost' => 'decimal:2',
        'logistics_cost' => 'decimal:2',
        'turnover_days' => 'integer',
        'litrobonus' => 'decimal:2',
        'return_logistics_cost' => 'decimal:2',
        'own_delivery_cost' => 'decimal:2',
        'ozon_compensation' => 'decimal:2',
        // Тарифы FBO декабрь 2025 + индекс локализации
        'avg_delivery_time_hours' => 'integer',
        'localization_index' => 'decimal:3',
        'logistics_coefficient' => 'decimal:3',
        'additional_commission_percent' => 'decimal:2',
        'additional_commission_amount' => 'decimal:2',
        'base_logistics_cost' => 'decimal:2',
        'logistics_with_coefficient' => 'decimal:2',
        'return_processing_cost' => 'decimal:2',
        'expected_return_cost' => 'decimal:2',
        'effective_logistics' => 'decimal:2',
        // Стоимость за единицу
        'logistics_per_unit' => 'decimal:2',
        'last_mile_per_unit' => 'decimal:2',
        'commission_per_unit' => 'decimal:2',
        'acquiring_per_unit' => 'decimal:2',
        'storage_per_unit' => 'decimal:2',
        'total_costs_per_unit' => 'decimal:2',
        'net_profit_per_unit' => 'decimal:2',
        'to_settlement_account' => 'decimal:2',
        // Налоги и наша часть
        'our_share_percent' => 'decimal:2',
        'our_share_amount' => 'decimal:2',
        'drr_amount' => 'decimal:2',
        'tax_percent' => 'decimal:2',
        'vat_percent' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        // Акции
        'is_in_promotion' => 'boolean',
        'promotion_discount' => 'decimal:2',
        'seller_price' => 'decimal:2',
        'marketing_seller_price' => 'decimal:2',
        // Фактическая схема работы
        'is_actual_scheme' => 'boolean',
        // WB: Габариты
        'length_mm' => 'decimal:2',
        'width_mm' => 'decimal:2',
        'height_mm' => 'decimal:2',
        'weight_g' => 'decimal:2',
        // WB: Наценка множитель
        'markup_multiplier' => 'decimal:2',
        // WB: Цена покупателя
        'customer_price' => 'decimal:2',
        // WB: СПП
        'spp_percent' => 'decimal:2',
        'spp_amount' => 'decimal:2',
        // WB: КС
        'warehouse_coefficient_percent' => 'decimal:2',
        'warehouse_coefficient_amount' => 'decimal:2',
        // WB: Логистика + КС
        'logistics_with_warehouse' => 'decimal:2',
        // WB: Итоговый % расходов
        'total_expenses_percent' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'sku');
    }

    public function scopeMarketplace($query, string $marketplace)
    {
        return $query->where('marketplace', $marketplace);
    }

    public function scopeIntegration($query, int $integrationId)
    {
        return $query->where('integration_id', $integrationId);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Integration::class);
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

    public function getDeliveryCostAttribute($value)
    {
        $current = (float) ($value ?? 0);
        if ($value !== null && $current > 0) {
            return $value;
        }

        $ownDelivery = (float) ($this->own_delivery_cost ?? 0);
        if ($ownDelivery > 0) {
            return number_format($ownDelivery, 2, '.', '');
        }

        $logistics = (float) ($this->logistics_cost ?? 0);
        if ($logistics <= 0) {
            return $value;
        }

        $lastMile = (float) ($this->last_mile_cost ?? 0);
        $delivery = $logistics + $lastMile;
        if ($delivery <= 0) {
            return $value;
        }

        return number_format($delivery, 2, '.', '');
    }

    public function getExpectedReturnCostAttribute($value)
    {
        $current = (float) ($value ?? 0);
        if ($value !== null && $current > 0) {
            return $value;
        }

        $returnLogistics = (float) ($this->return_logistics_cost ?? 0);
        if ($returnLogistics <= 0) {
            return $value;
        }

        $redemptionRate = $this->redemption_rate;
        if ($redemptionRate === null) {
            return $value;
        }

        $rate = max(0, min(100, (float) $redemptionRate));
        $expected = $returnLogistics * ((100 - $rate) / 100);

        return number_format($expected, 2, '.', '');
    }

    public function getEffectiveLogisticsAttribute($value)
    {
        $current = (float) ($value ?? 0);
        if ($value !== null && $current > 0) {
            return $value;
        }

        $delivery = (float) ($this->delivery_cost ?? 0);
        $expectedReturn = (float) ($this->expected_return_cost ?? 0);
        $effective = $delivery + $expectedReturn;

        if ($effective <= 0) {
            return $value;
        }

        return number_format($effective, 2, '.', '');
    }

    /**
     * Получить габариты из полей связанного товара
     * Приоритет: поля Product → характеристики
     */
    public function getDimensionsAttribute(): array
    {
        $product = $this->product;
        if (!$product) {
            return [
                'length' => null,
                'width' => null,
                'height' => null,
                'weight' => null,
                'volume' => null,
            ];
        }

        // Приоритет: поля Product (depth, width, height, weight, volume_weight)
        $length = $product->depth;
        $width = $product->width;
        $height = $product->height;
        $weight = $product->weight;
        $volume = $product->volume_weight;
        
        // Fallback: характеристики (для старых данных)
        if (!$length && !$width && !$height) {
            $characteristics = $product->characteristics ?? [];
            $length = $this->extractNumericValue($characteristics['Глубина упаковки'] ?? null);
            $width = $this->extractNumericValue($characteristics['Ширина упаковки'] ?? null);
            $height = $this->extractNumericValue($characteristics['Высота упаковки'] ?? null);
            $weight = $weight ?: $this->extractNumericValue($characteristics['Вес'] ?? $characteristics['Вес товара, г'] ?? null);
            $volume = $volume ?: $this->extractNumericValue($characteristics['Объёмный вес'] ?? null);
        }
        
        // Если объём не указан, рассчитываем из габаритов (в литрах)
        if (!$volume && $length && $width && $height) {
            $volume = round(($length * $width * $height) / 1000000, 2); // мм³ -> л
        }

        return [
            'length' => $length,
            'width' => $width,
            'height' => $height,
            'weight' => $weight,
            'volume' => $volume,
        ];
    }

    /**
     * Извлечь числовое значение из строки с единицами измерения
     */
    private function extractNumericValue(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }
        
        // Убираем всё кроме цифр, точки и запятой
        $numeric = preg_replace('/[^\d.,]/', '', $value);
        $numeric = str_replace(',', '.', $numeric);
        
        return $numeric !== '' ? (float)$numeric : null;
    }

    /**
     * Получить комиссии из ozon_data связанного товара
     */
    public function getCommissionsAttribute(): ?array
    {
        $product = $this->product;
        if (!$product) {
            return null;
        }

        $ozonData = $product->ozon_data ?? [];
        return $ozonData['commissions'] ?? null;
    }

    /**
     * Получить данные о выкупе из ozon_data связанного товара
     */
    public function getRedemptionAttribute(): ?array
    {
        $product = $this->product;
        if (!$product) {
            return null;
        }

        $ozonData = $product->ozon_data ?? [];
        return $ozonData['redemption'] ?? null;
    }

    /**
     * Добавляем габариты, комиссии и выкуп в JSON
     */
    protected $appends = ['dimensions', 'commissions', 'redemption'];
}
