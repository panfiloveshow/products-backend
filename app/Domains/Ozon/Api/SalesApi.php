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
