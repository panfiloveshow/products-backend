<?php

namespace App\Domains\Uzum\Api;

/**
 * Товарные эндпоинты Uzum Seller OpenAPI.
 */
class ProductsApi
{
    public function __construct(private readonly UzumClient $client)
    {
    }

    /**
     * Список собственных магазинов продавца.
     * GET /v1/shops
     *
     * @return array<int, array<string, mixed>>
     */
    public function getShops(): array
    {
        $response = $this->client->get('/v1/shops');

        // Ответ может быть как массивом, так и {data: [...]} / {payload: [...]}
        if (is_array($response)) {
            if (isset($response['data']) && is_array($response['data'])) {
                return $response['data'];
            }
            if (isset($response['payload']) && is_array($response['payload'])) {
                return $response['payload'];
            }
            return $response;
        }

        return [];
    }

    /**
     * Одна страница товаров магазина.
     * GET /v1/product/shop/{shopId}
     *
     * @return array{productList: array<int, array<string, mixed>>, total: int}
     */
    public function getProducts(int $shopId, int $page, int $size = 100, string $filter = 'ALL'): array
    {
        $response = $this->client->get("/v1/product/shop/{$shopId}", [
            'page' => $page,
            'size' => $size,
            'filter' => $filter,
        ]);

        $payload = $response['data'] ?? $response ?? [];

        return [
            'productList' => $payload['productList'] ?? [],
            'total' => (int) ($payload['totalProductsAmount'] ?? 0),
        ];
    }
}
