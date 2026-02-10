<?php

namespace App\Domains\Ozon\Api;

use Illuminate\Support\Facades\Log;

/**
 * API для аналитики остатков и оборачиваемости Ozon
 *
 * Эндпоинты:
 * - GET /v1/analytics/stocks — остатки + продажи + оборачиваемость по SKU×кластер (обновлён 17.06.2025)
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
     * GET /v1/analytics/stocks
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
     * Лимит: до 100 SKU за запрос
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

        $allItems = [];

        // API принимает макс 100 SKU за запрос
        foreach (array_chunk($skus, 100) as $chunk) {
            $params = [
                'skus' => array_map('strval', $chunk),
            ];

            if (!empty($warehouseIds)) {
                $params['warehouse_ids'] = array_map('strval', $warehouseIds);
            }

            if (!empty($clusterIds)) {
                $params['cluster_ids'] = array_map('strval', $clusterIds);
            }

            $response = $this->client->get('/v1/analytics/stocks', $params);

            if (!$response || !empty($response['_error'])) {
                Log::warning('Ozon StockAnalytics: ошибка /v1/analytics/stocks', [
                    'skus_count' => count($chunk),
                    'error' => $response['message'] ?? $response['error'] ?? 'unknown',
                ]);
                continue;
            }

            $items = $response['items'] ?? [];
            $allItems = array_merge($allItems, $items);
        }

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
