<?php

namespace App\Services\Marketplace;

use App\Domains\Wildberries\Api\CardListWithPhotoFilter;
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

    private string $commonApiUrl = 'https://common-api.wildberries.ru';

    // Задержка между запросами (мс) — глобальный лимит WB: 6 рек/мин на эндпоинт
    private int $requestDelayMs = 500;

    // Максимум попыток при 429
    private int $maxRetries = 5;

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? config('services.wildberries.api_key') ?? '';
    }

    /**
     * Выполняет GET-запрос к WB API с автоматическим retry при 429.
     * При 429 ждёт Retry-After (или 60с) и повторяет попытку.
     */
    private function wbGet(string $url, array $params = [], int $timeout = 60): \Illuminate\Http\Client\Response
    {
        usleep($this->requestDelayMs * 1000);
        $attempt = 0;
        do {
            $response = Http::withHeaders(['Authorization' => $this->apiKey])
                ->timeout($timeout)
                ->get($url, $params);
            if ($response->status() !== 429) {
                return $response;
            }
            $retryAfter = (int) ($response->header('Retry-After') ?: 60);
            $retryAfter = max(1, min($retryAfter, 120));
            Log::warning('WB API 429 — ожидание перед повтором', [
                'url' => $url,
                'retry_after' => $retryAfter,
                'attempt' => $attempt + 1,
            ]);
            sleep($retryAfter);
            $attempt++;
        } while ($attempt < $this->maxRetries);

        return $response;
    }

    /**
     * Выполняет POST-запрос к WB API с автоматическим retry при 429.
     */
    private function wbPost(string $url, array $data = [], int $timeout = 60): \Illuminate\Http\Client\Response
    {
        usleep($this->requestDelayMs * 1000);
        $attempt = 0;
        do {
            $response = Http::withHeaders([
                'Authorization' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout($timeout)->post($url, $data);
            if ($response->status() !== 429) {
                return $response;
            }
            $retryAfter = (int) ($response->header('Retry-After') ?: 60);
            $retryAfter = max(1, min($retryAfter, 120));
            Log::warning('WB API 429 — ожидание перед повтором', [
                'url' => $url,
                'retry_after' => $retryAfter,
                'attempt' => $attempt + 1,
            ]);
            sleep($retryAfter);
            $attempt++;
        } while ($attempt < $this->maxRetries);

        return $response;
    }

    /**
     * Получение списка товаров (карточек)
     * Актуальный эндпоинт: POST /content/v2/get/cards/list
     * Документация: https://dev.wildberries.ru/swagger/products
     */
    public function getProducts(): array
    {
        try {
            if (trim($this->apiKey) === '') {
                throw new \RuntimeException('Ключ API Wildberries не указан. Добавьте токен в настройках интеграции.');
            }

            $allCards = [];
            $cursor = ['limit' => 100];

            do {
                Log::info('WB API Request: /content/v2/get/cards/list', [
                    'cursor' => $cursor,
                ]);

                $response = $this->wbPost("{$this->contentApiUrl}/content/v2/get/cards/list", [
                    'settings' => [
                        'cursor' => $cursor,
                        'filter' => [
                            'withPhoto' => CardListWithPhotoFilter::allCards(),
                        ],
                    ],
                ]);

                if (! $response->successful()) {
                    Log::error('Wildberries API error (getProducts)', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    throw new \RuntimeException($this->formatProductsApiError($response));
                }

                $data = $response->json();

                $cardsCount = count($data['cards'] ?? []);
                Log::info('WB API Response: /content/v2/get/cards/list', [
                    'cards_returned' => $cardsCount,
                    'total_collected' => count($allCards) + $cardsCount,
                ]);

                if ($cardsCount === 0) {
                    break; // Достигли конца списка
                }

                foreach ($data['cards'] ?? [] as $card) {
                    $allCards[] = $card;
                }

                // Обновляем cursor для следующей страницы
                $cursorData = $data['cursor'] ?? null;

                // Если WB вернул nmID, проверяем что он не сбросился в 0 (что бывает в конце списка)
                if ($cursorData && isset($cursorData['nmID']) && $cardsCount > 0) {
                    $cursor = [
                        'limit' => 100,
                        'updatedAt' => $cursorData['updatedAt'] ?? null,
                        'nmID' => $cursorData['nmID'],
                    ];

                    // Защита от зацикливания: если WB возвращает курсор на начало
                    if ($cursor['nmID'] === 0 && ($cursorData['updatedAt'] ?? null) === null) {
                        $cursor = null;
                    }
                } else {
                    $cursor = null;
                }

            } while ($cursor && count($allCards) < 50000);

            // Загружаем цены из Prices API для всех карточек
            $prices = $this->loadAllPrices();
            Log::info('WB getProducts: prices loaded', ['count' => count($prices)]);

            $commissions = $this->getCommissions();
            Log::info('WB getProducts: commissions loaded', ['count' => count($commissions)]);

            $products = [];
            foreach ($allCards as $card) {
                foreach ($this->transformProduct($card, $prices, $commissions) as $productData) {
                    $products[] = $productData;
                }
            }

            Log::info('Wildberries products fetched', ['count' => count($products)]);

            return $products;

        } catch (\RuntimeException $e) {
            Log::error('WB getProducts error: '.$e->getMessage());

            throw $e;
        } catch (\Throwable $e) {
            Log::error('WB getProducts error: '.$e->getMessage());

            throw new \RuntimeException(
                'Не удалось получить товары Wildberries: '.$e->getMessage(),
                previous: $e
            );
        }
    }

    private function formatProductsApiError(\Illuminate\Http\Client\Response $response): string
    {
        $status = $response->status();
        $body = $response->json();
        $detail = is_array($body) ? (string) ($body['detail'] ?? $body['message'] ?? '') : '';
        $rawBody = $response->body();
        $searchText = mb_strtolower($detail.' '.$rawBody);

        if ($status === 401 && str_contains($searchText, 'token expired')) {
            return 'Ключ API Wildberries просрочен. Обновите токен в кабинете WB и сохраните его в настройках интеграции.';
        }

        if ($status === 401 && str_contains($searchText, 'empty authorization header')) {
            return 'Ключ API Wildberries не указан. Добавьте токен в настройках интеграции.';
        }

        if ($status === 401) {
            return 'Wildberries отклонил ключ API: проверьте токен в настройках интеграции.';
        }

        if ($status === 403) {
            return 'У ключа API Wildberries нет доступа к товарам или он деактивирован. Проверьте права токена в кабинете WB.';
        }

        $message = $detail !== '' ? $detail : trim($response->body());
        $message = $message !== '' ? ' '.$message : '';

        return "Wildberries не вернул товары: HTTP {$status}.{$message}";
    }

    /**
     * Загрузить все цены аккаунта из Prices API (с пагинацией)
     */
    private function loadAllPrices(): array
    {
        $prices = [];
        $offset = 0;
        $limit = 1000;

        do {
            $response = $this->wbGet("{$this->pricesApiUrl}/api/v2/list/goods/filter", [
                'limit' => $limit,
                'offset' => $offset,
            ]);

            if (! $response->successful()) {
                Log::warning('WB Prices API error', ['status' => $response->status()]);
                break;
            }

            $items = $response->json()['data']['listGoods'] ?? [];

            foreach ($items as $item) {
                $nmId = $item['nmID'] ?? null;
                $vendorCode = $item['vendorCode'] ?? null;
                if (! $nmId) {
                    continue;
                }

                $firstSize = $item['sizes'][0] ?? [];
                $discountedPrice = (float) ($firstSize['discountedPrice'] ?? 0);
                $basePrice = (float) ($firstSize['price'] ?? 0);

                $priceData = [
                    'final_price' => $discountedPrice > 0 ? $discountedPrice : $basePrice,
                    'base_price' => $basePrice,
                    'discounted_price' => $discountedPrice,
                    'discount' => (int) ($item['discount'] ?? 0),
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
     * Трансформация карточки WB в набор Product-записей (по одной на каждый баркод/размер).
     * WB Statistics API возвращает остатки по баркодам, поэтому нужна запись на каждый баркод.
     * Возвращает массив массивов (может быть несколько вариантов одной карточки).
     */
    private function transformProduct(array $card, array $prices = [], array $commissions = []): array
    {
        $nmId = (string) ($card['nmID'] ?? '');
        $vendorCode = $card['vendorCode'] ?? null;
        $subjectId = $card['subjectID'] ?? null;
        $subjectCommissions = $subjectId ? ($commissions[(string) $subjectId] ?? null) : null;

        // Цена из Prices API (приоритет)
        $priceData = $prices[$vendorCode] ?? $prices[$nmId] ?? null;
        $finalPrice = null;
        $oldPrice = null;
        if ($priceData) {
            $fp = (float) ($priceData['final_price'] ?? 0);
            $bp = (float) ($priceData['base_price'] ?? 0);
            $finalPrice = $fp > 0 ? $fp : null;
            $oldPrice = ($bp > 0 && $bp > $fp) ? $bp : null;
        }

        // Собираем фото
        $photos = [];
        foreach ($card['photos'] ?? [] as $photo) {
            if (isset($photo['big'])) {
                $photos[] = $photo['big'];
            }
        }

        $baseName = $card['title'] ?? $card['subjectName'] ?? 'Без названия';
        $sizes = $card['sizes'] ?? [];

        // Собираем все баркоды из всех размеров
        $allBarcodes = [];
        foreach ($sizes as $size) {
            foreach ($size['skus'] ?? [] as $barcode) {
                if ($barcode) {
                    $allBarcodes[] = [
                        'barcode' => $barcode,
                        'size_name' => trim(($size['wbSize'] ?? '') ?: ($size['techSize'] ?? '')),
                        'chrtID' => $size['chrtID'] ?? null,
                    ];
                }
            }
        }

        // Если размеров нет — создаём одну запись с vendorCode как SKU
        if (empty($allBarcodes)) {
            $allBarcodes = [[
                'barcode' => $vendorCode ?? $nmId,
                'size_name' => '',
                'chrtID' => null,
            ]];
        }

        $results = [];
        foreach ($allBarcodes as $sizeInfo) {
            $barcode = $sizeInfo['barcode'];
            $sizeName = $sizeInfo['size_name'];
            $name = $sizeName ? "{$baseName} ({$sizeName})" : $baseName;

            // Если цены из Prices API нет — пробуем из sizes карточки
            $itemFinalPrice = $finalPrice;
            $itemOldPrice = $oldPrice;
            if ($itemFinalPrice === null) {
                foreach ($sizes as $size) {
                    if (in_array($barcode, $size['skus'] ?? [])) {
                        $fp = (float) ($size['discountedPrice'] ?? 0);
                        $bp = (float) ($size['price'] ?? 0);
                        if ($fp > 100 || $bp > 100) {
                            $fp /= 100;
                            $bp /= 100;
                        }
                        $itemFinalPrice = $fp > 0 ? $fp : ($bp > 0 ? $bp : null);
                        $itemOldPrice = ($bp > 0 && $fp > 0 && $bp > $fp) ? $bp : null;
                        break;
                    }
                }
            }

            $results[] = [
                'sku' => $barcode,
                'vendor_code' => $vendorCode,
                'name' => $name,
                'barcode' => $barcode,
                'price' => $itemFinalPrice,
                'old_price' => $itemOldPrice,
                'stock' => 0,
                'description' => $card['description'] ?? null,
                'images' => $photos,
                'category' => $card['subjectName'] ?? null,
                'brand' => $card['brand'] ?? null,
                'rating' => $card['rating'] ?? null,
                'reviews_count' => $card['feedbackCount'] ?? 0,
                'marketplace' => 'wildberries',
                'marketplace_id' => $nmId,
                'url' => "https://www.wildberries.ru/catalog/{$nmId}/detail.aspx",
                'wb_data' => [
                    'nmID' => $card['nmID'],
                    'imtID' => $card['imtID'] ?? null,
                    'vendorCode' => $vendorCode,
                    'subjectID' => $card['subjectID'] ?? null,
                    'commissions' => [
                        'fbo' => [
                            'percent' => (float) ($subjectCommissions['fbo'] ?? 15.0),
                        ],
                        'fbs' => [
                            'percent' => (float) ($subjectCommissions['fbs'] ?? $subjectCommissions['fbo'] ?? 15.0),
                        ],
                    ],
                    'chrtID' => $sizeInfo['chrtID'],
                    'size' => $sizeName,
                    'sizes' => $sizes,
                    'characteristics' => $card['characteristics'] ?? [],
                    'createdAt' => $card['createdAt'] ?? null,
                    'updatedAt' => $card['updatedAt'] ?? null,
                ],
            ];
        }

        return $results;
    }

    /**
     * Получение остатков по складам
     * Актуальный эндпоинт: GET /api/v1/warehouses (список складов)
     * Документация: https://dev.wildberries.ru/openapi/orders-fbw
     */
    public function getInventory(): array
    {
        try {
            $inventory = [];
            $warehouseCoefficients = $this->getWarehouseCoefficients();
            $sizeMap = $this->getWbSizeMap();
            $stocks = $this->getWbWarehouseStocksReport();

            if (empty($stocks)) {
                Log::warning('WB getInventory: new analytics stocks report returned no rows, falling back to legacy statistics endpoint');
                $stocks = $this->getLegacyStocksReport();
            }

            foreach ($stocks as $stock) {
                $nmId = (int) ($stock['nmId'] ?? 0);
                $chrtId = (int) ($stock['chrtId'] ?? 0);
                $meta = $sizeMap['by_nm_chrt']["{$nmId}:{$chrtId}"] ?? $sizeMap['by_nm'][(string) $nmId] ?? [];
                $warehouseName = $stock['warehouseName'] ?? 'WB Склад';
                $rawWarehouseId = $stock['warehouseId'] ?? null;
                $warehouseId = $rawWarehouseId !== null && $rawWarehouseId !== ''
                    ? (string) $rawWarehouseId
                    : ($warehouseName !== '' ? 'wb_'.substr(md5($warehouseName), 0, 8) : '0');
                $barcode = $stock['barcode'] ?? $meta['barcode'] ?? null;
                $supplierArticle = $stock['supplierArticle'] ?? $meta['supplierArticle'] ?? null;
                $warehouseCoefficient = $this->resolveWarehouseCoefficient($warehouseName, $warehouseCoefficients);

                $inventory[] = [
                    'sku' => $barcode ?? $supplierArticle,
                    'warehouse_id' => $warehouseId,
                    'warehouse_name' => $warehouseName,
                    'marketplace' => 'wildberries',
                    'quantity' => $stock['quantityFull'] ?? $stock['quantity'] ?? 0,
                    'in_transit' => ($stock['inWayToClient'] ?? 0) + ($stock['inWayFromClient'] ?? 0),
                    'warehouse_coefficient' => $warehouseCoefficient,
                ];
            }

            Log::info('Wildberries inventory fetched', ['count' => count($inventory)]);

            return $inventory;

        } catch (\Exception $e) {
            Log::error('WB getInventory error: '.$e->getMessage());

            return [];
        }
    }

    private function getWbWarehouseStocksReport(): array
    {
        $limit = 250000;
        $offset = 0;
        $result = [];
        $pages = 0;

        do {
            $response = $this->wbPost("{$this->analyticsApiUrl}/api/analytics/v1/stocks-report/wb-warehouses", [
                'limit' => $limit,
                'offset' => $offset,
            ]);

            if (! $response->successful()) {
                Log::error('WB getWbWarehouseStocksReport error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'offset' => $offset,
                ]);
                break;
            }

            $items = $response->json('data.items') ?? [];
            if (empty($items)) {
                break;
            }

            $result = array_merge($result, $items);
            $count = count($items);
            $offset += $count;
            $pages++;

            if ($count === $limit) {
                sleep(20);
            }
        } while ($count === $limit && $pages < 20);

        return $result;
    }

    private function getLegacyStocksReport(): array
    {
        $response = $this->wbGet("{$this->statisticsApiUrl}/api/v1/supplier/stocks", [
            'dateFrom' => '2019-01-01',
        ]);

        if (! $response->successful()) {
            Log::error('WB legacy stocks report error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        }

        return $response->json() ?? [];
    }

    private function getWbSizeMap(): array
    {
        $byNmChrt = [];
        $byNm = [];
        $cursor = ['limit' => 100];
        $iteration = 0;

        do {
            $response = $this->wbPost("{$this->contentApiUrl}/content/v2/get/cards/list", [
                'settings' => [
                    'cursor' => $cursor,
                    'filter' => [
                        'withPhoto' => CardListWithPhotoFilter::allCards(),
                    ],
                ],
            ]);

            if (! $response->successful()) {
                Log::warning('WB getWbSizeMap error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                break;
            }

            $data = $response->json();
            $cards = $data['cards'] ?? [];

            foreach ($cards as $card) {
                $nmId = (int) ($card['nmID'] ?? 0);
                if (! $nmId) {
                    continue;
                }

                $supplierArticle = $card['vendorCode'] ?? null;
                $sizes = $card['sizes'] ?? [];

                if (! isset($byNm[(string) $nmId])) {
                    $byNm[(string) $nmId] = [
                        'barcode' => $sizes[0]['skus'][0] ?? null,
                        'supplierArticle' => $supplierArticle,
                    ];
                }

                foreach ($sizes as $size) {
                    $chrtId = (int) ($size['chrtID'] ?? 0);
                    if (! $chrtId) {
                        continue;
                    }

                    $byNmChrt["{$nmId}:{$chrtId}"] = [
                        'barcode' => $size['skus'][0] ?? null,
                        'supplierArticle' => $supplierArticle,
                    ];
                }
            }

            $cursor = $data['cursor'] ?? null;
            $iteration++;
            $hasMore = ! empty($cards) && $cursor && isset($cursor['nmID']);
        } while ($hasMore && $iteration < 50);

        return [
            'by_nm_chrt' => $byNmChrt,
            'by_nm' => $byNm,
        ];
    }

    /**
     * Получение списка складов WB
     * Актуальный эндпоинт: GET /api/v1/warehouses
     * Документация: https://dev.wildberries.ru/openapi/orders-fbw
     */
    public function getWarehouses(): array
    {
        try {
            $response = $this->wbGet("{$this->suppliesApiUrl}/api/v1/warehouses");

            if (! $response->successful()) {
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
            Log::error('WB getWarehouses error: '.$e->getMessage());

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

            $response = $this->wbGet("{$this->statisticsApiUrl}/api/v1/supplier/sales", [
                'dateFrom' => $dateFrom,
                'flag' => 1,
            ], 120);

            if (! $response->successful()) {
                Log::warning('WB getSalesByWarehouse error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            $sales = $response->json() ?? [];

            $now = now();
            $date7 = $now->copy()->subDays(7);
            $date14 = $now->copy()->subDays(14);
            $date30 = $now->copy()->subDays(30);

            // Агрегируем по [barcode][warehouse_key][period]
            // barcode совпадает с products.sku и inventory_warehouses.sku
            // warehouseName → wb_+md5 совпадает с warehouse_id в inventory_warehouses
            $counts = [];
            foreach ($sales as $sale) {
                $barcode = $sale['barcode'] ?? null;
                $warehouseName = $sale['warehouseName'] ?? '';
                $warehouseKey = $warehouseName !== '' ? 'wb_'.substr(md5($warehouseName), 0, 8) : '0';
                $isReturn = $sale['isReturn'] ?? false;
                $saleDate = $sale['date'] ?? null;

                if (! $barcode || $isReturn || ! $saleDate) {
                    continue;
                }

                try {
                    $dt = \Carbon\Carbon::parse($saleDate);
                } catch (\Exception $e) {
                    continue;
                }

                if (! isset($counts[$barcode][$warehouseKey])) {
                    $counts[$barcode][$warehouseKey] = ['d7' => 0, 'd14' => 0, 'd30' => 0];
                }

                if ($dt->gte($date30)) {
                    $counts[$barcode][$warehouseKey]['d30']++;
                }
                if ($dt->gte($date14)) {
                    $counts[$barcode][$warehouseKey]['d14']++;
                }
                if ($dt->gte($date7)) {
                    $counts[$barcode][$warehouseKey]['d7']++;
                }
            }

            // Формируем результат: [barcode][warehouse_key] = [avg_daily, sales_7, sales_14, sales_30]
            $result = [];
            foreach ($counts as $barcode => $warehouses) {
                foreach ($warehouses as $warehouseKey => $periods) {
                    $result[$barcode][$warehouseKey] = [
                        'avg_daily_sales' => round($periods['d30'] / 30, 4),
                        'sales_7_days' => $periods['d7'],
                        'sales_14_days' => $periods['d14'],
                        'sales_30_days' => $periods['d30'],
                    ];
                }
            }

            Log::info('WB sales by warehouse fetched', [
                'skus' => count($result),
                'raw' => count($sales),
                'sample_skus' => array_slice(array_keys($result), 0, 5),
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error('WB getSalesByWarehouse error: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Получение FBS-остатков на складах продавца
     * Эндпоинт: POST /api/v3/stocks/{warehouseId}
     * Документация: https://dev.wildberries.ru/openapi/orders-fbs
     *
     * ВАЖНО: параметр skus deprecated с 9.02.2025.
     * Используем chrtIds (ID размеров) из карточек товаров (Content API).
     */
    public function getFbsStocks(): array
    {
        try {
            $sellerWarehouses = $this->getSellerWarehouses();
            if (empty($sellerWarehouses)) {
                Log::info('WB FBS: нет складов продавца');

                return [];
            }

            // Получаем chrtIds из Content API (карточки товаров)
            $chrtIds = $this->getAllChrtIdsFromContent();
            if (empty($chrtIds)) {
                Log::warning('WB FBS: не удалось получить chrtIds из карточек товаров');

                return [];
            }

            Log::info('WB FBS: получено chrtIds', ['count' => count($chrtIds)]);

            $result = [];

            foreach ($sellerWarehouses as $warehouse) {
                $warehouseId = $warehouse['id'];
                $warehouseName = $warehouse['name'];

                // API позволяет до 1000 chrtIds за запрос
                $chunks = array_chunk($chrtIds, 1000);

                foreach ($chunks as $chunk) {
                    $response = $this->wbPost(
                        "https://marketplace-api.wildberries.ru/api/v3/stocks/{$warehouseId}",
                        ['chrtIds' => $chunk]
                    );

                    if (! $response->successful()) {
                        Log::warning('WB FBS stocks error', [
                            'warehouseId' => $warehouseId,
                            'status' => $response->status(),
                            'body' => $response->body(),
                        ]);
                        break;
                    }

                    $stocks = $response->json()['stocks'] ?? [];

                    foreach ($stocks as $stock) {
                        $barcode = $stock['sku'] ?? null;
                        if (! $barcode) {
                            continue;
                        }

                        $result[] = [
                            'sku' => $barcode,
                            'warehouse_id' => 'fbs_'.$warehouseId,
                            'warehouse_name' => '[FBS] '.$warehouseName,
                            'marketplace' => 'wildberries',
                            'fulfillment_type' => 'fbs',
                            'quantity' => $stock['amount'] ?? 0,
                        ];
                    }
                }
            }

            Log::info('WB FBS stocks fetched', ['count' => count($result)]);

            return $result;

        } catch (\Exception $e) {
            Log::error('WB getFbsStocks error: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Получить все chrtIds (ID размеров) из карточек товаров через WB Content API.
     * POST https://content-api.wildberries.ru/content/v2/get/cards/list
     *
     * Структура запроса согласно документации:
     * { settings: { cursor: { limit, updatedAt?, nmID? }, filter: { withPhoto: all-cards value } } }
     * Курсор пагинации в ответе: cursor.updatedAt + cursor.nmID
     */
    private function getAllChrtIdsFromContent(): array
    {
        $chrtIds = [];
        $updatedAt = null;
        $nmID = null;
        $maxIter = 100;
        $iter = 0;

        do {
            $cursorPayload = ['limit' => 100];
            if ($updatedAt !== null) {
                $cursorPayload['updatedAt'] = $updatedAt;
                $cursorPayload['nmID'] = $nmID;
            }

            $body = [
                'settings' => [
                    'cursor' => $cursorPayload,
                    'filter' => ['withPhoto' => CardListWithPhotoFilter::allCards()],
                ],
            ];

            $response = Http::withHeaders([
                'Authorization' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post(
                'https://content-api.wildberries.ru/content/v2/get/cards/list',
                $body
            );

            if (! $response->successful()) {
                Log::warning('WB getAllChrtIdsFromContent: ошибка Content API', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                break;
            }

            $data = $response->json();
            $cards = $data['cards'] ?? [];

            Log::info('WB getAllChrtIdsFromContent: получено карточек', [
                'count' => count($cards),
                'updatedAt' => $updatedAt,
                'nmID' => $nmID,
            ]);

            foreach ($cards as $card) {
                foreach (($card['sizes'] ?? []) as $size) {
                    $chrtId = $size['chrtID'] ?? null;
                    if ($chrtId) {
                        $chrtIds[] = (int) $chrtId;
                    }
                }
            }

            // Курсор для следующей страницы
            $cursor = $data['cursor'] ?? null;
            $updatedAt = $cursor['updatedAt'] ?? null;
            $nmID = $cursor['nmID'] ?? null;

            // Продолжаем если карточек столько же сколько запросили (100)
            $hasMore = count($cards) === 100 && $updatedAt !== null;
            $iter++;

        } while ($hasMore && $iter < $maxIter);

        $result = array_values(array_unique($chrtIds));
        Log::info('WB getAllChrtIdsFromContent: итого chrtIds', ['count' => count($result)]);

        return $result;
    }

    /**
     * Получение складов продавца (FBS)
     * Эндпоинт: GET /api/v3/warehouses
     */
    public function getSellerWarehouses(): array
    {
        try {
            $response = $this->wbGet('https://marketplace-api.wildberries.ru/api/v3/warehouses');

            if (! $response->successful()) {
                Log::error('WB getSellerWarehouses error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            return array_map(function ($wh) {
                return [
                    'id' => $wh['id'],
                    'name' => $wh['name'],
                ];
            }, $response->json() ?? []);

        } catch (\Exception $e) {
            Log::error('WB getSellerWarehouses error: '.$e->getMessage());

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
            $response = $this->wbGet("{$this->statisticsApiUrl}/api/v1/supplier/sales", [
                'dateFrom' => $dateFrom,
            ]);

            if (! $response->successful()) {
                Log::error('WB getSalesStats error', [
                    'status' => $response->status(),
                ]);

                return [];
            }

            return $response->json() ?? [];

        } catch (\Exception $e) {
            Log::error('WB getSalesStats error: '.$e->getMessage());

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
            $response = $this->wbGet("{$this->commonApiUrl}/api/v1/tariffs/commission", ['locale' => 'ru']);

            if (! $response->successful()) {
                return [];
            }

            $report = $response->json()['report'] ?? [];
            $result = [];
            foreach ($report as $item) {
                $subjectId = $item['subjectID'] ?? null;
                if (! $subjectId) {
                    continue;
                }

                $result[(string) $subjectId] = [
                    'fbo' => (float) ($item['kgvpMarketplace'] ?? 15.0),
                    'fbs' => (float) ($item['kgvpSupplier'] ?? 15.0),
                ];
            }

            return $result;

        } catch (\Exception $e) {
            Log::error('WB getCommissions error: '.$e->getMessage());

            return [];
        }
    }

    public function getWarehouseCoefficients(): array
    {
        try {
            $response = $this->wbGet("{$this->commonApiUrl}/api/v1/tariffs/box", [
                'date' => now()->format('Y-m-d'),
            ]);

            if (! $response->successful()) {
                return [];
            }

            $warehouseList = $response->json()['response']['data']['warehouseList'] ?? [];
            $result = [];

            foreach ($warehouseList as $warehouse) {
                $name = (string) ($warehouse['warehouseName'] ?? '');
                if ($name === '') {
                    continue;
                }

                $normalizedName = $this->normalizeWarehouseName($name);
                $coefPercent = (float) ($warehouse['boxDeliveryCoefExpr'] ?? 100);

                $result[$normalizedName] = [
                    'warehouse_name' => $name,
                    'warehouse_coefficient' => $coefPercent / 100,
                ];
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('WB getWarehouseCoefficients error: '.$e->getMessage());

            return [];
        }
    }

    private function resolveWarehouseCoefficient(string $warehouseName, array $coefficients): ?float
    {
        if ($warehouseName === '' || empty($coefficients)) {
            return null;
        }

        $normalizedName = $this->normalizeWarehouseName($warehouseName);
        if (isset($coefficients[$normalizedName])) {
            return (float) ($coefficients[$normalizedName]['warehouse_coefficient'] ?? 1.0);
        }

        foreach ($coefficients as $name => $data) {
            if (str_contains($name, $normalizedName) || str_contains($normalizedName, $name)) {
                return (float) ($data['warehouse_coefficient'] ?? 1.0);
            }
        }

        return null;
    }

    private function normalizeWarehouseName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = str_replace(['-', '–', '—'], ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name);

        return trim($name);
    }
}
