<?php

namespace App\Domains\Ozon\Api;

use Illuminate\Support\Facades\Log;

/**
 * API для аналитики остатков и оборачиваемости Ozon
 *
 * Эндпоинты:
 * - POST /v1/analytics/stocks — остатки + продажи + оборачиваемость по SKU×кластер
 * - POST /v1/analytics/turnover/stocks — оборачиваемость по SKU (ads за 60д, turnover, idc_grade)
 *
 * @see https://docs.ozon.ru/api/seller
 */
class StockAnalyticsApi
{
    public function __construct(
        private OzonClient $client
    ) {}

    /**
     * Получить аналитику остатков по SKU × склад/кластер
     *
     * POST /v1/analytics/stocks
     *
     * Возвращает по каждому SKU × кластер:
     * - ads — ср. продажи/день за 28д (все кластеры)
     * - ads_cluster — ср. продажи/день за 28д (по кластеру)
     * - idc — дней до OOS (все кластеры)
     * - idc_cluster — дней до OOS (по кластеру)
     * - days_without_sales / days_without_sales_cluster
     * - turnover_grade / turnover_grade_cluster
     * - valid_stock_count, warehouse_name, offer_id, sku
     *
     * API отдаёт постраничный список. Запрошенные SKU фильтруем локально,
     * чтобы не зависеть от спорной поддержки sku-фильтра в этом методе.
     *
     * @param array $skus SKU (Ozon numeric SKU), макс 100
     * @param array $warehouseIds Фильтр по складам (опционально)
     * @param array $clusterIds Фильтр по кластерам (опционально)
     * @return array Массив items с аналитикой
     */
    public function getStockAnalytics(array $skus, array $warehouseIds = [], array $clusterIds = []): array
    {
        if (empty($skus)) {
            return [];
        }

        $requestedSkus = array_fill_keys(array_map('strval', $skus), true);
        $warehouseFilter = array_fill_keys(array_map('strval', $warehouseIds), true);
        $clusterFilter = array_fill_keys(array_map('strval', $clusterIds), true);
        $allItems = [];
        $limit = 1000;
        $offset = 0;

        do {
            $body = [
                'limit' => $limit,
                'offset' => $offset,
                'warehouse_type' => 'ALL',
            ];

            $response = $this->client->post('/v1/analytics/stocks', $body);

            if (!$response || !empty($response['_error'])) {
                Log::warning('Ozon StockAnalytics: ошибка /v1/analytics/stocks', [
                    'skus_count' => count($skus),
                    'offset' => $offset,
                    'error' => $response['message'] ?? $response['error'] ?? 'unknown',
                ]);
                break;
            }

            $items = $response['items'] ?? $response['result']['items'] ?? [];
            foreach ($items as $item) {
                $sku = (string) ($item['sku'] ?? '');
                if ($sku === '' || !isset($requestedSkus[$sku])) {
                    continue;
                }

                if ($warehouseFilter !== []) {
                    $warehouseId = (string) ($item['warehouse_id'] ?? '');
                    if ($warehouseId === '' || !isset($warehouseFilter[$warehouseId])) {
                        continue;
                    }
                }

                if ($clusterFilter !== []) {
                    $clusterId = (string) ($item['cluster_id'] ?? '');
                    if ($clusterId === '' || !isset($clusterFilter[$clusterId])) {
                        continue;
                    }
                }

                $allItems[] = $item;
            }

            $offset += $limit;
        } while (count($items) === $limit);

        Log::info('Ozon StockAnalytics: загружено записей', [
            'requested_skus' => count($skus),
            'received_items' => count($allItems),
        ]);

        return $allItems;
    }

