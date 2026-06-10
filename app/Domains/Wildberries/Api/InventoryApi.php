<?php

namespace App\Domains\Wildberries\Api;

use App\Domains\Marketplace\Contracts\InventoryApiInterface;
use App\Models\Integration;
use Illuminate\Support\Facades\Log;

/**
 * API для работы с остатками Wildberries
 *
 * Актуальные Endpoints (обновлено 2024-12):
 *
 * Marketplace API (marketplace-api.wildberries.ru):
 * - GET /api/v3/warehouses - список складов продавца
 * - POST /api/v3/stocks/{warehouseId} - получить остатки (требует chrtIds!)
 * - PUT /api/v3/stocks/{warehouseId} - обновить остатки
 *
 * Analytics API (seller-analytics-api.wildberries.ru):
 * - POST /api/analytics/v1/stocks-report/wb-warehouses - отчёт по остаткам на складах WB
 *
 * Statistics API (statistics-api.wildberries.ru):
 * - GET /api/v1/supplier/stocks - legacy отчёт по остаткам на складах WB
 *
 * ВАЖНО:
 * - Параметр `skus` DEPRECATED (отключается 9 февраля 2025)!
 * - Используйте `chrtIds` (ID размеров) вместо `skus`
 * - Для FBS складов с cargoType: 2,3 обновление остатков отключено
 *
 * @see https://dev.wildberries.ru/openapi/work-with-products
 */
class InventoryApi implements InventoryApiInterface
{
    public function __construct(
        private WildberriesClient $client,
        private ?ProductsApi $productsApi = null
    ) {}

    /**
     * Получить остатки по всем складам
     *
     * Использует Statistics API для получения всех остатков сразу.
     * Это более эффективно чем запрашивать по каждому складу отдельно.
     *
     * @param  array  $chrtIds  Массив ID размеров (chrtId). Если пустой - получаем все
     */
    public function getStocks(?Integration $integration = null, array $chrtIds = []): array
    {
        $stocksReport = $this->getWbWarehousesStocksReport($integration, $chrtIds);

        if (empty($stocksReport)) {
            Log::warning('WB InventoryApi: Analytics API returned no data, trying legacy Statistics API');
            $stocksReport = $this->getStocksReport();
        }

        if (empty($stocksReport)) {
            Log::warning('WB InventoryApi: No stocks from WB warehouses APIs, trying FBS warehouses');

            return $this->getStocksFromFbsWarehouses($integration, $chrtIds);
        }

        $allStocks = [];

        foreach ($stocksReport as $item) {
            $barcode = $item['barcode'] ?? null;
            $nmId = $item['nmId'] ?? null;
            $supplierArticle = $item['supplierArticle'] ?? null;

            // Используем barcode как основной ключ (это SKU в WB)
            $key = $barcode ?? (string) $nmId;
            if (! $key) {
                continue;
            }

            // Фильтруем по chrtIds если переданы (но в Statistics API нет chrtId напрямую)
            // Пропускаем фильтрацию если chrtIds пустой

            if (! isset($allStocks[$key])) {
                $allStocks[$key] = [
                    'sku' => $barcode,
                    'nmId' => $nmId,
                    'supplierArticle' => $supplierArticle,
                    'barcode' => $barcode,
                    'warehouses' => [],
                    'total' => 0,
                    'inWayToClient' => 0,
                    'inWayFromClient' => 0,
                    // Дополнительные данные из Statistics API
                    'category' => $item['category'] ?? null,
                    'subject' => $item['subject'] ?? null,
                    'brand' => $item['brand'] ?? null,
                    'price' => $item['Price'] ?? 0,
                    'discount' => $item['Discount'] ?? 0,
                ];
            }

            $quantity = $item['quantity'] ?? 0;
            $warehouseName = $item['warehouseName'] ?? 'Unknown';

            // Определяем тип склада: Statistics API возвращает только склады WB (FBO)
            // Склады продавца (FBS) получаются через getStocksFromFbsWarehouses()
            $fulfillmentType = 'FBO';

            $allStocks[$key]['warehouses'][] = [
                'warehouse_id' => $item['warehouseId'] ?? null,
                'warehouse_name' => $warehouseName,
                'region_name' => $item['regionName'] ?? null,
                'quantity' => $quantity,
                'quantityFull' => $item['quantityFull'] ?? $quantity,
                'inWayToClient' => $item['inWayToClient'] ?? 0,
                'inWayFromClient' => $item['inWayFromClient'] ?? 0,
                'isSupply' => $item['isSupply'] ?? false,
                'isRealization' => $item['isRealization'] ?? false,
                'techSize' => $item['techSize'] ?? '',
                'fulfillment_type' => $fulfillmentType,
            ];

            $allStocks[$key]['total'] += $quantity;
            $allStocks[$key]['inWayToClient'] += $item['inWayToClient'] ?? 0;
            $allStocks[$key]['inWayFromClient'] += $item['inWayFromClient'] ?? 0;
        }

        Log::info('WB InventoryApi: Got FBO stocks from WB warehouses report', ['count' => count($allStocks)]);

        // Также получаем остатки с FBS складов продавца
        $fbsStocks = $this->getStocksFromFbsWarehouses($integration, $chrtIds);

        // Объединяем FBO и FBS остатки
        foreach ($fbsStocks as $fbsItem) {
            $sku = $fbsItem['sku'] ?? null;
            if (! $sku) {
                continue;
            }

            if (isset($allStocks[$sku])) {
                // Добавляем FBS склады к существующему товару
                $allStocks[$sku]['warehouses'] = array_merge(
                    $allStocks[$sku]['warehouses'],
                    $fbsItem['warehouses'] ?? []
                );
                $allStocks[$sku]['total'] += $fbsItem['total'] ?? 0;
            } else {
                // Новый товар только на FBS
                $allStocks[$sku] = $fbsItem;
            }
        }

        Log::info('WB InventoryApi: Combined FBO+FBS stocks', [
            'total_skus' => count($allStocks),
            'fbs_skus' => count($fbsStocks),
        ]);

        return array_values($allStocks);
    }

