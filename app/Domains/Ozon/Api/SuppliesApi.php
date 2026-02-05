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
     * Получить список доступных складов Ozon (FBO)
     * Использует кластеры для получения складов фулфилмента
     */
    public function getAvailableWarehouses(): array
    {
        $clusters = $this->getClusters();
        
        if (empty($clusters)) {
            return [];
        }

        $warehouses = [];
        
        foreach ($clusters as $cluster) {
            // Добавляем кластер как "склад" для упрощения выбора
            $warehouses[] = [
                'id' => (string) $cluster['id'],
                'name' => $cluster['name'],
                'type' => 'cluster',
                'warehouses_count' => $cluster['warehouses_count'] ?? 0,
                'accepting_warehouses_count' => $cluster['accepting_warehouses_count'] ?? 0,
                'warehouse_ids' => $cluster['warehouse_ids'] ?? [],
            ];
        }

        return $warehouses;
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
     * Забронировать слот приёмки по ID (возвращает структурированный ответ)
     * 
     * POST /v1/supply/timeslot/set
     */
    public function bookAcceptanceSlotById(string $supplyOrderId, int $timeslotId): array
    {
        $body = [
            'supply_order_id' => (int) $supplyOrderId,
            'timeslot_id' => $timeslotId,
        ];

        \Illuminate\Support\Facades\Log::info('Ozon supply/timeslot/set request', ['body' => $body]);

        $response = $this->client->post('/v1/supply/timeslot/set', $body);

        \Illuminate\Support\Facades\Log::info('Ozon supply/timeslot/set response', [
            'response_keys' => $response ? array_keys($response) : [],
            'has_error' => !empty($response['error']) || !empty($response['code']),
        ]);

        return [
            'success' => empty($response['error']) && empty($response['code']),
            'response' => $response,
            'error' => $response['error'] ?? $response['message'] ?? null,
            '_http_status' => $response['_http_status'] ?? null,
        ];
    }

    /**
     * Создать грузоместа с товарным составом
     * 
     * POST /v1/cargoes/create (новый endpoint с декабря 2024)
     * 
     * Формат Ozon API:
     * - supply_id: ID поставки (из /v2/supply-order/get -> orders.supplies.supply_id)
     * - cargoes: массив объектов {key: string, value: {type: "BOX"|"PALLET", items: [...]}}
     * - delete_current_version: удалить предыдущие грузоместа
     * 
     * @see https://docs.ozon.ru/api/seller/
     */
    public function createCargo(string $supplyId, array $cargoData): array
    {
        // Формируем грузоместа в формате Ozon API
        $cargoes = [];
        $index = 1;
        
        foreach ($cargoData['containers'] ?? [] as $container) {
            $type = strtoupper($container['type'] ?? 'box');
            if ($type === 'PALLET') {
                $type = 'PALLET';
            } else {
                $type = 'BOX';
            }
            
            // Формируем items для грузоместа
            $items = [];
            foreach ($container['items'] ?? [] as $item) {
                $items[] = [
                    'offer_id' => (string) ($item['offer_id'] ?? $item['sku'] ?? ''),
                    'barcode' => (string) ($item['barcode'] ?? ''),
                    'quantity' => (int) ($item['quantity'] ?? 1),
                    'quant' => (int) ($item['quant'] ?? 1),
                ];
            }
            
            // Если items пустой, создаём пустое грузоместо
            $cargoes[] = [
                'key' => 'cargo_' . $index,
                'value' => [
                    'type' => $type,
                    'items' => $items,
                ],
            ];
            $index++;
        }

        $body = [
            'supply_id' => (int) $supplyId,
            'cargoes' => $cargoes,
            'delete_current_version' => $cargoData['delete_current_version'] ?? false,
        ];

        \Log::info('Ozon createCargo request', ['body' => $body]);

        $response = $this->client->post('/v1/cargoes/create', $body);

        \Log::info('Ozon createCargo response', ['response' => $response]);

        if (!$response || !empty($response['errors'])) {
            $errorMessage = $response['errors']['error_reasons'][0] 
                ?? $response['error']['message'] 
                ?? $response['message'] 
                ?? json_encode($response);
            throw new \RuntimeException('Не удалось создать грузоместа: ' . $errorMessage);
        }

        return [
            'success' => true,
            'supply_id' => $supplyId,
            'operation_id' => $response['operation_id'] ?? null,
            'containers_count' => count($cargoes),
        ];
    }
    
    /**
     * Получить статус создания грузомест
     * 
     * POST /v2/cargoes/create/info
     */
    public function getCargoCreateInfo(string $supplyId): array
    {
        $body = [
            'supply_id' => (int) $supplyId,
        ];

        $response = $this->client->post('/v2/cargoes/create/info', $body);

        return [
            'success' => empty($response['error']),
            'data' => $response,
        ];
    }
    
    /**
     * Удалить грузоместо
     * 
     * POST /v1/cargoes/delete
     */
    public function deleteCargo(string $supplyId, int $cargoId): array
    {
        $body = [
            'supply_id' => (int) $supplyId,
            'cargo_id' => $cargoId,
        ];

        $response = $this->client->post('/v1/cargoes/delete', $body);

        return [
            'success' => empty($response['error']),
            'operation_id' => $response['operation_id'] ?? null,
        ];
    }
    
    /**
     * Создать этикетки для грузомест
     * 
     * POST /v1/cargoes-label/create
     */
    public function createCargoLabels(string $supplyId, array $cargoIds = []): array
    {
        $cargoes = [];
        foreach ($cargoIds as $cargoId) {
            $cargoes[] = ['cargo_id' => (int) $cargoId];
        }
        
        $body = [
            'supply_id' => (int) $supplyId,
            'cargoes' => $cargoes,
        ];

        $response = $this->client->post('/v1/cargoes-label/create', $body);

        if (!$response || !empty($response['_error']) || !empty($response['errors'])) {
            $errorMessage = $response['errors']['error_reasons'][0]
                ?? $response['message']
                ?? json_encode($response);
            throw new \RuntimeException('Не удалось создать этикетки: ' . $errorMessage);
        }

        return [
            'success' => true,
            'operation_id' => $response['operation_id'] ?? null,
        ];
    }
    
    /**
     * Получить статус и file_guid этикеток
     * 
     * POST /v1/cargoes-label/get
     */
    public function getCargoLabelsStatus(string $operationId): array
    {
        $body = [
            'operation_id' => (string) $operationId,
        ];

        $response = $this->client->post('/v1/cargoes-label/get', $body);

        $fileGuid = $response['file_guid']
            ?? $response['result']['file_guid']
            ?? $response['result']['file_guid_list'][0]
            ?? $response['result']['file_guids'][0]
            ?? null;
        $status = $response['status']
            ?? $response['result']['status']
            ?? $response['result']['state']
            ?? null;

        return [
            'success' => empty($response['_error']) && empty($response['error']) && empty($response['code']),
            'file_guid' => $fileGuid,
            'status' => $status,
            'data' => $response,
        ];
    }
    
    /**
     * Скачать PDF с этикетками
     * 
     * GET /v1/cargoes-label/file/{file_guid}
     * 
     * @return string URL для скачивания или содержимое файла
     */
    public function downloadCargoLabels(string $fileGuid): string
    {
        // Возвращаем URL для прямого скачивания через Ozon API
        return $this->client->getBaseUrl() . '/v1/cargoes-label/file/' . $fileGuid;
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
            $acceptingWarehouses = [];
            
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
                            $acceptingWarehouses[] = [
                                'id' => (string) $whId,
                                'name' => $whName,
                                'type' => $whType,
                            ];
                        }
                    }
                }
            }
            
            // id — это ID кластера для API /v1/draft/create
            // macrolocal_cluster_id — дополнительный идентификатор
            $clusterId = $cluster['id'] ?? null;
            $macrolocalClusterId = $cluster['macrolocal_cluster_id'] ?? null;
            
            return [
                'id' => (string) $clusterId, // Используем id кластера для API
                'macrolocal_cluster_id' => (string) $macrolocalClusterId,
                'name' => $cluster['name'] ?? null,
                'type' => $cluster['type'] ?? 'CLUSTER_TYPE_OZON',
                'warehouses_count' => $acceptingWarehousesCount,
                'warehouse_ids' => $acceptingWarehouseIds,
                'all_warehouse_ids' => $allWarehouseIds,
                'is_active' => true,
                'warehouses' => $acceptingWarehouses,
            ];
        }, $clusters);
    }

    private function buildClusterWarehouses(): array
    {
        $clusters = $this->getClusters();
        $warehouses = [];

        foreach ($clusters as $cluster) {
            foreach ($cluster['warehouses'] ?? [] as $warehouse) {
                $warehouses[] = [
                    'id' => (string) ($warehouse['id'] ?? $warehouse['warehouse_id'] ?? null),
                    'name' => $warehouse['name'] ?? null,
                    'type' => $warehouse['type'] ?? null,
                    'cluster_id' => (string) ($cluster['id'] ?? null),
                    'cluster_name' => $cluster['name'] ?? null,
                    'is_active' => $cluster['is_active'] ?? true,
                ];
            }
        }

        return $warehouses;
    }

    /**
     * Создать черновик прямой поставки (на конкретный склад)
     * 
     * POST /v1/draft/direct/create
     * 
     * @param array $data [
     *   'cluster_id' => string,
     *   'macrolocal_cluster_id' => string,
     *   'items' => [['sku' => string, 'quantity' => int], ...],
     * ]
     */
    public function createDirectDraft(array $data): array
    {
        $items = [];
        if (!empty($data['items'])) {
            $items = array_map(fn($item) => [
                'sku' => (int) ($item['sku'] ?? $item['product_id'] ?? 0),
                'quantity' => (int) ($item['quantity'] ?? 0),
            ], $data['items']);
        }

        // Используем актуальный эндпоинт /v1/draft/create
        // Пробуем передать cluster_ids как массив int64 (числа)
        $clusterId = (int) ($data['cluster_id'] ?? $data['macrolocal_cluster_id'] ?? 0);
        $body = [
            'cluster_ids' => [$clusterId],
            'items' => $items,
            'type' => 'CREATE_TYPE_DIRECT',
        ];

        \Illuminate\Support\Facades\Log::info('Ozon draft/create request', [
            'body' => $body,
        ]);

        $response = $this->client->post('/v1/draft/create', $body);

        \Illuminate\Support\Facades\Log::info('Ozon draft/create response', [
            'response' => $response,
        ]);

        if (!$response || empty($response['operation_id'])) {
            throw new \RuntimeException(
                'Не удалось создать черновик поставки: ' . 
                json_encode($response)
            );
        }

        $operationId = $response['operation_id'];

        // Ждём и получаем информацию о созданном черновике
        // Ozon может обрабатывать запрос несколько секунд
        $draftInfo = null;
        $maxAttempts = 5;
        $attempt = 0;
        
        while ($attempt < $maxAttempts) {
            $attempt++;
            sleep(2); // Ждём 2 секунды между попытками
            
            $draftInfo = $this->getDraftCreateInfo($operationId);
            $status = $draftInfo['status'] ?? '';
            
            // Если статус не IN_PROGRESS — выходим
            if ($status !== 'CALCULATION_STATUS_IN_PROGRESS') {
                break;
            }
            
            \Illuminate\Support\Facades\Log::debug('Ozon draft still in progress', [
                'operation_id' => $operationId,
                'attempt' => $attempt,
            ]);
        }
        
        \Illuminate\Support\Facades\Log::info('Ozon draft/create/info response', [
            'operation_id' => $operationId,
            'draft_info' => $draftInfo,
        ]);

        $draftId = null;
        $errors = [];
        $status = $draftInfo['status'] ?? 'UNKNOWN';
        
        // Сначала проверяем draft_id на верхнем уровне
        if (!empty($draftInfo['draft_id']) && $draftInfo['draft_id'] !== 0) {
            $draftId = (string) $draftInfo['draft_id'];
        }
        
        // Если нет — ищем в clusters
        if (!$draftId && !empty($draftInfo['clusters'])) {
            foreach ($draftInfo['clusters'] as $cluster) {
                if (!empty($cluster['draft_id']) && $cluster['draft_id'] !== 0) {
                    $draftId = (string) $cluster['draft_id'];
                    break;
                }
            }
        }

        // Обрабатываем ошибки
        if (!empty($draftInfo['errors'])) {
            foreach ($draftInfo['errors'] as $error) {
                if (!empty($error['items_validation'])) {
                    foreach ($error['items_validation'] as $item) {
                        $reasons = $item['reasons'] ?? [];
                        $sku = $item['sku'] ?? 'unknown';
                        foreach ($reasons as $reason) {
                            $errors[] = $this->translateOzonError($reason, $sku);
                        }
                    }
                }
                if (!empty($error['unknown_cluster_ids'])) {
                    $errors[] = 'Неизвестные кластеры: ' . implode(', ', $error['unknown_cluster_ids']);
                }
                if (!empty($error['error_message'])) {
                    $errors[] = $error['error_message'];
                }
            }
        }

        // Если статус FAILED и нет draft_id, выбрасываем исключение с понятным сообщением
        if ($status === 'CALCULATION_STATUS_FAILED' && !$draftId) {
            $errorMessage = !empty($errors) 
                ? implode('; ', array_filter($errors))
                : 'Не удалось создать черновик в Ozon';
            throw new \RuntimeException($errorMessage);
        }

        return [
            'draft_id' => $draftId ?? $operationId,
            'operation_id' => $operationId,
            'status' => $status === 'CALCULATION_STATUS_SUCCESS' ? 'draft' : 'pending',
            'supply_method' => 'direct',
            'macrolocal_cluster_id' => $data['macrolocal_cluster_id'] ?? null,
            'errors' => $errors,
            'created_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Перевод ошибок Ozon API на русский
     */
    private function translateOzonError(string $reason, $sku): string
    {
        $translations = [
            'ITEM_REJECTION_REASON_OUT_OF_ASSORTMENT' => "Товар SKU {$sku} не в ассортименте FBO (возможно, товар настроен только для FBS)",
            'ITEM_REJECTION_REASON_NO_STOCK' => "Товар SKU {$sku} нет на складе",
            'ITEM_REJECTION_REASON_BLOCKED' => "Товар SKU {$sku} заблокирован",
            'ITEM_REJECTION_REASON_UNKNOWN' => "Товар SKU {$sku} отклонён по неизвестной причине",
        ];

        return $translations[$reason] ?? "Товар SKU {$sku}: {$reason}";
    }

    /**
     * Получить информацию о созданном черновике
     * 
     * POST /v1/draft/create/info
     */
    public function getDraftCreateInfo(string $operationId): array
    {
        $response = $this->client->post('/v1/draft/create/info', [
            'operation_id' => $operationId,
        ]);

        return $response ?? [];
    }

    /**
     * Получить доступные таймслоты для черновика
     *
     * POST /v2/draft/timeslot/info
     */
    public function getDraftTimeslots(int $draftId, int $warehouseId, ?int $clusterId = null, ?string $warehouseName = null): array
    {
        // Запрашиваем слоты на ближайшие 28 дней
        $dateFrom = now()->toDateString();
        $dateTo = now()->addDays(27)->toDateString();

        $clusterIdHint = $clusterId
            ?? \App\Models\OzonWarehouseCluster::getClusterIdByWarehouse($warehouseName ?? '')
            ?? \App\Models\OzonWarehouseCluster::getClusterIdByWarehouse((string) $warehouseId);

        $resolvedClusterId = $clusterIdHint ? (int) $clusterIdHint : null;
        $macrolocalClusterId = null;

        foreach ($this->getClusters() as $cluster) {
            $warehouseIds = $cluster['warehouse_ids'] ?? $cluster['all_warehouse_ids'] ?? [];
            $warehouseIds = array_map('strval', $warehouseIds);
            $clusterIdValue = (string) ($cluster['id'] ?? '');
            $macroIdValue = (string) ($cluster['macrolocal_cluster_id'] ?? '');

            if (
                in_array((string) $warehouseId, $warehouseIds, true)
                || ($clusterIdHint && ((string) $clusterIdHint === $clusterIdValue || (string) $clusterIdHint === $macroIdValue))
            ) {
                $resolvedClusterId = (int) ($cluster['id'] ?? $clusterIdHint ?? 0);
                $macrolocalClusterId = (int) ($cluster['macrolocal_cluster_id'] ?? $cluster['id'] ?? 0);
                break;
            }
        }

        if (!$macrolocalClusterId && $resolvedClusterId) {
            $macrolocalClusterId = $resolvedClusterId;
        }

        if (!$macrolocalClusterId) {
            \Illuminate\Support\Facades\Log::warning('Ozon draft timeslots: cluster_id not resolved', [
                'draft_id' => $draftId,
                'warehouse_id' => $warehouseId,
                'warehouse_name' => $warehouseName,
            ]);

            return [];
        }

        $response = $this->client->post('/v2/draft/timeslot/info', [
            'draft_id' => (int) $draftId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'selected_cluster_warehouses' => [[
                'macrolocal_cluster_id' => (int) $macrolocalClusterId,
                'storage_warehouse_id' => (int) $warehouseId,
            ]],
        ]);

        $groups = $response['result']['drop_off_warehouse_timeslots']
            ?? $response['drop_off_warehouse_timeslots']
            ?? $response['result']['warehouses']
            ?? $response['warehouses']
            ?? [];
        $flatSlots = $response['result']['timeslots'] ?? $response['timeslots'] ?? [];
        $shouldFallback = !$response
            || !empty($response['_error'])
            || !empty($response['error_reason'])
            || (empty($groups) && empty($flatSlots));

        if ($shouldFallback) {
            $fallbackResponse = $this->client->post('/v1/draft/timeslot/info', [
                'draft_id' => (int) $draftId,
                'warehouse_ids' => [(string) $warehouseId],
                'date_from' => $dateFrom . 'T00:00:00Z',
                'date_to' => $dateTo . 'T23:59:59Z',
            ]);

            \Illuminate\Support\Facades\Log::info('Ozon draft/timeslot/info v1 fallback', [
                'draft_id' => $draftId,
                'warehouse_id' => $warehouseId,
                'cluster_id' => $resolvedClusterId,
                'macrolocal_cluster_id' => $macrolocalClusterId,
                'response' => $fallbackResponse,
            ]);

            $response = $fallbackResponse ?: $response;
            $groups = $response['result']['drop_off_warehouse_timeslots']
                ?? $response['drop_off_warehouse_timeslots']
                ?? $response['result']['warehouses']
                ?? $response['warehouses']
                ?? [];
            $flatSlots = $response['result']['timeslots'] ?? $response['timeslots'] ?? [];
        }

        \Illuminate\Support\Facades\Log::info('Ozon draft/timeslot/info v2 response', [
            'draft_id' => $draftId,
            'warehouse_id' => $warehouseId,
            'cluster_id' => $resolvedClusterId,
            'macrolocal_cluster_id' => $macrolocalClusterId,
            'response' => $response,
        ]);

        $slots = [];

        foreach ($groups as $group) {
            $groupWarehouseId = (string) ($group['storage_warehouse_id']
                ?? $group['drop_off_warehouse_id']
                ?? $warehouseId);
            $groupClusterId = (string) ($group['macrolocal_cluster_id'] ?? $resolvedClusterId ?? $macrolocalClusterId);

            foreach ($group['days'] ?? [] as $day) {
                $date = $day['date_in_timezone'] ?? $day['date'] ?? null;
                foreach ($day['timeslots'] ?? [] as $slot) {
                    $from = $slot['from_in_timezone'] ?? $slot['from'] ?? null;
                    $to = $slot['to_in_timezone'] ?? $slot['to'] ?? null;
                    $slotId = $slot['timeslot_id'] ?? $slot['id'] ?? null;
                    if (!$slotId && $from && $to) {
                        $slotId = substr(sha1($groupWarehouseId . '|' . $from . '|' . $to), 0, 32);
                    }

                    $slots[] = [
                        'id' => $slotId,
                        'warehouse_id' => $groupWarehouseId,
                        'cluster_id' => $groupClusterId,
                        'date' => $date ? substr($date, 0, 10) : substr((string) $from, 0, 10),
                        'time_from' => $from ? substr($from, 11, 5) : null,
                        'time_to' => $to ? substr($to, 11, 5) : null,
                        'from_datetime' => $from,
                        'to_datetime' => $to,
                        'is_available' => $slot['is_available'] ?? true,
                        'capacity' => $slot['capacity'] ?? null,
                    ];
                }
            }
        }

        if (empty($slots)) {
            $flatSlots = $response['result']['timeslots'] ?? $response['timeslots'] ?? [];
            foreach ($flatSlots as $slot) {
                $from = $slot['from_in_timezone'] ?? $slot['from'] ?? null;
                $to = $slot['to_in_timezone'] ?? $slot['to'] ?? null;
                $slotId = $slot['timeslot_id'] ?? $slot['id'] ?? null;
                if (!$slotId && $from && $to) {
                    $slotId = substr(sha1($warehouseId . '|' . $from . '|' . $to), 0, 32);
                }

                $slots[] = [
                    'id' => $slotId,
                    'warehouse_id' => (string) $warehouseId,
                    'cluster_id' => (string) $resolvedClusterId,
                    'date' => substr((string) $from, 0, 10),
                    'time_from' => $from ? substr($from, 11, 5) : null,
                    'time_to' => $to ? substr($to, 11, 5) : null,
                    'from_datetime' => $from,
                    'to_datetime' => $to,
                    'is_available' => $slot['is_available'] ?? true,
                    'capacity' => $slot['capacity'] ?? null,
                ];
            }
        }

        return $slots;
    }

    /**
     * Создать заявку на поставку из черновика
     * 
     * POST /v1/draft/supply/create
     */
    public function createSupplyFromDraft(int $draftId, int $warehouseId, ?array $timeslot = null): array
    {
        $body = [
            'draft_id' => $draftId,
            'warehouse_id' => $warehouseId,
        ];

        if ($timeslot) {
            $body['timeslot'] = [
                'from_in_timezone' => $timeslot['from'] ?? '',
                'to_in_timezone' => $timeslot['to'] ?? '',
            ];
        }

        \Illuminate\Support\Facades\Log::info('Ozon draft/supply/create request', [
            'body' => $body,
        ]);

        $response = $this->client->post('/v1/draft/supply/create', $body);

        \Illuminate\Support\Facades\Log::info('Ozon draft/supply/create response', [
            'response' => $response,
        ]);

        return $response ?? [];
    }

    /**
     * Получить статус создания заявки на поставку
     * 
     * POST /v1/supply/create/status
     */
    public function getSupplyCreateStatus(string $operationId): array
    {
        $response = $this->client->post('/v1/supply/create/status', [
            'operation_id' => $operationId,
        ]);

        return $response ?? [];
    }

    /**
     * Получить список заявок на поставку из Ozon
     * 
     * POST /v3/supply-order/list
     */
    public function getSupplyOrdersList(array $states = [], int $limit = 100, ?string $lastId = null): array
    {
        // Если статусы не указаны, запрашиваем все активные
        // Используем формат из документации Ozon
        if (empty($states)) {
            $states = [
                'DATA_FILLING',
                'READY_TO_SUPPLY',
                'IN_TRANSIT',
                'ACCEPTANCE',
                'ACCEPTED',
                'PARTIALLY_ACCEPTED',
            ];
        }

        $body = [
            'filter' => [
                'states' => $states,
            ],
            'limit' => $limit,
            'sort_by' => 'ORDER_CREATION',
            'sort_dir' => 'DESC',
        ];

        if ($lastId) {
            $body['last_id'] = $lastId;
        }

        \Illuminate\Support\Facades\Log::info('Ozon /v3/supply-order/list request', [
            'body' => $body,
        ]);

        $response = $this->client->post('/v3/supply-order/list', $body);

        \Illuminate\Support\Facades\Log::info('Ozon /v3/supply-order/list response', [
            'states' => $states,
            'response' => $response,
        ]);

        return [
            'order_ids' => $response['order_ids'] ?? [],
            'last_id' => $response['last_id'] ?? null,
        ];
    }

    /**
     * Получить детали заявок на поставку
     * 
     * POST /v3/supply-order/get
     */
    public function getSupplyOrdersDetails(array $orderIds): array
    {
        $response = $this->client->post('/v3/supply-order/get', [
            'order_ids' => array_map('intval', $orderIds),
        ]);

        \Illuminate\Support\Facades\Log::info('Ozon /v3/supply-order/get response', [
            'order_ids' => $orderIds,
            'orders_count' => count($response['orders'] ?? []),
        ]);

        return $response ?? [];
    }

    /**
     * Получить состав поставки (товары)
     * 
     * POST /v1/supply-order/bundle
     */
    public function getSupplyOrderBundle(int $supplyOrderId): array
    {
        $response = $this->client->post('/v1/supply-order/bundle', [
            'supply_order_id' => $supplyOrderId,
        ]);

        return $response ?? [];
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
        $clusterId = $data['macrolocal_cluster_id'] ?? '';
        
        // Items go inside cluster_info per Ozon API spec
        $items = [];
        if (!empty($data['items'])) {
            $items = array_map(fn($item) => [
                'sku' => (string) ($item['sku'] ?? $item['offer_id'] ?? ''),
                'quantity' => (int) ($item['quantity'] ?? 0),
            ], $data['items']);
        }
        
        $deliveryScheme = strtoupper($data['delivery_scheme'] ?? 'DROP_OFF');
        
        $body = [
            'cluster_info' => [
                'macrolocal_cluster_id' => (string) $clusterId,
                'items' => $items,
            ],
            'delivery_info' => [
                'delivery_scheme' => $deliveryScheme,
                'type' => $deliveryScheme,
            ],
        ];

        if (strtolower($data['delivery_scheme'] ?? 'drop_off') === 'drop_off') {
            $body['delivery_info']['drop_off_point'] = [
                'id' => (string) ($data['point_id'] ?? ''),
                'type' => $data['point_type'] ?? '',
            ];
        } else {
            $body['delivery_info']['seller_warehouse_id'] = $data['seller_warehouse_id'] ?? '';
        }

        \Log::info('Ozon crossdock draft request', [
            'endpoint' => '/v1/draft/crossdock/create',
            'body' => $body,
            'input_data' => $data,
        ]);

        $response = $this->client->post('/v1/draft/crossdock/create', $body);

        \Log::info('Ozon crossdock draft response', [
            'response' => $response,
        ]);

        if (!$response || empty($response['result'])) {
            $errorMsg = $response['error']['message'] 
                ?? $response['message'] 
                ?? ($response['error'] ?? 'Unknown error');
            if (is_array($errorMsg)) {
                $errorMsg = json_encode($errorMsg);
            }
            throw new \RuntimeException(
                'Не удалось создать черновик кросс-док поставки: ' . $errorMsg
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

    // Метод getDraftTimeslots определён выше

    // Метод createSupplyFromDraft определён выше

    // Метод getSupplyCreateStatus определён выше

    /**
     * Fetch real warehouse coordinates from /v1/warehouse/list API
     * by searching major cities. Returns array [warehouse_id => ['lat' => x, 'lng' => y]]
     */
    private function fetchWarehouseCoordinates(): array
    {
        $coordinates = [];
        
        // Search terms to fetch warehouses with real coordinates
        $searchTerms = [
            'Москва', 'Екатеринбург', 'Санкт-Петербург', 'Казань', 'Новосибирск',
            'Краснодар', 'Ростов', 'Воронеж', 'Самара', 'Нижний', 'Челябинск',
            'Пермь', 'Волгоград', 'Красноярск', 'Уфа', 'Омск', 'Тюмень',
            'Домодедово', 'Хоругвино', 'Коледино', 'Софьино', 'Пушкино',
            'Адыгейск', 'Жуковский', 'Гривно', 'Давыдовское', 'Ватутинки',
        ];
        
        foreach ($searchTerms as $search) {
            try {
                $response = $this->client->post('/v1/warehouse/list', [
                    'search' => $search,
                ]);
                
                if (!$response) continue;
                
                $result = $response['result'] ?? $response;
                $warehouses = $result['search'] ?? $result['warehouses'] ?? [];
                
                foreach ($warehouses as $wh) {
                    $whId = (string) ($wh['warehouse_id'] ?? $wh['id'] ?? null);
                    $rawCoords = $wh['coordinates'] ?? null;
                    
                    if ($whId && is_array($rawCoords)) {
                        $lat = $rawCoords['latitude'] ?? null;
                        $lng = $rawCoords['longitude'] ?? null;
                        if ($lat !== null && $lng !== null) {
                            $coordinates[$whId] = [
                                'lat' => (float) $lat,
                                'lng' => (float) $lng,
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {
                \Log::warning('Failed to fetch warehouse coordinates for: ' . $search, [
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        \Log::info('Fetched real warehouse coordinates', [
            'count' => count($coordinates),
        ]);
        
        return $coordinates;
    }

    /**
     * Получить список складов FBO
     * 
     * POST /v1/warehouse/fbo/list
     */
    public function getFboWarehouses(array $params = []): array
    {
        // First get all warehouses from /v1/cluster/list (no coordinates)
        $response = $this->client->post('/v1/cluster/list', [
            'cluster_type' => 'CLUSTER_TYPE_OZON',
        ]);

        \Log::info('Ozon FBO warehouses response (from cluster/list)', [
            'response_keys' => is_array($response) ? array_keys($response) : 'not_array',
        ]);
        
        // Then get real coordinates from /v1/warehouse/list by searching major cities
        $warehouseCoordinates = $this->fetchWarehouseCoordinates();

        if (!$response) {
            return $this->buildClusterWarehouses();
        }

        $result = $response['result'] ?? $response;
        $clusters = $result['clusters'] ?? [];
        
        // Flatten all warehouses from all clusters
        $warehouses = [];
        foreach ($clusters as $cluster) {
            $clusterId = $cluster['macrolocal_cluster_id'] ?? $cluster['id'] ?? null;
            $clusterName = $cluster['name'] ?? null;
            $logisticClusters = $cluster['logistic_clusters'] ?? [];
            
            foreach ($logisticClusters as $lc) {
                $lcWarehouses = $lc['warehouses'] ?? [];
                foreach ($lcWarehouses as $wh) {
                    $wh['_cluster_id'] = $clusterId;
                    $wh['_cluster_name'] = $clusterName;
                    $warehouses[] = $wh;
                }
            }
        }
        
        \Log::info('Ozon FBO warehouses parsed', [
            'clusters_count' => count($clusters),
            'warehouses_count' => count($warehouses),
            'sample_types' => array_unique(array_column(array_slice($warehouses, 0, 50), 'type')),
        ]);
        
        if (empty($warehouses)) {
            return $this->buildClusterWarehouses();
        }
        
        // Static coordinates for Russian cities (extracted from warehouse names)
        $cityCoordinates = [
            'МОСКВА' => [55.7558, 37.6173],
            'МСК' => [55.7558, 37.6173],
            'МО' => [55.7558, 37.6173],
            'САНКТ-ПЕТЕРБУРГ' => [59.9343, 30.3351],
            'СПБ' => [59.9343, 30.3351],
            'ЕКАТЕРИНБУРГ' => [56.8389, 60.6057],
            'ЕКБ' => [56.8389, 60.6057],
            'НОВОСИБИРСК' => [55.0084, 82.9357],
            'КАЗАНЬ' => [55.7887, 49.1221],
            'НИЖНИЙ НОВГОРОД' => [56.2965, 43.9361],
            'ЧЕЛЯБИНСК' => [55.1644, 61.4368],
            'САМАРА' => [53.1959, 50.1002],
            'РОСТОВ' => [47.2357, 39.7015],
            'УФА' => [54.7388, 55.9721],
            'КРАСНОЯРСК' => [56.0153, 92.8932],
            'ВОРОНЕЖ' => [51.6720, 39.1843],
            'ПЕРМЬ' => [58.0105, 56.2502],
            'ВОЛГОГРАД' => [48.7080, 44.5133],
            'КРАСНОДАР' => [45.0355, 38.9753],
            'САРАТОВ' => [51.5336, 46.0343],
            'ТЮМЕНЬ' => [57.1522, 65.5272],
            'ТОЛЬЯТТИ' => [53.5078, 49.4204],
            'ИЖЕВСК' => [56.8527, 53.2114],
            'БАРНАУЛ' => [53.3548, 83.7698],
            'УЛЬЯНОВСК' => [54.3142, 48.4031],
            'ИРКУТСК' => [52.2978, 104.2964],
            'ХАБАРОВСК' => [48.4827, 135.0838],
            'ЯРОСЛАВЛЬ' => [57.6261, 39.8845],
            'ВЛАДИВОСТОК' => [43.1332, 131.9113],
            'МАХАЧКАЛА' => [42.9849, 47.5047],
            'ТОМСК' => [56.4977, 84.9744],
            'ОРЕНБУРГ' => [51.7727, 55.0988],
            'КЕМЕРОВО' => [55.3908, 86.0779],
            'НОВОКУЗНЕЦК' => [53.7596, 87.1216],
            'РЯЗАНЬ' => [54.6269, 39.6916],
            'АСТРАХАНЬ' => [46.3497, 48.0408],
            'ПЕНЗА' => [53.1959, 45.0183],
            'ЛИПЕЦК' => [52.6031, 39.5708],
            'КИРОВ' => [58.6035, 49.6668],
            'ЧЕБОКСАРЫ' => [56.1322, 47.2519],
            'ТУЛА' => [54.1961, 37.6182],
            'КАЛИНИНГРАД' => [54.7104, 20.4522],
            'КУРСК' => [51.7373, 36.1874],
            'СТАВРОПОЛЬ' => [45.0428, 41.9734],
            'НЕВИННОМЫССК' => [44.6333, 41.9333],
            'СОЧИ' => [43.5855, 39.7231],
            'БЕЛГОРОД' => [50.5997, 36.5986],
            'БРЯНСК' => [53.2521, 34.3717],
            'ИВАНОВО' => [56.9966, 40.9715],
            'ВЛАДИМИР' => [56.1366, 40.3966],
            'АРХАНГЕЛЬСК' => [64.5401, 40.5433],
            'СУРГУТ' => [61.2500, 73.4167],
            'СМОЛЕНСК' => [54.7903, 32.0503],
            'КУРГАН' => [55.4500, 65.3333],
            'ОМСК' => [54.9885, 73.3242],
            'ДОМОДЕДОВО' => [55.4200, 37.7633],
            'ХОРУГВИНО' => [56.0500, 37.0833],
            'НОГИНСК' => [55.8667, 38.4333],
            'ПУШКИНО' => [56.0167, 37.8500],
            'ВАТУТИНКИ' => [55.5000, 37.3333],
            'КОСТРОМА' => [57.7678, 40.9269],
            'ТВЕРЬ' => [56.8587, 35.9176],
            'ВОЛОГДА' => [59.2181, 39.8886],
            'МУРМАНСК' => [68.9585, 33.0827],
            'ПЕТРОЗАВОДСК' => [61.7849, 34.3469],
            'СЫКТЫВКАР' => [61.6688, 50.8364],
            'КАЛУГА' => [54.5293, 36.2754],
            'ОРЕЛ' => [52.9651, 36.0785],
            'ТАМБОВ' => [52.7212, 41.4523],
            'ПСКОВ' => [57.8136, 28.3496],
            'ВЕЛИКИЙ НОВГОРОД' => [58.5228, 31.2749],
            'ЙОШКАР-ОЛА' => [56.6344, 47.8995],
            'САРАНСК' => [54.1838, 45.1749],
            'АДЫГЕЙСК' => [44.8783, 39.1908],
            'АЛМАТЫ' => [43.2220, 76.8512],
            'АСТАНА' => [51.1694, 71.4491],
            'БАТАЙСК' => [47.1389, 39.7444],
            'ГРИВНО' => [55.4167, 37.3333],
            'ДАВЫДОВСКОЕ' => [55.7500, 37.8333],
            'ДЗЕРЖИНСК' => [56.2389, 43.4611],
            'ЖУКОВСКИЙ' => [55.5953, 38.1200],
            'ЗЕЛЕНОДОЛЬСК' => [55.8500, 48.5167],
            'КОЛЕДИНО' => [55.3167, 37.4333],
            'СОФЬИНО' => [55.4833, 37.9500],
            'ТРОИЦКИЙ' => [55.5000, 37.3000],
            'НИКОЛЬСКОЕ' => [59.7000, 30.7833],
            'СОЛНЕЧНОГОРСК' => [56.1833, 36.9833],
            'СУХАРЕВО' => [55.9500, 37.4167],
            'ЩЕЛКОВО' => [55.9167, 37.9667],
            'ПЕРВОУРАЛЬСК' => [56.9000, 59.9500],
            'ПЫШМА' => [56.9667, 60.5833],
            'КОЛЬЦОВО' => [56.7500, 60.8000],
            'АПЕЛЬСИН' => [56.8389, 60.6057],
            'ЧЕРНЯХОВСКОГО' => [56.8389, 60.6057],
            'ВОЛЖСКИЙ' => [48.7833, 44.7667],
            'ВИДНОЕ' => [55.5500, 37.7000],
            'ПОДОЛЬСК' => [55.4311, 37.5456],
            'ЛЮБЕРЦЫ' => [55.6833, 37.8833],
            'БАЛАШИХА' => [55.8000, 37.9500],
            'МЫТИЩИ' => [55.9167, 37.7333],
            'КОРОЛЁВ' => [55.9167, 37.8167],
            'ХИМКИ' => [55.8833, 37.4333],
            'ОДИНЦОВО' => [55.6667, 37.2667],
            'РЕУТОВ' => [55.7500, 37.8500],
            'ДОЛГОПРУДНЫЙ' => [55.9333, 37.5000],
            'ЛОБНЯ' => [56.0000, 37.4833],
            'ДМИТРОВ' => [56.3500, 37.5167],
            'СЕРГИЕВ ПОСАД' => [56.3000, 38.1333],
            'КЛИН' => [56.3333, 36.7333],
            'ЭЛЕКТРОСТАЛЬ' => [55.7833, 38.4500],
            'КОЛОМНА' => [55.0833, 38.7833],
            'СЕРПУХОВ' => [54.9167, 37.4167],
            'ЧЕХОВ' => [55.1500, 37.4667],
            'РАМЕНСКОЕ' => [55.5667, 38.2333],
            'ЖЕЛЕЗНОДОРОЖНЫЙ' => [55.7500, 38.0167],
            'КРАСНОГОРСК' => [55.8167, 37.3333],
            'НАРО-ФОМИНСК' => [55.3833, 36.7333],
            'ОБНИНСК' => [55.1000, 36.6167],
            'ЖУЛЕБИНО' => [55.6833, 37.8500],
            'ВНУКОВО' => [55.6000, 37.2833],
            'ШЕРЕМЕТЬЕВО' => [55.9667, 37.4167],
            'БЫКОВО' => [55.6167, 38.0667],
            'ИСТРА' => [55.9167, 36.8667],
            'ЗВЕНИГОРОД' => [55.7333, 36.8500],
            'МОЖАЙСК' => [55.5000, 36.0167],
            'РУЗА' => [55.7000, 36.1833],
            'ВОЛОКОЛАМСК' => [56.0333, 35.9500],
            'ШАТУРА' => [55.5667, 39.5333],
            'ЕГОРЬЕВСК' => [55.3833, 39.0333],
            'ПАВЛОВСКИЙ ПОСАД' => [55.7833, 38.6500],
            'ОРЕХОВО-ЗУЕВО' => [55.8000, 38.9667],
            'СТУПИНО' => [54.9000, 38.0667],
            'КАШИРА' => [54.8333, 38.1500],
            'ЗАРАЙСК' => [54.7500, 38.8833],
            'ЛУХОВИЦЫ' => [54.9667, 39.0333],
            'ТАЛДОМ' => [56.7333, 37.5333],
            'ДУБНА' => [56.7333, 37.1667],
            'КИМРЫ' => [56.8667, 37.3500],
            'КОНАКОВО' => [56.7000, 36.7667],
            'ТОРЖОК' => [57.0333, 34.9500],
            'РЖЕВ' => [56.2667, 34.3167],
            'ВЫШНИЙ ВОЛОЧЁК' => [57.5833, 34.5500],
            'БОЛОГОЕ' => [57.8833, 34.0500],
            'УДОМЛЯ' => [57.8833, 35.0000],
            'ОСТАШКОВ' => [57.1500, 33.1000],
            'НЕЛИДОВО' => [56.2167, 32.7833],
            'ЗАПАДНАЯ ДВИНА' => [56.2500, 32.0833],
            'АНДРЕАПОЛЬ' => [56.6500, 32.2667],
            'ПЕНО' => [56.9167, 32.7667],
            'СЕЛИЖАРОВО' => [56.8500, 33.4333],
            'КУВШИНОВО' => [57.0333, 34.1667],
            'ЛИХОСЛАВЛЬ' => [57.1333, 35.4667],
            'СПИРОВО' => [57.4167, 35.0000],
            'МАКСАТИХА' => [57.7833, 35.9167],
            'РАМЕШКИ' => [57.3333, 36.0000],
            'КЕСОВА ГОРА' => [57.5500, 36.8333],
            'СОНКОВО' => [57.7667, 37.1333],
            'БЕЖЕЦК' => [57.7833, 36.6833],
            'КРАСНЫЙ ХОЛМ' => [58.0667, 37.1000],
            'ВЕСЬЕГОНСК' => [58.6667, 37.2667],
            'САНДОВО' => [58.4667, 36.4167],
            'МОЛОКОВО' => [57.5667, 36.5500],
            'СТАРИЦА' => [56.5167, 34.9333],
            'ЗУБЦОВ' => [56.1667, 34.5833],
            'ОЛЕНИНО' => [56.3833, 33.3000],
            'БЕЛЫЙ' => [55.8500, 32.9333],
            'ЖАРКОВСКИЙ' => [55.8500, 32.3000],
            'НОВОРОССИЙСК' => [44.7167, 37.7667],
            'АНАПА' => [44.8944, 37.3167],
            'ГЕЛЕНДЖИК' => [44.5611, 38.0778],
            'ТУАПСЕ' => [44.1000, 39.0833],
            'АРМАВИР' => [44.9833, 41.1167],
            'КРОПОТКИН' => [45.4333, 40.5667],
            'ТИХОРЕЦК' => [45.8500, 40.1333],
            'ЕЙСК' => [46.7000, 38.2667],
            'СЛАВЯНСК-НА-КУБАНИ' => [45.2500, 38.1167],
            'ТЕМРЮК' => [45.2667, 37.3833],
            'КРЫМСК' => [44.9333, 37.9833],
            'АБИНСК' => [44.8667, 38.1500],
            'ТИМАШЁВСК' => [45.6167, 38.9500],
            'КОРЕНОВСК' => [45.4667, 39.4500],
            'УСТЬ-ЛАБИНСК' => [45.2167, 39.6833],
            'ЛАБИНСК' => [44.6333, 40.7333],
            'КУРГАНИНСК' => [44.8833, 40.6000],
            'БЕЛОРЕЧЕНСК' => [44.7667, 39.8667],
            'АПШЕРОНСК' => [44.4667, 39.7333],
            'ХАДЫЖЕНСК' => [44.4167, 39.5333],
            'ГОРЯЧИЙ КЛЮЧ' => [44.6333, 39.1333],
            'ПРИМОРСКО-АХТАРСК' => [46.0500, 38.1833],
            'ТБИЛИССКАЯ' => [45.3667, 40.1833],
            'ВЫСЕЛКИ' => [45.8500, 39.6500],
            'НОВОКУБАНСК' => [45.1167, 41.0333],
            'ГУЛЬКЕВИЧИ' => [45.3500, 40.7000],
            'КАВКАЗСКАЯ' => [45.4500, 40.6667],
            'УСПЕНСКОЕ' => [44.9000, 41.3833],
            'ОТРАДНАЯ' => [44.3833, 41.5167],
            'МОСТОВСКОЙ' => [44.4167, 40.7833],
            'ПСЕБАЙ' => [44.1333, 40.8000],
            'КОМИ' => [61.6688, 50.8364],
            'FRESH' => [55.7558, 37.6173],
            'РЯБИНОВАЯ' => [55.7558, 37.6173],
            'НИНО' => [56.2965, 43.9361],
        ];
        
        $mapped = array_map(function($wh) use ($cityCoordinates, $warehouseCoordinates) {
            $warehouseId = (string) ($wh['warehouse_id'] ?? $wh['id'] ?? null);
            $coordinates = null;
            
            // First try to get real coordinates from /v1/warehouse/list cache
            if (isset($warehouseCoordinates[$warehouseId])) {
                $coordinates = $warehouseCoordinates[$warehouseId];
            }
            
            // Then try coordinates from cluster/list response
            if (!$coordinates) {
                $rawCoords = $wh['coordinates'] ?? ($wh['address']['coordinates'] ?? null);
                if (is_array($rawCoords)) {
                    $lat = $rawCoords['latitude'] ?? $rawCoords['lat'] ?? null;
                    $lng = $rawCoords['longitude'] ?? $rawCoords['lng'] ?? $rawCoords['lon'] ?? null;
                    if ($lat !== null && $lng !== null) {
                        $coordinates = [
                            'lat' => (float) $lat,
                            'lng' => (float) $lng,
                        ];
                    }
                }
            }
            
            // Fallback: extract from warehouse name using city coordinates
            if (!$coordinates) {
                $whName = strtoupper($wh['name'] ?? '');
                foreach ($cityCoordinates as $city => $coords) {
                    if (str_contains($whName, $city)) {
                        $offset = (crc32($warehouseId ?? $wh['name'] ?? '') % 100) / 10000;
                        $coordinates = [
                            'lat' => $coords[0] + $offset,
                            'lng' => $coords[1] + $offset,
                        ];
                        break;
                    }
                }
            }
            
            // Determine point type for frontend based on warehouse_type
            $warehouseType = $wh['warehouse_type'] ?? $wh['type'] ?? '';
            $pointType = 'sc'; // default = sorting center
            
            // Map Ozon warehouse types to frontend types
            if (str_contains($warehouseType, 'DELIVERY_POINT') || str_contains($warehouseType, 'ORDERS_RECEIVING')) {
                $pointType = 'pvz'; // ПВЗ
            } elseif (str_contains($warehouseType, 'SORTING_CENTER')) {
                $pointType = 'sc'; // СЦ
            } elseif (str_contains($warehouseType, 'CROSS_DOCK')) {
                $pointType = 'crossdock'; // Кросс-док
            } elseif (str_contains($warehouseType, 'FULL_FILLMENT')) {
                $pointType = 'rfc'; // РФЦ
            }
            
            // Normalize warehouse_type to full Ozon format
            $fullWarehouseType = $warehouseType;
            if (!str_starts_with($warehouseType, 'WAREHOUSE_TYPE_')) {
                $fullWarehouseType = 'WAREHOUSE_TYPE_' . $warehouseType;
            }
            
            return [
                'id' => (string) ($wh['warehouse_id'] ?? $wh['id'] ?? null),
                'name' => $wh['name'] ?? null,
                'type' => $pointType,
                'warehouse_type' => $fullWarehouseType,
                'address' => is_array($wh['address'] ?? null)
                    ? ($wh['address']['address'] ?? null)
                    : ($wh['address'] ?? null),
                'city' => $wh['city'] ?? ($wh['address']['city'] ?? null),
                'region' => $wh['region'] ?? ($wh['address']['region'] ?? null),
                'cluster_id' => $wh['_cluster_id'] ?? $wh['macrolocal_cluster_id'] ?? ($wh['address']['macrolocal_cluster_id'] ?? null),
                'cluster_name' => $wh['_cluster_name'] ?? $wh['cluster_name'] ?? null,
                'coordinates' => $coordinates,
                'is_active' => $wh['is_active'] ?? true,
            ];
        }, $warehouses);

        $hasClusterInfo = collect($mapped)->contains(function ($wh) {
            return !empty($wh['cluster_id']) || !empty($wh['address']);
        });

        return $hasClusterInfo ? $mapped : $this->buildClusterWarehouses();
    }

    /**
     * Получить точки отгрузки для кросс-докинга (ПВЗ и СЦ)
     * 
     * POST /v1/warehouse/list
     * 
     * Возвращает ПВЗ (WAREHOUSE_TYPE_DELIVERY_POINT) и СЦ (WAREHOUSE_TYPE_SORTING_CENTER)
     * для отгрузки товаров при кросс-докинге.
     */
    public function getCrossdockDropOffPoints(string $search = ''): array
    {
        $body = [
            'filter_by_supply_type' => ['CREATE_TYPE_CROSSDOCK'],
        ];
        
        if (!empty($search) && strlen($search) >= 4) {
            $body['search'] = $search;
        }

        $response = $this->client->post('/v1/warehouse/list', $body);

        \Log::info('Ozon crossdock drop-off points response', [
            'request_body' => $body,
            'response' => $response,
        ]);

        if (!$response) {
            return [];
        }

        $result = $response['result'] ?? $response;
        $warehouses = $result['search'] ?? $result['warehouses'] ?? $result ?? [];
        
        return array_map(function($wh) {
            $rawCoords = $wh['coordinates'] ?? null;
            $coordinates = null;
            
            if (is_array($rawCoords)) {
                $lat = $rawCoords['latitude'] ?? null;
                $lng = $rawCoords['longitude'] ?? null;
                if ($lat !== null && $lng !== null) {
                    $coordinates = [
                        'lat' => (float) $lat,
                        'lng' => (float) $lng,
                    ];
                }
            }
            
            $warehouseType = $wh['warehouse_type'] ?? '';
            $pointType = 'sc';
            if (str_contains($warehouseType, 'DELIVERY_POINT')) {
                $pointType = 'pvz';
            }
            
            return [
                'id' => (string) ($wh['warehouse_id'] ?? null),
                'name' => $wh['name'] ?? null,
                'type' => $pointType,
                'warehouse_type' => $warehouseType,
                'address' => $wh['address'] ?? null,
                'coordinates' => $coordinates,
            ];
        }, $warehouses);
    }

    /**
     * Получить список складов продавца (для Pick Up)
     * 
     * POST /v1/warehouse/fbo/seller/list
     */
    public function getSellerWarehouses(): array
    {
        $response = $this->client->post('/v1/warehouse/fbo/seller/list', [], true);

        if (!$response) {
            return [];
        }

        $result = $response['result'] ?? $response;
        $warehouses = $result['warehouses'] ?? $result ?? [];
        
        return array_map(fn($wh) => [
            'id' => (string) ($wh['seller_warehouse_id'] ?? $wh['warehouse_id'] ?? $wh['id'] ?? null),
            'name' => $wh['seller_warehouse_name'] ?? $wh['name'] ?? null,
            'address' => $wh['address']['address'] ?? null,
            'city' => $wh['address']['city'] ?? null,
            'region' => $wh['address']['region'] ?? null,
            'cluster_id' => $wh['address']['macrolocal_cluster_id'] ?? null,
            'country_code' => $wh['address']['country_code'] ?? null,
            'timezone' => $wh['address']['timezone'] ?? null,
            'contacts' => $wh['contacts']['phone_numbers'] ?? [],
            'courier_comment' => $wh['courier_comment'] ?? null,
            'is_pickup' => $wh['is_pickup'] ?? false,
            'is_active' => $wh['is_active'] ?? true,
            'working_days' => $wh['working_days'] ?? [],
        ], $warehouses);
    }

    /**
     * Создать пропуск для поставки (данные водителя и ТС)
     * 
     * POST /v1/supply-order/pass/create
     */
    public function createSupplyOrderPass(string $supplyOrderId, array $vehicle): array
    {
        $body = [
            'supply_order_id' => (int) $supplyOrderId,
            'vehicle' => [
                'driver_name' => $vehicle['driver_name'] ?? '',
                'driver_phone' => $vehicle['driver_phone'] ?? '',
                'vehicle_model' => $vehicle['vehicle_model'] ?? null,
                'vehicle_number' => $vehicle['vehicle_number'] ?? '',
            ],
        ];

        $response = $this->client->post('/v1/supply-order/pass/create', $body);

        if (!$response || !empty($response['error'])) {
            throw new \RuntimeException(
                'Не удалось создать пропуск: ' .
                ($response['error']['message'] ?? 'Unknown error')
            );
        }

        return $response['result'] ?? $response;
    }

    /**
     * Получить таймслоты для прямой поставки (FBP)
     *
     * POST /v1/fbp/order/direct/timeslot/list
     */
    public function getFbpOrderDirectTimeslotList(string $supplyOrderId, array $payload = []): array
    {
        $body = [
            'supply_order_id' => (int) $supplyOrderId,
        ];

        if (!empty($payload['date_from'])) {
            $body['date_from'] = $payload['date_from'];
        }
        if (!empty($payload['date_to'])) {
            $body['date_to'] = $payload['date_to'];
        }

        \Illuminate\Support\Facades\Log::info('Ozon fbp/order/direct/timeslot/list request', ['body' => $body]);

        $maxAttempts = 1;
        $response = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($attempt > 1) {
                $delay = 2 * $attempt;
                \Illuminate\Support\Facades\Log::warning("Ozon timeslot/list rate limit, waiting {$delay}s before attempt {$attempt}");
                sleep($delay);
            }

            $response = $this->client->post('/v1/fbp/order/direct/timeslot/list', $body);

            $httpStatus = $response['_http_status'] ?? null;
            $errorCode = $response['code'] ?? null;

            if ($httpStatus !== 429 && (int) $errorCode !== 8) {
                break;
            }
        }

        \Illuminate\Support\Facades\Log::info('Ozon fbp/order/direct/timeslot/list response', [
            'order_id' => $supplyOrderId,
            'response_keys' => $response ? array_keys($response) : [],
        ]);

        if (!$response || !empty($response['error']) || !empty($response['code'])) {
            return $response ?? [];
        }

        return $response['result'] ?? $response;
    }

    /**
     * Получить подробную информацию о заявке на поставку (v1)
     * 
     * POST /v1/supply-order/details
     */
    public function getSupplyOrderDetailsV1(string $supplyOrderId): array
    {
        $response = $this->client->post('/v1/supply-order/details', [
            'order_id' => (int) $supplyOrderId,
        ]);

        \Illuminate\Support\Facades\Log::info('Ozon supply-order/details response', [
            'order_id' => $supplyOrderId,
            'response_keys' => $response ? array_keys($response) : [],
        ]);

        if (!$response || !empty($response['error']) || !empty($response['code'])) {
            return $response ?? [];
        }

        return $response['result'] ?? $response;
    }

    /**
     * Получить доступные таймслоты для заявки
     * 
     * POST /v1/supply-order/timeslot/get
     */
    public function getSupplyOrderTimeslot(string $supplyOrderId, array $payload = []): array
    {
        $body = [
            'supply_order_id' => (int) $supplyOrderId,
        ];
        
        // Добавляем даты если указаны
        if (!empty($payload['date_from'])) {
            $body['date_from'] = $payload['date_from'];
        }
        if (!empty($payload['date_to'])) {
            $body['date_to'] = $payload['date_to'];
        }

        \Illuminate\Support\Facades\Log::info('Ozon supply-order/timeslot/get request', ['body' => $body]);

        // Retry логика для rate limit
        $maxAttempts = 3;
        $response = null;
        
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($attempt > 1) {
                $delay = 2 * $attempt; // 4, 6 секунд
                \Illuminate\Support\Facades\Log::warning("Ozon timeslot/get rate limit, waiting {$delay}s before attempt {$attempt}");
                sleep($delay);
            }
            
            $response = $this->client->post('/v1/supply-order/timeslot/get', $body);
            
            $httpStatus = $response['_http_status'] ?? null;
            $errorCode = $response['code'] ?? null;
            
            if ($httpStatus !== 429 && (int) $errorCode !== 8) {
                break;
            }
        }

        \Illuminate\Support\Facades\Log::info('Ozon supply-order/timeslot/get response', [
            'order_id' => $supplyOrderId,
            'response_keys' => $response ? array_keys($response) : [],
            'first_timeslot' => ($response['result']['timeslots'][0] ?? $response['timeslots'][0] ?? null),
        ]);

        if (!$response || !empty($response['error']) || !empty($response['code'])) {
            return $response ?? [];
        }

        return $response['result'] ?? $response;
    }

    /**
     * Получить статус обновления таймслота заявки
     * 
     * POST /v1/supply-order/timeslot/status
     */
    public function getSupplyOrderTimeslotStatus(string $operationId): array
    {
        $response = $this->client->post('/v1/supply-order/timeslot/status', [
            'operation_id' => $operationId,
        ]);

        if (!$response || !empty($response['error'])) {
            throw new \RuntimeException(
                'Не удалось получить статус таймслота: ' .
                ($response['error']['message'] ?? 'Unknown error')
            );
        }

        return $response['result'] ?? $response;
    }

    /**
     * Установить таймслот для заявки FBO
     * 
     * POST /v1/fbp/draft/direct/timeslot/edit
     * 
     * Использует timeslot_start напрямую без поиска timeslot_id
     */
    public function setSupplyOrderTimeslot(string $supplyOrderId, string $timeslotFrom, string $timeslotTo): array
    {
        // Убедимся, что timestamp в правильном формате с Z
        if (!str_ends_with($timeslotFrom, 'Z')) {
            $timeslotFrom = rtrim($timeslotFrom, 'Z') . 'Z';
        }
        if (!str_ends_with($timeslotTo, 'Z')) {
            $timeslotTo = rtrim($timeslotTo, 'Z') . 'Z';
        }

        $body = [
            'supply_id' => $supplyOrderId,
            'timeslot_start' => $timeslotFrom,
        ];

        \Illuminate\Support\Facades\Log::info('Ozon fbp/draft/direct/timeslot/edit request', ['body' => $body]);

        $response = $this->client->post('/v1/fbp/draft/direct/timeslot/edit', $body);

        \Illuminate\Support\Facades\Log::info('Ozon fbp/draft/direct/timeslot/edit response', [
            'response_keys' => $response ? array_keys($response) : [],
            'has_error' => !empty($response['error']) || !empty($response['code']),
            'response' => $response,
        ]);

        return [
            'success' => empty($response['error']) && empty($response['code']),
            'response' => $response,
            'error' => $response['error'] ?? $response['message'] ?? null,
            '_http_status' => $response['_http_status'] ?? null,
        ];
    }

    /**
     * Редактирование состава заявки
     * 
     * POST /v1/supply-order/content/update
     */
    public function updateSupplyOrderContent(string $supplyOrderId, array $payload = []): array
    {
        $body = array_merge([
            'supply_order_id' => (int) $supplyOrderId,
        ], $payload);

        $response = $this->client->post('/v1/supply-order/content/update', $body);

        if (!$response || !empty($response['error'])) {
            throw new \RuntimeException(
                'Не удалось обновить состав заявки: ' .
                ($response['error']['message'] ?? 'Unknown error')
            );
        }

        return $response['result'] ?? $response;
    }

    /**
     * Проверить новый состав заявки
     * 
     * POST /v1/supply-order/content/update/validation
     */
    public function validateSupplyOrderContent(string $supplyOrderId, array $payload = []): array
    {
        $body = array_merge([
            'supply_order_id' => (int) $supplyOrderId,
        ], $payload);

        $response = $this->client->post('/v1/supply-order/content/update/validation', $body);

        if (!$response || !empty($response['error'])) {
            throw new \RuntimeException(
                'Не удалось проверить состав заявки: ' .
                ($response['error']['message'] ?? 'Unknown error')
            );
        }

        return $response['result'] ?? $response;
    }

    /**
     * Получить статус редактирования состава заявки
     * 
     * POST /v1/supply-order/content/update/status
     */
    public function getSupplyOrderContentUpdateStatus(string $operationId): array
    {
        $response = $this->client->post('/v1/supply-order/content/update/status', [
            'operation_id' => $operationId,
        ]);

        if (!$response || !empty($response['error'])) {
            throw new \RuntimeException(
                'Не удалось получить статус редактирования состава: ' .
                ($response['error']['message'] ?? 'Unknown error')
            );
        }

        return $response['result'] ?? $response;
    }

    /**
     * Сгенерировать акт приёмки (FBP)
     * 
     * POST /v1/fbp/act-from/create
     */
    public function createFbpAcceptanceAct(string $supplyOrderId, array $payload = []): array
    {
        $body = array_merge([
            'supply_order_id' => (int) $supplyOrderId,
        ], $payload);

        $response = $this->client->post('/v1/fbp/act-from/create', $body);

        if (!$response || !empty($response['error'])) {
            throw new \RuntimeException(
                'Не удалось создать акт приёмки: ' .
                ($response['error']['message'] ?? 'Unknown error')
            );
        }

        return $response['result'] ?? $response;
    }

    /**
     * Получить статус генерации акта приёмки (FBP)
     * 
     * POST /v1/fbp/act-from/get
     */
    public function getFbpAcceptanceActStatus(string $operationId): array
    {
        $response = $this->client->post('/v1/fbp/act-from/get', [
            'operation_id' => $operationId,
        ]);

        if (!$response || !empty($response['error'])) {
            throw new \RuntimeException(
                'Не удалось получить статус акта: ' .
                ($response['error']['message'] ?? 'Unknown error')
            );
        }

        return $response['result'] ?? $response;
    }

    /**
     * Отредактировать таймслот в черновике прямой поставки (FBP)
     * 
     * POST /v1/fbp/draft/direct/timeslot/edit
     */
    public function editFbpDirectDraftTimeslot(string $draftId, string|int $timeslotId, array $payload = []): array
    {
        $body = array_merge([
            'draft_id' => (string) $draftId,
            'timeslot_id' => (int) $timeslotId,
        ], $payload);

        $response = $this->client->post('/v1/fbp/draft/direct/timeslot/edit', $body);

        if (!$response || !empty($response['error'])) {
            throw new \RuntimeException(
                'Не удалось изменить таймслот черновика: ' .
                ($response['error']['message'] ?? 'Unknown error')
            );
        }

        return $response['result'] ?? $response;
    }

    /**
     * Получить статус создания пропуска
     * 
     * POST /v1/supply-order/pass/status
     */
    public function getSupplyOrderPassStatus(string $operationId): array
    {
        $response = $this->client->post('/v1/supply-order/pass/status', [
            'operation_id' => $operationId,
        ]);

        if (!$response || !empty($response['error'])) {
            throw new \RuntimeException(
                'Не удалось получить статус пропуска: ' .
                ($response['error']['message'] ?? 'Unknown error')
            );
        }

        return $response['result'] ?? $response;
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
     * Получить аналитику остатков по кластеру
     * 
     * Использует /v1/analytics/turnover/stocks и фильтрует по warehouse_ids кластера
     * 
     * @param string $clusterId ID кластера
     * @param array $warehouseIds ID складов кластера
     * @param array|null $allRecommendations Уже загруженные рекомендации (для оптимизации)
     * @return array ['sku_count' => int, 'units_count' => int, 'items' => array]
     */
    public function getClusterStockAnalytics(string $clusterId, array $warehouseIds = [], ?array $allRecommendations = null): array
    {
        // Если рекомендации не переданы — загружаем
        if ($allRecommendations === null) {
            $allRecommendations = $this->getSupplyRecommendations(1000, 0);
        }
        
        if (empty($allRecommendations)) {
            return ['sku_count' => 0, 'units_count' => 0, 'items' => []];
        }
        
        // Фильтруем только товары с рекомендацией к поставке
        $recommendedItems = array_filter($allRecommendations, function($item) {
            return ($item['recommended_qty'] ?? 0) > 0 
                || in_array($item['priority'] ?? '', ['critical', 'high']);
        });
        
        $skuCount = count($recommendedItems);
        $unitsCount = array_sum(array_column($recommendedItems, 'recommended_qty'));
        
        return [
            'sku_count' => $skuCount,
            'units_count' => $unitsCount,
            'items' => array_values($recommendedItems),
        ];
    }
    
    /**
     * Получить рекомендации товаров для кластера
     * 
     * @param string $clusterId ID кластера
     * @param int $days Период рекомендаций (не используется, для совместимости)
     * @return array Список рекомендованных товаров
     */
    public function getClusterRecommendations(string $clusterId, int $days = 28): array
    {
        $analytics = $this->getClusterStockAnalytics($clusterId);
        return $analytics['items'] ?? [];
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

    // ========================================================================
    // ДОПОЛНИТЕЛЬНЫЕ МЕТОДЫ ДЛЯ РАБОТЫ С ЗАЯВКАМИ
    // ========================================================================

    /**
     * Получить подробную информацию о заявке на поставку
     * 
     * POST /v2/supply-order/get
     * 
     * @param string $supplyId ID заявки на поставку
     * @return array Детальная информация о заявке
     */
    public function getSupplyOrderDetails(string $supplyId): array
    {
        $response = $this->client->post('/v2/supply-order/get', [
            'supply_id' => $supplyId,
        ]);

        if (!$response) {
            return [];
        }

        $result = $response['result'] ?? $response;
        
        return [
            'supply_id' => $result['supply_id'] ?? $supplyId,
            'status' => $result['status'] ?? null,
            'created_at' => $result['created_at'] ?? null,
            'updated_at' => $result['updated_at'] ?? null,
            'warehouse_id' => $result['warehouse_id'] ?? null,
            'warehouse_name' => $result['warehouse_name'] ?? null,
            'timeslot' => $result['timeslot'] ?? null,
            'items' => array_map(fn($item) => [
                'sku' => $item['sku'] ?? null,
                'offer_id' => $item['offer_id'] ?? null,
                'name' => $item['name'] ?? null,
                'quantity' => $item['quantity'] ?? 0,
                'accepted_quantity' => $item['accepted_quantity'] ?? null,
                'rejected_quantity' => $item['rejected_quantity'] ?? null,
            ], $result['items'] ?? []),
            'total_items' => $result['total_items'] ?? 0,
            'total_quantity' => $result['total_quantity'] ?? 0,
            'pass' => $result['pass'] ?? null,
            'cargoes_count' => $result['cargoes_count'] ?? 0,
        ];
    }

    /**
     * Получить зоны размещения товаров по SKU перед поставкой
     * 
     * POST /v1/supply/placement/get
     * 
     * Определяет, в какую зону попадёт товар:
     * - SORTABLE — сортируемый товар (обычный)
     * - OVERSIZED — негабаритный товар
     * - и другие зоны
     * 
     * @param array $skus Массив SKU товаров
     * @return array Массив с зонами размещения
     */
    public function getPlacementZones(array $skus): array
    {
        $response = $this->client->post('/v1/supply/placement/get', [
            'sku' => $skus,
        ]);

        if (!$response) {
            return [];
        }

        $placements = $response['placement_element'] ?? $response['result']['placement_element'] ?? [];
        
        return array_map(fn($item) => [
            'sku' => (string) ($item['sku'] ?? ''),
            'zone' => $item['zone'] ?? 'UNKNOWN',
            'zone_name' => $this->getZoneName($item['zone'] ?? ''),
        ], $placements);
    }

    /**
     * Получить человекочитаемое название зоны размещения
     */
    private function getZoneName(string $zone): string
    {
        return match ($zone) {
            'SORTABLE' => 'Сортируемый',
            'OVERSIZED' => 'Негабаритный',
            'JEWELRY' => 'Ювелирный',
            'VETERINARY' => 'Ветеринарный',
            'PALLET' => 'Паллетный',
            'TIRES' => 'Шины',
            'KGT' => 'Крупногабаритный',
            default => $zone,
        };
    }

    /**
     * Получить информацию о грузоместах заявки
     * 
     * POST /v1/cargoes/get
     * 
     * @param string $supplyId ID заявки на поставку
     * @return array Информация о грузоместах
     */
    public function getCargoesInfo(string $supplyId): array
    {
        $response = $this->client->post('/v1/cargoes/get', [
            'supply_id' => $supplyId,
        ]);

        if (!$response) {
            return [];
        }

        $result = $response['result'] ?? $response;
        $cargoes = $result['cargoes'] ?? [];
        
        return [
            'supply_id' => $result['supply_id'] ?? $supplyId,
            'cargoes' => array_map(fn($cargo) => [
                'cargo_id' => $cargo['cargo_id'] ?? null,
                'barcode' => $cargo['barcode'] ?? null,
                'status' => $cargo['status'] ?? null,
                'items' => array_map(fn($item) => [
                    'sku' => $item['sku'] ?? null,
                    'quantity' => $item['quantity'] ?? 0,
                ], $cargo['items'] ?? []),
                'dimensions' => [
                    'length' => $cargo['length'] ?? null,
                    'width' => $cargo['width'] ?? null,
                    'height' => $cargo['height'] ?? null,
                    'weight' => $cargo['weight'] ?? null,
                ],
            ], $cargoes),
            'total_cargoes' => count($cargoes),
        ];
    }

    /**
     * Получить информацию о ценах товаров
     * 
     * POST /v1/product/info/prices
     * 
     * @param array $skus Массив SKU товаров (до 1000)
     * @return array Информация о ценах
     */
    public function getProductPrices(array $skus): array
    {
        $response = $this->client->post('/v1/product/info/prices', [
            'sku' => array_slice($skus, 0, 1000),
        ]);

        if (!$response) {
            return [];
        }

        $items = $response['result'] ?? $response['items'] ?? [];
        
        return array_map(fn($item) => [
            'sku' => (string) ($item['sku'] ?? ''),
            'offer_id' => $item['offer_id'] ?? null,
            'price' => $item['price'] ?? null,
            'old_price' => $item['old_price'] ?? null,
            'premium_price' => $item['premium_price'] ?? null,
            'min_price' => $item['min_price'] ?? null,
            'marketing_price' => $item['marketing_price'] ?? null,
        ], $items);
    }

    /**
     * Получить список заявок на поставку
     * 
     * POST /v2/supply-order/list
     * 
     * @param array $filters [
     *   'status' => string[] — фильтр по статусам,
     *   'warehouse_ids' => string[] — фильтр по складам,
     *   'from_date' => string — дата начала (ISO 8601),
     *   'to_date' => string — дата окончания (ISO 8601),
     * ]
     * @param int $limit Количество записей (макс. 1000)
     * @param int $offset Смещение
     * @return array Список заявок
     */
    public function getSupplyOrderList(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $body = [
            'limit' => min($limit, 1000),
            'offset' => $offset,
        ];

        if (!empty($filters['status'])) {
            $body['status'] = $filters['status'];
        }
        if (!empty($filters['warehouse_ids'])) {
            $body['warehouse_ids'] = $filters['warehouse_ids'];
        }
        if (!empty($filters['from_date'])) {
            $body['from_date'] = $filters['from_date'];
        }
        if (!empty($filters['to_date'])) {
            $body['to_date'] = $filters['to_date'];
        }

        $response = $this->client->post('/v2/supply-order/list', $body);

        if (!$response) {
            return [];
        }

        $orders = $response['result']['orders'] ?? $response['orders'] ?? [];
        
        return array_map(fn($order) => [
            'supply_id' => $order['supply_id'] ?? null,
            'status' => $order['status'] ?? null,
            'created_at' => $order['created_at'] ?? null,
            'warehouse_id' => $order['warehouse_id'] ?? null,
            'warehouse_name' => $order['warehouse_name'] ?? null,
            'total_items' => $order['total_items'] ?? 0,
            'total_quantity' => $order['total_quantity'] ?? 0,
            'timeslot' => $order['timeslot'] ?? null,
        ], $orders);
    }

}
