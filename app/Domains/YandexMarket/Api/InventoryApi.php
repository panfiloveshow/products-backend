<?php

namespace App\Domains\YandexMarket\Api;

use App\Domains\Marketplace\Contracts\InventoryApiInterface;
use App\Models\Integration;

/**
 * API для работы с остатками Yandex Market
 *
 * Endpoints:
 *
 * Остатки:
 * - POST /v2/campaigns/{campaignId}/offers/stocks - остатки по кампании (limit/page_token в query)
 *
 * Склады:
 * - POST /v2/businesses/{businessId}/warehouses - кабинеты с группами складов
 * - POST /v3/businesses/{businessId}/warehouses - кабинеты без групп складов
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
                'limit' => 100, // YM с 04.08.2026: max limit = 100 (был 200)
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

                    $stockCounts = [];
                    foreach ($offer['stocks'] ?? [] as $stock) {
                        $type = strtoupper((string) ($stock['type'] ?? 'FIT'));
                        $stockCounts[$type] = ($stockCounts[$type] ?? 0) + (int) ($stock['count'] ?? 0);
                    }

                    // AVAILABLE и FIT могут быть альтернативными представлениями продаваемого остатка.
                    // Если есть AVAILABLE, берём его; иначе берём FIT. Так не удваиваем один склад.
                    $quantity = $stockCounts['AVAILABLE'] ?? $stockCounts['FIT'] ?? 0;
                    $allStocks[$sku]['warehouses'][$warehouseId]['quantity'] += $quantity;
                    $allStocks[$sku]['total'] += $quantity;
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
     * Получить список складов кабинета ({id, name, ...})
     *
     * POST /v2/businesses/{businessId}/warehouses — для кабинетов с группами складов,
     * POST /v3/businesses/{businessId}/warehouses — для кабинетов без групп.
     * Модель кабинета заранее не знаем: берём v2, пусто или ошибка — v3.
     * GET /campaigns/{campaignId}/warehouses в API нет (404) — не зовём.
     */
    public function getWarehouses(?Integration $integration = null): array
    {
        try {
            $businessId = $this->client->resolveBusinessId();
        } catch (\Exception $e) {
            return [];
        }

        foreach (['v2', 'v3'] as $version) {
            try {
                $warehouses = $this->fetchWarehousePages("/{$version}/businesses/{$businessId}/warehouses");
            } catch (\Exception $e) {
                $warehouses = [];
            }
            if ($warehouses !== []) {
                return $warehouses;
            }
        }

        return [];
    }

    /**
     * Все страницы списка складов: limit (≤ 30) и page_token — в query.
     */
    private function fetchWarehousePages(string $endpoint): array
    {
        $warehouses = [];
        $pageToken = null;
        $pages = 0;

        do {
            $response = $this->client->post($endpoint, [], array_filter([
                'limit' => 30,
                'page_token' => $pageToken,
            ]));
            array_push($warehouses, ...($response['result']['warehouses'] ?? []));
            $pageToken = $response['result']['paging']['nextPageToken'] ?? null;
        } while ($pageToken && ++$pages < 50);

        return $warehouses;
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

}
