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
     * Делаем три отдельных запроса (7, 14, 30 дней) чтобы получить реальные данные
     * вместо линейной интерполяции — EWMA-прогноз автопланирования чувствителен к тренду.
     *
     * @param  list<string>  $shopSkus
     * @return array<string, array<string, mixed>>
     */
    public function getSalesBySku(int $days = 30, array $shopSkus = []): array
    {
        try {
            if ($shopSkus === []) {
                $shopSkus = $this->collectShopSkusFromCatalog(500);
            }
            if ($shopSkus === []) {
                return [];
            }

            $skuSlice = array_values(array_slice($shopSkus, 0, 500));
            $today = now()->format('Y-m-d');

            // BUG FIX: получаем реальные данные за каждый период вместо интерполяции
            $raw30 = $this->fetchStatsSkus($skuSlice, now()->subDays(30)->format('Y-m-d'), $today);
            $raw14 = $this->fetchStatsSkus($skuSlice, now()->subDays(14)->format('Y-m-d'), $today);
            $raw7  = $this->fetchStatsSkus($skuSlice, now()->subDays(7)->format('Y-m-d'), $today);

            $salesData = [];
            foreach ($raw30 as $sku => $orders30) {
                $orders14 = $raw14[$sku] ?? (int) round($orders30 * 14 / 30);
                $orders7  = $raw7[$sku]  ?? (int) round($orders30 * 7  / 30);

                $salesData[(string) $sku] = [
                    'sales_30_days' => $orders30,
                    'sales_14_days' => $orders14,
                    'sales_7_days'  => $orders7,
                    'avg_daily_sales' => round($orders30 / 30, 2),
                    'revenue' => 0,
                ];
            }

            return $salesData;
        } catch (\Exception $e) {
            Log::error('YandexMarket getSalesBySku error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Один запрос /stats/skus за указанный период.
     *
     * @param  list<string>  $shopSkus
     * @return array<string, int>  sku => orderCount
     */
    private function fetchStatsSkus(array $shopSkus, string $dateFrom, string $dateTo): array
    {
        try {
            $response = $this->client->post('/v2/campaigns/{campaignId}/stats/skus', [
                'shopSkus' => $shopSkus,
                'dateFrom' => $dateFrom,
                'dateTo'   => $dateTo,
            ]);

            $result = [];
            foreach ($response['result']['shopSkus'] ?? [] as $item) {
                $sku = $item['shopSku'] ?? null;
                if ($sku) {
                    $result[(string) $sku] = (int) ($item['orderCount'] ?? 0);
                }
            }

            return $result;
        } catch (\Exception $e) {
            Log::warning('YandexMarket fetchStatsSkus error', [
                'dateFrom' => $dateFrom,
                'dateTo'   => $dateTo,
                'error'    => $e->getMessage(),
            ]);

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
