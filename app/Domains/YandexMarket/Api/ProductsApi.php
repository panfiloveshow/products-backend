<?php

namespace App\Domains\YandexMarket\Api;

use App\Domains\Marketplace\Contracts\ProductsApiInterface;
use App\Models\Integration;

/**
 * API для работы с товарами Yandex Market
 *
 * Endpoints (Partner API v2):
 * - POST /v2/businesses/{businessId}/offer-mappings — список товаров в каталоге (цена — offer.basicPrice)
 * - POST /v2/businesses/{businessId}/offer-prices — цены для всех магазинов
 */
class ProductsApi implements ProductsApiInterface
{
    public function __construct(
        private YandexMarketClient $client
    ) {}

    /**
     * Получить список товаров
     */
    public function getProducts(?Integration $integration = null, array $options = []): array
    {
        $limit = min(100, max(1, (int) ($options['limit'] ?? 100)));
        $pageToken = $options['page_token'] ?? null;
        $businessId = $this->client->resolveBusinessId();

        $query = array_filter([
            'limit' => $limit,
            'page_token' => $pageToken,
        ]);

        $response = $this->client->post(
            '/v2/businesses/'.$businessId.'/offer-mappings',
            [],
            $query
        );

        if (! $response) {
            return [];
        }

        return [
            'items' => $response['result']['offerMappings'] ?? [],
            'paging' => $response['result']['paging'] ?? null,
        ];
    }

    /**
     * Получить товар по SKU (shopSku)
     */
    public function getProductBySku(string $sku, ?Integration $integration = null): ?array
    {
        $businessId = $this->client->resolveBusinessId();
        $response = $this->client->post('/v2/businesses/'.$businessId.'/offer-mappings', [
            'offerIds' => [$sku],
        ]);

        $items = $response['result']['offerMappings'] ?? [];

        return $items[0] ?? null;
    }

    /**
     * Получить цены товаров (цены для всех магазинов кабинета)
     *
     * POST /v2/businesses/{businessId}/offer-prices — limit (≤ 500) и page_token в query.
     * GET /v2/campaigns/{campaignId}/offer-prices устарел (отключение 05.04.2027).
     */
    public function getPrices(?Integration $integration = null, array $skus = []): array
    {
        $businessId = $this->client->resolveBusinessId();
        $allPrices = [];
        $pageToken = null;
        $pages = 0;

        do {
            $response = $this->client->post(
                '/v2/businesses/'.$businessId.'/offer-prices',
                [],
                array_filter(['limit' => 500, 'page_token' => $pageToken])
            );

            $items = $response['result']['offers'] ?? [];
            $pageToken = $response['result']['paging']['nextPageToken'] ?? null;

            foreach ($items as $item) {
                $sku = $item['offerId'] ?? null;
                if (! $sku) {
                    continue;
                }

                if (! empty($skus) && ! in_array($sku, $skus)) {
                    continue;
                }

                $priceData = $this->extractPrice($item['price'] ?? []);
                if ($priceData === null) {
                    continue;
                }

                $allPrices[$sku] = $priceData;
            }
        } while (! empty($items) && $pageToken && ++$pages < 200);

        return $allPrices;
    }

    /** @param array{value?: float, discountBase?: float, currencyId?: string} $price OfferDefaultPriceDTO */
    private function extractPrice(array $price): ?array
    {
        $value = isset($price['value']) ? (float) $price['value'] : null;
        if ($value === null || $value <= 0) {
            return null;
        }

        return [
            'price' => $value,
            'actual_price' => $value,
            'old_price' => isset($price['discountBase']) ? (float) $price['discountBase'] : null,
            'currency' => $price['currencyId'] ?? 'RUR',
        ];
    }

    /**
     * Получить все товары с пагинацией
     */
    public function getAllProducts(Integration $integration, int $batchSize = 100): \Generator
    {
        $pageToken = null;

        do {
            $result = $this->getProducts($integration, [
                'limit' => min(100, max(1, $batchSize)),
                'page_token' => $pageToken,
            ]);

            $items = $result['items'] ?? [];
            $pageToken = $result['paging']['nextPageToken'] ?? null;

            foreach ($items as $item) {
                yield $item;
            }

        } while (! empty($items) && $pageToken);
    }
}
