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
            $today = now()->format('Y-m-d');

            // stats/orders возвращает реальные данные о заказах/отменах/возвратах по SKU
            $raw30 = $this->fetchOrdersBySku(now()->subDays(30)->format('Y-m-d'), $today);
            $raw14 = $this->fetchOrdersBySku(now()->subDays(14)->format('Y-m-d'), $today);
            $raw7  = $this->fetchOrdersBySku(now()->subDays(7)->format('Y-m-d'), $today);

            if (empty($raw30)) {
                return [];
            }

            $salesData = [];
            foreach ($raw30 as $sku => $data30) {
                $orders30    = $data30['total'];
                $cancelled30 = $data30['cancelled'];
                $returned30  = $data30['returned'];

                $orders14 = $raw14[$sku]['total'] ?? (int) round($orders30 * 14 / 30);
                $orders7  = $raw7[$sku]['total']  ?? (int) round($orders30 * 7  / 30);

                // % выкупа: (заказов - отменено - возвращено) / заказов * 100
                $fulfilled = $orders30 - $cancelled30 - $returned30;
                $redemptionRate = $orders30 > 0
                    ? round(max(0, $fulfilled) / $orders30 * 100, 1)
                    : null;

                $salesData[(string) $sku] = [
                    'sales_30_days'     => $orders30,
                    'sales_14_days'     => $orders14,
                    'sales_7_days'      => $orders7,
                    'avg_daily_sales'   => round($orders30 / 30, 2),
                    'revenue'           => 0,
                    'orders_count'      => $orders30,
                    'cancelled_count'   => $cancelled30,
                    'returned_count'    => $returned30,
                    'redemption_rate'   => $redemptionRate,
                    'redemption_source' => $redemptionRate !== null ? 'api' : 'default',
                ];
            }

            return $salesData;
        } catch (\Exception $e) {
            Log::error('YandexMarket getSalesBySku error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Агрегирует статистику заказов по SKU из /stats/orders.
     * Считаем отменёнными: CANCELLED_IN_PROCESSING, CANCELLED_IN_DELIVERY, CANCELLED.
     * Считаем возвращёнными: RETURNED, PARTIALLY_RETURNED.
     *
     * @return array<string, array{total: int, cancelled: int, returned: int}>
     */
    private function fetchOrdersBySku(string $dateFrom, string $dateTo): array
    {
        try {
            $result = [];
            $page = 0;

            do {
                $response = $this->client->post('/v2/campaigns/{campaignId}/stats/orders', array_filter([
                    'dateFrom'  => $dateFrom,
                    'dateTo'    => $dateTo,
                    'pagerFrom' => $page * 200,
                    'pagerSize' => 200,
                ]));

                $orders = $response['result']['orders'] ?? [];

                foreach ($orders as $order) {
                    $status = strtoupper((string) ($order['status'] ?? ''));
                    $isCancelled = str_contains($status, 'CANCEL');
                    $isReturned  = str_contains($status, 'RETURN');

                    foreach ($order['items'] ?? [] as $item) {
                        $sku = (string) ($item['shopSku'] ?? '');
                        if ($sku === '') {
                            continue;
                        }
                        if (! isset($result[$sku])) {
                            $result[$sku] = ['total' => 0, 'cancelled' => 0, 'returned' => 0];
                        }
                        $result[$sku]['total']++;
                        if ($isCancelled) {
                            $result[$sku]['cancelled']++;
                        }
                        if ($isReturned) {
                            $result[$sku]['returned']++;
                        }
                    }
                }

                $hasMore = count($orders) === 200;
                $page++;
            } while ($hasMore && $page < 20); // max 4000 заказов

            return $result;
        } catch (\Exception $e) {
            Log::warning('YandexMarket fetchOrdersBySku error', [
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
