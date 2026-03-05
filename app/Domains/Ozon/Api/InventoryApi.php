<?php

namespace App\Domains\Ozon\Api;

use App\Domains\Marketplace\Contracts\InventoryApiInterface;
use App\Models\Integration;

/**
 * API для работы с остатками Ozon
 * 
 * Актуальные Endpoints (обновлено 2025-01):
 * 
 * Остатки:
 * - POST /v4/product/info/stocks - остатки товаров (актуальный! v3 deprecated 31.01.2025)
 * - POST /v1/product/info/stocks-by-warehouse/fbs - остатки по складам FBS/rFBS
 * - POST /v2/products/stocks - обновление остатков
 * 
 * Склады:
 * - POST /v1/warehouse/list - список складов
 * 
 * @see https://docs.ozon.ru/api/seller
 */
class InventoryApi implements InventoryApiInterface
{
    public function __construct(
        private OzonClient $client
    ) {}

    /**
     * Получить остатки по всем складам для ВСЕХ схем работы
     * 
     * Загружает остатки из ОБОИХ источников:
     * - FBO: POST /v4/product/info/stocks
     * - FBS/RFBS/EXPRESS: POST /v1/product/info/stocks-by-warehouse/fbs
     * 
     * @param Integration|null $integration Интеграция
     * @param array $skus Фильтр по SKU
     * @see https://docs.ozon.ru/api/seller
     */
    public function getStocks(?Integration $integration = null, array $skus = []): array
    {
        \Log::info('Ozon getStocks: загружаем остатки для ВСЕХ схем работы', [
            'integration_id' => $integration?->id,
            'skus_count' => count($skus),
        ]);

        // Загружаем остатки FBO
        $fboStocks = $this->getStocksForFbo($skus);
        
        // Загружаем остатки FBS/RFBS/EXPRESS
        $fbsStocks = $this->getStocksForFbsSchemes($skus);
        
        // Объединяем результаты
        $allStocks = array_merge($fboStocks, $fbsStocks);
        
        \Log::info('Ozon getStocks: загружено остатков', [
            'fbo_count' => count($fboStocks),
            'fbs_count' => count($fbsStocks),
            'total_count' => count($allStocks),
        ]);

        return $allStocks;
    }

    /**
     * Получить остатки для FBO схемы
     * POST /v4/product/info/stocks
     */
    private function getStocksForFbo(array $skus = []): array
    {
        $allStocks = [];
        $lastId = '';

        do {
            $body = [
                'filter' => [
                    'visibility' => 'ALL',
                ],
                'limit' => 1000,
                'last_id' => $lastId,
            ];

            if (!empty($skus)) {
                $body['filter']['offer_id'] = $skus;
            }

            // Используем v4 API (v3 deprecated с 31.01.2025)
            $response = $this->client->post('/v4/product/info/stocks', $body);

            if (!$response) {
                break;
            }

            $items = $response['result']['items'] ?? $response['items'] ?? [];
            $lastId = $response['result']['last_id'] ?? $response['last_id'] ?? '';

            foreach ($items as $item) {
                $sku = $item['offer_id'] ?? null;
                if (!$sku) continue;

                if (!isset($allStocks[$sku])) {
                    $allStocks[$sku] = [
                        'sku' => $sku,
                        'product_id' => $item['product_id'] ?? null,
                        'warehouses' => [],
                        'total' => 0,
                        'reserved' => 0,
                        'fulfillment_type' => 'FBO',
                    ];
                }

                foreach ($item['stocks'] ?? [] as $stock) {
                    $stockType = $stock['type'] ?? 'fbo';
                    $quantity = $stock['present'] ?? 0;
                    $reserved = $stock['reserved'] ?? 0;

                    // Пропускаем FBS записи — они загружаются через getStocksForFbsSchemes
                    // FBO типы: fbo, fbo_crossborder, fbo_express, etc.
                    if (strtolower($stockType) === 'fbs') {
                        continue;
                    }

                    $allStocks[$sku]['warehouses'][] = [
                        'warehouse_id' => 'fbo_' . $stockType,
                        'warehouse_name' => 'Ozon FBO ' . ucfirst($stockType),
                        'warehouse_type' => 'fbo',
                        'quantity' => $quantity,
                        'reserved' => $reserved,
                    ];
                    $allStocks[$sku]['total'] += $quantity;
                    $allStocks[$sku]['reserved'] += $reserved;
                }
            }

        } while (!empty($items) && !empty($lastId));

        // Убираем записи без складов (когда все stocks были FBS и пропущены)
        $allStocks = array_filter($allStocks, fn($item) => !empty($item['warehouses']));

        \Log::info('Ozon getStocksForFbo: загружено остатков', ['count' => count($allStocks)]);
        return array_values($allStocks);
    }

