<?php

namespace App\Domains\Ozon\Api;

use App\Domains\Marketplace\Contracts\SuppliesApiInterface;

/**
 * API для работы с поставками Ozon (FBO Supplies)
 * 
 * Endpoints (Legacy - до 16.02.2026):
 * - POST /v1/supply/order/list — список заказов на поставку
 * - POST /v1/supply/order/get — детали заказа на поставку
 * - POST /v1/supply/draft/create — создать черновик поставки
 * - POST /v1/supply/timeslot/list — доступные слоты
 * - POST /v1/supply/timeslot/set — забронировать слот
 * - POST /v1/supply/cargo/create — создать грузоместа
 * - POST /v1/supply/driver/set — назначить водителя
 * - POST /v1/warehouse/list — список складов
 * 
 * Endpoints (New - с 16.02.2026):
 * - POST /v1/cluster/list — список макролокальных кластеров
 * - POST /v1/draft/direct/create — черновик прямой поставки
 * - POST /v1/draft/crossdock/create — черновик кросс-док поставки
 * - POST /v1/draft/multi-cluster/create — черновик мультикластерной поставки
 * - POST /v2/draft/create/info — статус и расчёты черновика
 * - POST /v2/draft/timeslot/info — таймслоты для черновика
 * - POST /v2/draft/supply/create/status — статус создания поставки
 * - POST /v1/cargoes/get — грузоместа в поставках FBO (бета)
 * - POST /v1/warehouse/fbo/list — список складов FBO
 * - POST /v1/warehouse/fbo/seller/list — список складов продавца
 * 
 * @see https://docs.ozon.ru/api/seller
 * @see https://dev.ozon.ru/news/647-Izmeneniia-v-metodakh-Seller-API-pri-rabote-s-postavkami-FBO/
 */
class SuppliesApi implements SuppliesApiInterface
{
    /**
     * Статусы поставок Ozon
     */
    private const SUPPLY_STATUSES = [
        'DRAFT' => 'draft',
        'AWAITING_CONFIRMATION' => 'awaiting_confirmation',
        'CONFIRMED' => 'confirmed',
        'IN_TRANSIT' => 'in_transit',
        'AT_WAREHOUSE' => 'at_warehouse',
        'ACCEPTING' => 'accepting',
        'ACCEPTED' => 'accepted',
        'PARTIALLY_ACCEPTED' => 'partially_accepted',
        'CANCELLED' => 'cancelled',
    ];

    public function __construct(
        private OzonClient $client
    ) {}

    /**
     * Получить список заказов на поставку
     * 
     * POST /v1/supply/order/list
     * 
     * @param array $filters [
     *   'limit' => int,
     *   'offset' => int,
     *   'statuses' => string[],
     *   'date_from' => string (Y-m-d),
     *   'date_to' => string (Y-m-d),
     * ]
     */
    public function getSupplies(array $filters = []): array
    {
        $body = [
            'limit' => $filters['limit'] ?? 100,
            'offset' => $filters['offset'] ?? 0,
        ];

        if (!empty($filters['statuses'])) {
            $body['filter']['status'] = $filters['statuses'];
        }

        if (!empty($filters['date_from'])) {
            $body['filter']['created_at']['from'] = $filters['date_from'] . 'T00:00:00Z';
        }

        if (!empty($filters['date_to'])) {
            $body['filter']['created_at']['to'] = $filters['date_to'] . 'T23:59:59Z';
        }

        $response = $this->client->post('/v1/supply/order/list', $body);

        if (!$response) {
            return [];
        }

        $orders = $response['result']['orders'] ?? $response['orders'] ?? [];
        
        return array_map(fn($order) => $this->mapSupply($order), $orders);
    }

    /**
     * Получить детали заказа на поставку
     * 
     * POST /v1/supply/order/get
     */
    public function getSupplyDetails(string $supplyId): ?array
    {
        $response = $this->client->post('/v1/supply/order/get', [
            'supply_order_id' => (int) $supplyId,
        ]);

        if (!$response || empty($response['result'])) {
            return null;
        }

        return $this->mapSupply($response['result']);
    }

