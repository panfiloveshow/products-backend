<?php

namespace App\Domains\Ozon\Api;

use Illuminate\Support\Facades\Log;

/**
 * API для работы со складами Ozon
 */
class WarehousesApi
{
    public function __construct(
        private OzonClient $client
    ) {}

    /**
     * Получить список складов
     */
    public function getWarehouses(): array
    {
        try {
            $response = $this->client->post('/v1/warehouse/list', []);

            return $response['result'] ?? [];
        } catch (\Exception $e) {
            Log::error('Ozon getWarehouses error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Получить товары в пути к клиенту
     */
    public function getInTransitBySku(): array
    {
        try {
            $response = $this->client->post('/v2/posting/fbo/list', [
                'dir' => 'ASC',
                'filter' => [
                    'status' => 'delivering',
                ],
                'limit' => 1000,
                'offset' => 0,
                'with' => [
                    'analytics_data' => false,
                    'financial_data' => false,
                ],
            ]);

            $result = [];
            foreach ($response['result']['postings'] ?? [] as $posting) {
                foreach ($posting['products'] ?? [] as $product) {
                    $sku = $product['offer_id'] ?? null;
                    if (!$sku) continue;

                    if (!isset($result[$sku])) {
                        $result[$sku] = 0;
                    }
                    $result[$sku] += (int)($product['quantity'] ?? 0);
                }
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Ozon getInTransitBySku error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Получить возвраты по SKU
     * Использует актуальный endpoint /v1/returns/list (заменяет устаревший /v3/returns/company/fbo)
     */
    public function getReturnsBySku(int $days = 30): array
    {
        try {
            // Используем актуальный endpoint для возвратов FBO и FBS.
            // Пагинация по last_id (курсор из ответа), чтобы не терять возвраты
            // у крупных каталогов, где за окно их больше 500.
            $result = [];
            $lastId = 0;
            $maxPages = 50; // защита от бесконечного цикла (до 25k возвратов)

            for ($page = 0; $page < $maxPages; $page++) {
                $response = $this->client->post('/v1/returns/list', [
                    'filter' => [
                        'logistic_return_date' => [
                            'from' => now()->subDays($days)->format('Y-m-d\TH:i:s\Z'),
                            'to' => now()->format('Y-m-d\TH:i:s\Z'),
                        ],
                    ],
                    'limit' => 500, // Максимум 500 согласно документации
                    'last_id' => $lastId,
                ]);

                $returns = $response['returns'] ?? [];
                foreach ($returns as $return) {
                    // /v1/returns/list отдаёт ОДИН товар в поле `product` (объект),
                    // а не массив `products`. Старый код читал ['products'] → цикл
                    // всегда пустой → returns_count = 0 у всех SKU → при выкупе 100%
                    // возвраты не попадали в эффективную логистику. Поддерживаем оба
                    // варианта на случай иной формы ответа.
                    $products = $return['products'] ?? null;
                    if ($products === null && isset($return['product']) && is_array($return['product'])) {
                        $products = [$return['product']];
                    }

                    foreach ($products ?? [] as $product) {
                        $sku = $product['offer_id'] ?? null;
                        if (!$sku) continue;

                        if (!isset($result[$sku])) {
                            $result[$sku] = 0;
                        }
                        $result[$sku] += (int)($product['quantity'] ?? 1);
                    }
                }

                // Курсор следующей страницы. Останавливаемся, когда API сообщил,
                // что страниц больше нет, либо не вернул курсор/полную страницу.
                $nextLastId = (int) ($response['last_id'] ?? 0);
                $hasNext = $response['has_next'] ?? (count($returns) >= 500);
                if (! $hasNext || $nextLastId === 0 || $nextLastId === $lastId || empty($returns)) {
                    break;
                }
                $lastId = $nextLastId;
            }

            return $result;
        } catch (\Exception $e) {
            Log::warning('Ozon getReturnsBySku error', [
                'error' => $e->getMessage(),
                'endpoint' => '/v1/returns/list',
            ]);
            return [];
        }
    }

    /**
     * Получить детальную информацию по складам и остаткам
     */
    public function getDetailedInventory(): array
    {
        try {
            $lastId = '';
            $allStocks = [];

            do {
                $response = $this->client->post('/v2/analytics/stock_on_warehouses', [
                    'limit' => 1000,
                    'offset' => count($allStocks),
                    'warehouse_type' => 'ALL',
                ]);

                $rows = $response['result']['rows'] ?? [];
                if (empty($rows)) break;

                foreach ($rows as $row) {
                    // item_code содержит offer_id (артикул продавца), sku - числовой ID Ozon
                    $offerId = $row['item_code'] ?? '';
                    $warehouseName = $row['warehouse_name'] ?? 'Unknown';
                    
                    $allStocks[] = [
                        'sku' => $offerId, // Используем offer_id как sku для совместимости
                        'offer_id' => $offerId,
                        'ozon_sku' => $row['sku'] ?? '', // Числовой SKU Ozon
                        'warehouse_name' => $warehouseName,
                        'warehouse_id' => $warehouseName, // Используем имя как ID
                        'quantity' => (int)($row['free_to_sell_amount'] ?? 0),
                        'reserved' => (int)($row['reserved_amount'] ?? 0),
                        'warehouse_type' => $row['warehouse_type'] ?? 'FBO',
                        'fulfillment_type' => $row['warehouse_type'] ?? 'FBO',
                    ];
                }

                if (count($rows) < 1000) break;
            } while (true);

            return $allStocks;
        } catch (\Exception $e) {
            Log::error('Ozon getDetailedInventory error', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
