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

        $stats = [
            'total' => 0,
            'matched' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'missing' => 0,
        ];

        Product::query()
            ->where('integration_id', $integrationId)
            ->where('marketplace', 'wildberries')
            ->orderBy('id')
            ->chunk(500, function ($products) use ($prices, &$stats): void {
                foreach ($products as $product) {
                    $stats['total']++;
                    $wbData = is_array($product->wb_data) ? $product->wb_data : [];
                    [$priceData, $source] = $this->resolvePriceData($prices, $product, $wbData);

                    if ($priceData === null) {
                        $stats['missing']++;

                        continue;
                    }

                    $actualPrice = (float) ($priceData['final_price']
                        ?? $priceData['discounted_price']
                        ?? $priceData['price']
                        ?? 0);
                    if ($actualPrice <= 0) {
                        $stats['missing']++;

                        continue;
                    }

                    $stats['matched']++;
                    $basePrice = (float) ($priceData['price'] ?? 0);
                    $oldPrice = $basePrice > $actualPrice ? $basePrice : null;
                    $priceChanged = abs((float) $product->price - $actualPrice) > 0.009;
                    $oldPriceChanged = $this->nullablePriceChanged($product->old_price, $oldPrice);

                    if (! $priceChanged && ! $oldPriceChanged) {
                        $stats['unchanged']++;

                        continue;
                    }

                    $product->forceFill([
                        'price' => $actualPrice,
                        'old_price' => $oldPrice,
                        'wb_data' => array_merge($wbData, [
                            'actual_price' => $actualPrice,
                            'old_price' => $oldPrice,
                            'price_source' => $source,
                            'prices_by_size' => $priceData['sizes'] ?? ($wbData['prices_by_size'] ?? []),
                            'price_synced_at' => now()->utc()->toIso8601String(),
                        ]),
                    ])->save();
                    $stats['updated']++;
                }
            });

        return $stats;
    }

    /**
     * @param  array<string,array<string,mixed>>  $prices
     * @param  array<string,mixed>  $wbData
     * @return array{0:?array,1:string}
     */
    private function resolvePriceData(array $prices, Product $product, array $wbData): array
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

    private function nullablePriceChanged(mixed $current, ?float $next): bool
    {
        if ($current === null || $current === '') {
            return $next !== null;
        }

        if ($next === null) {
            return true;
        }

        return abs((float) $current - $next) > 0.009;
    }
}
