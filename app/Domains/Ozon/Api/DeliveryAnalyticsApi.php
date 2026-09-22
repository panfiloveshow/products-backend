<?php

namespace App\Domains\Ozon\Api;

/**
 * Аналитика доставки Ozon для профилей доставки SKU (юнит-экономика)
 *
 * Источник — «Аналитика → География продаж → Среднее время доставки»:
 * /v1/analytics/average-delivery-time и /details. Ozon удалил их 19.05.2026, прямой
 * замены (время доставки и заказы по кластерам) нет. Ближайшее — бета
 * /v1/analytics/local-sale/* (локальность и переплата), см. AnalyticsApi::getLocalizationIndex().
 *
 * @see https://docs.ozon.ru/api/seller
 */
class DeliveryAnalyticsApi
{
    public const SUPPLY_PERIOD_EIGHT_WEEKS = 'EIGHT_WEEKS';
    public const DELIVERY_SCHEMA_ALL = 'ALL';

    public function __construct(
        private OzonClient $client
    ) {}

    /**
     * Рекомендации по поставкам и заказы по кластерам для профилей доставки SKU.
     * Источник удалён Ozon — падаем явно, вызывающий (SyncUnitEconomicsCommand)
     * ловит исключение и считает без профилей доставки.
     */
    public function getSupplyRecommendations(
        array $clusterIds = [],
        string $deliverySchema = self::DELIVERY_SCHEMA_ALL,
        string $supplyPeriod = self::SUPPLY_PERIOD_EIGHT_WEEKS
    ): array {
        throw new \RuntimeException(
            'Ozon удалил /v1/analytics/average-delivery-time (19.05.2026), прямой замены нет — профили доставки не строятся'
        );
    }
}