    /**
     * Получить товары в поставке
     * 
     * POST /v1/supply/order/items
     */
    public function getSupplyProducts(string $supplyId): array
    {
        $response = $this->client->post('/v1/supply/order/items', [
            'supply_order_id' => (int) $supplyId,
        ]);

        if (!$response) {
            return [];
        }

        $items = $response['result']['items'] ?? $response['items'] ?? [];
        
        return array_map(fn($item) => [
            'sku' => $item['offer_id'] ?? $item['sku'] ?? null,
            'product_id' => $item['product_id'] ?? null,
            'name' => $item['name'] ?? null,
            'quantity' => $item['quantity'] ?? 0,
            'quantity_accepted' => $item['quantity_accepted'] ?? 0,
            'quantity_rejected' => $item['quantity_rejected'] ?? 0,
            'barcode' => $item['barcode'] ?? null,
        ], $items);
    }

    /**
     * Получить список доступных складов
     * 
     * POST /v1/warehouse/list
     */
    public function getAvailableWarehouses(): array
    {
        $response = $this->client->post('/v1/warehouse/list', []);

        if (!$response) {
            return [];
        }

        $warehouses = $response['result'] ?? [];
        
        return array_map(fn($wh) => [
            'id' => (string) ($wh['warehouse_id'] ?? null),
            'name' => $wh['name'] ?? null,
            'address' => $wh['address'] ?? null,
            'city' => $wh['city'] ?? null,
            'region' => $wh['region'] ?? null,
            'is_rfbs' => $wh['is_rfbs'] ?? false,
            'is_able_to_set_price' => $wh['is_able_to_set_price'] ?? false,
            'has_entrusted_acceptance' => $wh['has_entrusted_acceptance'] ?? false,
            'first_mile_type' => $wh['first_mile_type'] ?? null,
            'status' => $wh['status'] ?? null,
        ], $warehouses);
    }

    /**
     * Получить доступные слоты для приёмки
     * 
     * POST /v1/supply/timeslot/list
     */
    public function getAcceptanceSlots(string $warehouseId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $body = [
            'warehouse_id' => (int) $warehouseId,
        ];

        if ($dateFrom) {
            $body['date_from'] = $dateFrom . 'T00:00:00Z';
        }

        if ($dateTo) {
            $body['date_to'] = $dateTo . 'T23:59:59Z';
        }

        $response = $this->client->post('/v1/supply/timeslot/list', $body);

        if (!$response) {
            return [];
        }

        $slots = $response['result']['timeslots'] ?? $response['timeslots'] ?? [];
        
        return array_map(fn($slot) => [
            'id' => $slot['timeslot_id'] ?? null,
            'warehouse_id' => $warehouseId,
            'date' => substr($slot['from'] ?? '', 0, 10),
            'time_from' => substr($slot['from'] ?? '', 11, 5),
            'time_to' => substr($slot['to'] ?? '', 11, 5),
            'from_datetime' => $slot['from'] ?? null,
            'to_datetime' => $slot['to'] ?? null,
            'is_available' => $slot['is_available'] ?? true,
            'capacity' => $slot['capacity'] ?? null,
            'capacity_used' => $slot['capacity_used'] ?? 0,
        ], $slots);
    }

    /**
     * Получить коэффициенты приёмки
     * 
     * Ozon не использует коэффициенты как WB.
     * Возвращаем пустой массив для совместимости.
     */
    public function getAcceptanceCoefficients(): array
    {
        return [];
    }

