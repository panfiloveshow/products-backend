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
                'ACCEPTING',
                'ACCEPTANCE',
                'ACCEPTED',
                'PARTIALLY_ACCEPTED',
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
     * Получить товары заявки напрямую
     * POST /v1/supply-order/items
     */
    public function getItems(int $supplyOrderId): array
    {
        Log::info('Ozon FBO supply-order/items request', [
            'supply_order_id' => $supplyOrderId,
        ]);
        
        $response = $this->client->post('/v1/supply-order/items', [
            'supply_order_id' => $supplyOrderId,
        ]);

        Log::info('Ozon FBO supply-order/items response', [
            'supply_order_id' => $supplyOrderId,
            'items_count' => count($response['items'] ?? []),
        ]);

        return $response['items'] ?? [];
    }

    /**
     * Отмена заявки на поставку
     */
    public function cancel(int $supplyOrderId): array
    {
        $response = $this->client->post('/v1/supply-order/cancel', [
            'supply_order_id' => $supplyOrderId,
        ]);

        Log::info('Ozon FBO supply-order/cancel', [
            'supply_order_id' => $supplyOrderId,
            'response' => $response,
        ]);

        return [
            'operation_id' => $response['operation_id'] ?? null,
            'success' => !empty($response['operation_id']),
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
}
