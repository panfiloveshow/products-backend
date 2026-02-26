<?php

namespace App\Services\Marketplace;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Yandex Market Partner API Service
 * Актуальные эндпоинты на 2024-2025 год
 * Документация: https://yandex.ru/dev/market/partner-api
 */
class YandexMarketService implements MarketplaceInterface
{
    private string $token;
    private string $campaignId;
    private string $baseUrl = 'https://api.partner.market.yandex.ru';

    public function __construct(?string $token = null, ?string $campaignId = null)
    {
        $this->token = $token ?? config('services.yandex_market.token', '');
        $this->campaignId = $campaignId ?? config('services.yandex_market.campaign_id', '');
    }

    /**
     * Получение списка товаров
     * Актуальный эндпоинт: GET /campaigns/{campaignId}/offer-mapping-entries
     * Документация: https://yandex.ru/dev/market/partner-api/doc/ru/reference/offer-mappings
     */
    public function getProducts(): array
    {
        try {
            $products = [];
            $pageToken = null;
            
            do {
                $params = ['limit' => 200];
                if ($pageToken) {
                    $params['page_token'] = $pageToken;
                }
                
                $response = Http::withHeaders([
                    'Authorization' => "OAuth {$this->token}",
                    'Content-Type' => 'application/json',
                ])->get("{$this->baseUrl}/campaigns/{$this->campaignId}/offer-mapping-entries", $params);

                if (!$response->successful()) {
                    Log::error('Yandex Market API error (getProducts)', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    break;
                }

                $data = $response->json();
                $entries = $data['result']['offerMappingEntries'] ?? [];
                
                foreach ($entries as $entry) {
                    $products[] = $this->transformProduct($entry);
                }

                // Пагинация
                $pageToken = $data['result']['paging']['nextPageToken'] ?? null;
                
            } while ($pageToken && count($products) < 10000);

            Log::info('Yandex Market products fetched', ['count' => count($products)]);
            return $products;
            
        } catch (\Exception $e) {
            Log::error('YM getProducts error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Трансформация товара YM в формат Product
     */
    private function transformProduct(array $entry): array
    {
        $offer = $entry['offer'] ?? [];
        $mapping = $entry['mapping'] ?? [];

        // Получаем цену
        $price = null;
        if (isset($offer['price']['value'])) {
            $price = (float)$offer['price']['value'];
        }

        return [
            'sku' => $offer['shopSku'] ?? '',
            'name' => $offer['name'] ?? 'Без названия',
            'barcode' => $offer['barcodes'][0] ?? null,
            'price' => $price,
            'old_price' => null,
            'stock' => 0, // Остатки получаем отдельно
            'description' => $offer['description'] ?? null,
            'images' => $offer['pictures'] ?? $offer['urls'] ?? [],
            'category' => $offer['category'] ?? $mapping['categoryId'] ?? null,
            'brand' => $offer['vendor'] ?? null,
            'rating' => null,
            'reviews_count' => 0,
            'marketplace' => 'yandex',
            'marketplace_id' => (string)($mapping['marketSku'] ?? $offer['shopSku']),
            'url' => isset($mapping['marketSku']) 
                ? "https://market.yandex.ru/product/{$mapping['marketSku']}" 
                : null,
            'yandex_data' => [
                'shopSku' => $offer['shopSku'] ?? null,
                'marketSku' => $mapping['marketSku'] ?? null,
                'categoryId' => $mapping['categoryId'] ?? null,
                'modelId' => $mapping['modelId'] ?? null,
                'vendorCode' => $offer['vendorCode'] ?? null,
                'availability' => $offer['availability'] ?? null,
                'processingState' => $offer['processingState'] ?? null,
            ],
        ];
    }

    /**
     * Получение остатков по складам
     * Актуальный эндпоинт: POST /campaigns/{campaignId}/offers/stocks
     */
    public function getInventory(): array
    {
        try {
            $inventory = [];
            $pageToken = null;
            
            do {
                $body = ['limit' => 200];
                if ($pageToken) {
                    $body['page_token'] = $pageToken;
                }
                
                $response = Http::withHeaders([
                    'Authorization' => "OAuth {$this->token}",
                    'Content-Type' => 'application/json',
                ])->post("{$this->baseUrl}/campaigns/{$this->campaignId}/offers/stocks", $body);

                if (!$response->successful()) {
                    Log::error('YM API error (getInventory)', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    break;
                }

                $data = $response->json();
                
                foreach ($data['result']['warehouses'] ?? [] as $warehouse) {
                    foreach ($warehouse['offers'] ?? [] as $offer) {
                        foreach ($offer['stocks'] ?? [] as $stock) {
                            $inventory[] = [
                                'sku' => $offer['shopSku'],
                                'warehouse_id' => (string)$warehouse['warehouseId'],
                                'warehouse_name' => $warehouse['warehouseName'] ?? "Склад {$warehouse['warehouseId']}",
                                'marketplace' => 'yandex',
                                'quantity' => $stock['count'] ?? 0,
                            ];
                        }
                    }
                }

                $pageToken = $data['result']['paging']['nextPageToken'] ?? null;
                
            } while ($pageToken && count($inventory) < 50000);

            Log::info('Yandex Market inventory fetched', ['count' => count($inventory)]);
            return $inventory;
            
        } catch (\Exception $e) {
            Log::error('YM getInventory error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Получение списка складов
     * Актуальный эндпоинт: GET /warehouses
     */
    public function getWarehouses(): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "OAuth {$this->token}",
                'Content-Type' => 'application/json',
            ])->get("{$this->baseUrl}/warehouses");

            if (!$response->successful()) {
                Log::error('YM getWarehouses error', ['status' => $response->status()]);
                return [];
            }

            $data = $response->json();
            return array_map(function ($wh) {
                return [
                    'id' => $wh['id'],
                    'name' => $wh['name'],
                    'address' => $wh['address'] ?? null,
                ];
            }, $data['result']['warehouses'] ?? []);
            
        } catch (\Exception $e) {
            Log::error('YM getWarehouses error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Получение статистики продаж
     * Актуальный эндпоинт: POST /campaigns/{campaignId}/stats/skus
     */
    public function getSalesStats(string $dateFrom, string $dateTo): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "OAuth {$this->token}",
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/campaigns/{$this->campaignId}/stats/skus", [
                'shopSkus' => [],
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
            ]);

            if (!$response->successful()) {
                return [];
            }

            return $response->json()['result']['shopSkus'] ?? [];
            
        } catch (\Exception $e) {
            Log::error('YM getSalesStats error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Получение комиссий
     * Актуальный эндпоинт: POST /tariffs/calculate
     */
    public function getCommissions(): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "OAuth {$this->token}",
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/tariffs/calculate", [
                'campaignId' => $this->campaignId,
            ]);

            if (!$response->successful()) {
                return [];
            }

            return $response->json()['result'] ?? [];
            
        } catch (\Exception $e) {
            Log::error('YM getCommissions error', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
