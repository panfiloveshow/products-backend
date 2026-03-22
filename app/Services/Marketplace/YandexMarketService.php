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
    private string $defaultCurrency = 'RUR';

    public function __construct(?string $token = null, ?string $campaignId = null)
    {
        $this->token = $token ?? config('services.yandex_market.token') ?? '';
        $this->campaignId = $campaignId ?? config('services.yandex_market.campaign_id') ?? '';
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
            'marketplace' => 'yandex_market',
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
                                'marketplace' => 'yandex_market',
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
        Log::warning('YM getCommissions called without offer context, returning empty result');
        return [];
    }

    public function calculateTariffs(array $offers, array $parameters = []): array
    {
        if (empty($offers)) {
            return [];
        }

        try {
            $sellingProgram = strtoupper((string) ($parameters['sellingProgram'] ?? $parameters['selling_program'] ?? 'FBY'));
            $response = Http::withHeaders([
                'Authorization' => "OAuth {$this->token}",
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/v2/tariffs/calculate", [
                'parameters' => [
                    'campaignId' => (int) ($parameters['campaignId'] ?? $parameters['campaign_id'] ?? $this->campaignId),
                    'sellingProgram' => $sellingProgram,
                    'frequency' => strtoupper((string) ($parameters['frequency'] ?? 'DAILY')),
                    'paymentDelayWeeks' => (int) ($parameters['paymentDelayWeeks'] ?? $parameters['payment_delay_weeks'] ?? 0),
                    'currency' => (string) ($parameters['currency'] ?? $this->defaultCurrency),
                ],
                'offers' => array_map(function (array $offer) {
                    return [
                        'categoryId' => (int) ($offer['categoryId'] ?? $offer['category_id'] ?? 0),
                        'price' => (float) ($offer['price'] ?? 0),
                        'length' => (float) ($offer['length'] ?? 0),
                        'width' => (float) ($offer['width'] ?? 0),
                        'height' => (float) ($offer['height'] ?? 0),
                        'weight' => (float) ($offer['weight'] ?? 0),
                        'quantity' => (int) ($offer['quantity'] ?? 1),
                    ];
                }, $offers),
            ]);

            if (!$response->successful()) {
                Log::error('YM calculateTariffs error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return [];
            }

            return $response->json()['result']['offers'] ?? $response->json()['result'] ?? [];
            
        } catch (\Exception $e) {
            Log::error('YM calculateTariffs error', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