    /**
     * Получить остатки с FBS складов продавца
     *
     * Склады продавца получаются через Marketplace API /api/v3/warehouses
     */
    private function getStocksFromFbsWarehouses(?Integration $integration, array $chrtIds = []): array
    {
        $warehouses = $this->getWarehouses($integration);

        if (empty($warehouses)) {
            Log::warning('WB InventoryApi: No warehouses found');

            return [];
        }

        $sizeMeta = $this->getSizeMetadata($integration);

        // Если chrtIds не переданы, получаем их из карточек товаров
        if (empty($chrtIds)) {
            $chrtIds = array_keys($sizeMeta['chrt_to_nm'] ?? []);

            if (empty($chrtIds)) {
                Log::warning('WB InventoryApi: No chrtIds found from products');

                return [];
            }
        }

        $allStocks = [];

        foreach ($warehouses as $warehouse) {
            $warehouseId = $warehouse['id'] ?? null;
            if (! $warehouseId) {
                continue;
            }

            // Пропускаем склады в процессе обработки или удаления
            if (($warehouse['isDeleting'] ?? false) || ($warehouse['isProcessing'] ?? false)) {
                Log::info('WB InventoryApi: Skipping warehouse', [
                    'id' => $warehouseId,
                    'isDeleting' => $warehouse['isDeleting'] ?? false,
                    'isProcessing' => $warehouse['isProcessing'] ?? false,
                ]);

                continue;
            }

            $stocks = $this->getStocksByWarehouse($warehouseId, $integration, $chrtIds);

            foreach ($stocks as $stock) {
                $sku = $stock['sku'] ?? null;
                $chrtId = (int) ($stock['chrtId'] ?? 0);
                $nmId = $chrtId > 0 ? ($sizeMeta['chrt_to_nm'][$chrtId] ?? null) : null;
                $meta = ($nmId && $chrtId > 0)
                    ? ($sizeMeta['by_nm_chrt']["{$nmId}:{$chrtId}"] ?? [])
                    : [];
                $sku = $sku ?: ($meta['barcode'] ?? null) ?: ($meta['supplierArticle'] ?? null);
                if (! $sku) {
                    continue;
                }

                if (! isset($allStocks[$sku])) {
                    $allStocks[$sku] = [
                        'sku' => $sku,
                        'chrtId' => $chrtId,
                        'warehouses' => [],
                        'total' => 0,
                    ];
                }

                $quantity = $stock['amount'] ?? 0;
                $allStocks[$sku]['warehouses'][] = [
                    'warehouse_id' => $warehouseId,
                    'warehouse_name' => $warehouse['name'] ?? '',
                    'cargo_type' => $warehouse['cargoType'] ?? null,
                    'quantity' => $quantity,
                    'fulfillment_type' => 'FBS', // Склады продавца — всегда FBS
                ];
                $allStocks[$sku]['total'] += $quantity;
            }
        }

        return array_values($allStocks);
    }

