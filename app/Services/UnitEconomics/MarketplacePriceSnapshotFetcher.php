<?php

namespace App\Services\UnitEconomics;

use App\Domains\Ozon\Api\OzonClient;
use App\Domains\Ozon\Api\ProductsApi as OzonProductsApi;
use App\Domains\Wildberries\Api\ProductsApi as WildberriesProductsApi;
use App\Domains\Wildberries\Api\WildberriesClient;
use App\Models\Integration;
use InvalidArgumentException;

class MarketplacePriceSnapshotFetcher
{
    public function fetch(Integration $integration): array
    {
        $credentials = $integration->resolveCredentials();

        return match ($integration->marketplace) {
            'wildberries' => (new WildberriesProductsApi(new WildberriesClient($credentials['api_key'] ?? '')))->getPriceSnapshot($integration),
            'ozon' => (new OzonProductsApi(OzonClient::fromIntegration($integration)))->getPriceSnapshot($integration,
                \App\Models\Product::where('integration_id', $integration->id)->where('marketplace', 'ozon')->pluck('sku')->map(fn ($sku) => (string) $sku)->all()),
            default => throw new InvalidArgumentException('Unsupported price marketplace'),
        };
    }

    public function scopeKey(Integration $integration): string
    {
        $credentials = $integration->resolveCredentials();
        $seller = $integration->marketplace === 'ozon' ? ($credentials['client_id'] ?? null) : ($credentials['seller_id'] ?? null);
        if (! $seller && $integration->marketplace === 'wildberries') {
            $parts = explode('.', (string) ($credentials['api_key'] ?? ''));
            $claims = json_decode(base64_decode(strtr($parts[1] ?? '', '-_', '+/')) ?: '', true);
            $seller = is_array($claims) ? ($claims['sid'] ?? null) : null;
        }
        $identity = $seller ?: $integration->credentialFingerprint() ?: 'integration:'.$integration->id;

        return 'ue-price:'.hash('sha256', $integration->marketplace.'|'.$identity.'|prices');
    }
}
