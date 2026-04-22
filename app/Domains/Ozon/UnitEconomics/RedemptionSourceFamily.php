<?php

namespace App\Domains\Ozon\UnitEconomics;

/**
 * Семейство источника redemption_rate для фронт-контракта.
 * Стабильные значения, которые не меняются при добавлении новых конкретных источников.
 */
enum RedemptionSourceFamily: string
{
    case Postings = 'postings';   // выкуп посчитан по реальным заказам
    case Api = 'api';              // выкуп посчитан по API-агрегату
    case NoSales = 'no_sales';     // за период продаж не было
    case Fallback = 'fallback';    // деградация — посчитали не идеально
    case Manual = 'manual';        // ручной override продавца
    case Product = 'product';      // из product.ozon_data
    case Default = 'default';      // ничего не сработало — дефолт маркетплейса

    /**
     * Можно ли доверять цифре без проверки пользователем.
     */
    public function isReliable(): bool
    {
        return match ($this) {
            self::Postings, self::Api, self::Manual => true,
            default => false,
        };
    }
}