    /**
     * Публичный метод для получения FBS остатков (используется в WildberriesMarketplace::getFbsStocks())
     * Возвращает только FBS склады продавца
     */
    public function getStocksFromFbsWarehousesDirect(?Integration $integration = null): array
    {
        return $this->getStocksFromFbsWarehouses($integration, []);
    }

    /**
     * Получить список складов продавца (FBS)
     *
     * GET /api/v3/warehouses
     *
     * Возвращает:
     * - name: название склада
     * - officeId: ID связанного офиса
     * - id: уникальный ID склада
     * - cargoType: тип груза (1 - мелкогабарит, 2 - КГТ, 3 - КГТ+)
     * - deliveryType: тип доставки
     * - isDeleting: склад удаляется
     * - isProcessing: склад обрабатывается (обновление/удаление остатков недоступно)
     *
     * @see https://dev.wildberries.ru/openapi/work-with-products
     */
    public function getWarehouses(?Integration $integration = null): array
    {
        // /api/v3/warehouses требует Bearer авторизацию
        $response = $this->client->getWithBearer('/api/v3/warehouses');

        return $response ?? [];
    }

    /**
     * Получить остатки по конкретному складу
     *
     * POST /api/v3/stocks/{warehouseId}
     *
     * ВАЖНО:
     * - Параметр `skus` DEPRECATED (отключается 9 февраля 2025)!
     * - Используйте `chrtIds` (ID размеров) вместо `skus`
     * - Лимит: до 1000 chrtIds за запрос
     *
     * Rate limits: 300 req/min, интервал 200ms, burst 20
     * Ошибка 409 считается как 10 запросов!
     *
     * @param  string  $warehouseId  ID склада
     * @param  array  $chrtIds  Массив ID размеров (обязательно!)
     *
     * @see https://dev.wildberries.ru/openapi/work-with-products
     */
    public function getStocksByWarehouse(string $warehouseId, ?Integration $integration = null, array $chrtIds = []): array
    {
        // Если chrtIds не переданы, получаем их из карточек
        if (empty($chrtIds)) {
            $chrtIds = $this->getAllChrtIds($integration);

            if (empty($chrtIds)) {
                Log::warning('WB InventoryApi: Cannot get stocks - no chrtIds available');

                return [];
            }
        }

        // WB API позволяет запросить до 1000 chrtIds за раз
        $chunks = array_chunk($chrtIds, 1000);
        $allStocks = [];

        foreach ($chunks as $chunk) {
            $response = $this->client->post("/api/v3/stocks/{$warehouseId}", [
                'chrtIds' => $chunk,
            ]);

            if ($response && isset($response['stocks'])) {
                $allStocks = array_merge($allStocks, $response['stocks']);
            }
        }

        return $allStocks;
    }

    /**
     * Обновить остатки на складе
     *
     * PUT /api/v3/stocks/{warehouseId}
     *
     * ВАЖНО:
     * - Для FBS складов с cargoType: 2,3 обновление мелкогабарита отключено!
     * - Используйте склады с cargoType: 1 для мелкогабаритных товаров
     *
     * @param  string  $warehouseId  ID склада
     * @param  array  $stocks  Массив [{sku: string, amount: int}, ...]
     *
     * @see https://dev.wildberries.ru/openapi/work-with-products
     */
    public function updateStocks(Integration $integration, string $warehouseId, array $stocks): bool
    {
        $response = $this->client->put("/api/v3/stocks/{$warehouseId}", [
            'stocks' => $stocks,
        ]);

        return $response !== null;
    }

