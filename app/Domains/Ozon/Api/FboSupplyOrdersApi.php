<?php

namespace App\Domains\Ozon\Api;

use Illuminate\Support\Facades\Log;

/**
 * API для работы с заявками на поставку FBO
 * 
 * Endpoints:
 * - POST /v3/supply-order/list — список заявок
 * - POST /v3/supply-order/get — детали заявок
 * - POST /v1/supply-order/bundle — состав заявки
 * - POST /v1/supply-order/cancel — отмена заявки
 * - POST /v1/supply-order/cancel/status — статус отмены
 * - POST /v1/supply-order/status/counter — счётчики по статусам
 */
class FboSupplyOrdersApi
{
    public function __construct(
        private OzonClient $client
    ) {}

    /**
     * Получить список заявок на поставку
     */
    public function list(array $states = [], int $limit = 100, ?string $lastId = null): array
    {
        if (empty($states)) {
            $states = [
                'DATA_FILLING',
                'READY_TO_SUPPLY',
                'IN_TRANSIT',
                'AT_WAREHOUSE',
                'ACCEPTED_AT_SUPPLY_WAREHOUSE',
                'ACCEPTING',
                'ACCEPTANCE',
                'ACCEPTANCE_AT_STORAGE_WAREHOUSE',
                'REPORTS_CONFIRMATION_AWAITING',
                'REPORT_REJECTED',
                'ACCEPTED',
                'COMPLETED',
                'PARTIALLY_ACCEPTED',
                'REJECTED_AT_SUPPLY_WAREHOUSE',
                'CANCELLED',
            ];
        }

        $body = [
            'filter' => ['states' => $states],
            'limit' => $limit,
            'sort_by' => 'ORDER_CREATION',
            'sort_dir' => 'DESC',
        ];

        if ($lastId) {
            $body['last_id'] = $lastId;
        }

        Log::info('Ozon FBO supply-order/list request', ['body' => $body]);

        $response = $this->client->post('/v3/supply-order/list', $body);

        Log::info('Ozon FBO supply-order/list response', [
            'states' => $states,
            'count' => count($response['order_ids'] ?? []),
        ]);

        return [
            'order_ids' => $response['order_ids'] ?? [],
            'last_id' => $response['last_id'] ?? null,
        ];
    }

    /**
     * Получить детали заявок
     */
    public function get(array $orderIds): array
    {
        $response = $this->client->post('/v3/supply-order/get', [
            'order_ids' => array_map('intval', $orderIds),
        ]);

        Log::info('Ozon FBO supply-order/get response', [
            'order_ids' => $orderIds,
            'orders_count' => count($response['orders'] ?? []),
        ]);

        return $response ?? [];
    }

    /**
     * Получить состав заявки (товары)
     * Ozon API: POST /v1/supply-order/bundle
     * Требует bundle_ids - массив UUID бандлов из supplies[].bundle_id
     */
    public function getBundle(int $supplyOrderId): array
    {
        Log::info('Ozon FBO supply-order/bundle request', [
            'supply_order_id' => $supplyOrderId,
        ]);
        
        // Сначала получаем детали заявки чтобы извлечь bundle_ids
        $orderDetails = $this->get([$supplyOrderId]);
        $orders = $orderDetails['orders'] ?? [];
        $order = $orders[0] ?? null;
        
        if (!$order) {
            Log::warning('Ozon FBO supply-order/bundle: order not found', [
                'supply_order_id' => $supplyOrderId,
            ]);
            return [];
        }
        
        // Извлекаем bundle_id (UUID) из supplies
        $bundleIds = [];
        $supplies = $order['supplies'] ?? [];
        foreach ($supplies as $supply) {
            // bundle_id - это UUID строка на уровне supply
            if (!empty($supply['bundle_id'])) {
                $bundleIds[] = $supply['bundle_id'];
            }
        }
        
        if (empty($bundleIds)) {
            Log::info('Ozon FBO supply-order/bundle: no bundle_ids found', [
                'supply_order_id' => $supplyOrderId,
                'supplies_count' => count($supplies),
                'first_supply_keys' => isset($supplies[0]) ? array_keys($supplies[0]) : [],
            ]);
            return [];
        }
        
        Log::info('Ozon FBO supply-order/bundle: calling API', [
            'supply_order_id' => $supplyOrderId,
            'bundle_ids' => $bundleIds,
        ]);
        
        $response = $this->client->post('/v1/supply-order/bundle', [
            'bundle_ids' => $bundleIds,
            'limit' => 100,
        ]);

        Log::info('Ozon FBO supply-order/bundle response', [
            'supply_order_id' => $supplyOrderId,
            'response_keys' => $response ? array_keys($response) : [],
            'bundles_count' => count($response['bundles'] ?? []),
        ]);

        return $response ?? [];
    }

