<?php

namespace App\Services\Marketplace;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ozon Seller API Service
 * Актуальные эндпоинты на 2024-2025 год
 * Документация: https://docs.ozon.ru/api/seller
 */
class OzonService implements MarketplaceInterface
{
    private string $clientId;
    private string $apiKey;
    private string $baseUrl = 'https://api-seller.ozon.ru';

    public function __construct(?string $clientId = null, ?string $apiKey = null)
    {
        $this->clientId = $clientId ?? config('services.ozon.client_id', '');
        $this->apiKey = $apiKey ?? config('services.ozon.api_key', '');
    }

    /**
     * Получение списка товаров
     * Актуальный эндпоинт: POST /v3/product/list + POST /v3/product/info/list
     * Документация: https://docs.ozon.ru/api/seller
     */
    public function getProducts(): array
    {
        try {
            $products = [];
            $lastId = '';
            
            do {
                // POST /v3/product/list - актуальный эндпоинт
                $response = Http::withHeaders([
                    'Client-Id' => $this->clientId,
                    'Api-Key' => $this->apiKey,
                ])->post("{$this->baseUrl}/v3/product/list", [
                    'filter' => [
                        'visibility' => 'ALL',
                    ],
                    'last_id' => $lastId,
                    'limit' => 1000,
                ]);

                if (!$response->successful()) {
                    Log::error('Ozon API error (getProducts)', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    break;
                }

                $data = $response->json();
                $items = $data['result']['items'] ?? [];
                
                if (empty($items)) {
                    break;
                }

                // Собираем product_id для получения подробной информации
                $productIds = array_column($items, 'product_id');
                
                // POST /v3/product/info/list - получаем подробную информацию
                $infoResponse = Http::withHeaders([
                    'Client-Id' => $this->clientId,
                    'Api-Key' => $this->apiKey,
                ])->post("{$this->baseUrl}/v3/product/info/list", [
                    'product_id' => $productIds,
                ]);

                if ($infoResponse->successful()) {
                    $infoData = $infoResponse->json();
                    foreach ($infoData['items'] ?? [] as $productInfo) {
                        $products[] = $this->transformProduct($productInfo);
                    }
                }

                $lastId = $data['result']['last_id'] ?? '';
                
            } while (!empty($lastId) && count($products) < 10000);

            Log::info('Ozon products fetched', ['count' => count($products)]);
            return $products;
            
        } catch (\Exception $e) {
            Log::error('Ozon getProducts error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Трансформация товара Ozon в формат Product
     */
    private function transformProduct(array $item): array
    {
        // Получаем цену
        $price = 0;
        $oldPrice = null;
        if (isset($item['price'])) {
            $price = (float)str_replace(' ', '', $item['price']);
        }
        if (isset($item['old_price']) && $item['old_price'] != $item['price']) {
            $oldPrice = (float)str_replace(' ', '', $item['old_price']);
        }

        return [
            'sku' => $item['offer_id'] ?? (string)$item['id'],
            'name' => $item['name'] ?? 'Без названия',
            'barcode' => $item['barcode'] ?? null,
            'price' => $price,
            'old_price' => $oldPrice,
            'stock' => 0, // Остатки получаем отдельно через getInventory
            'description' => $item['description'] ?? null,
            'images' => $item['images'] ?? [],
            'category' => $item['description_category_id'] ?? $item['category_id'] ?? null,
            'brand' => $item['brand'] ?? null,
            'rating' => $item['rating'] ?? null,
            'reviews_count' => $item['reviews_count'] ?? 0,
            'marketplace' => 'ozon',
            'marketplace_id' => (string)$item['id'],
            'url' => "https://www.ozon.ru/product/{$item['id']}",
            'ozon_data' => [
                'product_id' => $item['id'],
                'offer_id' => $item['offer_id'] ?? null,
                'fbo_sku' => $item['fbo_sku'] ?? null,
                'fbs_sku' => $item['fbs_sku'] ?? null,
                'sku' => $item['sku'] ?? null,
                'status' => $item['status'] ?? null,
                'visible' => $item['visible'] ?? null,
                'primary_image' => $item['primary_image'] ?? null,
            ],
        ];
    }

    /**
     * Получение остатков по складам
     * Актуальный эндпоинт: POST /v4/product/info/stocks
     * Документация: https://docs.ozon.ru/api/seller
     */
    public function getInventory(): array
    {
        try {
            $inventory = [];
            $lastId = '';
            
            do {
                // POST /v4/product/info/stocks - актуальный эндпоинт (v3 deprecated)
                $response = Http::withHeaders([
                    'Client-Id' => $this->clientId,
                    'Api-Key' => $this->apiKey,
                ])->post("{$this->baseUrl}/v4/product/info/stocks", [
                    'filter' => [
                        'visibility' => 'ALL',
                    ],
                    'last_id' => $lastId,
                    'limit' => 1000,
                ]);

                if (!$response->successful()) {
                    Log::error('Ozon API error (getInventory)', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    break;
                }

                $data = $response->json();
                $items = $data['result']['items'] ?? [];

                foreach ($items as $item) {
                    foreach ($item['stocks'] ?? [] as $stock) {
                        $inventory[] = [
                            'sku' => $item['offer_id'],
                            'warehouse_id' => $stock['type'],
                            'warehouse_name' => $this->getWarehouseTypeName($stock['type']),
                            'marketplace' => 'ozon',
                            'quantity' => $stock['present'] ?? 0,
                        ];
                    }
                }

                $lastId = $data['result']['last_id'] ?? '';
                
            } while (!empty($lastId) && count($inventory) < 50000);

            Log::info('Ozon inventory fetched', ['count' => count($inventory)]);
            return $inventory;
            
        } catch (\Exception $e) {
            Log::error('Ozon getInventory error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function getWarehouseTypeName(string $type): string
    {
        return match ($type) {
            'fbo' => 'FBO (Ozon)',
            'fbs' => 'FBS (Продавец)',
            'crossborder' => 'Crossborder',
            default => $type,
        };
    }

    /**
     * Получение списка складов
     * Актуальный эндпоинт: POST /v2/warehouse/list
     */
    public function getWarehouses(): array
    {
        try {
            $response = Http::withHeaders([
                'Client-Id' => $this->clientId,
                'Api-Key' => $this->apiKey,
            ])->post("{$this->baseUrl}/v2/warehouse/list", []);

            if (!$response->successful()) {
                Log::error('Ozon getWarehouses error', [
                    'status' => $response->status(),
                ]);
                return [];
            }

            $data = $response->json();
            return array_map(function ($wh) {
                return [
                    'id' => $wh['warehouse_id'],
                    'name' => $wh['name'],
                    'is_rfbs' => $wh['is_rfbs'] ?? false,
                ];
            }, $data['result'] ?? []);
            
        } catch (\Exception $e) {
            Log::error('Ozon getWarehouses error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Получение статистики продаж
     * Актуальный эндпоинт: POST /v1/analytics/data
     */
    public function getSalesStats(string $dateFrom, string $dateTo): array
    {
        try {
            $response = Http::withHeaders([
                'Client-Id' => $this->clientId,
                'Api-Key' => $this->apiKey,
            ])->post("{$this->baseUrl}/v1/analytics/data", [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'metrics' => ['revenue', 'ordered_units', 'returns'],
                'dimension' => ['sku'],
                'limit' => 1000,
            ]);

            if (!$response->successful()) {
                return [];
            }

            return $response->json()['result']['data'] ?? [];
            
        } catch (\Exception $e) {
            Log::error('Ozon getSalesStats error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Получение комиссий и цен
     * Актуальный эндпоинт: POST /v5/product/info/prices
     */
    public function getCommissions(): array
    {
        try {
            $response = Http::withHeaders([
                'Client-Id' => $this->clientId,
                'Api-Key' => $this->apiKey,
            ])->post("{$this->baseUrl}/v5/product/info/prices", [
                'filter' => [
                    'visibility' => 'ALL',
                ],
                'limit' => 1000,
            ]);

            if (!$response->successful()) {
                return [];
            }

            return $response->json()['result']['items'] ?? [];
            
        } catch (\Exception $e) {
            Log::error('Ozon getCommissions error', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
