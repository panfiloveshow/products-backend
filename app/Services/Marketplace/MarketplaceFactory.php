<?php

namespace App\Services\Marketplace;

use InvalidArgumentException;

class MarketplaceFactory
{
    public static function create(string $marketplace, array $credentials = []): MarketplaceInterface
    {
        return match ($marketplace) {
            'wildberries' => new WildberriesService($credentials['api_key'] ?? null),
            'ozon' => new OzonService(
                $credentials['client_id'] ?? null,
                $credentials['api_key'] ?? null
            ),
            'yandex', 'yandex_market' => new YandexMarketService(
                $credentials['token'] ?? null,
                $credentials['campaign_id'] ?? null
            ),
            default => throw new InvalidArgumentException("Unknown marketplace: {$marketplace}"),
        };
    }
}
