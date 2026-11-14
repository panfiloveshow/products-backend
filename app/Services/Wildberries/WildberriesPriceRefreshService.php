<?php

namespace App\Services\Wildberries;

use App\Domains\Wildberries\Api\ProductsApi;
use App\Domains\Wildberries\Api\WildberriesClient;
use App\Domains\Wildberries\Api\WildberriesRateLimitException;
use App\Models\Product;
use RuntimeException;

class WildberriesPriceRefreshService
{
    /**
     * Refresh only seller prices from the authoritative WB Prices API.
     *
     * @return array{total:int, matched:int, updated:int, unchanged:int, missing:int}
     */
    public function refresh(int $integrationId, string $apiKey): array
    {
        $client = new WildberriesClient($apiKey);
        $prices = (new ProductsApi($client))->getPrices();

        if ($prices === []) {
            if ($client->getLastResponseStatus() === 429) {
                throw new WildberriesRateLimitException(
                    $client->getLastRateLimitRetryAfter() ?? 60,
                );
            }

            throw new RuntimeException('WB Prices API returned no prices');
        }

        $integration = new \App\Models\Integration;
        $integration->forceFill(['id' => $integrationId, 'marketplace' => 'wildberries']);

        return app(\App\Services\UnitEconomics\MarketplacePriceObservation::class)->apply($integration, $prices, 0);
    }

    /**
     * @param  array<string,array<string,mixed>>  $prices
     * @param  array<string,mixed>  $wbData
     * @return array{0:?array,1:string}
     */
    public function resolvePriceData(array $prices, Product $product, array $wbData): array
    {
        $nmId = trim((string) ($wbData['nmID'] ?? ''));
        $sizeId = trim((string) ($wbData['sizeID'] ?? $wbData['chrtID'] ?? ''));
        $sizeKey = $nmId !== '' && $sizeId !== '' ? $nmId.':'.$sizeId : null;

        if ($sizeKey !== null && isset($prices[$sizeKey])) {
            return [$prices[$sizeKey], 'prices_api_size'];
        }

        $vendorCode = trim((string) ($product->vendor_code ?? ''));
        if ($vendorCode !== '' && isset($prices[$vendorCode])) {
            return [$prices[$vendorCode], 'prices_api_nm'];
        }

        if ($nmId !== '' && isset($prices[$nmId])) {
            return [$prices[$nmId], 'prices_api_nm'];
        }

        return [null, 'prices_api_missing'];
    }

}
