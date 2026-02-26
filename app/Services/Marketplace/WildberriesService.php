<?php

namespace App\Services\Marketplace;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Wildberries API Service
 * Актуальные эндпоинты на 2024-2025 год
 * Документация: https://dev.wildberries.ru
 */
class WildberriesService implements MarketplaceInterface
{
    private string $apiKey;
    
    // Актуальные базовые URL для разных API Wildberries
    private string $contentApiUrl = 'https://content-api.wildberries.ru';
    private string $suppliesApiUrl = 'https://supplies-api.wildberries.ru';
    private string $statisticsApiUrl = 'https://statistics-api.wildberries.ru';
    private string $analyticsApiUrl = 'https://seller-analytics-api.wildberries.ru';
    private string $pricesApiUrl = 'https://discounts-prices-api.wildberries.ru';

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? config('services.wildberries.api_key', '');
    }

    /**
     * Получение списка товаров (карточек)
     * Актуальный эндпоинт: POST /content/v2/get/cards/list
     * Документация: https://dev.wildberries.ru/swagger/products
     */
    public function getProducts(): array
    {
        try {
            $allCards = [];
            $cursor = ['limit' => 100];

            do {
                $response = Http::withHeaders([
                    'Authorization' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ])->post("{$this->contentApiUrl}/content/v2/get/cards/list", [
                    'settings' => [
                        'cursor' => $cursor,
                        'filter' => [
                            'withPhoto' => -1,
                        ],
                    ],
                ]);

                if (!$response->successful()) {
                    Log::error('Wildberries API error (getProducts)', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    break;
                }

                $data = $response->json();
                
                foreach ($data['cards'] ?? [] as $card) {
                    $allCards[] = $card;
                }

                // Обновляем cursor для следующей страницы
                $cursorData = $data['cursor'] ?? null;
                if ($cursorData && isset($cursorData['nmID'])) {
                    $cursor = [
                        'limit' => 100,
                        'updatedAt' => $cursorData['updatedAt'] ?? null,
                        'nmID' => $cursorData['nmID'],
                    ];
                } else {
                    $cursor = null;
                }
                
            } while ($cursor && count($allCards) < 10000);

            // Загружаем цены из Prices API для всех карточек
            $prices = $this->loadAllPrices();
            Log::info('WB getProducts: prices loaded', ['count' => count($prices)]);

            $products = [];
            foreach ($allCards as $card) {
                $products[] = $this->transformProduct($card, $prices);
            }

            Log::info('Wildberries products fetched', ['count' => count($products)]);
            return $products;
            
        } catch (\Exception $e) {
            Log::error('WB getProducts error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Загрузить все цены аккаунта из Prices API (с пагинацией)
     */
    private function loadAllPrices(): array
    {
        $prices = [];
        $offset = 0;
        $limit  = 1000;

        do {
            $response = Http::withHeaders(['Authorization' => $this->apiKey])
                ->get("{$this->pricesApiUrl}/api/v2/list/goods/filter", [
                    'limit'  => $limit,
                    'offset' => $offset,
                ]);

            if (!$response->successful()) {
                Log::warning('WB Prices API error', ['status' => $response->status()]);
                break;
            }

            $items = $response->json()['data']['listGoods'] ?? [];

            foreach ($items as $item) {
                $nmId       = $item['nmID'] ?? null;
                $vendorCode = $item['vendorCode'] ?? null;
                if (!$nmId) continue;

                $firstSize       = $item['sizes'][0] ?? [];
                $discountedPrice = (float) ($firstSize['discountedPrice'] ?? 0);
                $basePrice       = (float) ($firstSize['price'] ?? 0);

                $priceData = [
                    'final_price'      => $discountedPrice > 0 ? $discountedPrice : $basePrice,
                    'base_price'       => $basePrice,
                    'discounted_price' => $discountedPrice,
                    'discount'         => (int) ($item['discount'] ?? 0),
                ];

                $prices[(string) $nmId] = $priceData;
                if ($vendorCode) {
                    $prices[$vendorCode] = $priceData;
                }
            }

            $offset += $limit;
        } while (count($items) === $limit);

        return $prices;
    }

    /**
     * Трансформация карточки WB в формат Product
     */
    private function transformProduct(array $card, array $prices = []): array
    {
        // Получаем первый размер для SKU и баркода
        $firstSize = $card['sizes'][0] ?? [];
        $sku     = $firstSize['skus'][0] ?? $card['vendorCode'] ?? (string)$card['nmID'];
        $barcode = $firstSize['skus'][0] ?? null;
        $nmId    = (string) ($card['nmID'] ?? '');
        $vendorCode = $card['vendorCode'] ?? null;

        // Цена из Prices API (приоритет) или из sizes карточки (устарело)
        $priceData       = $prices[$vendorCode] ?? $prices[$nmId] ?? null;
        $finalPrice      = null;
        $oldPrice        = null;
        if ($priceData) {
            $fp = (float) ($priceData['final_price'] ?? 0);
            $bp = (float) ($priceData['base_price'] ?? 0);
            $finalPrice = $fp > 0 ? $fp : null;
            $oldPrice   = ($bp > 0 && $bp > $fp) ? $bp : null;
        } elseif (!empty($firstSize['discountedPrice']) || !empty($firstSize['price'])) {
            $fp = (float) ($firstSize['discountedPrice'] ?? 0);
            $bp = (float) ($firstSize['price'] ?? 0);
            // sizes из Cards API хранят в копейках
            if ($fp > 100 || $bp > 100) { $fp /= 100; $bp /= 100; }
            $finalPrice = $fp > 0 ? $fp : ($bp > 0 ? $bp : null);
            $oldPrice   = ($bp > 0 && $fp > 0 && $bp > $fp) ? $bp : null;
        }
        $discountedPrice = $finalPrice;
        
        // Собираем фото
        $photos = [];
        foreach ($card['photos'] ?? [] as $photo) {
            if (isset($photo['big'])) {
                $photos[] = $photo['big'];
            }
        }

        return [
            'sku' => $sku,
            'name' => $card['title'] ?? $card['subjectName'] ?? 'Без названия',
            'barcode' => $barcode,
            'price' => $discountedPrice,
            'old_price' => $oldPrice,
            'stock' => 0, // Остатки получаем отдельно через getInventory
            'description' => $card['description'] ?? null,
            'images' => $photos,
            'category' => $card['subjectName'] ?? null,
            'brand' => $card['brand'] ?? null,
            'rating' => $card['rating'] ?? null,
            'reviews_count' => $card['feedbackCount'] ?? 0,
            'marketplace' => 'wildberries',
            'marketplace_id' => (string)$card['nmID'],
            'url' => "https://www.wildberries.ru/catalog/{$card['nmID']}/detail.aspx",
            'wb_data' => [
                'nmID' => $card['nmID'],
                'imtID' => $card['imtID'] ?? null,
                'vendorCode' => $card['vendorCode'] ?? null,
                'subjectID' => $card['subjectID'] ?? null,
                'sizes' => $card['sizes'] ?? [],
                'characteristics' => $card['characteristics'] ?? [],
                'createdAt' => $card['createdAt'] ?? null,
                'updatedAt' => $card['updatedAt'] ?? null,
            ],
        ];
    }

    /**
     * Получение остатков по складам
     * Актуальный эндпоинт: GET /api/v1/warehouses (список складов)
     * Документация: https://dev.wildberries.ru/openapi/orders-fbw
     */
    public function getInventory(): array
    {
        try {
            $warehouses = $this->getWarehouses();
            $inventory = [];

            // Получаем остатки через Statistics API
            $response = Http::withHeaders([
                'Authorization' => $this->apiKey,
            ])->get("{$this->statisticsApiUrl}/api/v1/supplier/stocks", [
                'dateFrom' => now()->subDays(1)->format('Y-m-d'),
            ]);

            if ($response->successful()) {
                $stocks = $response->json() ?? [];
                
                foreach ($stocks as $stock) {
                    $inventory[] = [
                        'sku' => $stock['supplierArticle'] ?? $stock['barcode'],
                        'warehouse_id' => (string)($stock['warehouseId'] ?? 0),
                        'warehouse_name' => $stock['warehouseName'] ?? 'WB Склад',
                        'marketplace' => 'wildberries',
                        'quantity' => $stock['quantity'] ?? 0,
                    ];
                }
            }

            Log::info('Wildberries inventory fetched', ['count' => count($inventory)]);
            return $inventory;
            
        } catch (\Exception $e) {
            Log::error('WB getInventory error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Получение списка складов WB
     * Актуальный эндпоинт: GET /api/v1/warehouses
     * Документация: https://dev.wildberries.ru/openapi/orders-fbw
     */
    public function getWarehouses(): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => $this->apiKey,
            ])->get("{$this->suppliesApiUrl}/api/v1/warehouses");

            if (!$response->successful()) {
                Log::error('WB getWarehouses error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return [];
            }

            return array_map(function ($wh) {
                return [
                    'id' => $wh['ID'] ?? $wh['id'],
                    'name' => $wh['name'],
                    'address' => $wh['address'] ?? null,
                    'isActive' => $wh['isActive'] ?? true,
                ];
            }, $response->json() ?? []);
            
        } catch (\Exception $e) {
            Log::error('WB getWarehouses error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Получение продаж по складам за N дней из Statistics API
     * Эндпоинт: GET /api/v1/supplier/sales
     * Возвращает: [sku => [warehouse_id => avg_daily_sales]]
     */
    public function getSalesByWarehouse(int $days = 30): array
    {
        try {
            // Один запрос за 30 дней, из него считаем 7/14/30 по полю date
            $dateFrom = now()->subDays(30)->format('Y-m-d');

            $response = Http::withHeaders([
                'Authorization' => $this->apiKey,
            ])->timeout(60)->get("{$this->statisticsApiUrl}/api/v1/supplier/sales", [
                'dateFrom' => $dateFrom,
                'flag'     => 1,
            ]);

            if (!$response->successful()) {
                Log::warning('WB getSalesByWarehouse error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return [];
            }

            $sales = $response->json() ?? [];

            $now     = now();
            $date7   = $now->copy()->subDays(7);
            $date14  = $now->copy()->subDays(14);
            $date30  = $now->copy()->subDays(30);

            // Агрегируем по [barcode][warehouse_key][period]
            // barcode совпадает с products.sku и inventory_warehouses.sku
            // warehouseName → wb_+md5 совпадает с warehouse_id в inventory_warehouses
            $counts = [];
            foreach ($sales as $sale) {
                $barcode       = $sale['barcode'] ?? null;
                $warehouseName = $sale['warehouseName'] ?? '';
                $warehouseKey  = $warehouseName !== '' ? 'wb_' . substr(md5($warehouseName), 0, 8) : '0';
                $isReturn      = $sale['isReturn'] ?? false;
                $saleDate      = $sale['date'] ?? null;

                if (!$barcode || $isReturn || !$saleDate) continue;

                try {
                    $dt = \Carbon\Carbon::parse($saleDate);
                } catch (\Exception $e) {
                    continue;
                }

                if (!isset($counts[$barcode][$warehouseKey])) {
                    $counts[$barcode][$warehouseKey] = ['d7' => 0, 'd14' => 0, 'd30' => 0];
                }

                if ($dt->gte($date30)) $counts[$barcode][$warehouseKey]['d30']++;
                if ($dt->gte($date14)) $counts[$barcode][$warehouseKey]['d14']++;
                if ($dt->gte($date7))  $counts[$barcode][$warehouseKey]['d7']++;
            }

            // Формируем результат: [barcode][warehouse_key] = [avg_daily, sales_7, sales_14, sales_30]
            $result = [];
            foreach ($counts as $barcode => $warehouses) {
                foreach ($warehouses as $warehouseKey => $periods) {
                    $result[$barcode][$warehouseKey] = [
                        'avg_daily_sales' => round($periods['d30'] / 30, 4),
                        'sales_7_days'    => $periods['d7'],
                        'sales_14_days'   => $periods['d14'],
                        'sales_30_days'   => $periods['d30'],
                    ];
                }
            }

            Log::info('WB sales by warehouse fetched', [
                'skus' => count($result),
                'raw'  => count($sales),
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error('WB getSalesByWarehouse error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Получение FBS-остатков на складах продавца
     * Эндпоинт: GET /api/v3/stocks/{warehouseId}
     * Документация: https://dev.wildberries.ru/openapi/orders-fbs
     */
    public function getFbsStocks(): array
    {
        try {
            $sellerWarehouses = $this->getSellerWarehouses();
            if (empty($sellerWarehouses)) {
                Log::info('WB FBS: нет складов продавца');
                return [];
            }

            $result = [];

            foreach ($sellerWarehouses as $warehouse) {
                $warehouseId = $warehouse['id'];
                $warehouseName = $warehouse['name'];

                $skusPage = [];
                $cursor = null;

                do {
                    $params = ['limit' => 1000];
                    if ($cursor) {
                        $params['next'] = $cursor;
                    }

                    $response = Http::withHeaders([
                        'Authorization' => $this->apiKey,
                    ])->get("https://marketplace-api.wildberries.ru/api/v3/stocks/{$warehouseId}", $params);

                    if (!$response->successful()) {
                        Log::warning('WB FBS stocks error', [
                            'warehouseId' => $warehouseId,
                            'status' => $response->status(),
                        ]);
                        break;
                    }

                    $data = $response->json();
                    $stocks = $data['stocks'] ?? [];

                    foreach ($stocks as $stock) {
                        $barcode = $stock['sku'] ?? null;
                        if (!$barcode) continue;

                        $result[] = [
                            'sku'            => $barcode,
                            'warehouse_id'   => 'fbs_' . $warehouseId,
                            'warehouse_name' => '[FBS] ' . $warehouseName,
                            'marketplace'    => 'wildberries',
                            'fulfillment_type' => 'fbs',
                            'quantity'       => $stock['amount'] ?? 0,
                        ];
                    }

                    $cursor = $data['cursor']['next'] ?? null;

                } while ($cursor && count($stocks) > 0);
            }

            Log::info('WB FBS stocks fetched', ['count' => count($result)]);
            return $result;

        } catch (\Exception $e) {
            Log::error('WB getFbsStocks error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Получение складов продавца (FBS)
     * Эндпоинт: GET /api/v3/warehouses
     */
    public function getSellerWarehouses(): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => $this->apiKey,
            ])->get('https://marketplace-api.wildberries.ru/api/v3/warehouses');

            if (!$response->successful()) {
                Log::error('WB getSellerWarehouses error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return [];
            }

            return array_map(function ($wh) {
                return [
                    'id'   => $wh['id'],
                    'name' => $wh['name'],
                ];
            }, $response->json() ?? []);

        } catch (\Exception $e) {
            Log::error('WB getSellerWarehouses error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Получение статистики продаж
     * Актуальный эндпоинт: GET /api/v1/supplier/sales
     * Документация: https://dev.wildberries.ru/openapi/statistics
     */
    public function getSalesStats(string $dateFrom, string $dateTo): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => $this->apiKey,
            ])->get("{$this->statisticsApiUrl}/api/v1/supplier/sales", [
                'dateFrom' => $dateFrom,
            ]);

            if (!$response->successful()) {
                Log::error('WB getSalesStats error', [
                    'status' => $response->status(),
                ]);
                return [];
            }

            return $response->json() ?? [];
            
        } catch (\Exception $e) {
            Log::error('WB getSalesStats error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Получение комиссий
     * Актуальный эндпоинт: GET /api/v1/tariffs/commission
     */
    public function getCommissions(): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => $this->apiKey,
            ])->get("{$this->analyticsApiUrl}/api/v1/tariffs/commission");

            if (!$response->successful()) {
                return [];
            }

            return $response->json()['report'] ?? [];
            
        } catch (\Exception $e) {
            Log::error('WB getCommissions error: ' . $e->getMessage());
            return [];
        }
    }
}
