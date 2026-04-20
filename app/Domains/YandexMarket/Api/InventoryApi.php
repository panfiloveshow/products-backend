<?php

namespace App\Domains\YandexMarket\Api;

use App\Domains\Marketplace\Contracts\InventoryApiInterface;
use App\Models\Integration;

/**
 * API для работы с остатками Yandex Market
 *
 * Актуальные Endpoints (обновлено 2024-12):
 *
 * Остатки:
 * - GET /api/v2/stocks/warehouse - остатки по складам с пагинацией
 * - GET /campaigns/{campaignId}/offers/stocks - остатки по кампании
 *
 * Склады:
 * - POST /v2/businesses/{businessId}/warehouses - список складов бизнеса
 * - GET /campaigns/{campaignId}/warehouses - склады кампании
 *
 * Отчёты:
 * - POST /reports/generateStocksOnWarehousesReport - генерация отчёта
 *
 * Типы остатков (WarehouseStockType):
 * - FIT: доступен для продажи или зарезервирован
 * - FREEZE: зарезервирован для заказов
 * - AVAILABLE: доступен для продажи
 * - QUARANTINE: временно недоступен
 * - UTILIZATION: на утилизацию
 * - DEFECT: брак
 * - EXPIRED: просрочен
 *
 * @see https://yandex.ru/dev/market/partner-api/doc/en/reference/stocks
 */
class InventoryApi implements InventoryApiInterface
{
    public function __construct(
        private YandexMarketClient $client
    ) {}

    /**
     * Получить остатки по всем складам
     *
     * GET /api/v2/stocks/warehouse - новый эндпоинт с пагинацией
     *
     * Типы остатков:
     * - FIT: доступен для продажи или зарезервирован
     * - FREEZE: зарезервирован для заказов
     * - AVAILABLE: доступен для продажи
     * - QUARANTINE: временно недоступен
     *
     * @see https://yandex.ru/dev/market/partner-api/doc/en/reference/stocks
     */
    /**
     * @param  string  $scheme  FBY|FBS|DBS|EXPRESS — схема исполнения для этой кампании
     */
    public function getStocks(?Integration $integration = null, array $skus = [], string $scheme = 'FBY'): array
    {
        // allStocks[sku][warehouseId] => агрегированные данные склада
        $allStocks = [];
        $pageToken = null;

        do {
            $query = array_filter([
                'limit' => 200,
                'page_token' => $pageToken,
            ]);

            $response = $this->client->post(
                '/v2/campaigns/{campaignId}/offers/stocks',
                [],
                $query
            );

            if (! $response) {
                break;
            }

            $warehouses = $response['result']['warehouses'] ?? [];
            $pageToken = $response['result']['paging']['nextPageToken'] ?? null;

            foreach ($warehouses as $warehouse) {
                $warehouseId = (string) ($warehouse['warehouseId'] ?? 'unknown');
                $warehouseName = $warehouse['name']
                    ?? $warehouse['warehouseName']
                    ?? $warehouse['title']
                    ?? null;
                $warehouseName = $warehouseName !== null && $warehouseName !== '' ? (string) $warehouseName : null;

                foreach ($warehouse['offers'] ?? [] as $offer) {
                    $sku = $offer['offerId'] ?? $offer['shopSku'] ?? null;
                    if (! $sku) {
                        continue;
                    }

                    if ($skus !== [] && ! in_array($sku, $skus, true)) {
                        continue;
                    }

                    if (! isset($allStocks[$sku])) {
                        $allStocks[$sku] = [
                            'sku' => $sku,
                            'warehouses' => [],
                            'total' => 0,
                            'updated_at' => $offer['updatedAt'] ?? null,
                        ];
                    }

                    // Агрегируем все типы остатков в одну запись на склад
                    // (BUG FIX: раньше каждый тип создавал отдельную строку, которую следующий перезаписывал)
                    if (! isset($allStocks[$sku]['warehouses'][$warehouseId])) {
                        $whRow = [
                            'warehouse_id' => $warehouseId,
                            'quantity' => 0,
                            'fulfillment_type' => $scheme,
                        ];
                        if ($warehouseName !== null) {
                            $whRow['warehouse_name'] = $warehouseName;
                        }
                        $allStocks[$sku]['warehouses'][$warehouseId] = $whRow;
                    }

                    foreach ($offer['stocks'] ?? [] as $stock) {
                        $quantity = (int) ($stock['count'] ?? 0);
                        $type = $stock['type'] ?? 'FIT';

                        // Суммируем только доступные к продаже остатки (FIT + AVAILABLE)
                        if (in_array($type, ['FIT', 'AVAILABLE'], true)) {
                            $allStocks[$sku]['warehouses'][$warehouseId]['quantity'] += $quantity;
                            $allStocks[$sku]['total'] += $quantity;
                        }
                    }
                }
            }
        } while ($pageToken);

        // Преобразуем ассоциативный массив складов в индексированный
        foreach ($allStocks as &$item) {
            $item['warehouses'] = array_values($item['warehouses']);
        }
        unset($item);

        return array_values($allStocks);
    }

