<?php

namespace App\Domains\YandexMarket\Api;

use App\Domains\Marketplace\Contracts\ProductsApiInterface;
use App\Models\Integration;

/**
 * API для работы с товарами Yandex Market
 *
 * Endpoints (Partner API v2):
 * - POST /v2/businesses/{businessId}/offer-mappings — список товаров в каталоге
 * - GET /v2/campaigns/{campaignId}/offer-prices — цены
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
     * Получить цены товаров
     */
    public function getPrices(?Integration $integration = null, array $skus = []): array
    {
        $allPrices = [];
        $pageToken = null;

        do {
            $params = ['limit' => 200];
            if ($pageToken) {
                $params['page_token'] = $pageToken;
            }

            $response = $this->client->get(
                '/v2/campaigns/{campaignId}/offer-prices',
                $params
            );

            if (! $response) {
                break;
            }

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

                $allPrices[$sku] = [
                    'price' => (float) ($item['price']['value'] ?? 0),
                    'currency' => $item['price']['currencyId'] ?? 'RUR',
                ];
            }

        } while (! empty($items) && $pageToken);

        return $allPrices;
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
