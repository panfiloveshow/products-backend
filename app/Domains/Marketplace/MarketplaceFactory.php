<?php

namespace App\Domains\Marketplace;

use App\Domains\Marketplace\Contracts\MarketplaceInterface;
use App\Domains\Wildberries\WildberriesMarketplace;
use App\Domains\Ozon\OzonMarketplace;
use App\Domains\YandexMarket\YandexMarketMarketplace;
use App\Models\Integration;
use InvalidArgumentException;

/**
 * Фабрика для создания сервисов маркетплейсов
 * 
 * Заменяет старую App\Services\Marketplace\MarketplaceFactory
 */
class MarketplaceFactory
{
    /**
     * Создать сервис маркетплейса
     */
    public static function create(string $marketplace, array $credentials = [], ?Integration $integration = null): MarketplaceInterface
    {
        return match ($marketplace) {
            'wildberries' => new WildberriesMarketplace($credentials),
            'ozon' => new OzonMarketplace($credentials, $integration),
            'yandex', 'yandex_market' => new YandexMarketMarketplace($credentials),
            default => throw new InvalidArgumentException("Unknown marketplace: {$marketplace}"),
        };
    }

    /**
     * Получить список поддерживаемых маркетплейсов
     */
    public static function getSupportedMarketplaces(): array
    {
        return ['wildberries', 'ozon', 'yandex', 'yandex_market'];
    }
}
