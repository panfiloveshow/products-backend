<?php

namespace App\Domains\YandexMarket\Api;

use Illuminate\Support\Facades\Log;

/**
 * API для работы с продажами Yandex Market
 */
class SalesApi
{
    public function __construct(
        private YandexMarketClient $client
    ) {}

    /**
     * Получить статистику продаж за период
     */
    public function getSalesStats(string $dateFrom, string $dateTo): array
    {
        try {
            $response = $this->client->get("/stats/orders", [
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
            ]);

            return $response['result'] ?? [];
        } catch (\Exception $e) {
            Log::error('YandexMarket getSalesStats error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Получить продажи по SKU
     */
    public function getSalesBySku(int $days = 30): array
    {
        try {
            $dateFrom = now()->subDays($days)->format('Y-m-d');
            $dateTo = now()->format('Y-m-d');

            $response = $this->client->post("/stats/skus", [
                'shopSkus' => [],
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
            ]);

            $salesData = [];
            foreach ($response['result']['shopSkus'] ?? [] as $item) {
                $sku = $item['shopSku'] ?? null;
                if (!$sku) continue;

                $orders = (int)($item['orderCount'] ?? 0);
                
                $salesData[$sku] = [
                    'sales_30_days' => $orders,
                    'sales_14_days' => (int)($orders * 14 / $days),
                    'sales_7_days' => (int)($orders * 7 / $days),
                    'avg_daily_sales' => round($orders / $days, 2),
                    'revenue' => (float)($item['revenue'] ?? 0),
                ];
            }

            return $salesData;
        } catch (\Exception $e) {
            Log::error('YandexMarket getSalesBySku error', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
