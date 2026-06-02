<?php

namespace App\Domains\Ozon\Api;

use Carbon\Carbon;
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
     * Получить продажи по SKU и складу через /v3/posting/fbo/list за последние N дней.
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

            $now = now();

            // rawUnits[offer_id][warehouse_id] = demand facts by real posting dates.
            $rawUnits = [];

            do {
                $response = $this->client->post('/v3/posting/fbo/list', [
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

                $postings = $response['result']['postings'] ?? $response['result'] ?? [];

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
                        if (! isset($rawUnits[$offerId][$warehouseId])) {
                            $rawUnits[$offerId][$warehouseId] = [
                                'units' => 0,
                                'warehouse_name' => $whName,
                                'sales_7_days' => 0,
                                'sales_14_days' => 0,
                                'sales_30_days' => 0,
                                'daily_units' => [],
                            ];
                        }

                        $rawUnits[$offerId][$warehouseId]['units'] += $qty;

                        $postingDate = $this->resolvePostingDate($posting);
                        if ($postingDate !== null) {
                            $dayKey = $postingDate->toDateString();
                            $rawUnits[$offerId][$warehouseId]['daily_units'][$dayKey] =
                                ($rawUnits[$offerId][$warehouseId]['daily_units'][$dayKey] ?? 0) + $qty;

                            if ($postingDate->gte($now->copy()->subDays(7)->startOfDay())) {
                                $rawUnits[$offerId][$warehouseId]['sales_7_days'] += $qty;
                            }
                            if ($postingDate->gte($now->copy()->subDays(14)->startOfDay())) {
                                $rawUnits[$offerId][$warehouseId]['sales_14_days'] += $qty;
                            }
                            if ($postingDate->gte($now->copy()->subDays(30)->startOfDay())) {
                                $rawUnits[$offerId][$warehouseId]['sales_30_days'] += $qty;
                            }
                        }
                    }
                }

                $offset += $limit;
            } while (count($postings) === $limit);

            // Преобразуем в финальный формат с продажами за периоды
            $result = [];
            foreach ($rawUnits as $offerId => $warehouses) {
                foreach ($warehouses as $warehouseId => $data) {
                    $units = $data['units'];
                    $avgDaily = $days > 0 ? round($units / $days, 2) : 0;
                    $shapeStats = $this->calculateDailyShapeStats($data['daily_units'] ?? [], $units, $days);

                    $sales7 = (int) ($data['sales_7_days'] ?? 0);
                    $sales14 = (int) ($data['sales_14_days'] ?? 0);
                    $sales30 = (int) ($data['sales_30_days'] ?? 0);

                    // If Ozon stops returning posting dates, keep the old proportional
                    // fallback but mark it as low-quality through empty active_days.
                    if ($sales7 === 0 && $sales14 === 0 && $sales30 === 0 && $units > 0 && empty($data['daily_units'])) {
                        $sales7 = (int) round($units * 7 / $days);
                        $sales14 = (int) round($units * 14 / $days);
                        $sales30 = (int) round($units * 30 / $days);
                    }

                    $result[$offerId][$warehouseId] = [
                        'warehouse_name'      => $data['warehouse_name'],
                        'sales_7_days'        => $sales7,
                        'sales_14_days'       => $sales14,
                        'sales_30_days'       => $sales30,
                        'avg_daily_sales'     => $avgDaily,
                        'ordered_units_total' => $units,
                        'active_days' => $shapeStats['active_days'],
                        'peak_day_units' => $shapeStats['peak_day_units'],
                        'peak_share' => $shapeStats['peak_share'],
                        'median_nonzero_daily_units' => $shapeStats['median_nonzero_daily_units'],
                        'winsorized_units_total' => $shapeStats['winsorized_units_total'],
                        'winsorized_avg_daily_sales' => $shapeStats['winsorized_avg_daily_sales'],
                    ];
                }
            }

            Log::info('Ozon getSalesBySkuAndWarehouse (FBO postings v3) loaded', [
                'days'       => $days,
                'skus_count' => count($result),
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('Ozon getSalesBySkuAndWarehouse error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function resolvePostingDate(array $posting): ?Carbon
    {
        foreach (['delivered_at', 'delivery_date', 'shipment_date', 'in_process_at', 'created_at'] as $field) {
            if (empty($posting[$field])) {
                continue;
            }

            try {
                return Carbon::parse($posting[$field])->startOfDay();
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * @return array{active_days:int, peak_day_units:int, peak_share:float, median_nonzero_daily_units:float, winsorized_units_total:float, winsorized_avg_daily_sales:float}
     */
    private function calculateDailyShapeStats(array $dailyUnits, int $totalUnits, int $days): array
    {
        $activeDays = count(array_filter($dailyUnits, fn ($units) => (int) $units > 0));
        $peakDayUnits = empty($dailyUnits) ? 0 : (int) max($dailyUnits);
        $nonZero = array_values(array_filter(array_map('intval', $dailyUnits), fn ($units) => $units > 0));
        sort($nonZero);

        $median = 0.0;
        $count = count($nonZero);
        if ($count > 0) {
            $middle = intdiv($count, 2);
            $median = $count % 2 === 1
                ? (float) $nonZero[$middle]
                : (($nonZero[$middle - 1] + $nonZero[$middle]) / 2);
        }

        $periodAvg = $days > 0 ? $totalUnits / $days : 0.0;
        $cap = max(3.0, $median * 3.0, $periodAvg * 1.5);
        $winsorizedTotal = 0.0;
        foreach ($dailyUnits as $units) {
            $winsorizedTotal += min((int) $units, $cap);
        }

        if (empty($dailyUnits) && $totalUnits > 0) {
            $winsorizedTotal = $totalUnits;
        }

        return [
            'active_days' => $activeDays,
            'peak_day_units' => $peakDayUnits,
            'peak_share' => $totalUnits > 0 ? round($peakDayUnits / $totalUnits, 4) : 0.0,
            'median_nonzero_daily_units' => round($median, 4),
            'winsorized_units_total' => round($winsorizedTotal, 4),
            'winsorized_avg_daily_sales' => $days > 0 ? round($winsorizedTotal / $days, 4) : 0.0,
        ];
    }

    /**
     * Получить продажи FBS по SKU и складу через /v3/posting/fbs/list за последние N дней.
     *
     * @return array [offer_id => [warehouse_id => ['warehouse_name', 'sales_7_days', 'sales_14_days', 'sales_30_days', 'avg_daily_sales', 'ordered_units_total', 'fulfillment_type']]]
     */
    public function getSalesBySkuAndWarehouseFbs(int $days = 28): array
    {
        try {
            $since = now()->subDays($days)->setTime(0, 0, 0)->toIso8601String();
            $to = now()->toIso8601String();
            $offset = 0;
            $limit = 1000;
            $rawUnits = [];

            do {
                $response = $this->client->post('/v3/posting/fbs/list', [
                    'dir' => 'ASC',
                    'filter' => [
                        'since' => $since,
                        'to' => $to,
                        'status' => 'delivered',
                    ],
                    'limit' => $limit,
                    'offset' => $offset,
                    'with' => [
                        'analytics_data' => true,
                        'financial_data' => false,
                    ],
                ]);

                $postings = $response['result']['postings'] ?? [];

                foreach ($postings as $posting) {
                    $analytics = $posting['analytics_data'] ?? [];
                    $warehouseName = (string) ($analytics['warehouse_name'] ?? '');
                    $warehouseIdRaw = (string) ($analytics['warehouse_id'] ?? '');

                    if ($warehouseName === '' && $warehouseIdRaw === '') {
                        continue;
                    }

                    $warehouseId = $warehouseIdRaw !== ''
                        ? 'ozonfbs_' . $warehouseIdRaw
                        : 'ozonfbs_' . substr(md5($warehouseName), 0, 12);

                    foreach ($posting['products'] ?? [] as $product) {
                        $offerId = (string) ($product['offer_id'] ?? '');
                        if ($offerId === '') {
                            continue;
                        }

                        $qty = (int) ($product['quantity'] ?? 0);
                        if (! isset($rawUnits[$offerId][$warehouseId])) {
                            $rawUnits[$offerId][$warehouseId] = [
                                'units' => 0,
                                'warehouse_name' => $warehouseName !== '' ? $warehouseName : $warehouseIdRaw,
                            ];
                        }

                        $rawUnits[$offerId][$warehouseId]['units'] += $qty;
                    }
                }

                $offset += $limit;
            } while (count($postings) === $limit);

            $result = [];
            foreach ($rawUnits as $offerId => $warehouses) {
                foreach ($warehouses as $warehouseId => $data) {
                    $units = $data['units'];
                    $avgDaily = $days > 0 ? round($units / $days, 2) : 0;

                    $result[$offerId][$warehouseId] = [
                        'warehouse_name' => $data['warehouse_name'],
                        'sales_7_days' => (int) round($units * 7 / $days),
                        'sales_14_days' => (int) round($units * 14 / $days),
                        'sales_30_days' => (int) round($units * 30 / $days),
                        'avg_daily_sales' => $avgDaily,
                        'ordered_units_total' => $units,
                        'fulfillment_type' => 'FBS',
                    ];
                }
            }

            Log::info('Ozon getSalesBySkuAndWarehouseFbs loaded', [
                'days' => $days,
                'skus_count' => count($result),
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('Ozon getSalesBySkuAndWarehouseFbs error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Получить статистику заказов по SKU
     */
    public function getOrdersStatsBySku(string $dateFrom, string $dateTo): array
    {
        return $this->fetchMetricBySku('ordered_units', $dateFrom, $dateTo);
    }

    /**
     * Получить статистику возвратов по SKU
     */
    public function getReturnsStatsBySku(string $dateFrom, string $dateTo): array
    {
        return $this->fetchMetricBySku('returns', $dateFrom, $dateTo);
    }

    /**
     * Получить статистику отмен по SKU (доступно не всегда — только Premium).
     * Возвращает [] при отсутствии доступа, чтобы вызывающий мог безопасно деградировать.
     */
    public function getCancellationsStatsBySku(string $dateFrom, string $dateTo): array
    {
        return $this->fetchMetricBySku('cancellations', $dateFrom, $dateTo);
    }

    private function fetchMetricBySku(string $metric, string $dateFrom, string $dateTo): array
    {
        $result = [];
        $pageSize = 1000;
        $offset = 0;
        $maxPages = 50;
        $page = 0;

        try {
            while ($page < $maxPages) {
                $response = $this->client->post('/v1/analytics/data', [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'metrics' => [$metric],
                    'dimension' => ['sku'],
                    'filters' => [],
                    'sort' => [['key' => $metric, 'order' => 'DESC']],
                    'limit' => $pageSize,
                    'offset' => $offset,
                ]);

                $rows = $response['result']['data'] ?? [];
                foreach ($rows as $row) {
                    $sku = $row['dimensions'][0]['id'] ?? null;
                    if ($sku) {
                        $result[$sku] = (int) ($row['metrics'][0] ?? 0);
                    }
                }

                if (count($rows) < $pageSize) {
                    break;
                }

                $offset += $pageSize;
                $page++;
            }

            return $result;
        } catch (\Exception $e) {
            Log::error("Ozon fetchMetricBySku({$metric}) error", [
                'error' => $e->getMessage(),
                'offset' => $offset,
                'partial_count' => count($result),
            ]);
            return $result;
        }
    }
}
