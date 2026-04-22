<?php

namespace App\Domains\Ozon\UnitEconomics;

/**
 * Источники расчёта `redemption_rate` (% выкупа).
 *
 * Разбросанность значений по коду + миграциям вызывала путаницу:
 * значение 'api' писалось и для Ozon (через /v1/analytics/data), и для Yandex,
 * и для WB — но смысл в каждом маркетплейсе разный. После сегодняшних фиксов
 * для Ozon больше не пишется 'api' (только 'postings_28d', 'analytics_api_28d',
 * 'no_sales_28d'), но старые значения в БД остались.
 *
 * Для UI нужен стабильный family-контракт: postings / api / manual / fallback / none —
 * независимо от конкретного sub-источника.
 */
enum RedemptionSource: string
{
    // Ozon — реальные postings за 28 дней (основной источник после 2026-04-22):
    case Postings28d = 'postings_28d';

    // Ozon — /v1/analytics/data (fallback когда postings нет):
    case AnalyticsApi28d = 'analytics_api_28d';

    // Ozon — за 28д нет заказов вообще:
    case NoSales28d = 'no_sales_28d';

    // Ozon — non-Premium fallback через отдельные метрики (30 дней):
    case FallbackOrdersReturns = 'fallback_orders_returns';
    case FallbackPartial = 'fallback_partial';

    // Yandex/WB и legacy Ozon:
    case Api = 'api';
    case ApiOrdersSkuSales = 'api_orders_sku_sales';

    // Ручной override в UnitEconomicsSettings:
    case Manual = 'manual';

    // Из product-data (редко):
    case Product = 'product';

    // Дефолт маркетплейса (85% Ozon, 80% WB, 90% Yandex):
    case Default = 'default';

    /**
     * Семейство источника — стабильный контракт для фронтенда.
     * При добавлении новых значений .value список может расти; .family не меняется.
     */
    public function family(): RedemptionSourceFamily
    {
        return match ($this) {
            self::Postings28d => RedemptionSourceFamily::Postings,
            self::AnalyticsApi28d,
            self::Api,
            self::ApiOrdersSkuSales => RedemptionSourceFamily::Api,
            self::NoSales28d => RedemptionSourceFamily::NoSales,
            self::FallbackOrdersReturns,
            self::FallbackPartial => RedemptionSourceFamily::Fallback,
            self::Manual => RedemptionSourceFamily::Manual,
            self::Product => RedemptionSourceFamily::Product,
            self::Default => RedemptionSourceFamily::Default,
        };
    }

    /**
     * Свежий per-sync источник (имеет приоритет над existingUE в CacheService).
     */
    public function isFresh(): bool
    {
        return match ($this) {
            self::Postings28d,
            self::AnalyticsApi28d,
            self::NoSales28d,
            self::FallbackOrdersReturns,
            self::FallbackPartial => true,
            default => false,
        };
    }

    /**
     * Период в днях, на который опирался источник (для UI-подписи «за N дней»).
     */
    public function periodDays(): int
    {
        return match ($this) {
            self::Postings28d,
            self::AnalyticsApi28d,
            self::NoSales28d => 28,
            self::FallbackOrdersReturns,
            self::FallbackPartial => 30,
            default => 28,
        };
    }

    public static function families(): array
    {
        return array_map(fn (self $c) => $c->family()->value, self::cases());
    }

    /**
     * Безопасный tryFrom для external string — возвращает Default при незнакомом коде.
     */
    public static function fromStringSafe(?string $code): self
    {
        if ($code === null) {
            return self::Default;
        }
        return self::tryFrom($code) ?? self::Default;
    }
}