    /**
     * Получить остатки для FBS/RFBS/EXPRESS схем
     * POST /v1/product/info/stocks-by-warehouse/fbs
     * 
     * ВАЖНО: API требует передать offer_id - нельзя запрашивать без параметров!
     * Если $skus пустой, сначала получаем список всех товаров через v3/product/list
     */
    public function getStocksForFbsSchemes(array $skus = []): array
    {
        // Если SKU не переданы, получаем список всех offer_id
        if (empty($skus)) {
            $skus = $this->getAllOfferIds();

            if (empty($skus)) {
                \Log::warning('Ozon getStocksForFbsSchemes: нет offer_id для запроса FBS остатков');
                return [];
            }
        }

        // API принимает до 500 offer_id за раз — разбиваем на чанки
        $chunks    = array_chunk($skus, 500);
        $allStocks = [];

        foreach ($chunks as $chunk) {
            $body = ['offer_id' => $chunk];

            \Log::info('Ozon getStocksForFbsSchemes: запрос', [
                'offer_id_count' => count($chunk),
                'first'          => $chunk[0] ?? null,
            ]);

            $response = $this->client->post('/v1/product/info/stocks-by-warehouse/fbs', $body);

            if (!$response) {
                \Log::warning('Ozon getStocksForFbsSchemes: пустой ответ API');
                continue;
            }

            $items = $response['result'] ?? [];

            \Log::info('Ozon getStocksForFbsSchemes: ответ API', [
                'result_count' => count($items),
                'first_item'   => !empty($items) ? array_keys($items[0]) : [],
                'sample'       => array_slice($items, 0, 2),
            ]);

            foreach ($items as $item) {
                // offer_id — артикул продавца, sku — числовой ID Ozon
                $offerId = $item['offer_id'] ?? null;
                $ozonSku = $item['sku'] ?? null;

                // Используем offer_id как ключ; если пустой — пропускаем (нет привязки к артикулу)
                $key = $offerId ?: null;
                if (!$key) {
                    \Log::debug('Ozon getStocksForFbsSchemes: пропускаем элемент без offer_id', ['sku' => $ozonSku]);
                    continue;
                }

                $quantity      = (int)($item['present']  ?? 0);
                $reserved      = (int)($item['reserved'] ?? 0);
                $warehouseId   = $item['warehouse_id']   ?? null;
                $warehouseName = $item['warehouse_name'] ?? 'FBS склад';

                if (!isset($allStocks[$key])) {
                    $allStocks[$key] = [
                        'sku'              => $key,
                        'ozon_sku'         => $ozonSku,
                        'product_id'       => $item['product_id'] ?? null,
                        'warehouses'       => [],
                        'total'            => 0,
                        'reserved'         => 0,
                        'fulfillment_type' => 'FBS',
                    ];
                }

                $allStocks[$key]['warehouses'][] = [
                    'warehouse_id'     => $warehouseId,
                    'warehouse_name'   => $warehouseName,
                    'warehouse_type'   => 'fbs',
                    'quantity'         => $quantity,
                    'reserved'         => $reserved,
                    'fulfillment_type' => 'fbs',
                ];

                $allStocks[$key]['total']    += $quantity;
                $allStocks[$key]['reserved'] += $reserved;
            }
        }

        \Log::info('Ozon getStocksForFbsSchemes: итого загружено SKU', ['count' => count($allStocks)]);
        return array_values($allStocks);
    }

    /**
     * Получить список всех offer_id товаров через v3/product/list
     * Используется для запроса FBS остатков, т.к. API требует передать offer_id
     */
    private function getAllOfferIds(): array
    {
        $allOfferIds = [];
        $lastId = '';
        
        do {
            $body = [
                'filter' => ['visibility' => 'ALL'],
                'limit' => 1000,
            ];
            
            if (!empty($lastId)) {
                $body['last_id'] = $lastId;
            }
            
            $response = $this->client->post('/v3/product/list', $body);
            
            if (!$response) {
                break;
            }
            
            $items = $response['result']['items'] ?? [];
            $lastId = $response['result']['last_id'] ?? '';
            
            foreach ($items as $item) {
                if (!empty($item['offer_id'])) {
                    $allOfferIds[] = $item['offer_id'];
                }
            }
            
        } while (!empty($items) && !empty($lastId));
        
        \Log::info('Ozon getAllOfferIds: получено offer_id', ['count' => count($allOfferIds)]);
        return $allOfferIds;
    }

    /**
     * Определить преобладающую схему работы для интеграции
     */
    private function getFulfillmentType(?Integration $integration): string
    {
        if (!$integration) {
            return 'FBO'; // По умолчанию
        }

        // Получаем наиболее частую схему работы из товаров этой интеграции
        $fulfillmentType = \App\Models\Product::where('integration_id', $integration->id)
            ->where('marketplace', 'ozon')
            ->whereNotNull('fulfillment_type')
            ->where('fulfillment_type', '!=', '')
            ->selectRaw('fulfillment_type, COUNT(*) as count')
            ->groupBy('fulfillment_type')
            ->orderByDesc('count')
            ->value('fulfillment_type');

        return $fulfillmentType ?: 'FBO';
    }
    