    /**
     * Создать черновик поставки
     * 
     * POST /v1/supply/draft/create
     * 
     * @param array $data [
     *   'warehouse_id' => int,
     *   'items' => [['offer_id' => string, 'quantity' => int], ...],
     * ]
     */
    public function createSupplyDraft(array $data): array
    {
        $body = [
            'warehouse_id' => (int) ($data['warehouse_id'] ?? 0),
        ];

        if (!empty($data['items'])) {
            $body['items'] = array_map(fn($item) => [
                'offer_id' => $item['sku'] ?? $item['offer_id'] ?? '',
                'quantity' => $item['quantity'] ?? 0,
            ], $data['items']);
        }

        $response = $this->client->post('/v1/supply/draft/create', $body);

        if (!$response || empty($response['result'])) {
            throw new \RuntimeException(
                'Не удалось создать черновик поставки: ' . 
                ($response['error']['message'] ?? 'Unknown error')
            );
        }

        return [
            'id' => (string) ($response['result']['supply_order_id'] ?? null),
            'status' => 'draft',
            'warehouse_id' => (string) ($data['warehouse_id'] ?? null),
            'created_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Добавить товары в поставку
     * 
     * POST /v1/supply/order/items/add
     */
    public function addItemsToSupply(string $supplyId, array $items): bool
    {
        $body = [
            'supply_order_id' => (int) $supplyId,
            'items' => array_map(fn($item) => [
                'offer_id' => $item['sku'] ?? $item['offer_id'] ?? '',
                'quantity' => $item['quantity'] ?? 0,
            ], $items),
        ];

        $response = $this->client->post('/v1/supply/order/items/add', $body);

        return $response !== null && empty($response['error']);
    }

    /**
     * Забронировать слот приёмки
     * 
     * POST /v1/supply/timeslot/set
     */
    public function bookAcceptanceSlot(string $supplyId, string $slotId): array
    {
        $response = $this->client->post('/v1/supply/timeslot/set', [
            'supply_order_id' => (int) $supplyId,
            'timeslot_id' => (int) $slotId,
        ]);

        if (!$response || !empty($response['error'])) {
            throw new \RuntimeException(
                'Не удалось забронировать слот: ' . 
                ($response['error']['message'] ?? 'Unknown error')
            );
        }

        return [
            'success' => true,
            'supply_id' => $supplyId,
            'slot_id' => $slotId,
            'booked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Создать грузоместа
     * 
     * POST /v1/supply/cargo/create
     */
    public function createCargo(string $supplyId, array $cargoData): array
    {
        $body = [
            'supply_order_id' => (int) $supplyId,
            'containers' => array_map(fn($container) => [
                'container_type' => $container['type'] ?? 'box',
                'weight' => $container['weight'] ?? 0,
                'length' => $container['length'] ?? 0,
                'width' => $container['width'] ?? 0,
                'height' => $container['height'] ?? 0,
                'items' => $container['items'] ?? [],
            ], $cargoData['containers'] ?? []),
        ];

        $response = $this->client->post('/v1/supply/cargo/create', $body);

        if (!$response || !empty($response['error'])) {
            throw new \RuntimeException(
                'Не удалось создать грузоместа: ' . 
                ($response['error']['message'] ?? 'Unknown error')
            );
        }

        return [
            'success' => true,
            'supply_id' => $supplyId,
            'containers_count' => count($cargoData['containers'] ?? []),
        ];
    }

    /**
     * Назначить водителя
     * 
     * POST /v1/supply/driver/set
     */
    public function setDriver(string $supplyId, array $driverData): array
    {
        $body = [
            'supply_order_id' => (int) $supplyId,
            'driver' => [
                'name' => $driverData['name'] ?? '',
                'phone' => $driverData['phone'] ?? '',
                'car_number' => $driverData['car_number'] ?? '',
                'car_model' => $driverData['car_model'] ?? '',
            ],
        ];

        $response = $this->client->post('/v1/supply/driver/set', $body);

        if (!$response || !empty($response['error'])) {
            throw new \RuntimeException(
                'Не удалось назначить водителя: ' . 
                ($response['error']['message'] ?? 'Unknown error')
            );
        }

        return [
            'success' => true,
            'supply_id' => $supplyId,
        ];
    }

    /**
     * Получить статусы поставок
     */
    public function getSupplyStatuses(): array
    {
        return self::SUPPLY_STATUSES;
    }

    /**
     * Проверить поддержку функционала
     */
    public function supportsFeature(string $feature): bool
    {
        $supported = [
            'get_supplies' => true,
            'get_supply_details' => true,
            'get_supply_products' => true,
            'get_warehouses' => true,
            'get_acceptance_slots' => true,
            'get_acceptance_coefficients' => false, // Ozon не использует КС
            'get_transit_tariffs' => false,
            'create_supply' => true,                // Поддерживается!
            'add_items' => true,                    // Поддерживается!
            'book_slot' => true,                    // Поддерживается!
            'create_cargo' => true,
            'set_driver' => true,
        ];

        return $supported[$feature] ?? false;
    }

    /**
     * Маппинг поставки Ozon к унифицированному формату
     */
    private function mapSupply(array $order): array
    {
        $status = $order['status'] ?? 'DRAFT';
        
        return [
            'id' => (string) ($order['supply_order_id'] ?? $order['id'] ?? null),
            'external_id' => $order['supply_order_id'] ?? null,
            'name' => $order['name'] ?? "Поставка #{$order['supply_order_id']}",
            'status' => self::SUPPLY_STATUSES[$status] ?? strtolower($status),
            'status_code' => $status,
            'marketplace' => 'ozon',
            'warehouse_id' => (string) ($order['warehouse_id'] ?? null),
            'warehouse_name' => $order['warehouse_name'] ?? null,
            'macrolocal_cluster_id' => $order['macrolocal_cluster_id'] ?? null,
            'cluster_name' => $order['cluster_name'] ?? null,
            'created_at' => $order['created_at'] ?? null,
            'timeslot_from' => $order['timeslot']['from'] ?? null,
            'timeslot_to' => $order['timeslot']['to'] ?? null,
            'items_count' => $order['items_count'] ?? 0,
            'total_quantity' => $order['total_quantity'] ?? 0,
            'supply_type' => $order['supply_type'] ?? 'FBO',
            'supply_method' => $order['supply_method'] ?? null,
            'delivery_scheme' => $order['delivery_scheme'] ?? null,
            'raw_data' => $order,
        ];
    }

    // ========================================================================
    // НОВЫЕ МЕТОДЫ ДЛЯ КЛАСТЕРНОЙ МОДЕЛИ (с 16.02.2026)
    // ========================================================================

    /**
     * Получить список макролокальных кластеров
     * 
     * POST /v1/cluster/list
     * 
     * Request: { "cluster_type": "CLUSTER_TYPE_OZON" }
     * Response: { "clusters": [{ "id": int, "name": string, "logistic_clusters": [{ "warehouses": [...] }] }] }
     * 
     * @return array Список кластеров с id, названием и количеством складов
     */
    public function getClusters(): array
    {
        $response = $this->client->post('/v1/cluster/list', [
            'cluster_type' => 'CLUSTER_TYPE_OZON',
        ]);

        // Логируем ответ для отладки
        \Illuminate\Support\Facades\Log::info('Ozon getClusters response', [
            'response' => $response,
        ]);

        if (!$response) {
            return [];
        }

        $clusters = $response['result']['clusters'] ?? $response['clusters'] ?? [];
        
        return array_map(function ($cluster) {
            // Считаем только склады приёмки (FULL_FILLMENT) — как на Ozon
            // Типы: FULL_FILLMENT (РФЦ), CROSS_DOCK (кроссдокинг), SORTING_CENTER (сортировка), ORDERS_RECEIVING_POINT (ПВЗ)
            $acceptingWarehousesCount = 0;
            $allWarehouseIds = [];
            $acceptingWarehouseIds = [];
            
            // Специализированные склады, которые не показываются для обычных товаров
            $specializedKeywords = ['ВЕТАПТЕКА', 'ЮВЕЛИРН', 'НЕГАБАРИТ', 'ПАЛЛЕТН', 'ШИНЫ', 'КГТ'];
            
            $logisticClusters = $cluster['logistic_clusters'] ?? [];
            foreach ($logisticClusters as $lc) {
                $warehouses = $lc['warehouses'] ?? [];
                foreach ($warehouses as $wh) {
                    $whId = (string) ($wh['warehouse_id'] ?? $wh['id'] ?? null);
                    $whType = $wh['type'] ?? '';
                    $whName = $wh['name'] ?? '';
                    
                    $allWarehouseIds[] = $whId;
                    
                    // Считаем только склады фулфилмента (FULL_FILLMENT)
                    if ($whType === 'FULL_FILLMENT') {
                        // Исключаем специализированные склады (ветаптека, ювелирный, негабарит, паллетный, шины, КГТ)
                        $isSpecialized = false;
                        foreach ($specializedKeywords as $keyword) {
                            if (stripos($whName, $keyword) !== false) {
                                $isSpecialized = true;
                                break;
                            }
                        }
                        
                        if (!$isSpecialized) {
                            $acceptingWarehousesCount++;
                            $acceptingWarehouseIds[] = $whId;
                        }
                    }
                }
            }
            
            return [
                'id' => (string) ($cluster['id'] ?? null),
                'name' => $cluster['name'] ?? null,
                'type' => $cluster['type'] ?? 'CLUSTER_TYPE_OZON',
                'warehouses_count' => $acceptingWarehousesCount,
                'warehouse_ids' => $acceptingWarehouseIds,
                'all_warehouse_ids' => $allWarehouseIds,
                'is_active' => true,
            ];
        }, $clusters);
    }

    /**
     * Создать черновик прямой поставки (на конкретный склад)
     * 
     * POST /v1/draft/direct/create
     * 
     * @param array $data [
     *   'macrolocal_cluster_id' => string,
     *   'items' => [['sku' => string, 'quantity' => int], ...],
     * ]
     */
    public function createDirectDraft(array $data): array
    {
        $body = [
            'macrolocal_cluster_id' => $data['macrolocal_cluster_id'] ?? '',
        ];

        if (!empty($data['items'])) {
            $body['items'] = array_map(fn($item) => [
                'sku' => $item['sku'] ?? $item['offer_id'] ?? '',
                'quantity' => $item['quantity'] ?? 0,
            ], $data['items']);
        }

        $response = $this->client->post('/v1/draft/direct/create', $body);

        if (!$response || empty($response['result'])) {
            throw new \RuntimeException(
                'Не удалось создать черновик прямой поставки: ' . 
                ($response['error']['message'] ?? 'Unknown error')
            );
        }

        return [
            'draft_id' => (string) ($response['result']['draft_id'] ?? null),
            'status' => 'draft',
            'supply_method' => 'direct',
            'macrolocal_cluster_id' => $data['macrolocal_cluster_id'] ?? null,
            'created_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Создать черновик кросс-док поставки (Drop Off или Pick Up)
     * 
     * POST /v1/draft/crossdock/create
     * 
     * @param array $data [
     *   'macrolocal_cluster_id' => string,
     *   'delivery_scheme' => 'drop_off' | 'pick_up',
     *   'point_id' => string (для drop_off — ID точки из /v1/warehouse/fbo/list),
     *   'point_type' => string (для drop_off — тип точки),
     *   'seller_warehouse_id' => string (для pick_up — ID склада продавца),
     *   'items' => [['sku' => string, 'quantity' => int], ...],
     * ]
     */
    public function createCrossdockDraft(array $data): array
    {
        $body = [
            'macrolocal_cluster_id' => $data['macrolocal_cluster_id'] ?? '',
            'delivery_scheme' => strtoupper($data['delivery_scheme'] ?? 'DROP_OFF'),
        ];

        if (($data['delivery_scheme'] ?? '') === 'drop_off') {
            $body['drop_off_point'] = [
                'id' => $data['point_id'] ?? '',
                'type' => $data['point_type'] ?? '',
            ];
        } else {
            $body['seller_warehouse_id'] = $data['seller_warehouse_id'] ?? '';
        }

        if (!empty($data['items'])) {
            $body['items'] = array_map(fn($item) => [
                'sku' => $item['sku'] ?? $item['offer_id'] ?? '',
                'quantity' => $item['quantity'] ?? 0,
            ], $data['items']);
        }

        $response = $this->client->post('/v1/draft/crossdock/create', $body);

        if (!$response || empty($response['result'])) {
            throw new \RuntimeException(
                'Не удалось создать черновик кросс-док поставки: ' . 
                ($response['error']['message'] ?? 'Unknown error')
            );
        }

        return [
            'draft_id' => (string) ($response['result']['draft_id'] ?? null),
            'status' => 'draft',
            'supply_method' => 'crossdock',
            'delivery_scheme' => $data['delivery_scheme'] ?? 'drop_off',
            'macrolocal_cluster_id' => $data['macrolocal_cluster_id'] ?? null,
            'created_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Создать черновик мультикластерной поставки
     * 
     * POST /v1/draft/multi-cluster/create
     * 
     * @param array $data [
     *   'cluster_ids' => string[] (массив ID кластеров),
     *   'delivery_scheme' => 'drop_off' | 'pick_up',
     *   'point_id' => string (для drop_off),
     *   'point_type' => string (для drop_off),
     *   'seller_warehouse_id' => string (для pick_up),
     *   'items' => [['sku' => string, 'quantity' => int], ...],
     * ]
     */
    public function createMultiClusterDraft(array $data): array
    {
        $body = [
            'macrolocal_cluster_ids' => $data['cluster_ids'] ?? [],
            'delivery_scheme' => strtoupper($data['delivery_scheme'] ?? 'DROP_OFF'),
        ];

        if (($data['delivery_scheme'] ?? '') === 'drop_off') {
            $body['drop_off_point'] = [
                'id' => $data['point_id'] ?? '',
                'type' => $data['point_type'] ?? '',
            ];
        } else {
            $body['seller_warehouse_id'] = $data['seller_warehouse_id'] ?? '';
        }

        if (!empty($data['items'])) {
            $body['items'] = array_map(fn($item) => [
                'sku' => $item['sku'] ?? $item['offer_id'] ?? '',
                'quantity' => $item['quantity'] ?? 0,
            ], $data['items']);
        }

        $response = $this->client->post('/v1/draft/multi-cluster/create', $body);

        if (!$response || empty($response['result'])) {
            throw new \RuntimeException(
                'Не удалось создать черновик мультикластерной поставки: ' . 
                ($response['error']['message'] ?? 'Unknown error')
            );
        }

        return [
            'draft_id' => (string) ($response['result']['draft_id'] ?? null),
            'status' => 'draft',
            'supply_method' => 'multi_cluster',
            'delivery_scheme' => $data['delivery_scheme'] ?? 'drop_off',
            'cluster_ids' => $data['cluster_ids'] ?? [],
            'created_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Получить статус и расчёты черновика
     * 
     * POST /v2/draft/create/info
     * 
     * @param string $draftId ID черновика
     * @return array Статус, ошибки и расчёты по складам
     */
    public function getDraftInfo(string $draftId): array
    {
        $response = $this->client->post('/v2/draft/create/info', [
            'draft_id' => $draftId,
        ]);

        if (!$response) {
            return [];
        }

        $result = $response['result'] ?? [];
        
        return [
            'draft_id' => $draftId,
            'status' => $result['status'] ?? 'unknown',
            'errors' => $result['errors'] ?? [],
            'warehouses' => array_map(fn($wh) => [
                'warehouse_id' => (string) ($wh['warehouse_id'] ?? null),
                'warehouse_name' => $wh['warehouse_name'] ?? null,
                'cluster_id' => $wh['macrolocal_cluster_id'] ?? null,
                'items_count' => $wh['items_count'] ?? 0,
                'total_quantity' => $wh['total_quantity'] ?? 0,
                'estimated_cost' => $wh['estimated_cost'] ?? null,
                'is_available' => $wh['is_available'] ?? true,
            ], $result['warehouses'] ?? []),
        ];
    }

    /**
     * Получить таймслоты для черновика (новый API)
     * 
     * POST /v2/draft/timeslot/info
     * 
     * @param string $draftId ID черновика
     * @param string $warehouseId ID склада в кластере
     */
    public function getDraftTimeslots(string $draftId, string $warehouseId): array
    {
        $response = $this->client->post('/v2/draft/timeslot/info', [
            'draft_id' => $draftId,
            'warehouse_id' => (int) $warehouseId,
        ]);

        if (!$response) {
            return [];
        }

        $slots = $response['result']['timeslots'] ?? $response['timeslots'] ?? [];
        
        return array_map(fn($slot) => [
            'id' => $slot['timeslot_id'] ?? null,
            'warehouse_id' => $warehouseId,
            'date' => substr($slot['from'] ?? '', 0, 10),
            'time_from' => substr($slot['from'] ?? '', 11, 5),
            'time_to' => substr($slot['to'] ?? '', 11, 5),
            'from_datetime' => $slot['from'] ?? null,
            'to_datetime' => $slot['to'] ?? null,
            'is_available' => $slot['is_available'] ?? true,
            'capacity' => $slot['capacity'] ?? null,
        ], $slots);
    }

    /**
     * Создать поставку из черновика (новый API)
     * 
     * POST /v2/draft/timeslot/info (с параметрами для создания)
     * 
     * @param string $draftId ID черновика
     * @param string $warehouseId ID склада
     * @param string $timeslotId ID таймслота
     */
    public function createSupplyFromDraft(string $draftId, string $warehouseId, string $timeslotId): array
    {
        $response = $this->client->post('/v2/draft/timeslot/info', [
            'draft_id' => $draftId,
            'warehouse_id' => (int) $warehouseId,
            'timeslot_id' => (int) $timeslotId,
            'create_supply' => true,
        ]);

        if (!$response || !empty($response['error'])) {
            throw new \RuntimeException(
                'Не удалось создать поставку из черновика: ' . 
                ($response['error']['message'] ?? 'Unknown error')
            );
        }

        return [
            'success' => true,
            'draft_id' => $draftId,
            'warehouse_id' => $warehouseId,
            'timeslot_id' => $timeslotId,
            'supply_order_id' => $response['result']['supply_order_id'] ?? null,
            'created_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Получить статус создания поставки
     * 
     * POST /v2/draft/supply/create/status
     */
    public function getSupplyCreateStatus(string $draftId): array
    {
        $response = $this->client->post('/v2/draft/supply/create/status', [
            'draft_id' => $draftId,
        ]);

        if (!$response) {
            return [];
        }

        $result = $response['result'] ?? [];
        
        return [
            'draft_id' => $draftId,
            'status' => $result['status'] ?? 'unknown',
            'supply_order_id' => $result['supply_order_id'] ?? null,
            'errors' => $result['errors'] ?? [],
            'created_at' => $result['created_at'] ?? null,
        ];
    }

    /**
     * Получить список складов FBO
     * 
     * POST /v1/warehouse/fbo/list
     */
    public function getFboWarehouses(): array
    {
        $response = $this->client->post('/v1/warehouse/fbo/list', []);

        if (!$response) {
            return [];
        }

        $warehouses = $response['result']['warehouses'] ?? $response['result'] ?? [];
        
        return array_map(fn($wh) => [
            'id' => (string) ($wh['warehouse_id'] ?? $wh['id'] ?? null),
            'name' => $wh['name'] ?? null,
            'type' => $wh['type'] ?? null,
            'address' => $wh['address'] ?? null,
            'city' => $wh['city'] ?? null,
            'region' => $wh['region'] ?? null,
            'cluster_id' => $wh['macrolocal_cluster_id'] ?? null,
            'cluster_name' => $wh['cluster_name'] ?? null,
            'is_active' => $wh['is_active'] ?? true,
        ], $warehouses);
    }

    /**
     * Получить список складов продавца (для Pick Up)
     * 
     * POST /v1/warehouse/fbo/seller/list
     */
    public function getSellerWarehouses(): array
    {
        $response = $this->client->post('/v1/warehouse/fbo/seller/list', []);

        if (!$response) {
            return [];
        }

        $warehouses = $response['result']['warehouses'] ?? $response['result'] ?? [];
        
        return array_map(fn($wh) => [
            'id' => (string) ($wh['warehouse_id'] ?? $wh['id'] ?? null),
            'name' => $wh['name'] ?? null,
            'address' => $wh['address'] ?? null,
            'city' => $wh['city'] ?? null,
            'is_active' => $wh['is_active'] ?? true,
        ], $warehouses);
    }

    /**
     * Получить грузоместа в поставках FBO (бета)
     * 
     * POST /v1/cargoes/get
     */
    public function getCargoes(string $supplyOrderId): array
    {
        $response = $this->client->post('/v1/cargoes/get', [
            'supply_order_id' => (int) $supplyOrderId,
        ]);

        if (!$response) {
            return [];
        }

        $cargoes = $response['result']['cargoes'] ?? $response['cargoes'] ?? [];
        
        return array_map(fn($cargo) => [
            'id' => $cargo['cargo_id'] ?? null,
            'barcode' => $cargo['barcode'] ?? null,
            'type' => $cargo['container_type'] ?? null,
            'weight' => $cargo['weight'] ?? 0,
            'dimensions' => [
                'length' => $cargo['length'] ?? 0,
                'width' => $cargo['width'] ?? 0,
                'height' => $cargo['height'] ?? 0,
            ],
            'items_count' => $cargo['items_count'] ?? 0,
        ], $cargoes);
    }

    /**
     * Получить рекомендации товаров для поставки
     * 
     * Использует /v1/analytics/turnover/stocks для получения товаров с низким запасом
     * Сортирует по уровню запаса (критичные первыми)
     * 
     * @param int $limit Количество товаров (по умолчанию 100)
     * @param int $offset Смещение для пагинации
     * @return array Список товаров с рекомендациями к поставке
     */
    public function getSupplyRecommendations(int $limit = 100, int $offset = 0): array
    {
        $response = $this->client->post('/v1/analytics/turnover/stocks', [
            'limit' => min($limit, 1000),
            'offset' => $offset,
        ]);

        if (!$response) {
            return [];
        }

        $items = $response['items'] ?? [];
        
        // Приоритет по уровню запаса
        $gradePriority = [
            'GRADES_CRITICAL' => 1,
            'GRADES_RED' => 2,
            'GRADES_YELLOW' => 3,
            'GRADES_GREEN' => 4,
            'GRADES_NOSALES' => 5,
            'GRADES_NONE' => 6,
        ];
        
        // Сортируем по критичности запаса
        usort($items, function($a, $b) use ($gradePriority) {
            $priorityA = $gradePriority[$a['idc_grade'] ?? 'GRADES_NONE'] ?? 6;
            $priorityB = $gradePriority[$b['idc_grade'] ?? 'GRADES_NONE'] ?? 6;
            return $priorityA - $priorityB;
        });
        
        return array_map(function($item) {
            $avgDailySales = $item['ads'] ?? 0;
            $currentStock = $item['current_stock'] ?? 0;
            $daysOfStock = $item['idc'] ?? 0;
            
            // Рекомендуемое количество: на 28 дней минус текущий запас
            $recommendedQty = max(0, ceil($avgDailySales * 28) - $currentStock);
            
            // Определяем приоритет на основе уровня запаса
            $grade = $item['idc_grade'] ?? 'GRADES_NONE';
            $priority = match($grade) {
                'GRADES_CRITICAL' => 'critical',
                'GRADES_RED' => 'high',
                'GRADES_YELLOW' => 'medium',
                'GRADES_GREEN' => 'low',
                default => 'none',
            };
            
            return [
                'sku' => (string) ($item['sku'] ?? ''),
                'offer_id' => $item['offer_id'] ?? null,
                'name' => $item['name'] ?? null,
                'current_stock' => $currentStock,
                'avg_daily_sales' => round($avgDailySales, 2),
                'days_of_stock' => round($daysOfStock, 1),
                'turnover_days' => round($item['turnover'] ?? 0, 1),
                'recommended_qty' => $recommendedQty,
                'stock_grade' => $grade,
                'turnover_grade' => $item['turnover_grade'] ?? 'GRADES_NONE',
                'priority' => $priority,
            ];
        }, $items);
    }
    
    /**
     * Получить рекомендации товаров для кластера (legacy метод)
     * Использует getSupplyRecommendations и фильтрует по наличию на складах кластера
     * 
     * @param string $clusterId ID кластера
     * @param int $days Период рекомендаций (не используется, для совместимости)
     * @return array Список рекомендованных товаров
     */
    public function getClusterRecommendations(string $clusterId, int $days = 28): array
    {
        // Получаем все рекомендации
        return $this->getSupplyRecommendations(100, 0);
    }

    /**
     * Получить склады кластера
     * 
     * POST /v1/cluster/warehouses
     * 
     * @param string $clusterId ID кластера
     * @return array Список складов в кластере
     */
    public function getClusterWarehouses(string $clusterId): array
    {
        $response = $this->client->post('/v1/cluster/warehouses', [
            'macrolocal_cluster_id' => $clusterId,
        ]);

        if (!$response) {
            // Fallback: получаем все FBO склады и фильтруем по кластеру
            $allWarehouses = $this->getFboWarehouses();
            return array_filter($allWarehouses, fn($wh) => $wh['cluster_id'] === $clusterId);
        }

        $warehouses = $response['result']['warehouses'] ?? $response['warehouses'] ?? [];
        
        return array_map(fn($wh) => [
            'id' => (string) ($wh['warehouse_id'] ?? $wh['id'] ?? null),
            'name' => $wh['name'] ?? null,
            'type' => $wh['type'] ?? 'fbo',
            'address' => $wh['address'] ?? null,
            'city' => $wh['city'] ?? null,
            'is_active' => $wh['is_active'] ?? true,
            'accepts_supplies' => $wh['accepts_supplies'] ?? true,
        ], $warehouses);
    }

    /**
     * Универсальный метод создания черновика (автовыбор типа)
     * 
     * @param array $data [
     *   'supply_method' => 'direct' | 'crossdock' | 'multi_cluster',
     *   'macrolocal_cluster_id' => string (для direct/crossdock),
     *   'cluster_ids' => string[] (для multi_cluster),
     *   'warehouse_id' => string (legacy, для обратной совместимости),
     *   'delivery_scheme' => 'drop_off' | 'pick_up' (для crossdock/multi_cluster),
     *   'items' => [['sku' => string, 'quantity' => int], ...],
     *   ...
     * ]
     */
    public function createDraft(array $data): array
    {
        $supplyMethod = $data['supply_method'] ?? null;
        
        // Обратная совместимость: если передан warehouse_id без supply_method
        if (!$supplyMethod && !empty($data['warehouse_id'])) {
            return $this->createSupplyDraft($data);
        }

        return match ($supplyMethod) {
            'direct' => $this->createDirectDraft($data),
            'crossdock' => $this->createCrossdockDraft($data),
            'multi_cluster' => $this->createMultiClusterDraft($data),
            default => $this->createSupplyDraft($data), // Legacy fallback
        };
    }
}