    /**
     * Получить аналитику остатков, индексированную по offer_id × warehouse_name
     *
     * @param array $skus Ozon numeric SKU
     * @return array [offer_id => [warehouse_name => item_data, ...], ...]
     */
    public function getStockAnalyticsByOfferWarehouse(array $skus): array
    {
        $items = $this->getStockAnalytics($skus);
        $result = [];

        foreach ($items as $item) {
            $offerId = $item['offer_id'] ?? null;
            $whName = $item['warehouse_name'] ?? 'unknown';
            if (!$offerId) continue;

            if (!isset($result[$offerId])) {
                $result[$offerId] = [];
            }

            $result[$offerId][$whName] = [
                'sku' => $item['sku'] ?? null,
                'ads' => $item['ads'] ?? 0,
                'ads_cluster' => $item['ads_cluster'] ?? 0,
                'idc' => $item['idc'] ?? 0,
                'idc_cluster' => $item['idc_cluster'] ?? 0,
                'days_without_sales' => $item['days_without_sales'] ?? 0,
                'days_without_sales_cluster' => $item['days_without_sales_cluster'] ?? 0,
                'turnover_grade' => $item['turnover_grade'] ?? null,
                'turnover_grade_cluster' => $item['turnover_grade_cluster'] ?? null,
                'valid_stock_count' => $item['valid_stock_count'] ?? 0,
                'defect_stock_count' => $item['defect_stock_count'] ?? 0,
                'warehouse_name' => $whName,
            ];
        }

        return $result;
    }

    /**
     * Получить аналитику остатков, индексированную по offer_id × cluster_id.
     *
     * Для автопланирования Ozon важен именно кластерный срез: спрос и доступный
     * остаток оцениваются по региону в целом, а не по одному складу внутри него.
     *
     * @param array $skus Ozon numeric SKU
     * @return array [offer_id => [cluster_id => item_data, ...], ...]
     */
    public function getStockAnalyticsByOfferCluster(array $skus): array
    {
        $items = $this->getStockAnalytics($skus);
        $result = [];

        foreach ($items as $item) {
            $offerId = $item['offer_id'] ?? null;
            $clusterId = $item['cluster_id'] ?? null;
            if (!$offerId || $clusterId === null) {
                continue;
            }

            $clusterKey = (string) $clusterId;
            if (!isset($result[$offerId][$clusterKey])) {
                $result[$offerId][$clusterKey] = [
                    'sku' => $item['sku'] ?? null,
                    'cluster_id' => $clusterId,
                    'cluster_name' => $item['cluster_name'] ?? null,
                    'macrolocal_cluster_id' => $item['macrolocal_cluster_id'] ?? null,
                    'ads' => $item['ads'] ?? 0,
                    'ads_cluster' => $item['ads_cluster'] ?? 0,
                    'idc' => $item['idc'] ?? 0,
                    'idc_cluster' => $item['idc_cluster'] ?? 0,
                    'days_without_sales' => $item['days_without_sales'] ?? 0,
                    'days_without_sales_cluster' => $item['days_without_sales_cluster'] ?? 0,
                    'turnover_grade' => $item['turnover_grade'] ?? null,
                    'turnover_grade_cluster' => $item['turnover_grade_cluster'] ?? null,
                    'valid_stock_count' => 0,
                    'available_stock_count' => 0,
                    'requested_stock_count' => 0,
                    'transit_stock_count' => 0,
                    'return_from_customer_stock_count' => 0,
                    'warehouse_names' => [],
                ];
            }

            $clusterData = &$result[$offerId][$clusterKey];
            $clusterData['ads'] = max((float) ($clusterData['ads'] ?? 0), (float) ($item['ads'] ?? 0));
            $clusterData['ads_cluster'] = max((float) ($clusterData['ads_cluster'] ?? 0), (float) ($item['ads_cluster'] ?? 0));
            $clusterData['idc'] = max((float) ($clusterData['idc'] ?? 0), (float) ($item['idc'] ?? 0));
            $clusterData['idc_cluster'] = max((float) ($clusterData['idc_cluster'] ?? 0), (float) ($item['idc_cluster'] ?? 0));
            $clusterData['days_without_sales'] = max((int) ($clusterData['days_without_sales'] ?? 0), (int) ($item['days_without_sales'] ?? 0));
            $clusterData['days_without_sales_cluster'] = max((int) ($clusterData['days_without_sales_cluster'] ?? 0), (int) ($item['days_without_sales_cluster'] ?? 0));
            $clusterData['valid_stock_count'] += (int) ($item['valid_stock_count'] ?? 0);
            $clusterData['available_stock_count'] += (int) ($item['available_stock_count'] ?? 0);
            $clusterData['requested_stock_count'] += (int) ($item['requested_stock_count'] ?? 0);
            $clusterData['transit_stock_count'] += (int) ($item['transit_stock_count'] ?? 0);
            $clusterData['return_from_customer_stock_count'] += (int) ($item['return_from_customer_stock_count'] ?? 0);

            $warehouseName = $item['warehouse_name'] ?? null;
            if ($warehouseName && !in_array($warehouseName, $clusterData['warehouse_names'], true)) {
                $clusterData['warehouse_names'][] = $warehouseName;
            }
            unset($clusterData);
        }

        return $result;
    }

