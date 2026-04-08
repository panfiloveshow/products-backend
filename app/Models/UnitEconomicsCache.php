<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Кэш юнит-экономики (автоматический расчёт)
 *
 * Хранит предрассчитанные данные для каждой схемы работы.
 * Обновляется автоматически при:
 * - синхронизации товаров
 * - изменении настроек пользователя
 */
class UnitEconomicsCache extends Model
{
    use HasFactory;

    protected $table = 'unit_economics_cache';

    protected $fillable = [
        'integration_id',
        'product_id',
        'sku',
        'product_name',
        'marketplace',
        'fulfillment_type',
        'marketplace_data',
        // Базовые данные
        'price',
        'old_price',
        'sales_count',
        // Акции
        'is_in_promotion',
        'promotion_discount',
        // Габариты
        'volume_liters',
        'volume_weight',
        'depth',
        'width',
        'height',
        'weight',
        // Комиссия
        'commission_percent',
        'commission_amount',
        // Логистика
        'avg_delivery_time_hours',
        'logistics_coefficient',
        'additional_commission_percent',
        'tariff_version',
        'tariff_effective_from',
        'tariff_source',
        'route_key',
        'route_label',
        'is_local_sale',
        'non_local_markup_percent',
        'price_segment',
        'sales_fee_percent',
        'base_logistics_cost',
        'logistics_cost',
        'last_mile_cost',
        'processing_cost',
        'storage_cost',
        'packaging_cost',
        // Возвраты
        'redemption_rate',
        'redemption_source',
        'orders_count',
        'returns_count',
        'return_logistics_cost',
        'return_processing_cost',
        'expected_return_cost',
        'effective_logistics',
        // Эквайринг
        'acquiring_percent',
        'acquiring_amount',
        // Настройки пользователя
        'cost_price',
        'drr_percent',
        'drr_amount',
        'our_share_percent',
        'our_share_amount',
        'tax_percent',
        'tax_amount',
        'vat_percent',
        'vat_amount',
        // Итоги
        'revenue',
        'total_costs',
        'gross_profit',
        'net_profit',
        'to_settlement_account',
        'margin_percent',
        'markup_percent',
        'roi_percent',
        // Метаданные
        'calculated_at',
        'data_version',
    ];

    protected $casts = [
        'marketplace_data' => 'array',
        'price' => 'decimal:2',
        'old_price' => 'decimal:2',
        'sales_count' => 'integer',
        'is_in_promotion' => 'boolean',
        'promotion_discount' => 'decimal:2',
        'volume_liters' => 'decimal:4',
        'volume_weight' => 'decimal:4',
        'depth' => 'decimal:2',
        'width' => 'decimal:2',
        'height' => 'decimal:2',
        'weight' => 'decimal:2',
        'commission_percent' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'avg_delivery_time_hours' => 'integer',
        'logistics_coefficient' => 'decimal:3',
        'additional_commission_percent' => 'decimal:2',
        'tariff_effective_from' => 'date',
        'is_local_sale' => 'boolean',
        'non_local_markup_percent' => 'decimal:2',
        'sales_fee_percent' => 'decimal:2',
        'base_logistics_cost' => 'decimal:2',
        'logistics_cost' => 'decimal:2',
        'last_mile_cost' => 'decimal:2',
        'processing_cost' => 'decimal:2',
        'storage_cost' => 'decimal:2',
        'packaging_cost' => 'decimal:2',
        'redemption_rate' => 'decimal:2',
        'return_logistics_cost' => 'decimal:2',
        'return_processing_cost' => 'decimal:2',
        'expected_return_cost' => 'decimal:2',
        'effective_logistics' => 'decimal:2',
        'acquiring_percent' => 'decimal:2',
        'acquiring_amount' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'drr_percent' => 'decimal:2',
        'drr_amount' => 'decimal:2',
        'our_share_percent' => 'decimal:2',
        'our_share_amount' => 'decimal:2',
        'tax_percent' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'vat_percent' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'revenue' => 'decimal:2',
        'total_costs' => 'decimal:2',
        'gross_profit' => 'decimal:2',
        'net_profit' => 'decimal:2',
        'to_settlement_account' => 'decimal:2',
        'margin_percent' => 'decimal:2',
        'markup_percent' => 'decimal:2',
        'roi_percent' => 'decimal:2',
        'calculated_at' => 'datetime',
        'data_version' => 'integer',
    ];

    /**
     * Интеграция
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /**
     * Товар
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // ==================== SCOPES ====================

    /**
     * Фильтр по интеграции
     */
    public function scopeForIntegration(Builder $query, int $integrationId): Builder
    {
        return $query->where($this->getTable().'.integration_id', $integrationId);
    }

    /**
     * Фильтр по маркетплейсу
     */
    public function scopeForMarketplace(Builder $query, string $marketplace): Builder
    {
        return $query->where($this->getTable().'.marketplace', $marketplace);
    }

    /**
     * Фильтр по схеме работы
     */
    public function scopeForScheme(Builder $query, string $fulfillmentType): Builder
    {
        return $query->where($this->getTable().'.fulfillment_type', strtoupper($fulfillmentType));
    }

    /**
     * Поиск по SKU или названию
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        return $query->where(function ($q) use ($search) {
            $q->where($this->getTable().'.sku', 'like', "%{$search}%")
                ->orWhere($this->getTable().'.product_name', 'like', "%{$search}%");
        });
    }

    /**
     * Только прибыльные товары
     */
    public function scopeProfitable(Builder $query, ?bool $profitable): Builder
    {
        if ($profitable === null) {
            return $query;
        }

        return $profitable
            ? $query->where($this->getTable().'.net_profit', '>', 0)
            : $query->where($this->getTable().'.net_profit', '<=', 0);
    }

    /**
     * Фильтр по марже
     */
    public function scopeMarginRange(Builder $query, ?float $min, ?float $max): Builder
    {
        if ($min !== null) {
            $query->where($this->getTable().'.margin_percent', '>=', $min);
        }
        if ($max !== null) {
            $query->where($this->getTable().'.margin_percent', '<=', $max);
        }

        return $query;
    }

    /**
     * Фильтр по цене
     */
    public function scopePriceRange(Builder $query, ?float $min, ?float $max): Builder
    {
        if ($min !== null) {
            $query->where($this->getTable().'.price', '>=', $min);
        }
        if ($max !== null) {
            $query->where($this->getTable().'.price', '<=', $max);
        }

        return $query;
    }

    // ==================== HELPERS ====================

    /**
     * Получить габариты как массив
     */
    public function getDimensionsAttribute(): array
    {
        return [
            'depth' => $this->depth,
            'width' => $this->width,
            'height' => $this->height,
            'weight' => $this->weight,
            'volume_liters' => $this->volume_liters,
            'volume_weight' => $this->volume_weight,
        ];
    }

    /**
     * Проверить, устарел ли кэш
     */
    public function isStale(int $maxAgeMinutes = 60): bool
    {
        if (! $this->calculated_at) {
            return true;
        }

        return $this->calculated_at->diffInMinutes(now()) > $maxAgeMinutes;
    }
}
