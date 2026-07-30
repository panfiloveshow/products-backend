<?php

namespace App\Domains\Wildberries\Api;

use App\Jobs\SyncInventoryJob;
use Illuminate\Support\Facades\Log;

/**
 * API для работы с продажами Wildberries
 */
class SalesApi
{
    public function __construct(
        private WildberriesClient $client
    ) {}

    private function extractNmId(array $item): ?string
    {
        $nmId = $item['nmId'] ?? $item['nmID'] ?? $item['nm_id'] ?? null;

        return $nmId !== null && $nmId !== '' ? (string) $nmId : null;
    }

    private function extractQuantity(array $item): int
    {
        return max(1, (int) ($item['quantity'] ?? $item['qty'] ?? 1));
    }

    private function extractSrid(array $item): ?string
    {
        $srid = $item['srid'] ?? null;

        return $srid !== null && $srid !== '' ? (string) $srid : null;
    }

    private function extractLastChangeTimestamp(array $item): int
    {
        $value = $item['lastChangeDate'] ?? $item['date'] ?? null;
        $timestamp = $value ? strtotime((string) $value) : false;

        return $timestamp !== false ? $timestamp : 0;
    }

    private function isReturnSale(array $item): bool
    {
        $saleId = strtoupper((string) ($item['saleID'] ?? ''));

        if ($saleId !== '') {
            if (str_starts_with($saleId, 'R')) {
                return true;
            }

            if (str_starts_with($saleId, 'S')) {
                return false;
            }
        }

        return ((float) ($item['forPay'] ?? 0)) < 0
            || ((float) ($item['priceWithDisc'] ?? 0)) < 0
            || ((float) ($item['totalPrice'] ?? 0)) < 0;
    }

