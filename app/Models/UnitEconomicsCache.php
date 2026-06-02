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
        return $this->belongsTo(Product::class, 'product_id', 'id');
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
        $normalizedSearch = trim((string) $search);
        if ($normalizedSearch === '') {
            return $query;
        }

        $tokens = preg_split('/\s+/u', $normalizedSearch) ?: [];
        $tokens = array_values(array_unique(array_filter(array_map(
            static fn ($token) => trim((string) $token),
            array_slice($tokens, 0, 6)
        ))));

        $table = $this->getTable();
        $driver = $query->getConnection()->getDriverName();
        $likeOperator = $driver === 'pgsql' ? 'ilike' : 'like';

        return $query->where(function (Builder $root) use ($tokens, $normalizedSearch, $table, $likeOperator) {
            // Точный SKU/артикул должен находиться мгновенно.
            $root->where("{$table}.sku", '=', $normalizedSearch)
                ->orWhereHas('product', function (Builder $productQuery) use ($normalizedSearch) {
                    $productQuery->where('products.sku', '=', $normalizedSearch)
                        ->orWhere('products.vendor_code', '=', $normalizedSearch)
                        ->orWhere('products.barcode', '=', $normalizedSearch);
                });

            // Prefix-поиск для быстрых "sku starts with ...".
            $root->orWhere("{$table}.sku", $likeOperator, "{$normalizedSearch}%")
                ->orWhereHas('product', function (Builder $productQuery) use ($normalizedSearch, $likeOperator) {
                    $productQuery->where('products.sku', $likeOperator, "{$normalizedSearch}%")
                        ->orWhere('products.vendor_code', $likeOperator, "{$normalizedSearch}%")
                        ->orWhere('products.barcode', $likeOperator, "{$normalizedSearch}%");
                });

            // Полное совпадение/подстрока по основным полям.
            $root->orWhere("{$table}.sku", $likeOperator, "%{$normalizedSearch}%")
                ->orWhere("{$table}.product_name", $likeOperator, "%{$normalizedSearch}%")
                ->orWhereHas('product', function (Builder $productQuery) use ($normalizedSearch, $likeOperator) {
                    $productQuery->where('products.sku', $likeOperator, "%{$normalizedSearch}%")
                        ->orWhere('products.vendor_code', $likeOperator, "%{$normalizedSearch}%")
                        ->orWhere('products.barcode', $likeOperator, "%{$normalizedSearch}%")
                        ->orWhere('products.name', $likeOperator, "%{$normalizedSearch}%");
                });

            // По каждому токену требуем попадание хотя бы в одно поле (AND между токенами).
            foreach ($tokens as $token) {
                if (mb_strlen($token) < 2) {
                    continue;
                }

                $root->where(function (Builder $tokenQuery) use ($token, $table, $likeOperator) {
                    $tokenQuery->where("{$table}.sku", $likeOperator, "%{$token}%")
                        ->orWhere("{$table}.product_name", $likeOperator, "%{$token}%")
                        ->orWhereHas('product', function (Builder $productQuery) use ($token, $likeOperator) {
                            $productQuery->where('products.sku', $likeOperator, "%{$token}%")
                                ->orWhere('products.vendor_code', $likeOperator, "%{$token}%")
                                ->orWhere('products.barcode', $likeOperator, "%{$token}%")
                                ->orWhere('products.name', $likeOperator, "%{$token}%");
                        });
                });
            }
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
     * Фильтр по диапазону прибыли.
     */
    public function scopeProfitRange(Builder $query, ?float $min, ?float $max): Builder
    {
        if ($min !== null) {
            $query->where($this->getTable().'.net_profit', '>=', $min);
        }
        if ($max !== null) {
            $query->where($this->getTable().'.net_profit', '<=', $max);
        }

        return $query;
    }

    /**
     * Фильтр по диапазону ROI.
     */
    public function scopeRoiRange(Builder $query, ?float $min, ?float $max): Builder
    {
        if ($min !== null) {
            $query->where($this->getTable().'.roi_percent', '>=', $min);
        }
        if ($max !== null) {
            $query->where($this->getTable().'.roi_percent', '<=', $max);
        }

        return $query;
    }

    /**
     * Фильтр по диапазону эффективной логистики.
     */
    public function scopeEffectiveLogisticsRange(Builder $query, ?float $min, ?float $max): Builder
    {
        if ($min !== null) {
            $query->where($this->getTable().'.effective_logistics', '>=', $min);
        }
        if ($max !== null) {
            $query->where($this->getTable().'.effective_logistics', '<=', $max);
        }

        return $query;
    }

    /**
     * Фильтр по диапазону продаж.
     */
    public function scopeSalesRange(Builder $query, ?int $min, ?int $max): Builder
    {
        if ($min !== null) {
            $query->where($this->getTable().'.sales_count', '>=', $min);
        }
        if ($max !== null) {
            $query->where($this->getTable().'.sales_count', '<=', $max);
        }

        return $query;
    }

    /**
     * Фильтр по диапазону нелокальной наценки.
     */
    public function scopeNonLocalMarkupRange(Builder $query, ?float $min, ?float $max): Builder
    {
        if ($min !== null) {
            $query->where($this->getTable().'.non_local_markup_percent', '>=', $min);
        }
        if ($max !== null) {
            $query->where($this->getTable().'.non_local_markup_percent', '<=', $max);
        }

        return $query;
    }

    /**
     * Фильтр по качеству расчёта (low/medium/high) из marketplace_data.
     */
    public function scopeConfidence(Builder $query, ?string $confidence): Builder
    {
        $confidence = strtolower(trim((string) $confidence));
        if (! in_array($confidence, ['low', 'medium', 'high'], true)) {
            return $query;
        }

        $expr = $this->jsonTextExpr($query, 'marketplace_data', 'calculation_confidence');

        return $query->whereRaw("LOWER({$expr}) = ?", [$confidence]);
    }

    /**
     * Фильтр по локальности.
     * - local: только локальные
     * - non_local: только нелокальные
     * - mixed: смешанная локальность (0<rate<100)
     * - no_sales: нет продаж за период
     */
    public function scopeLocalityState(Builder $query, ?string $state): Builder
    {
        $state = strtolower(trim((string) $state));
        if ($state === '') {
            return $query;
        }

        $table = $this->getTable();
        $localityRateExpr = $this->jsonNumberExpr($query, 'marketplace_data', 'expected_locality_rate');

        return match ($state) {
            'local' => $query->where(function (Builder $q) use ($table, $localityRateExpr) {
                $q->where("{$table}.is_local_sale", true)
                    ->orWhereRaw("{$localityRateExpr} >= 99.99");
            }),
            'non_local' => $query->where(function (Builder $q) use ($table, $localityRateExpr) {
                $q->where("{$table}.is_local_sale", false)
                    ->orWhereRaw("{$localityRateExpr} <= 0.01");
            }),
            'mixed' => $query->where("{$table}.is_local_sale", null)
                ->whereRaw("{$localityRateExpr} > 0.01")
                ->whereRaw("{$localityRateExpr} < 99.99"),
            'no_sales' => $query->where("{$table}.sales_count", '<=', 0),
            default => $query,
        };
    }

    /**
     * Быстрые пресеты фильтрации для UE-таблицы.
     */
    public function scopeQuickFilter(Builder $query, ?string $quickFilter): Builder
    {
        $quickFilter = strtolower(trim((string) $quickFilter));
        if ($quickFilter === '') {
            return $query;
        }

        $table = $this->getTable();
        $confidenceExpr = $this->jsonTextExpr($query, 'marketplace_data', 'calculation_confidence');
        $localityRateExpr = $this->jsonNumberExpr($query, 'marketplace_data', 'expected_locality_rate');

        return match ($quickFilter) {
            'unprofitable', 'negative_margin' => $query->where("{$table}.net_profit", '<=', 0),
            'no_sales_28d' => $query->where("{$table}.sales_count", '<=', 0),
            'low_confidence' => $query->whereRaw("LOWER({$confidenceExpr}) = 'low'"),
            'high_non_locality', 'locality_risk' => $query->where(function (Builder $q) use ($table, $localityRateExpr) {
                $q->whereRaw("{$localityRateExpr} <= 50")
                    ->orWhere("{$table}.is_local_sale", false);
            }),
            'high_non_local_markup' => $query->where("{$table}.non_local_markup_percent", '>=', 4),
            'data_gap' => $query->where(function (Builder $q) use ($table) {
                $q->where("{$table}.price", '<=', 0)
                    ->orWhere("{$table}.cost_price", '<=', 0)
                    ->orWhere("{$table}.commission_percent", '<=', 0);
            }),
            default => $query,
        };
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

    private function jsonTextExpr(Builder $query, string $column, string $key): string
    {
        $table = $this->getTable();
        $qualified = "{$table}.{$column}";
        $driver = $query->getConnection()->getDriverName();

        return match ($driver) {
            'pgsql' => "COALESCE({$qualified}->>'{$key}', '')",
            'mysql', 'mariadb' => "COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$qualified}, '$.{$key}')), '')",
            'sqlite' => "COALESCE(json_extract({$qualified}, '$.{$key}'), '')",
            default => "''",
        };
    }

    private function jsonNumberExpr(Builder $query, string $column, string $key): string
    {
        $table = $this->getTable();
        $qualified = "{$table}.{$column}";
        $driver = $query->getConnection()->getDriverName();

        return match ($driver) {
            'pgsql' => "COALESCE(NULLIF({$qualified}->>'{$key}', ''), '0')::numeric",
            'mysql', 'mariadb' => "COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT({$qualified}, '$.{$key}')) AS DECIMAL(14,4)), 0)",
            'sqlite' => "COALESCE(CAST(json_extract({$qualified}, '$.{$key}') AS REAL), 0)",
            default => '0',
        };
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