    /**
     * Отмена заявки на поставку
     */
    public function cancel(int $supplyOrderId): array
    {
        $response = $this->client->post('/v1/supply-order/cancel', [
            'order_id' => $supplyOrderId,
        ]);

        Log::info('Ozon FBO supply-order/cancel', [
            'supply_order_id' => $supplyOrderId,
            'response' => $response,
        ]);

        return [
            'operation_id' => $response['operation_id'] ?? null,
            'success' => !empty($response['operation_id']),
            'error' => $response['error'] ?? null,
            '_http_status' => $response['_http_status'] ?? null,
            'response' => $response,
        ];
    }

    /**
     * Проверка статуса отмены
     */
    public function getCancelStatus(string $operationId): array
    {
        $response = $this->client->post('/v1/supply-order/cancel/status', [
            'operation_id' => $operationId,
        ]);

        return $response ?? [];
    }

    /**
     * Получение счётчиков по статусам
     */
    public function getStatusCounters(): array
    {
        $response = $this->client->post('/v1/supply-order/status/counter', []);

        Log::info('Ozon FBO status/counter response', ['response' => $response]);

        return $response['counters'] ?? $response ?? [];
    }

    // Черновики и заявки из черновика (/v1/draft/*/create, /v2/draft/*) — в SuppliesApi.

    /**
     * Получить информацию о черновике с доступностью складов
     * POST /v2/draft/create/info (обновлённый API с availability_status)
     */
    public function getDraftInfo(string $draftId): array
    {
        $response = $this->client->post('/v2/draft/create/info', [
            'draft_id' => (int) $draftId,
        ]);

        Log::info('Ozon FBO v2/draft/create/info response', ['draft_id' => $draftId, 'response' => $response]);

        $result = $response['result'] ?? $response;

        // Нормализуем структуру — преобразуем availability_status в is_available
        $clusters = $result['clusters'] ?? [];
        foreach ($clusters as &$cluster) {
            if (isset($cluster['warehouses'])) {
                foreach ($cluster['warehouses'] as &$warehouse) {
                    // state: FULL_AVAILABLE, PARTIAL_AVAILABLE, NOT_AVAILABLE, UNSPECIFIED
                    $state = $warehouse['availability_status']['state'] ?? 'UNSPECIFIED';
                    $warehouse['is_available'] = in_array($state, ['FULL_AVAILABLE', 'PARTIAL_AVAILABLE'], true);
                    $warehouse['invalid_reason'] = $warehouse['availability_status']['invalid_reason'] ?? null;
                }
            }
        }

        return [
            'clusters' => $clusters,
            'draft_id' => $result['draft_id'] ?? $draftId,
            'status' => $result['status'] ?? null,
            'total_items_count' => $result['total_items_count'] ?? 0,
        ];
    }

    /**
     * Изменить таймслот заявки на поставку
     * POST /v1/fbp/order/direct/timeslot/edit
     * 
     * @param int $supplyOrderId ID заявки на поставку
     * @param string $timeslotFrom Начало таймслота (ISO 8601 с Z)
     * @param string $timeslotTo Конец таймслота (ISO 8601 с Z)
     * @return array
     */
    public function editTimeslot(int $supplyOrderId, string $timeslotFrom, string $timeslotTo): array
    {
        // Убедимся, что timestamp в правильном формате с Z
        if (!str_ends_with($timeslotFrom, 'Z')) {
            $timeslotFrom = rtrim($timeslotFrom, 'Z') . 'Z';
        }
        if (!str_ends_with($timeslotTo, 'Z')) {
            $timeslotTo = rtrim($timeslotTo, 'Z') . 'Z';
        }

        $body = [
            'supply_id' => (string) $supplyOrderId,
            'timeslot_start' => $timeslotFrom,
            'timeslot_end' => $timeslotTo,
        ];

        Log::info('Ozon FBO fbp/order/direct/timeslot/edit request', ['body' => $body]);

        $response = $this->client->post('/v1/fbp/order/direct/timeslot/edit', $body);

        Log::info('Ozon FBO fbp/order/direct/timeslot/edit response', ['response' => $response]);

        return [
            'success' => empty($response['error']) && empty($response['code']),
            'response' => $response,
            'error' => $response['error'] ?? $response['message'] ?? null,
            '_http_status' => $response['_http_status'] ?? null,
        ];
    }
}