    /**
     * Получить отчёт по остаткам на складах WB (Statistics API)
     *
     * GET /api/v1/supplier/stocks (statistics-api.wildberries.ru)
     *
     * Данные обновляются каждые 30 минут.
     * Лимит: 60 000 строк за запрос.
     *
     * Response fields:
     * - lastChangeDate: дата последнего изменения остатка
     * - warehouseName: название склада
     * - supplierArticle: артикул поставщика
     * - nmId: ID товара WB
     * - barcode: штрихкод
     * - quantity: доступное количество
     * - inWayToClient: в пути к клиенту
     * - inWayFromClient: в пути от клиента
     * - quantityFull: полное количество
     * - category, subject, brand: категория, предмет, бренд
     * - techSize: размер
     * - Price: цена
     * - Discount: скидка
     *
     * @param  string  $dateFrom  Дата в формате RFC3339 (UTC+3)
     *
     * @see https://dev.wildberries.ru/openapi/reports
     */
    public function getStocksReport(string $dateFrom = '2019-06-20'): array
    {
        Log::info('WB InventoryApi: Requesting stocks from Statistics API', [
            'endpoint' => '/api/v1/supplier/stocks',
            'dateFrom' => $dateFrom,
        ]);

        // retriesOn429: остатки по складам критичны для КС (разбивка по складам
        // в юнит-экономике) — без ретрая 429 склады деградируют до FBS-«Мой склад».
        $response = $this->client->statisticsGet('/api/v1/supplier/stocks', [
            'dateFrom' => $dateFrom,
        ], retriesOn429: 2);

        if ($response === null) {
            Log::warning('WB InventoryApi: Statistics API returned null (check API key permissions)');

            return [];
        }

        Log::info('WB InventoryApi: Statistics API response', [
            'count' => count($response),
            'sample' => array_slice($response, 0, 2),
        ]);

        return $response;
    }

    /**
     * Получить текущие остатки WB-складов через Seller Analytics API.
     *
     * Новый endpoint WB:
     * - POST /api/analytics/v1/stocks-report/wb-warehouses
     * - limit до 250000
     * - offset pagination
     * - 1 запрос в 20 секунд на аккаунт
     *
     * Endpoint не возвращает barcode / supplierArticle, поэтому обогащаем
     * строки данными из карточек товаров по nmId + chrtId.
     */
    public function getWbWarehousesStocksReport(?Integration $integration = null, array $chrtIds = []): array
    {
        $limit = 250000;
        $offset = 0;
        $result = [];
        $pages = 0;
        $sizeMeta = $this->getSizeMetadata($integration);

        $body = [
            'limit' => $limit,
            'offset' => 0,
        ];

        if (! empty($chrtIds) && ! empty($sizeMeta['chrt_to_nm'])) {
            $filteredChrtIds = array_values(array_unique(array_map('intval', $chrtIds)));
            $nmIds = [];

            foreach ($filteredChrtIds as $chrtId) {
                if (isset($sizeMeta['chrt_to_nm'][$chrtId])) {
                    $nmIds[] = $sizeMeta['chrt_to_nm'][$chrtId];
                }
            }

            $nmIds = array_values(array_unique($nmIds));
            if (! empty($nmIds) && count($nmIds) <= 1000) {
                $body['nmIds'] = $nmIds;
                $body['chrtIds'] = $filteredChrtIds;
            }
        }

        do {
            $body['offset'] = $offset;

            Log::info('WB InventoryApi: Requesting WB warehouses report', [
                'endpoint' => '/api/analytics/v1/stocks-report/wb-warehouses',
                'limit' => $limit,
                'offset' => $offset,
            ]);

            $response = $this->client->analyticsPost('/api/analytics/v1/stocks-report/wb-warehouses', $body);
            $items = $response['data']['items'] ?? [];

            if (empty($items)) {
                break;
            }

            foreach ($items as $item) {
                $nmId = (int) ($item['nmId'] ?? 0);
                $chrtId = (int) ($item['chrtId'] ?? 0);
                $meta = $sizeMeta['by_nm_chrt']["{$nmId}:{$chrtId}"] ?? $sizeMeta['by_nm'][(string) $nmId] ?? [];

                $result[] = array_merge($item, [
                    'barcode' => $meta['barcode'] ?? null,
                    'supplierArticle' => $meta['supplierArticle'] ?? null,
                ]);
            }

            $count = count($items);
            $offset += $count;
            $pages++;

            if ($count === $limit) {
                sleep(20);
            }
        } while ($count === $limit && $pages < 20);

        Log::info('WB InventoryApi: WB warehouses report loaded', [
            'rows' => count($result),
            'pages' => $pages,
        ]);

        return $result;
    }