    /**
     * Получить оборачиваемость по SKU
     *
     * POST /v1/analytics/turnover/stocks
     *
     * Возвращает по каждому SKU:
     * - ads — ср. продажи/день за 60д
     * - current_stock — текущий остаток
     * - idc — дней до OOS
     * - idc_grade — уровень остатков (GREEN/YELLOW/RED/CRITICAL)
     * - turnover — фактическая оборачиваемость в днях
     * - turnover_grade — уровень оборачиваемости
     *
     * Лимит: 1 запрос/мин, до 1000 SKU
     *
     * @param array $skus SKU (Ozon numeric SKU), макс 1000
     * @return array Массив items
     */
    public function getTurnoverAnalytics(array $skus = [], int $limit = 1000, int $offset = 0): array
    {
        $body = [
            'limit' => min($limit, 1000),
            'offset' => $offset,
        ];

        if (!empty($skus)) {
            $body['sku'] = array_map('strval', array_slice($skus, 0, 1000));
        }

        $response = $this->client->post('/v1/analytics/turnover/stocks', $body);

        if (!$response || !empty($response['_error'])) {
            Log::warning('Ozon StockAnalytics: ошибка /v1/analytics/turnover/stocks', [
                'skus_count' => count($skus),
                'error' => $response['message'] ?? $response['error'] ?? 'unknown',
            ]);
            return [];
        }

        $items = $response['items'] ?? [];

        Log::info('Ozon StockAnalytics: turnover загружено', [
            'requested_skus' => count($skus),
            'received_items' => count($items),
        ]);

        return $items;
    }

    /**
     * Получить оборачиваемость, индексированную по offer_id
     *
     * @param array $skus Ozon numeric SKU
     * @return array [offer_id => turnover_data, ...]
     */
    public function getTurnoverByOfferId(array $skus = []): array
    {
        $allItems = [];

        // Пагинация: макс 1000 за запрос
        $chunks = !empty($skus) ? array_chunk($skus, 1000) : [[]];

        foreach ($chunks as $chunk) {
            $offset = 0;
            do {
                $items = $this->getTurnoverAnalytics($chunk, 1000, $offset);
                $allItems = array_merge($allItems, $items);
                $offset += 1000;
            } while (count($items) === 1000 && empty($chunk));
            // Если передали конкретные SKU, пагинация не нужна
        }

        $result = [];
        foreach ($allItems as $item) {
            $offerId = $item['offer_id'] ?? null;
            if (!$offerId) continue;

            $result[$offerId] = [
                'sku' => $item['sku'] ?? null,
                'ads' => $item['ads'] ?? 0,
                'current_stock' => $item['current_stock'] ?? 0,
                'idc' => $item['idc'] ?? 0,
                'idc_grade' => $item['idc_grade'] ?? null,
                'turnover' => $item['turnover'] ?? 0,
                'turnover_grade' => $item['turnover_grade'] ?? null,
            ];
        }

        return $result;
    }
}
