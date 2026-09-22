<?php

namespace App\Domains\Ozon\Api;

use App\Domains\Marketplace\Contracts\SuppliesApiInterface;
use App\Exceptions\OzonAmbiguousRemoteStateException;
use App\Exceptions\OzonPreconditionException;

/**
 * API для работы с поставками Ozon (FBO Supplies)
 *
 * Старое API /v1/supply/* и /v1/draft/create* Ozon отключил (16.03.2026):
 * слотов без черновика и «черновика на склад» больше нет.
 *
 * Endpoints:
 * - POST /v1/cluster/list — список макролокальных кластеров
 * - POST /v1/draft/direct/create — черновик прямой поставки
 * - POST /v1/draft/crossdock/create — черновик кросс-док поставки
 * - POST /v1/draft/multi-cluster/create — черновик мультикластерной поставки
 * - POST /v2/draft/create/info — статус и расчёты черновика
 * - POST /v2/draft/timeslot/info — таймслоты для черновика
 * - POST /v2/draft/supply/create, /v2/draft/supply/create/status — заявка из черновика
 * - POST /v3/supply-order/list, /v3/supply-order/get — заявки на поставку
 * - POST /v1/cargoes/get — грузоместа в поставках FBO (бета)
 * - POST /v1/warehouse/fbo/list — точки отгрузки FBO
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
     * Список заявок: /v1/supply/order/list отключён, используйте
     * getSupplyOrdersList() + getSupplyOrdersDetails() (/v3/supply-order/*).
     */
    public function getSupplies(array $filters = []): array
    {
        $this->legacySupplyApiRemoved('список поставок /v1/supply/order/list');
    }

    /**
     * Получить детали заявки на поставку
     *
     * POST /v3/supply-order/get
     */
    public function getSupplyDetails(string $supplyId): ?array
    {
        $response = $this->getSupplyOrdersDetails([(int) $supplyId]);
        if (! empty($response['_error'])) {
            throw new \RuntimeException('Ozon /v3/supply-order/get: ' . json_encode($response, JSON_UNESCAPED_UNICODE));
        }

        $order = collect($response['orders'] ?? [])->first(
            fn (array $item): bool => (string) ($item['order_id'] ?? '') === $supplyId
        );

        return is_array($order) ? $this->mapSupply($order) : null;
    }

    /**
     * Товары заявки: /v1/supply/order/items отключён, состав заявки —
     * FboSupplyOrdersApi::getBundle() (/v1/supply-order/bundle).
     */
    public function getSupplyProducts(string $supplyId): array
    {
        $this->legacySupplyApiRemoved('товары поставки /v1/supply/order/items');
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
     * Слоты склада без черновика: /v1/supply/timeslot/list отключён, замены нет.
     * Слоты теперь есть только у черновика — getDraftTimeslots() (/v2/draft/timeslot/info).
     */
    public function getAcceptanceSlots(string $warehouseId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $this->legacySupplyApiRemoved('слоты склада без черновика /v1/supply/timeslot/list');
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
     * Черновик «на склад»: /v1/supply/draft/create отключён. Черновик теперь
     * создаётся на кластер по Ozon SKU — createDirectDraft()/createCrossdockDraft().
     */
    public function createSupplyDraft(array $data): array
    {
        $this->legacySupplyApiRemoved('черновик на склад /v1/supply/draft/create');
    }

    /**
     * /v1/supply/order/items/add отключён. Состав готовой заявки меняется через
     * /v1/supply-order/content/update — updateSupplyOrderContent().
     */
    public function addItemsToSupply(string $supplyId, array $items): bool
    {
        $this->legacySupplyApiRemoved('добавление товаров /v1/supply/order/items/add');
    }

    /**
     * Бронь слота по timeslot_id: /v1/supply/timeslot/set отключён. Слот выбирается
     * при создании заявки из черновика (createSupplyFromDraft), у готовой заявки —
     * /v1/supply-order/timeslot/update по интервалу from/to, а не по ID.
     */
    public function bookAcceptanceSlot(string $supplyId, string $slotId): array
    {
        $this->legacySupplyApiRemoved('бронь слота /v1/supply/timeslot/set');
    }

    private function legacySupplyApiRemoved(string $what): never
    {
        throw new \RuntimeException(
            "Ozon отключил {$what} (старое API поставок). Создавайте поставку через черновик в разделе «Поставки»."
        );
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
        // Методы интерфейса на старом /v1/supply/* отключены Ozon — только через черновик.
        $supported = [
            'get_supplies' => false,
            'get_supply_details' => true,
            'get_supply_products' => false,
            'get_warehouses' => true,
            'get_acceptance_slots' => false,
            'get_acceptance_coefficients' => false, // Ozon не использует КС
            'get_transit_tariffs' => false,
            'create_supply' => false,
            'add_items' => false,
            'book_slot' => false,
            'create_cargo' => true,
            'set_driver' => false,
        ];

        return $supported[$feature] ?? false;
    }

    /**
     * Маппинг заявки /v3/supply-order/get (orders[]) к унифицированному формату
     */
    private function mapSupply(array $order): array
    {
        $status = (string) ($order['state'] ?? 'UNSPECIFIED');
        $orderId = $order['order_id'] ?? null;
        $supply = $order['supplies'][0] ?? [];

        return [
            'id' => (string) $orderId,
            'external_id' => $orderId,
            'name' => $order['order_number'] ?? "Поставка #{$orderId}",
            'status' => self::SUPPLY_STATUSES[$status] ?? strtolower($status),
            'status_code' => $status,
            'marketplace' => 'ozon',
            'warehouse_id' => (string) ($supply['storage_warehouse']['warehouse_id'] ?? $order['dropoff_warehouse']['warehouse_id'] ?? ''),
            'warehouse_name' => $supply['storage_warehouse']['name'] ?? $order['dropoff_warehouse']['name'] ?? null,
            'macrolocal_cluster_id' => $supply['macrolocal_cluster_id'] ?? null,
            'created_at' => $order['created_date'] ?? null,
            'timeslot_from' => $order['timeslot']['timeslot']['from'] ?? null,
            'timeslot_to' => $order['timeslot']['timeslot']['to'] ?? null,
            'supply_type' => 'FBO',
            'supply_method' => ! empty($supply['is_crossdock']) ? 'crossdock' : 'direct',
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
            $warehouseTypes = [];
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
                    $warehouseTypes[$whId] = $whType;

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
            
            // id — ID кластера (так он хранится у нас: поставки, ozon_warehouse_clusters),
            // macrolocal_cluster_id — ID для /v1/draft/* и /v2/draft/* (resolveMacrolocalClusterId)
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
                'warehouse_types' => $warehouseTypes,
                'is_active' => true,
                'warehouses' => $acceptingWarehouses,
            ];
        }, $clusters);
    }

    /**
     * Загруженность/доступность складов FBO (этап 1, Ozon-синк ограничений).
     * POST /v1/supplier/available_warehouses — возвращает доступные для поставки склады
     * с расписанием: ёмкость приёмки (товаров/день) и ближайшую дату.
     *
     * Wire-формат (проверено по docs.ozon.ru / Go-клиенту):
     *   result[].warehouse.{id,name}
     *   result[].schedule.{date, capacity[].{start,end,value}}
     *
     * @return array<string, array{warehouse_id:string, warehouse_name:?string, capacity_per_day:int, nearest_date:?string}>
     */
    public function getWarehouseWorkload(): array
    {
        $response = $this->client->post('/v1/supplier/available_warehouses', [], true);

        if (! $response || ($response['_error'] ?? false)) {
            return [];
        }

        $out = [];
        foreach (($response['result'] ?? []) as $row) {
            $warehouse = $row['warehouse'] ?? [];
            $id = (string) ($warehouse['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $maxValue = 0;
            foreach (($row['schedule']['capacity'] ?? []) as $capacity) {
                $maxValue = max($maxValue, (int) ($capacity['value'] ?? 0));
            }

            $out[$id] = [
                'warehouse_id' => $id,
                'warehouse_name' => $warehouse['name'] ?? null,
                'capacity_per_day' => $maxValue,
                'nearest_date' => $row['schedule']['date'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Создать черновик прямой поставки в кластер
     *
     * POST /v1/draft/direct/create
     *
     * @param array $data [
     *   'cluster_id' | 'macrolocal_cluster_id' => string|int,
     *   'items' => [['sku' => int, 'quantity' => int], ...],
     *   'deletion_sku_mode' => 'PARTIAL'|'FULL' (по умолчанию PARTIAL),
     * ]
     */
    public function createDirectDraft(array $data): array
    {
        $items = $this->draftItems($data['items'] ?? []);
        $clusterId = (int) ($data['cluster_id'] ?? $data['macrolocal_cluster_id'] ?? 0);
        if ($clusterId <= 0 || $items === []) {
            throw new OzonPreconditionException('Для прямого черновика нужны cluster_id и товары.');
        }

        $result = $this->createDraftVia('/v1/draft/direct/create', [
            'cluster_info' => [
                'items' => $items,
                'macrolocal_cluster_id' => $this->resolveMacrolocalClusterId(0, $clusterId, null) ?? $clusterId,
            ],
            'deletion_sku_mode' => $data['deletion_sku_mode'] ?? 'PARTIAL',
        ]);

        return $result + [
            'supply_method' => 'direct',
            'macrolocal_cluster_id' => $data['macrolocal_cluster_id'] ?? null,
            'created_at' => now()->toIso8601String(),
        ];
    }

    /** @return list<array{sku:int, quantity:int}> */
    private function draftItems(array $items): array
    {
        return array_values(array_map(fn ($item) => [
            'sku' => (int) ($item['sku'] ?? $item['product_id'] ?? 0),
            'quantity' => (int) ($item['quantity'] ?? 0),
        ], $items));
    }

    /**
     * Общий шаг /v1/draft/{direct|crossdock|multi-cluster}/create: черновик создаётся
     * сразу (draft_id), а расчёт складов асинхронный — делаем один неблокирующий poll
     * /v2/draft/create/info, дальнейшее ожидание выполняет очередь ExecuteOzonSupplyDraftJob.
     *
     * draft_id отдаём только после status=SUCCESS (до этого слотов и складов нет),
     * id созданного черновика — в pending_draft_id, по нему дальше идёт poll.
     */
    private function createDraftVia(string $endpoint, array $body): array
    {
        \Illuminate\Support\Facades\Log::info("Ozon {$endpoint} request", ['body' => $body]);
        $response = $this->client->post($endpoint, $body);
        \Illuminate\Support\Facades\Log::info("Ozon {$endpoint} response", ['response' => $response]);

        if (! is_array($response) || ! empty($response['_error'])) {
            throw new OzonAmbiguousRemoteStateException(
                'Не удалось создать черновик поставки: ' . json_encode($response, JSON_UNESCAPED_UNICODE)
            );
        }

        $draftId = (int) ($response['draft_id'] ?? 0);
        $errors = $this->draftErrors($response['errors'] ?? []);
        if ($draftId <= 0) {
            // HTTP 200 без draft_id — Ozon отклонил состав, черновика нет.
            return [
                'draft_id' => null,
                'pending_draft_id' => null,
                'status' => 'failed',
                'errors' => $errors ?: ['Ozon не создал черновик.'],
            ];
        }

        return $this->pollDraftCreation((string) $draftId, $errors);
    }

    /**
     * Poll /v2/draft/create/info по id созданного черновика.
     *
     * @param list<string> $knownErrors ошибки из ответа create
     */
    public function pollDraftCreation(string $draftId, array $knownErrors = []): array
    {
        $info = $this->getDraftCreateInfo($draftId);
        $status = empty($info['_error']) ? ($info['status'] ?? null) : null;

        return [
            'draft_id' => $status === 'SUCCESS' ? $draftId : null,
            'pending_draft_id' => $draftId,
            'status' => match ($status) {
                'SUCCESS' => 'draft',
                'FAILED' => 'failed',
                default => 'pending',
            },
            'errors' => array_values(array_unique(array_merge($knownErrors, $this->draftErrors($info['errors'] ?? [])))),
            'draft_info' => $info,
        ];
    }

    /** @return list<string> */
    private function draftErrors(array $errors): array
    {
        $messages = [];
        foreach ($errors as $error) {
            foreach ($error['items_validation'] ?? [] as $validation) {
                foreach ($validation['rejected_items'] ?? [] as $item) {
                    foreach ($item['reasons'] ?? [] as $reason) {
                        $messages[] = $this->translateOzonError((string) $reason, $item['sku'] ?? 'unknown');
                    }
                }
            }
            foreach ($error['error_reasons'] ?? [] as $reason) {
                $messages[] = (string) $reason;
            }
            $message = $error['message'] ?? $error['error_message'] ?? null;
            if (is_string($message) && $message !== '') {
                $messages[] = $message;
            }
        }

        return array_values(array_unique($messages));
    }

    /**
     * Перевод причин отклонения товара (errors[].items_validation[].rejected_items[].reasons)
     */
    private function translateOzonError(string $reason, $sku): string
    {
        $translations = [
            'OUT_OF_ASSORTMENT' => "Товар SKU {$sku} не в ассортименте FBO (возможно, товар настроен только для FBS)",
            'INVALID' => "Товар SKU {$sku} недействителен",
            'INCOMPATIBLE_WAREHOUSE' => "Товар SKU {$sku} нельзя разместить на складах кластера",
            'MULTIPLICITY' => "Товар SKU {$sku}: количество не кратно квантам",
            'NO_PRICE' => "Товар SKU {$sku}: нет цены",
            'EMPTY_BARCODE' => "Товар SKU {$sku}: нет штрихкода",
            'SKU_IS_RESTRICTED' => "Товар SKU {$sku} ограничен к поставке",
        ];

        return $translations[$reason] ?? "Товар SKU {$sku}: {$reason}";
    }

    /**
     * Статус и расчёт черновика
     *
     * POST /v2/draft/create/info
     */
    public function getDraftCreateInfo(string $draftId): array
    {
        $response = $this->client->post('/v2/draft/create/info', [
            'draft_id' => (int) $draftId,
        ]);

        return $response ?? [];
    }

    /**
     * Получить доступные таймслоты для черновика
     *
     * POST /v2/draft/timeslot/info
     */
    public function getDraftTimeslots(
        int $draftId,
        int $warehouseId,
        ?int $clusterId = null,
        ?string $warehouseName = null,
        string $supplyMethod = 'direct'
    ): array {
        // Слоты на ближайшие 28 дней (максимум Ozon — 28 дней с текущей даты)
        $dateFrom = now()->toDateString();
        $dateTo = now()->addDays(27)->toDateString();

        $macrolocalClusterId = $this->resolveMacrolocalClusterId($warehouseId, $clusterId, $warehouseName);
        if (! $macrolocalClusterId) {
            \Illuminate\Support\Facades\Log::warning('Ozon draft timeslots: cluster_id not resolved', [
                'draft_id' => $draftId,
                'warehouse_id' => $warehouseId,
                'warehouse_name' => $warehouseName,
            ]);

            return [];
        }

        $supplyType = $this->ozonSupplyType($supplyMethod);
        $selected = ['macrolocal_cluster_id' => $macrolocalClusterId];
        if ($supplyType === 'DIRECT') {
            // storage_warehouse_id — только для прямых поставок
            $selected['storage_warehouse_id'] = $warehouseId;
        }

        $response = $this->client->post('/v2/draft/timeslot/info', [
            'draft_id' => $draftId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'supply_type' => $supplyType,
            'selected_cluster_warehouses' => [$selected],
        ]);

        \Illuminate\Support\Facades\Log::info('Ozon /v2/draft/timeslot/info response', [
            'draft_id' => $draftId,
            'warehouse_id' => $warehouseId,
            'macrolocal_cluster_id' => $macrolocalClusterId,
            'response' => $response,
        ]);

        if (! is_array($response) || ! empty($response['_error']) || ! empty($response['error_reason'])) {
            \Illuminate\Support\Facades\Log::warning('Ozon draft timeslots: Ozon не вернул слоты', [
                'draft_id' => $draftId,
                'error_reason' => $response['error_reason'] ?? null,
                'http_status' => $response['_http_status'] ?? null,
            ]);

            return [];
        }

        // result.drop_off_warehouse_timeslots — один объект: days[].timeslots[] в часовом поясе склада.
        $slots = [];
        foreach ($response['result']['drop_off_warehouse_timeslots']['days'] ?? [] as $day) {
            foreach ($day['timeslots'] ?? [] as $slot) {
                $from = $slot['from_in_timezone'] ?? null;
                $to = $slot['to_in_timezone'] ?? null;
                if (! $from || ! $to) {
                    continue;
                }

                $slots[] = [
                    // У слотов v2 нет ID — стабильный ключ из склада и интервала.
                    'id' => substr(sha1($warehouseId . '|' . $from . '|' . $to), 0, 32),
                    'warehouse_id' => (string) $warehouseId,
                    'cluster_id' => (string) ($clusterId ?? $macrolocalClusterId),
                    'date' => substr((string) ($day['date_in_timezone'] ?? $from), 0, 10),
                    'time_from' => substr($from, 11, 5),
                    'time_to' => substr($to, 11, 5),
                    'from_datetime' => $from,
                    'to_datetime' => $to,
                    'is_available' => true,
                    'capacity' => null,
                ];
            }
        }

        return $slots;
    }

    /** Тип поставки для /v2/draft/* (supply_type) по supply_method поставки. */
    private function ozonSupplyType(string $supplyMethod): string
    {
        return match (strtolower($supplyMethod)) {
            'crossdock' => 'CROSSDOCK',
            'multi_cluster', 'multi-cluster' => 'MULTI_CLUSTER',
            default => 'DIRECT',
        };
    }

    /**
     * Резолв macrolocal_cluster_id по складу или ID кластера из /v1/cluster/list
     * (для /v1/draft/* и /v2/draft/*). Возвращает id или null.
     */
    private function resolveMacrolocalClusterId(int $warehouseId, ?int $clusterId, ?string $warehouseName): ?int
    {
        $clusterIdHint = $clusterId
            ?? \App\Models\OzonWarehouseCluster::getClusterIdByWarehouse($warehouseName ?? '')
            ?? \App\Models\OzonWarehouseCluster::getClusterIdByWarehouse((string) $warehouseId);

        $macrolocalClusterId = null;
        foreach ($this->getClusters() as $cluster) {
            $warehouseIds = array_map('strval', $cluster['warehouse_ids'] ?? $cluster['all_warehouse_ids'] ?? []);
            $clusterIdValue = (string) ($cluster['id'] ?? '');
            $macroIdValue = (string) ($cluster['macrolocal_cluster_id'] ?? '');
            if (
                in_array((string) $warehouseId, $warehouseIds, true)
                || ($clusterIdHint && ((string) $clusterIdHint === $clusterIdValue || (string) $clusterIdHint === $macroIdValue))
            ) {
                $macrolocalClusterId = (int) ($cluster['macrolocal_cluster_id'] ?? $cluster['id'] ?? 0);
                break;
            }
        }
        if (!$macrolocalClusterId && $clusterIdHint) {
            $macrolocalClusterId = (int) $clusterIdHint;
        }

        return $macrolocalClusterId ?: null;
    }

    /**
     * Создать заявку на поставку из черновика.
     * v2: POST /v2/draft/supply/create (selected_cluster_warehouses + supply_type).
     *
     * ВНИМАНИЕ: money-path — создаёт реальную заявку на поставку в Ozon.
     * Перед прод-использованием проверить на тестовой интеграции.
     */
    public function createSupplyFromDraft(
        int $draftId,
        int $warehouseId,
        ?array $timeslot = null,
        ?int $clusterId = null,
        ?string $warehouseName = null,
        string $supplyType = 'DIRECT'
    ): array {
        $timeslotFrom = trim((string) ($timeslot['from'] ?? ''));
        $timeslotTo = trim((string) ($timeslot['to'] ?? ''));
        if ($timeslotFrom === '' || $timeslotTo === '') {
            throw new OzonPreconditionException(
                'Для создания заявки Ozon обязателен актуальный интервал поставки from/to.'
            );
        }
        $timeslotBody = [
            'from_in_timezone' => $timeslotFrom,
            'to_in_timezone' => $timeslotTo,
        ];
        $ozonSupplyType = $this->ozonSupplyType($supplyType);
        $macrolocalClusterId = $this->resolveMacrolocalClusterId($warehouseId, $clusterId, $warehouseName);

        if (! $macrolocalClusterId || $draftId <= 0 || $warehouseId <= 0) {
            throw new OzonPreconditionException(
                'Для создания заявки нужны draft_id, storage_warehouse_id и macrolocal_cluster_id.'
            );
        }

        $body = [
            'draft_id' => $draftId,
            'selected_cluster_warehouses' => [[
                'macrolocal_cluster_id' => (int) $macrolocalClusterId,
                'storage_warehouse_id' => (int) $warehouseId,
            ]],
            'supply_type' => $ozonSupplyType,
            'timeslot' => $timeslotBody,
        ];

        \Illuminate\Support\Facades\Log::info('Ozon v2/draft/supply/create request', ['body' => $body]);
        $response = $this->client->post('/v2/draft/supply/create', $body);
        \Illuminate\Support\Facades\Log::info('Ozon v2/draft/supply/create response', ['response' => $response]);

        if (! $response || ! empty($response['_error']) || ! empty($response['error_reasons']) || ! empty($response['code'])) {
            // Никакого fallback на другой create endpoint: ответ мог потеряться
            // после успешного создания, а второй POST дал бы дубль заявки.
            throw new OzonAmbiguousRemoteStateException(
                'Ozon не подтвердил создание заявки; требуется сверка кабинета перед повтором.'
            );
        }

        return $response;
    }

    /**
     * Получить статус создания заявки на поставку.
     * v2: POST /v2/draft/supply/create/status (ключ draft_id, не operation_id).
     */
    public function getSupplyCreateStatus(string $draftId): array
    {
        $response = $this->client->post('/v2/draft/supply/create/status', [
            'draft_id' => (int) $draftId,
        ]);

        \Illuminate\Support\Facades\Log::info('Ozon v2/draft/supply/create/status response', [
            'draft_id' => $draftId,
            'response' => $response,
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
     *   'macrolocal_cluster_id' => string (ID кластера),
     *   'delivery_scheme' => 'drop_off' | 'pick_up',
     *   'point_id' => string (для drop_off — ID точки из /v1/warehouse/fbo/list),
     *   'point_type' => string (для drop_off — тип точки; если нет — берём из /v1/cluster/list),
     *   'seller_warehouse_id' => string (для pick_up — ID склада продавца),
     *   'items' => [['sku' => int, 'quantity' => int], ...],
     * ]
     */
    public function createCrossdockDraft(array $data): array
    {
        $clusterId = (int) ($data['macrolocal_cluster_id'] ?? 0);
        $items = $this->draftItems($data['items'] ?? []);
        if ($clusterId <= 0 || $items === []) {
            throw new OzonPreconditionException('Для crossdock-черновика нужны кластер и товары.');
        }

        $result = $this->createDraftVia('/v1/draft/crossdock/create', [
            'cluster_info' => [
                'items' => $items,
                'macrolocal_cluster_id' => $this->resolveMacrolocalClusterId(0, $clusterId, null) ?? $clusterId,
            ],
            'deletion_sku_mode' => $data['deletion_sku_mode'] ?? 'PARTIAL',
            'delivery_info' => $this->draftDeliveryInfo($data),
        ]);

        return $result + [
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
     * Товары задаются по кластерам (clusters_info[].items), поэтому общий список
     * товаров принимаем только для одного кластера.
     *
     * @param array $data [
     *   'cluster_ids' => string[] (массив ID кластеров),
     *   'delivery_scheme' => 'drop_off' | 'pick_up',
     *   'point_id' => string (для drop_off),
     *   'point_type' => string (для drop_off),
     *   'seller_warehouse_id' => string (для pick_up),
     *   'items' => [['sku' => int, 'quantity' => int], ...],
     * ]
     */
    public function createMultiClusterDraft(array $data): array
    {
        $clusterIds = array_values(array_filter(array_map('intval', (array) ($data['cluster_ids'] ?? []))));
        $items = $this->draftItems($data['items'] ?? []);
        if (count($clusterIds) !== 1 || $items === []) {
            throw new OzonPreconditionException(
                'Для мультикластерного черновика нужен товарный состав по каждому кластеру; поддержан один кластер с товарами.'
            );
        }

        $result = $this->createDraftVia('/v1/draft/multi-cluster/create', [
            'clusters_info' => [[
                'items' => $items,
                'macrolocal_cluster_id' => $this->resolveMacrolocalClusterId(0, $clusterIds[0], null) ?? $clusterIds[0],
            ]],
            'deletion_sku_mode' => $data['deletion_sku_mode'] ?? 'PARTIAL',
            'delivery_info' => $this->draftDeliveryInfo($data),
        ]);

        return $result + [
            'supply_method' => 'multi_cluster',
            'delivery_scheme' => $data['delivery_scheme'] ?? 'drop_off',
            'cluster_ids' => $data['cluster_ids'] ?? [],
            'created_at' => now()->toIso8601String(),
        ];
    }

    /** delivery_info для /v1/draft/crossdock/create и /v1/draft/multi-cluster/create */
    private function draftDeliveryInfo(array $data): array
    {
        if (($data['delivery_scheme'] ?? 'drop_off') === 'pick_up') {
            $sellerWarehouseId = (int) ($data['seller_warehouse_id'] ?? 0);
            if ($sellerWarehouseId <= 0) {
                throw new OzonPreconditionException('Для отгрузки курьером (PICKUP) нужен склад продавца.');
            }

            return ['type' => 'PICKUP', 'seller_warehouse_id' => $sellerWarehouseId];
        }

        $pointId = (int) ($data['point_id'] ?? 0);
        $pointType = $pointId > 0 ? $this->dropOffWarehouseType($data['point_type'] ?? null, $pointId) : null;
        if ($pointType === null) {
            throw new OzonPreconditionException(
                'Для отгрузки в пункт приёма (DROPOFF) нужны ID и тип точки отгрузки (warehouse_type).'
            );
        }

        return [
            'type' => 'DROPOFF',
            'drop_off_warehouse' => ['warehouse_id' => $pointId, 'warehouse_type' => $pointType],
        ];
    }

    /**
     * Тип точки для delivery_info.drop_off_warehouse.warehouse_type. Принимает тип из
     * /v1/warehouse/fbo/list (WAREHOUSE_TYPE_*) или наш короткий (pvz/sc/crossdock/rfc);
     * если не передан — ищет точку среди складов /v1/cluster/list.
     */
    private function dropOffWarehouseType(?string $pointType, int $pointId): ?string
    {
        $allowed = ['DELIVERY_POINT', 'SORTING_CENTER', 'CROSS_DOCK', 'ORDERS_RECEIVING_POINT', 'FULL_FILLMENT'];
        $type = str_replace('WAREHOUSE_TYPE_', '', strtoupper(trim((string) $pointType)));
        $type = ['PVZ' => 'DELIVERY_POINT', 'SC' => 'SORTING_CENTER', 'CROSSDOCK' => 'CROSS_DOCK', 'RFC' => 'FULL_FILLMENT'][$type] ?? $type;
        if (in_array($type, $allowed, true)) {
            return $type;
        }

        foreach ($this->getClusters() as $cluster) {
            $type = $cluster['warehouse_types'][(string) $pointId] ?? null;
            if (in_array($type, $allowed, true)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Получить статус и расчёты черновика
     *
     * POST /v2/draft/create/info
     *
     * @param string $draftId ID черновика
     * @return array status (SUCCESS|IN_PROGRESS|FAILED), errors, clusters[].warehouses[]
     *               (storage_warehouse, availability_status, bundle_id) как в ответе Ozon
     */
    public function getDraftInfo(string $draftId): array
    {
        $response = $this->getDraftCreateInfo($draftId);

        if ($response === [] || ! empty($response['_error'])) {
            return [];
        }

        return [
            'draft_id' => $draftId,
            'status' => $response['status'] ?? 'UNSPECIFIED',
            'errors' => $response['errors'] ?? [],
            'clusters' => $response['clusters'] ?? [],
        ];
    }

    /**
     * Получить точки отгрузки для кросс-докинга (ПВЗ, СЦ, кросс-док)
     *
     * POST /v1/warehouse/fbo/list — поиск по названию, search обязателен (от 4 символов).
     * (/v1/warehouse/list — это склады FBS продавца, для точек FBO он не подходит.)
     */
    public function getCrossdockDropOffPoints(string $search = ''): array
    {
        $search = trim($search);
        if (mb_strlen($search) < 4) {
            // Без поиска Ozon точки не отдаёт — не зовём API заведомо невалидным запросом.
            return [];
        }

        $body = [
            'filter_by_supply_type' => ['CREATE_TYPE_CROSSDOCK'],
            'search' => $search,
        ];

        $response = $this->client->post('/v1/warehouse/fbo/list', $body);

        \Log::info('Ozon crossdock drop-off points response', [
            'request_body' => $body,
            'response' => $response,
        ]);

        if (! is_array($response) || ! empty($response['_error'])) {
            \Log::warning('Ozon /v1/warehouse/fbo/list: ошибка, точек отгрузки нет', [
                'http_status' => $response['_http_status'] ?? null,
            ]);

            return [];
        }

        $warehouses = $response['search'] ?? [];

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

    // ========================================================================
    // ДОПОЛНИТЕЛЬНЫЕ МЕТОДЫ ДЛЯ РАБОТЫ С ЗАЯВКАМИ
    // ========================================================================

    // ponytail: getSupplyOrderDetails() на POST /v2/supply-order/get удалён.
    // Вызывающих не было, а трекинг читает заявки через getSupplyOrdersDetails()
    // на /v3/supply-order/get — держать рядом мёртвый v2-путь опасно.

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

}