    /**
     * Построить маппинг nmId/chrtId -> barcode/vendorCode из карточек WB.
     *
     * @return array{
     *   by_nm_chrt: array<string, array{barcode:?string,supplierArticle:?string}>,
     *   by_nm: array<string, array{barcode:?string,supplierArticle:?string}>,
     *   chrt_to_nm: array<int, int>
     * }
     */
    private function getSizeMetadata(?Integration $integration = null): array
    {
        if (! $this->productsApi) {
            $this->productsApi = new ProductsApi($this->client);
        }

        $byNmChrt = [];
        $byNm = [];
        $chrtToNm = [];
        $cursor = null;
        $maxIterations = 50;
        $iteration = 0;

        do {
            $result = $this->productsApi->getProducts($integration, [
                'limit' => 100,
                'cursor' => $cursor,
            ]);

            $cards = $result['cards'] ?? [];
            $cursor = $result['cursor'] ?? null;

            foreach ($cards as $card) {
                $nmId = (int) ($card['nmID'] ?? 0);
                if (! $nmId) {
                    continue;
                }

                $supplierArticle = $card['vendorCode'] ?? null;
                $sizes = $card['sizes'] ?? [];

                if (! isset($byNm[(string) $nmId])) {
                    $firstBarcode = $sizes[0]['skus'][0] ?? null;
                    $byNm[(string) $nmId] = [
                        'barcode' => $firstBarcode,
                        'supplierArticle' => $supplierArticle,
                    ];
                }

                foreach ($sizes as $size) {
                    $chrtId = (int) ($size['chrtID'] ?? 0);
                    if (! $chrtId) {
                        continue;
                    }

                    $barcode = $size['skus'][0] ?? null;
                    $byNmChrt["{$nmId}:{$chrtId}"] = [
                        'barcode' => $barcode,
                        'supplierArticle' => $supplierArticle,
                    ];
                    $chrtToNm[$chrtId] = $nmId;
                }
            }

            $iteration++;
            $hasMore = ! empty($cards) && $cursor && isset($cursor['nmID']);
        } while ($hasMore && $iteration < $maxIterations);

        Log::info('WB InventoryApi: Collected size metadata', [
            'nm_count' => count($byNm),
            'nm_chrt_count' => count($byNmChrt),
        ]);

        return [
            'by_nm_chrt' => $byNmChrt,
            'by_nm' => $byNm,
            'chrt_to_nm' => $chrtToNm,
        ];
    }

    /**
     * Получить все chrtIds (ID размеров) из карточек товаров
     *
     * chrtId - это уникальный идентификатор размера товара в WB.
     * Используется вместо deprecated параметра skus.
     */
    private function getAllChrtIds(?Integration $integration = null): array
    {
        if (! $this->productsApi) {
            $this->productsApi = new ProductsApi($this->client);
        }

        $chrtIds = [];
        $cursor = null;
        $maxIterations = 50; // Защита от бесконечного цикла
        $iteration = 0;

        do {
            $result = $this->productsApi->getProducts($integration, [
                'limit' => 100,
                'cursor' => $cursor,
            ]);

            $cards = $result['cards'] ?? [];
            $cursor = $result['cursor'] ?? null;

            foreach ($cards as $card) {
                // Собираем chrtIds из всех размеров
                $sizes = $card['sizes'] ?? [];
                foreach ($sizes as $size) {
                    $chrtId = $size['chrtID'] ?? null;
                    if ($chrtId) {
                        $chrtIds[] = (int) $chrtId;
                    }
                }
            }

            $iteration++;

            // Проверяем условие выхода: нет курсора или достигли лимита итераций
            $hasMore = ! empty($cards) && $cursor && isset($cursor['nmID']);

        } while ($hasMore && $iteration < $maxIterations);

        Log::info('WB InventoryApi: Collected chrtIds', ['count' => count($chrtIds)]);

        return array_unique($chrtIds);
    }

    /**
     * @deprecated Используйте getAllChrtIds() вместо этого метода
     * Параметр skus будет отключён 9 февраля 2025
     */
    private function getAllBarcodes(?Integration $integration = null): array
    {
        Log::warning('WB InventoryApi: getAllBarcodes() is deprecated, use getAllChrtIds()');

        return $this->getAllChrtIds($integration);
    }
}