    /**
     * Получить статистику продаж за период
     */
    public function getSalesStats(string $dateFrom, string $dateTo): array
    {
        try {
            $response = $this->client->statistics('/api/v1/supplier/sales', [
                'dateFrom' => $dateFrom,
            ]);

            return $response ?? [];
        } catch (\Exception $e) {
            Log::error('WB getSalesStats error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Один сырой отчёт продаж для всех расчётов юнит-экономики.
     *
     * /api/v1/supplier/sales разрешает только один запрос в минуту на продавца.
     * Поэтому вызывающий код должен получить отчёт один раз и переиспользовать
     * его для продаж, СПП и выкупа.
     */
    public function getSalesReport(int $days = 30): ?array
    {
        $dateFrom = now()->subDays($days)->format('Y-m-d');

        return $this->client->statistics('/api/v1/supplier/sales', [
            'dateFrom' => $dateFrom,
        ]);
    }

    /**
     * Получить продажи по SKU за последние N дней
     *
     * Индексирует по barcode (основной ключ в InventoryApi) и supplierArticle (fallback)
     */
    public function getSalesBySku(int $days = 30): array
    {
        try {
            $response = $this->getSalesReport($days);

            return $response === null ? [] : $this->buildSalesBySku($response, $days);
        } catch (\Exception $e) {
            Log::error('WB getSalesBySku error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Построить продажи по SKU из уже загруженного отчёта.
     */
    public function buildSalesBySku(array $response, int $days = 30): array
    {
        try {

            $salesBySku = [];

            foreach ($response as $sale) {
                // Используем barcode как основной ключ (совпадает с InventoryApi)
                $barcode = $sale['barcode'] ?? null;
                $supplierArticle = $sale['supplierArticle'] ?? null;

                // Пропускаем если нет ни barcode, ни supplierArticle
                if (! $barcode && ! $supplierArticle) {
                    continue;
                }

                $saleDate = isset($sale['date']) ? strtotime($sale['date']) : time();
                $daysAgo = (time() - $saleDate) / 86400;

                $quantity = (int) ($sale['quantity'] ?? 1);
                $price = (float) ($sale['priceWithDisc'] ?? 0);

                // Функция для добавления продаж к ключу
                $addSales = function ($key) use (&$salesBySku, $quantity, $price, $daysAgo) {
                    if (! $key) {
                        return;
                    }

                    if (! isset($salesBySku[$key])) {
                        $salesBySku[$key] = [
                            'sales_30_days' => 0,
                            'sales_14_days' => 0,
                            'sales_7_days' => 0,
                            'revenue' => 0,
                        ];
                    }

                    $salesBySku[$key]['sales_30_days'] += $quantity;
                    $salesBySku[$key]['revenue'] += $price * $quantity;

                    if ($daysAgo <= 14) {
                        $salesBySku[$key]['sales_14_days'] += $quantity;
                    }
                    if ($daysAgo <= 7) {
                        $salesBySku[$key]['sales_7_days'] += $quantity;
                    }
                };

                // Индексируем по barcode (основной ключ)
                $addSales($barcode);

                // Также индексируем по supplierArticle для совместимости
                if ($supplierArticle && $supplierArticle !== $barcode) {
                    $addSales($supplierArticle);
                }
            }

            // Добавляем avg_daily_sales
            foreach ($salesBySku as $sku => &$data) {
                $data['avg_daily_sales'] = round($data['sales_30_days'] / max(1, $days), 2);
            }

            Log::info('WB getSalesBySku: loaded sales data', [
                'count' => count($salesBySku),
                'sample_keys' => array_slice(array_keys($salesBySku), 0, 5),
            ]);

            return $salesBySku;
        } catch (\Exception $e) {
            Log::error('WB buildSalesBySku error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Продажи по SKU и складу отгрузки (warehouseName из /api/v1/supplier/sales).
     * Формат для {@see SyncInventoryJob} / {@see \App\Jobs\SyncSalesJob}: метрики
     * пишутся в строку КОНКРЕТНОГО склада (матчинг по имени), не суммой по SKU.
     *
     * @return array<string, array<string, array{sales_7_days:int,sales_14_days:int,sales_30_days:int,avg_daily_sales:float}>>
     */
    public function getSalesByWarehouse(int $days = 30): array
    {
        try {
            $days = max(1, $days);
            $dateFrom = now()->subDays($days)->format('Y-m-d');

            $response = $this->client->statistics('/api/v1/supplier/sales', [
                'dateFrom' => $dateFrom,
            ]);

            /** @var array<string, array<string, array{sales_30_days:int,sales_14_days:int,sales_7_days:int}>> $byKeyWarehouse */
            $byKeyWarehouse = [];

            foreach ($response ?? [] as $sale) {
                if ($this->isReturnSale($sale)) {
                    continue;
                }

                $barcode = $sale['barcode'] ?? null;
                $supplierArticle = $sale['supplierArticle'] ?? null;
                if (! $barcode && ! $supplierArticle) {
                    continue;
                }

                $warehouseName = (string) ($sale['warehouseName'] ?? $sale['warehouse_name'] ?? 'WB');
                $quantity = $this->extractQuantity($sale);
                $saleDate = isset($sale['date']) ? strtotime((string) $sale['date']) : time();
                $daysAgo = $saleDate ? (time() - $saleDate) / 86400 : 0;

                $accumulate = function (?string $key) use (&$byKeyWarehouse, $warehouseName, $quantity, $daysAgo): void {
                    if ($key === null || $key === '') {
                        return;
                    }
                    if (! isset($byKeyWarehouse[$key][$warehouseName])) {
                        $byKeyWarehouse[$key][$warehouseName] = [
                            'sales_30_days' => 0,
                            'sales_14_days' => 0,
                            'sales_7_days' => 0,
                        ];
                    }
                    $ref = &$byKeyWarehouse[$key][$warehouseName];
                    $ref['sales_30_days'] += $quantity;
                    if ($daysAgo <= 14) {
                        $ref['sales_14_days'] += $quantity;
                    }
                    if ($daysAgo <= 7) {
                        $ref['sales_7_days'] += $quantity;
                    }
                };

                if ($barcode !== null && $barcode !== '') {
                    $accumulate((string) $barcode);
                }
                if ($supplierArticle && (string) $supplierArticle !== (string) $barcode) {
                    $accumulate((string) $supplierArticle);
                }
            }

            $result = [];
            foreach ($byKeyWarehouse as $sku => $warehouses) {
                $result[$sku] = [];
                foreach ($warehouses as $whName => $data) {
                    $s30 = (int) $data['sales_30_days'];
                    $result[$sku][$whName] = [
                        'sales_30_days' => $s30,
                        'sales_14_days' => (int) $data['sales_14_days'],
                        'sales_7_days' => (int) $data['sales_7_days'],
                        'avg_daily_sales' => round($s30 / $days, 4),
                    ];
                }
            }

            Log::info('WB getSalesByWarehouse: loaded', [
                'sku_keys' => count($result),
                'window_days' => $days,
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('WB getSalesByWarehouse error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function getSalesCountByNmId(int $days = 30): array
    {
        try {
            $response = $this->getSalesReport($days);

            return $response === null ? [] : $this->buildSalesCountByNmId($response);
        } catch (\Exception $e) {
            Log::error('WB getSalesCountByNmId error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function buildSalesCountByNmId(array $response): array
    {
        try {

            $salesByNmId = [];
            $salesStateByNmId = [];

            foreach ($response as $sale) {
                $nmId = $this->extractNmId($sale);
                if (! $nmId) {
                    continue;
                }

                $srid = $this->extractSrid($sale);
                $timestamp = $this->extractLastChangeTimestamp($sale);
                $stateKey = $srid ?: md5(json_encode([
                    $nmId,
                    $sale['saleID'] ?? null,
                    $sale['date'] ?? null,
                    $sale['barcode'] ?? null,
                ]));

                if (! isset($salesStateByNmId[$nmId][$stateKey]) || $timestamp >= $salesStateByNmId[$nmId][$stateKey]['timestamp']) {
                    $salesStateByNmId[$nmId][$stateKey] = [
                        'timestamp' => $timestamp,
                        'is_return' => $this->isReturnSale($sale),
                    ];
                }
            }

            foreach ($salesStateByNmId as $nmId => $states) {
                $salesByNmId[$nmId] = collect($states)
                    ->filter(fn (array $state) => ! $state['is_return'])
                    ->count();
            }

            return $salesByNmId;
        } catch (\Exception $e) {
            Log::error('WB buildSalesCountByNmId error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Один сырой отчёт заказов. Используется как резервный источник СПП, когда
     * WB отдельно блокирует /supplier/sales глобальным лимитером.
     */
    public function getOrdersReport(int $days = 30): ?array
    {
        $dateFrom = now()->subDays($days)->format('Y-m-d');

        return $this->client->statistics('/api/v1/supplier/orders', [
            'dateFrom' => $dateFrom,
        ]);
    }

    public function getOrdersByNmId(int $days = 30, ?array $ordersReport = null): array
    {
        try {
            $response = $ordersReport ?? $this->getOrdersReport($days);

            return $response === null ? [] : $this->buildOrdersByNmId($response);
        } catch (\Exception $e) {
            Log::error('WB getOrdersByNmId error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function buildOrdersByNmId(array $response): array
    {
        try {

            $ordersByNmId = [];
            $orderStateByNmId = [];

            foreach ($response as $order) {
                $nmId = $this->extractNmId($order);
                if (! $nmId) {
                    continue;
                }

                $srid = $this->extractSrid($order);
                $timestamp = $this->extractLastChangeTimestamp($order);
                $stateKey = $srid ?: md5(json_encode([
                    $nmId,
                    $order['date'] ?? null,
                    $order['barcode'] ?? null,
                    $order['gNumber'] ?? null,
                ]));

                if (! isset($orderStateByNmId[$nmId][$stateKey]) || $timestamp >= $orderStateByNmId[$nmId][$stateKey]['timestamp']) {
                    $orderStateByNmId[$nmId][$stateKey] = [
                        'timestamp' => $timestamp,
                        'is_cancel' => (bool) ($order['isCancel'] ?? false),
                    ];
                }
            }

            foreach ($orderStateByNmId as $nmId => $states) {
                $ordersByNmId[$nmId] = collect($states)
                    ->filter(fn (array $state) => ! $state['is_cancel'])
                    ->count();
            }

            return $ordersByNmId;
        } catch (\Exception $e) {
            Log::error('WB buildOrdersByNmId error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function getRedemptionStatsByNmId(int $days = 30, ?array $salesReport = null): array
    {
        try {
            $ordersByNmId = $this->getOrdersByNmId($days);
            $salesByNmId = $salesReport === null
                ? $this->getSalesCountByNmId($days)
                : $this->buildSalesCountByNmId($salesReport);

            $keys = array_unique(array_merge(array_keys($ordersByNmId), array_keys($salesByNmId)));
            $result = [];

            foreach ($keys as $key) {
                $ordersCount = (int) ($ordersByNmId[$key] ?? 0);
                $salesCount = (int) ($salesByNmId[$key] ?? 0);

                if ($ordersCount <= 0) {
                    continue;
                }

                $returnsCount = max(0, $ordersCount - $salesCount);
                $redemptionRate = round(min(100, ($salesCount / $ordersCount) * 100), 2);

                $result[$key] = [
                    'redemption_rate' => $redemptionRate,
                    'orders_count' => $ordersCount,
                    'returns_count' => $returnsCount,
                    'sales_count' => $salesCount,
                    'source' => 'api',
                ];
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('WB getRedemptionStatsByNmId error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Заказ по модели «Маркетплейс» (FBS) — отгрузка со склада продавца.
     *
     * WB помечает такие продажи полем warehouseType = «Склад продавца»
     * (FBW/FBO приходит как «Склад WB»). По методике WB FBS-заказы — исключения
     * при расчёте индекса локализации (для них КТР = 1.00).
     *
     * Если поле отсутствует (старые данные/выгрузка без warehouseType), считаем
     * заказ FBW, чтобы не исключать лишнего и сохранить прежнее поведение.
     */
    private function isFbsSale(array $sale): bool
    {
        $warehouseType = trim((string) ($sale['warehouseType'] ?? $sale['warehouse_type'] ?? ''));

        if ($warehouseType === '') {
            return false;
        }

        return mb_stripos($warehouseType, 'продав') !== false
            || mb_stripos($warehouseType, 'seller') !== false;
    }

    /**
     * Получить продажи по регионам (федеральным округам) для расчёта индекса локализации
     *
     * Использует API /api/v1/supplier/sales который содержит:
     * - warehouseName — склад отгрузки
     * - oblastOkrugName — федеральный округ доставки
     * - warehouseType — тип склада («Склад WB» = FBW/FBO, «Склад продавца» = FBS)
     *
     * Это позволяет точно определить локальные заказы (склад и доставка в одном ФО)
     * и отделить FBS-заказы (исключения по методике WB).
     *
     * FBS-заказы попадают в total и в excluded_fbs, но НЕ участвуют в подсчёте
     * локальных заказов (доля локализации считается только по FBW/FBO).
     *
     * @param  int  $days  Количество дней
     * @return array [nmId => ['total' => int, 'local' => int, 'excluded_fbs' => int, 'by_warehouse' => array, 'by_delivery_fo' => array]]
     */
    public function getSalesByRegion(int $days = 31): array
    {
        try {
            $dateFrom = now()->subDays($days)->format('Y-m-d');

            $response = $this->client->statistics('/api/v1/supplier/sales', [
                'dateFrom' => $dateFrom,
            ]);

            if (empty($response)) {
                return [];
            }

            // Маппинг складов WB на федеральные округа
            $warehouseToFo = $this->getWarehouseToFoMapping();

            // Кластеры ФО (объединённые округа)
            $foClusters = [
                'Северо-Кавказский федеральный округ' => 'south',
                'Южный федеральный округ' => 'south',
                'Сибирский федеральный округ' => 'siberia',
                'Дальневосточный федеральный округ' => 'siberia',
                'Центральный федеральный округ' => 'central',
                'Северо-Западный федеральный округ' => 'northwest',
                'Приволжский федеральный округ' => 'volga',
                'Уральский федеральный округ' => 'ural',
            ];

            $salesByNmId = [];

            foreach ($response as $sale) {
                $nmId = $this->extractNmId($sale);
                $warehouseName = $sale['warehouseName'] ?? $sale['warehouse_name'] ?? '';
                $deliveryFo = $sale['oblastOkrugName'] ?? $sale['oblast_okrug_name'] ?? '';
                $qty = $this->extractQuantity($sale);

                if (! $nmId) {
                    continue;
                }

                $isFbs = $this->isFbsSale($sale);

                if (! isset($salesByNmId[$nmId])) {
                    $salesByNmId[$nmId] = [
                        'total' => 0,
                        'local' => 0,
                        'excluded_fbs' => 0,
                        'by_warehouse' => [],
                        'by_delivery_fo' => [],
                    ];
                }

                $salesByNmId[$nmId]['total'] += $qty;

                if ($isFbs) {
                    // FBS-заказ — исключение по методике WB: в подсчёте локальности
                    // не участвует, но учитывается в total и excluded_fbs.
                    $salesByNmId[$nmId]['excluded_fbs'] += $qty;
                } else {
                    // Локальность определяем только для FBW/FBO заказов.
                    $warehouseFo = $warehouseToFo[$warehouseName] ?? $this->guessWarehouseFo($warehouseName);
                    $warehouseCluster = $foClusters[$warehouseFo] ?? $warehouseFo;
                    $deliveryCluster = $foClusters[$deliveryFo] ?? $deliveryFo;

                    // Заказ локальный, если склад и доставка в одном кластере
                    $isLocal = ($warehouseCluster === $deliveryCluster) && ! empty($warehouseCluster) && ! empty($deliveryCluster);

                    if ($isLocal) {
                        $salesByNmId[$nmId]['local'] += $qty;
                    }
                }

                // Статистика по складам и ФО доставки (по всем заказам, для отладки)
                $salesByNmId[$nmId]['by_warehouse'][$warehouseName] =
                    ($salesByNmId[$nmId]['by_warehouse'][$warehouseName] ?? 0) + $qty;
                $salesByNmId[$nmId]['by_delivery_fo'][$deliveryFo] =
                    ($salesByNmId[$nmId]['by_delivery_fo'][$deliveryFo] ?? 0) + $qty;
            }

            Log::info('WB getSalesByRegion: loaded sales with warehouse data', [
                'count' => count($salesByNmId),
                'total_sales' => array_sum(array_column($salesByNmId, 'total')),
                'date_from' => $dateFrom,
            ]);

            return $salesByNmId;
        } catch (\Exception $e) {
            Log::error('WB getSalesByRegion error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Маппинг складов WB на федеральные округа
     */
    private function getWarehouseToFoMapping(): array
    {
        return [
            // Центральный ФО
            'Коледино' => 'Центральный федеральный округ',
            'Подольск' => 'Центральный федеральный округ',
            'Подольск 3' => 'Центральный федеральный округ',
            'Подольск 4' => 'Центральный федеральный округ',
            'Электросталь' => 'Центральный федеральный округ',
            'Тула' => 'Центральный федеральный округ',
            'Белые Столбы' => 'Центральный федеральный округ',
            'Белые Столбы 2' => 'Центральный федеральный округ',
            'Чехов' => 'Центральный федеральный округ',
            'Чехов 2' => 'Центральный федеральный округ',
            'Домодедово' => 'Центральный федеральный округ',
            'Внуково' => 'Центральный федеральный округ',
            'Пушкино' => 'Центральный федеральный округ',
            'Пушкино 2' => 'Центральный федеральный округ',
            'Рязань' => 'Центральный федеральный округ',
            'Рязань (Тюшевское)' => 'Центральный федеральный округ',
            'Брянск' => 'Центральный федеральный округ',
            'Котовск' => 'Центральный федеральный округ',
            'Вёшки' => 'Центральный федеральный округ',
            // Северо-Западный ФО
            'Санкт-Петербург' => 'Северо-Западный федеральный округ',
            'СПб Шушары' => 'Северо-Западный федеральный округ',
            'СПб Уткина Заводь' => 'Северо-Западный федеральный округ',
            // Южный ФО
            'Краснодар' => 'Южный федеральный округ',
            'Ростов-на-Дону' => 'Южный федеральный округ',
            // Северо-Кавказский ФО
            'Невинномысск' => 'Северо-Кавказский федеральный округ',
            // Приволжский ФО
            'Казань' => 'Приволжский федеральный округ',
            'Нижний Новгород' => 'Приволжский федеральный округ',
            'Самара' => 'Приволжский федеральный округ',
            'Самара (Новосемейкино)' => 'Приволжский федеральный округ',
            // Уральский ФО
            'Екатеринбург' => 'Уральский федеральный округ',
            'Екатеринбург 2' => 'Уральский федеральный округ',
            // Сибирский ФО
            'Новосибирск' => 'Сибирский федеральный округ',
            'Красноярск' => 'Сибирский федеральный округ',
            // Дальневосточный ФО
            'Хабаровск' => 'Дальневосточный федеральный округ',
        ];
    }

    /**
     * Попытка определить ФО склада по названию
     */
    private function guessWarehouseFo(string $warehouseName): string
    {
        $mapping = $this->getWarehouseToFoMapping();

        // Точное совпадение
        if (isset($mapping[$warehouseName])) {
            return $mapping[$warehouseName];
        }

        // Частичное совпадение
        foreach ($mapping as $warehouse => $fo) {
            if (stripos($warehouseName, $warehouse) !== false || stripos($warehouse, $warehouseName) !== false) {
                return $fo;
            }
        }

        // По умолчанию — ЦФО (большинство складов там)
        return 'Центральный федеральный округ';
    }

    /**
     * Получить SPP (процент скидки продавца) из продаж
     */
    public function getSppFromSales(int $days = 30): array
    {
        try {
            $response = $this->getSalesReport($days);

            return $response === null ? [] : $this->buildSppFromSales($response);
        } catch (\Exception $e) {
            Log::error('WB getSppFromSales error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Построить последний известный СПП по nmId из уже загруженного отчёта.
     */
    public function buildSppFromSales(array $response): array
    {
        try {
            return $this->buildSppMapsFromReport($response)['by_nm_id'];
        } catch (\Exception $e) {
            Log::error('WB buildSppFromSales error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Barcode is the exact product-size key used by products.sku. nmId is only
     * a card-level fallback and can combine sizes with different prices.
     * Missing/non-numeric spp is ignored; an explicit numeric zero is valid.
     * The newest order/sale observation wins; averaging historical discounts
     * would produce a value that never existed for a real buyer.
     *
     * @return array{by_sku:array<string,float>,by_nm_id:array<string,float>}
     */
    public function buildSppMapsFromReport(array $response): array
    {
        $bySku = [];
        $byNmId = [];

        foreach ($response as $sequence => $row) {
            if (! array_key_exists('spp', $row) || ! is_numeric($row['spp'])) {
                continue;
            }

            $spp = (float) $row['spp'];
            if (! is_finite($spp)) {
                continue;
            }

            $observation = [
                'value' => $spp,
                'rank' => $this->sppObservationRank($row, (int) $sequence),
            ];
            $nmId = $this->extractNmId($row);
            if ($nmId !== null && $this->isNewerSppObservation($observation, $byNmId[$nmId] ?? null)) {
                $byNmId[$nmId] = $observation;
            }

            foreach (['barcode', 'supplierArticle'] as $key) {
                $sku = $row[$key] ?? null;
                if ($sku !== null && $sku !== '') {
                    $sku = (string) $sku;
                    if ($this->isNewerSppObservation($observation, $bySku[$sku] ?? null)) {
                        $bySku[$sku] = $observation;
                    }
                }
            }
        }

        $values = static fn (array $observations): array => array_map(
            static fn (array $observation): float => round($observation['value'], 2),
            $observations,
        );

        return [
            'by_sku' => $values($bySku),
            'by_nm_id' => $values($byNmId),
        ];
    }

    /**
     * `date` is the moment the buyer placed the order. `lastChangeDate` can be
     * updated later (for example, after cancellation), so it is only a fallback.
     */
    private function sppObservationRank(array $row, int $sequence): array
    {
        $parse = static function (mixed $value): ?int {
            if (! is_string($value) || trim($value) === '') {
                return null;
            }

            $timestamp = strtotime($value);

            return $timestamp === false ? null : $timestamp;
        };

        $dateTimestamp = $parse($row['date'] ?? null);
        $changeTimestamp = $parse($row['lastChangeDate'] ?? null);

        return [
            $dateTimestamp ?? $changeTimestamp ?? PHP_INT_MIN,
            $changeTimestamp ?? PHP_INT_MIN,
            $sequence,
        ];
    }

    /**
     * @param  array{value:float,rank:array{int,int,int}}  $candidate
     * @param  array{value:float,rank:array{int,int,int}}|null  $current
     */
    private function isNewerSppObservation(array $candidate, ?array $current): bool
    {
        if ($current === null) {
            return true;
        }

        foreach ([0, 1, 2] as $index) {
            if ($candidate['rank'][$index] !== $current['rank'][$index]) {
                return $candidate['rank'][$index] > $current['rank'][$index];
            }
        }

        return false;
    }
}
