<?php

namespace App\Domains\Ozon\Api;

use App\Domains\Marketplace\Contracts\SuppliesApiInterface;

/**
 * API для работы с поставками Ozon (FBO Supplies)
 * 
 * Endpoints:
 * - POST /v1/supply/order/list — список заказов на поставку
 * - POST /v1/supply/order/get — детали заказа на поставку
 * - POST /v1/supply/draft/create — создать черновик поставки
 * - POST /v1/supply/timeslot/list — доступные слоты
 * - POST /v1/supply/timeslot/set — забронировать слот
 * - POST /v1/supply/cargo/create — создать грузоместа
 * - POST /v1/supply/driver/set — назначить водителя
 * - POST /v1/warehouse/list — список складов
 * 
 * @see https://docs.ozon.ru/api/seller
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
            'created_at' => $order['created_at'] ?? null,
            'timeslot_from' => $order['timeslot']['from'] ?? null,
            'timeslot_to' => $order['timeslot']['to'] ?? null,
            'items_count' => $order['items_count'] ?? 0,
            'total_quantity' => $order['total_quantity'] ?? 0,
            'supply_type' => $order['supply_type'] ?? 'FBO',
            'raw_data' => $order,
        ];
    }
}