    /**
     * Получить список складов
     *
     * Пробуем два эндпоинта:
     * 1. GET /campaigns/{campaignId}/warehouses — для кампаний с собственными складами
     * 2. POST /businesses/{businessId}/warehouses — fallback через businessId (FBS/FBY)
     */
    public function getWarehouses(?Integration $integration = null): array
    {
        $cid = $this->client->getCampaignId();
        if ($cid === '') {
            return [];
        }

        // 1. Пробуем campaign-level эндпоинт
        try {
            $response = $this->client->get('/campaigns/{campaignId}/warehouses');
            $warehouses = $response['warehouses'] ?? $response['result']['warehouses'] ?? [];
            if (! empty($warehouses)) {
                return $warehouses;
            }
        } catch (\Exception $e) {
            // 404/400 — нормально для FBY/FBS, пробуем бизнес-эндпоинт
        }

        // 2. Fallback: бизнес-склады через resolveBusinessId
        try {
            $businessId = $this->client->resolveBusinessId();
            $response = $this->client->post("/businesses/{$businessId}/warehouses", []);
            $warehouses = $response['result']['warehouses'] ?? $response['warehouses'] ?? [];

            return $warehouses;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Получить склады бизнеса с группировкой
     *
     * POST /v2/businesses/{businessId}/warehouses
     *
     * Возвращает информацию о группировке складов для переноса остатков.
     * Склады в одной группе (groupInfo.groupId) можно обновлять вместе.
     *
     * @param  string  $businessId  ID бизнеса
     * @param  array  $campaignIds  Список ID кампаний
     *
     * @see https://yandex.ru/dev/market/partner-api/doc/en/step-by-step/warehouses
     */
    public function getBusinessWarehouses(string $businessId, array $campaignIds): array
    {
        $response = $this->client->post("/v2/businesses/{$businessId}/warehouses", [
            'campaignIds' => $campaignIds,
        ]);

        return $response['warehouses'] ?? [];
    }

    /**
     * Получить остатки по конкретному складу
     */
    public function getStocksByWarehouse(string $warehouseId, ?Integration $integration = null): array
    {
        $allStocks = $this->getStocks($integration);

        return array_filter($allStocks, function ($stock) use ($warehouseId) {
            foreach ($stock['warehouses'] as $warehouse) {
                if ((string) $warehouse['warehouse_id'] === $warehouseId) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * Обновить остатки
     *
     * PUT /campaigns/{campaignId}/offers/stocks
     */
    public function updateStocks(int $warehouseId, array $stocks): bool
    {
        $response = $this->client->put('/campaigns/{campaignId}/offers/stocks', [
            'skus' => array_map(function ($stock) use ($warehouseId) {
                return [
                    'sku' => $stock['sku'],
                    'warehouseId' => $warehouseId,
                    'items' => [
                        [
                            'type' => 'FIT',
                            'count' => $stock['quantity'],
                        ],
                    ],
                ];
            }, $stocks),
        ]);

        return $response !== null;
    }

    /**
     * Генерация отчёта по остаткам на складах
     *
     * POST /reports/generateStocksOnWarehousesReport
     *
     * @param  array  $params  [campaignId, businessId, warehouseIds, reportDate, categoryIds, hasStocks]
     *
     * @see https://yandex.ru/dev/market/partner-api/doc/en/reference/reports
     */
    public function generateStocksReport(array $params): ?string
    {
        $response = $this->client->post('/reports/generateStocksOnWarehousesReport', $params);

        return $response['report_id'] ?? null;
    }
}