    /**
     * Получить остатки по складам FBS/rFBS
     * 
     * POST /v1/product/info/stocks-by-warehouse/fbs
     * 
     * Возвращает детальную информацию по каждому складу:
     * - sku, offer_id, product_id
     * - present (общее количество)
     * - reserved (зарезервировано)
     * - warehouse_id, warehouse_name
     * 
     * @see https://docs.ozon.ru/api/seller
     */
    public function getStocksByWarehouseFbs(array $skus = [], array $offerIds = []): array
    {
        $body = [];
        
        if (!empty($skus)) {
            $body['sku'] = $skus;
        }
        
        if (!empty($offerIds)) {
            $body['offer_id'] = $offerIds;
        }
        
        $response = $this->client->post('/v1/product/info/stocks-by-warehouse/fbs', $body);
        
        return $response['result'] ?? [];
    }

    /**
     * Получить остатки по каждому реальному FBO-складу Ozon.
     * Использует /v2/analytics/stock_on_warehouses.
     * Возвращает массив в формате SyncInventoryJob:
     * [['sku' => offer_id, 'warehouses' => [['warehouse_id', 'warehouse_name', 'quantity', 'fulfillment_type']]]]
     */
    public function getStocksPerWarehouse(): array
    {
        $allRows  = [];
        $offset   = 0;
        $limit    = 1000;

        do {
            $response = $this->client->post('/v2/analytics/stock_on_warehouses', [
                'limit'          => $limit,
                'offset'         => $offset,
                'warehouse_type' => 'ALL',
            ]);

            $rows = $response['result']['rows'] ?? [];
            $allRows = array_merge($allRows, $rows);
            $offset += $limit;
        } while (count($rows) === $limit);

        // Группируем по offer_id (item_code = offer_id продавца)
        $grouped = [];
        foreach ($allRows as $row) {
            $offerId     = $row['item_code'] ?? null;
            $whName      = $row['warehouse_name'] ?? '';
            // quantity = free_to_sell + reserved = present (как показывает Ozon Seller)
            // promised_amount — товар едет на склад или в обработке, не учитываем в остатке
            $qty         = (int)($row['free_to_sell_amount'] ?? 0)
                         + (int)($row['reserved_amount'] ?? 0);

            if (!$offerId || !$whName) {
                continue;
            }

            // warehouse_id формируем из названия склада (стабильный строковый ключ)
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
                'reserved'         => (int)($row['promised_amount'] ?? 0),
                'fulfillment_type' => 'FBO',
            ];
            $grouped[$offerId]['total'] += $qty;
        }

        \Log::info('Ozon getStocksPerWarehouse: остатки по складам загружены', [
            'rows'  => count($allRows),
            'skus'  => count($grouped),
        ]);

        return array_values($grouped);
    }

    /**
     * Получить список складов
     * 
     * POST /v1/warehouse/list
     */
    public function getWarehouses(?Integration $integration = null): array
    {
        $response = $this->client->post('/v1/warehouse/list', []);
        return $response['result'] ?? [];
    }

    /**
     * Получить остатки по конкретному складу (по типу)
     * 
     * Ozon не разделяет остатки по конкретным складам в базовом API.
     * Для FBS используйте getStocksByWarehouseFbs()
     */
    public function getStocksByWarehouse(string $warehouseId, ?Integration $integration = null): array
    {
        // Ozon не разделяет остатки по конкретным складам в API
        // Возвращаем все остатки с фильтрацией по типу склада
        $allStocks = $this->getStocks($integration);
        
        return array_filter($allStocks, function ($stock) use ($warehouseId) {
            foreach ($stock['warehouses'] as $warehouse) {
                if ($warehouse['warehouse_type'] === $warehouseId) {
                    return true;
                }
            }
            return false;
        });
    }

    /**
     * Обновить остатки
     * 
     * POST /v2/products/stocks (актуальная версия!)
     * 
     * @param array $stocks [{offer_id: string, warehouse_id: int, stock: int}, ...]
     * 
     * @see https://docs.ozon.ru/api/seller
     */
    public function updateStocks(array $stocks): bool
    {
        $response = $this->client->post('/v2/products/stocks', [
            'stocks' => $stocks,
        ]);

        return $response !== null;
    }
    
    /**
     * Получить аналитику остатков на складах
     * 
     * GET /v2/analytics/stock_on_warehouses (v1 deprecated!)
     * 
     * @see https://docs.ozon.ru/api/seller
     */
    public function getStockAnalytics(array $params = []): array
    {
        $response = $this->client->get('/v2/analytics/stock_on_warehouses', $params);
        return $response['result'] ?? [];
    }
    
    /**
     * Генерация отчёта по остаткам на складах (только FBS)
     * 
     * POST /v1/report/warehouse/stock
     * 
     * @see https://docs.ozon.ru/api/seller
     */
    public function generateStockReport(): ?string
    {
        $response = $this->client->post('/v1/report/warehouse/stock', []);
        return $response['report_id'] ?? null;
    }
}
