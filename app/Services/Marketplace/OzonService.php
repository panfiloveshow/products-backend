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
        $this->clientId = $clientId ?? config('services.ozon.client_id') ?? '';
        $this->apiKey = $apiKey ?? config('services.ozon.api_key') ?? '';
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
            // Ozon public URL использует каталожный sku (общий для всего маркетплейса),
            // а не seller-side product_id из $item['id'] — иначе по ссылке открывается
            // чужой товар с совпавшим product_id. fbo_sku/fbs_sku — deprecated fallback
            // для старых карточек.
            'url' => 'https://www.ozon.ru/product/'.(
                $item['sku']
                    ?? $item['fbo_sku']
                    ?? $item['fbs_sku']
                    ?? $item['id']
            ),
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
            $cursor = '';
            $isFirstPage = true;

            do {
                // POST /v4/product/info/stocks — пагинация через cursor (не last_id)
                $body = [
                    'filter' => ['visibility' => 'ALL'],
                    'limit'  => 1000,
                ];
                if ($isFirstPage) {
                    $body['last_id'] = '';
                } else {
                    $body['cursor'] = $cursor;
                }

                $response = Http::withHeaders([
                    'Client-Id' => $this->clientId,
                    'Api-Key'   => $this->apiKey,
                ])->post("{$this->baseUrl}/v4/product/info/stocks", $body);

                if (!$response->successful()) {
                    Log::error('Ozon API error (getInventory)', [
                        'status' => $response->status(),
                        'body'   => $response->body(),
                    ]);
                    break;
                }

                $data   = $response->json();
                $items  = $data['items'] ?? [];

                foreach ($items as $item) {
                    $offerId = $item['offer_id'] ?? null;
                    if (!$offerId) continue;

                    foreach ($item['stocks'] ?? [] as $stock) {
                        $inventory[] = [
                            'sku'            => $offerId,
                            'warehouse_id'   => $stock['type'],
                            'warehouse_name' => $this->getWarehouseTypeName($stock['type']),
                            'marketplace'    => 'ozon',
                            'quantity'       => $stock['present'] ?? 0,
                        ];
                    }
                }

                $cursor      = $data['cursor'] ?? '';
                $isFirstPage = false;

            } while (!empty($cursor) && count($inventory) < 50000);

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
     * Остатки по каждому реальному FBO-складу Ozon через /v2/analytics/stock_on_warehouses.
     * Возвращает формат, совместимый с SyncInventoryJob:
     * [['sku' => offer_id, 'warehouses' => [...], 'total' => int, 'fulfillment_type' => 'FBO']]
     */
    public function getInventoryPerWarehouse(): array
    {
        try {
            $allRows = [];
            $offset  = 0;
            $limit   = 1000;

            do {
                $response = Http::withHeaders([
                    'Client-Id' => $this->clientId,
                    'Api-Key'   => $this->apiKey,
                ])->post("{$this->baseUrl}/v2/analytics/stock_on_warehouses", [
                    'limit'          => $limit,
                    'offset'         => $offset,
                    'warehouse_type' => 'ALL',
                ]);

                if (!$response->successful()) {
                    Log::error('Ozon getInventoryPerWarehouse error', [
                        'status' => $response->status(),
                        'body'   => $response->body(),
                    ]);
                    break;
                }

                $rows    = $response->json()['result']['rows'] ?? [];
                $allRows = array_merge($allRows, $rows);
                $offset += $limit;
            } while (count($rows) === $limit);

            // Группируем по offer_id (item_code = offer_id продавца)
            $grouped = [];
            foreach ($allRows as $row) {
                $offerId = $row['item_code'] ?? null;
                $whName  = $row['warehouse_name'] ?? '';
                // quantity = free_to_sell + reserved = present (как показывает Ozon Seller)
                // promised_amount — товар едет на склад или в обработке, не учитываем в остатке
                $qty     = (int)($row['free_to_sell_amount'] ?? 0)
                         + (int)($row['reserved_amount'] ?? 0);

                if (!$offerId || !$whName) {
                    continue;
                }

                // warehouse_id: стабильный хэш от имени склада
                $warehouseId = 'ozon_' . substr(md5($whName), 0, 12);

                if (!isset($grouped[$offerId])) {
                    $grouped[$offerId] = [
                        'sku'              => $offerId,
                        'warehouses'       => [],
                        'total'            => 0,
                        'fulfillment_type' => 'FBO',
                    ];
                }

                $grouped[$offerId]['warehouses'][] = [
                    'warehouse_id'     => $warehouseId,
                    'warehouse_name'   => $whName,
                    'warehouse_type'   => 'fbo',
                    'quantity'         => $qty,
                    'reserved'         => (int)($row['reserved_amount'] ?? 0) + (int)($row['promised_amount'] ?? 0),
                    'fulfillment_type' => 'FBO',
                ];
                $grouped[$offerId]['total'] += $qty;
            }

            Log::info('Ozon getInventoryPerWarehouse загружено', [
                'rows' => count($allRows),
                'skus' => count($grouped),
            ]);

            return array_values($grouped);
        } catch (\Exception $e) {
            Log::error('Ozon getInventoryPerWarehouse error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Продажи по SKU и складу через /v2/posting/fbo/list за последние N дней.
     * Возвращает [offer_id => [warehouse_id_hash => [warehouse_name, sales_30_days, avg_daily_sales, ...]]]
     */
    public function getSalesBySkuAndWarehouse(int $days = 28): array
    {
        try {
            $since  = now()->subDays($days)->setTime(0, 0, 0)->toIso8601String();
            $to     = now()->toIso8601String();
            $offset = 0;
            $limit  = 1000;

            // rawUnits[offer_id][warehouse_id] = ['units' => int, 'warehouse_name' => string]
            $rawUnits = [];

            do {
                $response = Http::withHeaders([
                    'Client-Id' => $this->clientId,
                    'Api-Key'   => $this->apiKey,
                ])->post("{$this->baseUrl}/v2/posting/fbo/list", [
                    'dir'    => 'ASC',
                    'filter' => [
                        'since'  => $since,
                        'to'     => $to,
                        'status' => 'delivered',
                    ],
                    'limit'  => $limit,
                    'offset' => $offset,
                    'with'   => [
                        'analytics_data' => true,
                        'financial_data' => false,
                    ],
                ]);

                if (!$response->successful()) {
                    Log::error('Ozon getSalesBySkuAndWarehouse postings error', [
                        'status' => $response->status(),
                    ]);
                    break;
                }

                $postings = $response->json()['result'] ?? [];

                foreach ($postings as $posting) {
                    $whName = $posting['analytics_data']['warehouse_name'] ?? '';
                    if (!$whName) {
                        continue;
                    }
                    // Тот же хэш что и в getInventoryPerWarehouse
                    $warehouseId = 'ozon_' . substr(md5($whName), 0, 12);

                    foreach ($posting['products'] ?? [] as $product) {
                        $offerId = $product['offer_id'] ?? null;
                        if (!$offerId) {
                            continue;
                        }
                        $qty = (int)($product['quantity'] ?? 0);
                        if (!isset($rawUnits[$offerId][$warehouseId])) {
                            $rawUnits[$offerId][$warehouseId] = ['units' => 0, 'warehouse_name' => $whName];
                        }
                        $rawUnits[$offerId][$warehouseId]['units'] += $qty;
                    }
                }

                $offset += $limit;
            } while (count($postings) === $limit);

            // Преобразуем в финальный формат
            $result = [];
            foreach ($rawUnits as $offerId => $warehouses) {
                foreach ($warehouses as $warehouseId => $data) {
                    $units    = $data['units'];
                    $avgDaily = $days > 0 ? round($units / $days, 2) : 0;

                    $result[$offerId][$warehouseId] = [
                        'warehouse_name'      => $data['warehouse_name'],
                        'sales_7_days'        => (int)round($units * 7  / $days),
                        'sales_14_days'       => (int)round($units * 14 / $days),
                        'sales_30_days'       => (int)round($units * 30 / $days),
                        'avg_daily_sales'     => $avgDaily,
                        'ordered_units_total' => $units,
                    ];
                }
            }

            Log::info('Ozon getSalesBySkuAndWarehouse загружено', [
                'days'       => $days,
                'skus_count' => count($result),
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('Ozon getSalesBySkuAndWarehouse error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Остатки FBS по складам продавца через /v1/product/info/stocks-by-warehouse/fbs.
     * Возвращает формат совместимый с SyncInventoryJob.
     */
    public function getInventoryFbsPerWarehouse(): array
    {
        try {
            // Получаем список всех offer_id через /v3/product/list
            $allOfferIds = [];
            $lastId      = '';
            do {
                $body = ['filter' => ['visibility' => 'ALL'], 'limit' => 1000];
                if ($lastId) {
                    $body['last_id'] = $lastId;
                }
                $resp = Http::withHeaders([
                    'Client-Id' => $this->clientId,
                    'Api-Key'   => $this->apiKey,
                ])->post("{$this->baseUrl}/v3/product/list", $body);

                if (!$resp->successful()) {
                    break;
                }
                $items  = $resp->json()['result']['items'] ?? [];
                $lastId = $resp->json()['result']['last_id'] ?? '';
                foreach ($items as $item) {
                    if (!empty($item['offer_id'])) {
                        $allOfferIds[] = $item['offer_id'];
                    }
                }
            } while (!empty($items) && !empty($lastId));

            if (empty($allOfferIds)) {
                Log::info('Ozon getInventoryFbsPerWarehouse: нет offer_id');
                return [];
            }

            // Запрашиваем FBS-остатки по всем offer_id
            $resp = Http::withHeaders([
                'Client-Id' => $this->clientId,
                'Api-Key'   => $this->apiKey,
            ])->post("{$this->baseUrl}/v1/product/info/stocks-by-warehouse/fbs", [
                'offer_id' => $allOfferIds,
            ]);

            if (!$resp->successful()) {
                Log::error('Ozon getInventoryFbsPerWarehouse API error', [
                    'status' => $resp->status(),
                    'body'   => $resp->body(),
                ]);
                return [];
            }

            $rows    = $resp->json()['result'] ?? [];
            $grouped = [];

            foreach ($rows as $row) {
                $offerId     = $row['offer_id'] ?? null;
                $whId        = (string)($row['warehouse_id'] ?? '');
                $whName      = $row['warehouse_name'] ?? ('FBS-склад ' . $whId);
                $qty         = (int)($row['present'] ?? 0);
                $reserved    = (int)($row['reserved'] ?? 0);

                if (!$offerId || !$whId) {
                    continue;
                }

                // warehouse_id: стабильный хэш от имени склада (как в FBO)
                $warehouseId = 'ozonfbs_' . substr(md5($whName ?: $whId), 0, 12);

                if (!isset($grouped[$offerId])) {
                    $grouped[$offerId] = [
                        'sku'              => $offerId,
                        'warehouses'       => [],
                        'total'            => 0,
                        'fulfillment_type' => 'FBS',
                    ];
                }

                $grouped[$offerId]['warehouses'][] = [
                    'warehouse_id'     => $warehouseId,
                    'warehouse_name'   => $whName,
                    'warehouse_type'   => 'fbs',
                    'quantity'         => $qty,
                    'reserved'         => $reserved,
                    'fulfillment_type' => 'FBS',
                ];
                $grouped[$offerId]['total'] += $qty;
            }

            Log::info('Ozon getInventoryFbsPerWarehouse загружено', [
                'rows' => count($rows),
                'skus' => count($grouped),
            ]);

            return array_values($grouped);
        } catch (\Exception $e) {
            Log::error('Ozon getInventoryFbsPerWarehouse error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Продажи FBS по SKU и складу через /v2/posting/fbs/list за последние N дней.
     * Возвращает [offer_id => [warehouse_id_hash => [warehouse_name, sales_30_days, avg_daily_sales, ...]]]
     */
    public function getSalesBySkuAndWarehouseFbs(int $days = 28): array
    {
        try {
            $since    = now()->subDays($days)->setTime(0, 0, 0)->toIso8601String();
            $to       = now()->toIso8601String();
            $offset   = 0;
            $limit    = 1000;
            $rawUnits = [];

            do {
                $response = Http::withHeaders([
                    'Client-Id' => $this->clientId,
                    'Api-Key'   => $this->apiKey,
                ])->post("{$this->baseUrl}/v2/posting/fbs/list", [
                    'dir'    => 'ASC',
                    'filter' => [
                        'since'  => $since,
                        'to'     => $to,
                        'status' => 'delivered',
                    ],
                    'limit'  => $limit,
                    'offset' => $offset,
                    'with'   => [
                        'analytics_data' => true,
                        'financial_data' => false,
                    ],
                ]);

                if (!$response->successful()) {
                    Log::error('Ozon getSalesBySkuAndWarehouseFbs error', ['status' => $response->status()]);
                    break;
                }

                $postings = $response->json()['result'] ?? [];

                foreach ($postings as $posting) {
                    $whName = $posting['analytics_data']['warehouse_name'] ?? '';
                    $whId   = (string)($posting['analytics_data']['warehouse_id'] ?? '');

                    if (!$whName && !$whId) {
                        continue;
                    }

                    $warehouseId = 'ozonfbs_' . substr(md5($whName ?: $whId), 0, 12);

                    foreach ($posting['products'] ?? [] as $product) {
                        $offerId = $product['offer_id'] ?? null;
                        if (!$offerId) {
                            continue;
                        }
                        $qty = (int)($product['quantity'] ?? 0);
                        if (!isset($rawUnits[$offerId][$warehouseId])) {
                            $rawUnits[$offerId][$warehouseId] = ['units' => 0, 'warehouse_name' => $whName ?: $whId];
                        }
                        $rawUnits[$offerId][$warehouseId]['units'] += $qty;
                    }
                }

                $offset += $limit;
            } while (count($postings) === $limit);

            $result = [];
            foreach ($rawUnits as $offerId => $warehouses) {
                foreach ($warehouses as $warehouseId => $data) {
                    $units    = $data['units'];
                    $avgDaily = $days > 0 ? round($units / $days, 2) : 0;

                    $result[$offerId][$warehouseId] = [
                        'warehouse_name'      => $data['warehouse_name'],
                        'sales_7_days'        => (int)round($units * 7  / $days),
                        'sales_14_days'       => (int)round($units * 14 / $days),
                        'sales_30_days'       => (int)round($units * 30 / $days),
                        'avg_daily_sales'     => $avgDaily,
                        'ordered_units_total' => $units,
                        'fulfillment_type'    => 'FBS',
                    ];
                }
            }

            Log::info('Ozon getSalesBySkuAndWarehouseFbs загружено', [
                'days'       => $days,
                'skus_count' => count($result),
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('Ozon getSalesBySkuAndWarehouseFbs error', ['error' => $e->getMessage()]);
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
