<?php

namespace App\Domains\Ozon\Api;

use Illuminate\Support\Facades\Log;

/**
 * API для работы с продажами Ozon
 */
class SalesApi
{
    public function __construct(
        private OzonClient $client
    ) {}

    /**
     * Получить статистику продаж за период
     */
    public function getSalesStats(string $dateFrom, string $dateTo): array
    {
        try {
            $response = $this->client->post('/v1/analytics/data', [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'metrics' => ['revenue', 'ordered_units'],
                'dimension' => ['day'],
                'filters' => [],
                'sort' => [['key' => 'revenue', 'order' => 'DESC']],
                'limit' => 1000,
                'offset' => 0,
            ]);

            return $response['result']['data'] ?? [];
        } catch (\Exception $e) {
            Log::error('Ozon getSalesStats error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Получить продажи по SKU за последние N дней
     * 
     * @param int $days Количество дней для анализа
     * @param array $productIdToOfferId Маппинг product_id -> offer_id (SKU продавца)
     * @return array Данные по offer_id (SKU продавца)
     */
    public function getSalesBySku(int $days = 28, array $productIdToOfferId = []): array
    {
        try {
            $dateFrom = now()->subDays($days)->format('Y-m-d');
            $dateTo = now()->format('Y-m-d');

            $response = $this->client->post('/v1/analytics/data', [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'metrics' => ['ordered_units', 'revenue'],
                'dimension' => ['sku'],
                'filters' => [],
                'sort' => [['key' => 'ordered_units', 'order' => 'DESC']],
                'limit' => 1000,
                'offset' => 0,
            ]);

            $salesData = [];
            foreach ($response['result']['data'] ?? [] as $row) {
                // SKU здесь - это числовой product_id Ozon, НЕ offer_id
                $productId = $row['dimensions'][0]['id'] ?? null;
                if (!$productId) continue;

                // Конвертируем product_id в offer_id (SKU продавца)
                $offerId = $productIdToOfferId[$productId] ?? null;
                if (!$offerId) continue;

                $orderedUnits = (int)($row['metrics'][0] ?? 0);
                $avgDailySales = $days > 0 ? $orderedUnits / $days : 0;

                $salesData[$offerId] = [
                    'sales_30_days' => (int)round($orderedUnits * 30 / $days),
                    'sales_14_days' => (int)round($orderedUnits * 14 / $days),
                    'sales_7_days' => (int)round($orderedUnits * 7 / $days),
                    'avg_daily_sales' => round($avgDailySales, 2),
                    'revenue_30_days' => (float)($row['metrics'][1] ?? 0) * 30 / $days,
                ];
            }

            Log::info('Ozon getSalesBySku loaded', [
                'days' => $days,
                'skus_count' => count($salesData),
                'mapped' => count($productIdToOfferId),
            ]);

            return $salesData;
        } catch (\Exception $e) {
            Log::error('Ozon getSalesBySku error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Получить продажи по SKU и складу через /v2/posting/fbo/list за последние N дней.
     * Каждое FBO-отправление содержит analytics_data.warehouse_id и список товаров.
     * Агрегируем количество по offer_id + warehouse_id.
     *
     * @return array [offer_id => [warehouse_id => ['warehouse_name', 'sales_7_days', 'sales_14_days', 'sales_30_days', 'avg_daily_sales']]]
     */
    public function getSalesBySkuAndWarehouse(int $days = 28, array $productIdToOfferId = []): array
    {
        try {
            $since  = now()->subDays($days)->setTime(0, 0, 0)->toIso8601String();
            $to     = now()->toIso8601String();
            $offset = 0;
            $limit  = 1000;

            // rawUnits[offer_id][warehouse_id] = ['units' => int, 'warehouse_name' => string]
            $rawUnits = [];

            do {
                $response = $this->client->post('/v2/posting/fbo/list', [
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

                $postings = $response['result'] ?? [];

                foreach ($postings as $posting) {
                    $warehouseId = (string)($posting['analytics_data']['warehouse_id'] ?? '');
                    $whName      = $posting['analytics_data']['warehouse_name'] ?? $warehouseId;

                    if (!$warehouseId) {
                        continue;
                    }

                    foreach ($posting['products'] ?? [] as $product) {
                        $ozonSku = (string)($product['sku'] ?? '');
                        $offerId = $productIdToOfferId[$ozonSku] ?? null;

                        // Fallback: ищем по offer_id если он передан напрямую
                        if (!$offerId) {
                            $offerIdDirect = $product['offer_id'] ?? null;
                            if ($offerIdDirect) {
                                $offerId = $offerIdDirect;
                            }
                        }

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

            // Преобразуем в финальный формат с продажами за периоды
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

            Log::info('Ozon getSalesBySkuAndWarehouse (FBO postings) loaded', [
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
     * Получить статистику заказов по SKU
     */
    public function getOrdersStatsBySku(string $dateFrom, string $dateTo): array
    {
        try {
            $response = $this->client->post('/v1/analytics/data', [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'metrics' => ['ordered_units'],
                'dimension' => ['sku'],
                'filters' => [],
                'sort' => [['key' => 'ordered_units', 'order' => 'DESC']],
                'limit' => 1000,
                'offset' => 0,
            ]);

            $result = [];
            foreach ($response['result']['data'] ?? [] as $row) {
                $sku = $row['dimensions'][0]['id'] ?? null;
                if ($sku) {
                    $result[$sku] = (int)($row['metrics'][0] ?? 0);
                }
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Ozon getOrdersStatsBySku error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Получить статистику возвратов по SKU
     */
    public function getReturnsStatsBySku(string $dateFrom, string $dateTo): array
    {
        try {
            $response = $this->client->post('/v1/analytics/data', [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'metrics' => ['returns'],
                'dimension' => ['sku'],
                'filters' => [],
                'sort' => [['key' => 'returns', 'order' => 'DESC']],
                'limit' => 1000,
                'offset' => 0,
            ]);

            $result = [];
            foreach ($response['result']['data'] ?? [] as $row) {
                $sku = $row['dimensions'][0]['id'] ?? null;
                if ($sku) {
                    $result[$sku] = (int)($row['metrics'][0] ?? 0);
                }
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Ozon getReturnsStatsBySku error', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
