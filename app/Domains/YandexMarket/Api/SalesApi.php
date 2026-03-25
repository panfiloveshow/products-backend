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
            $response = $this->client->post('/v2/campaigns/{campaignId}/stats/orders', [
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
     * Продажи по SKU. В v2 метод stats/skus требует непустой shopSkus (до 500).
     * Если $shopSkus пуст — собираем SKU из первых страниц offer-mappings.
     *
     * @param  list<string>  $shopSkus
     * @return array<string, array<string, mixed>>
     */
    public function getSalesBySku(int $days = 30, array $shopSkus = []): array
    {
        try {
            $dateFrom = now()->subDays($days)->format('Y-m-d');
            $dateTo = now()->format('Y-m-d');

            if ($shopSkus === []) {
                $shopSkus = $this->collectShopSkusFromCatalog(500);
            }
            if ($shopSkus === []) {
                return [];
            }

            $response = $this->client->post('/v2/campaigns/{campaignId}/stats/skus', [
                'shopSkus' => array_values(array_slice($shopSkus, 0, 500)),
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
            ]);

            $salesData = [];
            foreach ($response['result']['shopSkus'] ?? [] as $item) {
                $sku = $item['shopSku'] ?? null;
                if (! $sku) {
                    continue;
                }

                $orders = (int) ($item['orderCount'] ?? 0);

                $salesData[$sku] = [
                    'sales_30_days' => $orders,
                    'sales_14_days' => (int) ($orders * 14 / max(1, $days)),
                    'sales_7_days' => (int) ($orders * 7 / max(1, $days)),
                    'avg_daily_sales' => round($orders / max(1, $days), 2),
                    'revenue' => (float) ($item['revenue'] ?? 0),
                ];
            }

            return $salesData;
        } catch (\Exception $e) {
            Log::error('YandexMarket getSalesBySku error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function collectShopSkusFromCatalog(int $max = 500): array
    {
        $businessId = $this->client->resolveBusinessId();
        $skus = [];
        $pageToken = null;

        do {
            $query = array_filter([
                'limit' => 100,
                'page_token' => $pageToken,
            ]);
            $response = $this->client->post('/v2/businesses/'.$businessId.'/offer-mappings', [], $query);
            foreach ($response['result']['offerMappings'] ?? [] as $entry) {
                $offer = $entry['offer'] ?? [];
                $s = trim((string) ($offer['shopSku'] ?? $offer['vendorCode'] ?? ''));
                if ($s !== '') {
                    $skus[] = $s;
                }
                if (count($skus) >= $max) {
                    break 2;
                }
            }
            $pageToken = $response['result']['paging']['nextPageToken'] ?? null;
        } while ($pageToken && count($skus) < $max);

        return array_values(array_unique($skus));
    }
}
