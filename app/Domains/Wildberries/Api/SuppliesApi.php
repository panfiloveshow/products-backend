<?php

namespace App\Domains\Wildberries\Api;

use App\Domains\Marketplace\Contracts\SuppliesApiInterface;

/**
 * API для работы с поставками Wildberries (FBW Supplies)
 * 
 * Поддерживаемые операции (чтение):
 * - POST /api/v1/supplies — список поставок
 * - GET /api/v1/supplies/{ID} — детали поставки
 * - GET /api/v1/supplies/{ID}/goods — товары в поставке
 * - GET /api/v1/warehouses — список складов
 * - GET /api/tariffs/v1/acceptance/coefficients — слоты приёмки на 14 дней
 * - GET /api/v1/transit-tariffs — тарифы транзита
 * 
 * НЕ поддерживается через API (только через ЛК WB):
 * - Создание поставок
 * - Бронирование слотов приёмки
 * - Добавление товаров в поставку
 * 
 * Для проверки доступности слота используйте getAvailableAcceptanceSlots():
 * - coefficient = 0 или 1 И allowUnload = true → слот доступен
 * 
 * @see https://dev.wildberries.ru/openapi/orders-fbw
 * @see https://dev.wildberries.ru/openapi/wb-tariffs
 */
class SuppliesApi implements SuppliesApiInterface
{
    private const BASE_URL = 'https://supplies-api.wildberries.ru';
    
    /**
     * Статусы поставок WB
     */
    private const SUPPLY_STATUSES = [
        1 => 'not_planned',      // Не запланирована
        2 => 'planned',          // Запланирована
        3 => 'unloading_allowed', // Разрешена разгрузка
        4 => 'accepting',        // Идёт приёмка
        5 => 'accepted',         // Принята
        6 => 'unloaded_at_gate', // Разгружена у ворот
    ];

    public function __construct(
        private WildberriesClient $client
    ) {}

    /**
     * Получить список поставок
     * 
     * GET /api/v1/supplies
     * 
     * @param array $filters [
     *   'limit' => int (default 1000),
     *   'offset' => int (default 0),
     *   'statuses' => int[] (1-6),
     *   'date_from' => string (Y-m-d),
     *   'date_to' => string (Y-m-d),
     * ]
     */
    public function getSupplies(array $filters = []): array
    {
        $queryParams = [
            'limit' => $filters['limit'] ?? 1000,
            'offset' => $filters['offset'] ?? 0,
        ];

        $body = [];
        
        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $body['dateFrom'] = $filters['date_from'] ?? null;
            $body['dateTo'] = $filters['date_to'] ?? null;
        }
        
        if (!empty($filters['statuses'])) {
            $body['statuses'] = $filters['statuses'];
        }

        $response = $this->client->post(
            self::BASE_URL . '/api/v1/supplies',
            $body,
            $queryParams
        );

        if (!$response) {
            return [];
        }

        $supplies = $response['supplies'] ?? [];
        
        return array_map(fn($supply) => $this->mapSupply($supply), $supplies);
    }

    /**
     * Получить детали поставки
     * 
     * GET /api/v1/supplies/{ID}
     */
    public function getSupplyDetails(string $supplyId): ?array
    {
        $response = $this->client->get(
            self::BASE_URL . "/api/v1/supplies/{$supplyId}"
        );

        if (!$response) {
            return null;
        }

        return $this->mapSupply($response);
    }

    /**
     * Получить товары в поставке
     * 
     * GET /api/v1/supplies/{ID}/goods
     */
    public function getSupplyProducts(string $supplyId): array
    {
        $response = $this->client->get(
            self::BASE_URL . "/api/v1/supplies/{$supplyId}/goods"
        );

        if (!$response) {
            return [];
        }

        $goods = $response['goods'] ?? [];
        
        return array_map(fn($item) => [
            'sku' => $item['barcode'] ?? $item['sku'] ?? null,
            'nm_id' => $item['nmId'] ?? null,
            'name' => $item['name'] ?? null,
            'quantity' => $item['quantity'] ?? 0,
            'quantity_accepted' => $item['quantityAccepted'] ?? 0,
            'quantity_rejected' => $item['quantityRejected'] ?? 0,
            'price' => $item['price'] ?? 0,
            'total_price' => $item['totalPrice'] ?? 0,
        ], $goods);
    }

    /**
     * Получить упаковку поставки
     * 
     * GET /api/v1/supplies/{ID}/package
     */
    public function getSupplyPackage(string $supplyId): array
    {
        $response = $this->client->get(
            self::BASE_URL . "/api/v1/supplies/{$supplyId}/package"
        );

        if (!$response) {
            return [];
        }

        return [
            'boxes_count' => $response['boxesCount'] ?? 0,
            'pallets_count' => $response['palletsCount'] ?? 0,
            'total_weight' => $response['totalWeight'] ?? 0,
            'total_volume' => $response['totalVolume'] ?? 0,
            'boxes' => $response['boxes'] ?? [],
        ];
    }

    /**
     * Получить список доступных складов
     * 
     * GET /api/v1/warehouses
     */
    public function getAvailableWarehouses(): array
    {
        $response = $this->client->get(
            self::BASE_URL . '/api/v1/warehouses'
        );

        if (!$response) {
            return [];
        }

        return array_map(fn($wh) => [
            'id' => (string) ($wh['ID'] ?? $wh['id'] ?? null),
            'name' => $wh['name'] ?? null,
            'address' => $wh['address'] ?? null,
            'city' => $wh['city'] ?? null,
            'accepts_cargo' => $wh['acceptsCargo'] ?? true,
            'accepts_qr' => $wh['acceptsQR'] ?? false,
            'work_time' => $wh['workTime'] ?? null,
            'cargo_types' => $wh['cargoTypes'] ?? [],
        ], $response);
    }

    /**
     * Получить опции приёмки (слоты)
     * 
     * GET /api/v1/acceptance/options
     */
    public function getAcceptanceSlots(string $warehouseId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $queryParams = [
            'warehouseId' => $warehouseId,
        ];
        
        if ($dateFrom) {
            $queryParams['dateFrom'] = $dateFrom;
        }
        if ($dateTo) {
            $queryParams['dateTo'] = $dateTo;
        }

        $response = $this->client->get(
            self::BASE_URL . '/api/v1/acceptance/options',
            $queryParams
        );

        if (!$response) {
            return [];
        }

        $options = $response['options'] ?? $response;
        
        return array_map(fn($slot) => [
            'id' => $slot['id'] ?? null,
            'warehouse_id' => $warehouseId,
            'date' => $slot['date'] ?? null,
            'time_from' => $slot['timeFrom'] ?? null,
            'time_to' => $slot['timeTo'] ?? null,
            'coefficient' => $slot['coefficient'] ?? 1.0,
            'is_available' => $slot['isAvailable'] ?? true,
            'boxes_limit' => $slot['boxesLimit'] ?? null,
            'pallets_limit' => $slot['palletsLimit'] ?? null,
        ], is_array($options) ? $options : []);
    }

    /**
     * Получить коэффициенты приёмки по складам
     * 
     * Использует common-api.wildberries.ru/api/v1/tariffs/box
     */
    public function getAcceptanceCoefficients(): array
    {
        $response = $this->client->get(
            'https://common-api.wildberries.ru/api/v1/tariffs/box'
        );

        if (!$response) {
            return [];
        }

        $result = [];
        $warehouses = $response['response']['data']['warehouseList'] ?? [];
        
        foreach ($warehouses as $wh) {
            $warehouseId = (string) ($wh['warehouseID'] ?? $wh['warehouseId'] ?? null);
            if (!$warehouseId) continue;
            
            $result[$warehouseId] = [
                'warehouse_id' => $warehouseId,
                'warehouse_name' => $wh['warehouseName'] ?? null,
                'box_delivery_base' => $wh['boxDeliveryBase'] ?? 0,
                'box_delivery_liter' => $wh['boxDeliveryLiter'] ?? 0,
                'box_storage_base' => $wh['boxStorageBase'] ?? 0,
                'box_storage_liter' => $wh['boxStorageLiter'] ?? 0,
                'coefficient' => $wh['coefficient'] ?? 1.0,
            ];
        }

        return $result;
    }

    /**
     * Получить тарифы транзита
     * 
     * GET /api/v1/transit-tariffs
     */
    public function getTransitTariffs(): array
    {
        $response = $this->client->get(
            self::BASE_URL . '/api/v1/transit-tariffs'
        );

        if (!$response) {
            return [];
        }

        return array_map(fn($tariff) => [
            'from_warehouse_id' => $tariff['fromWarehouseId'] ?? null,
            'to_warehouse_id' => $tariff['toWarehouseId'] ?? null,
            'from_warehouse_name' => $tariff['fromWarehouseName'] ?? null,
            'to_warehouse_name' => $tariff['toWarehouseName'] ?? null,
            'cost_per_liter' => $tariff['costPerLiter'] ?? 0,
            'min_cost' => $tariff['minCost'] ?? 0,
        ], $response['tariffs'] ?? $response);
    }

    /**
     * Создать черновик поставки
     * 
     * WB API не поддерживает создание поставок через API напрямую.
     * Поставки создаются в ЛК WB.
     * 
     * @throws \RuntimeException
     */
    public function createSupplyDraft(array $data): array
    {
        throw new \RuntimeException(
            'Wildberries не поддерживает создание поставок через API. ' .
            'Создайте поставку в личном кабинете WB.'
        );
    }

    /**
     * Добавить товары в поставку
     * 
     * WB API не поддерживает добавление товаров через API.
     */
    public function addItemsToSupply(string $supplyId, array $items): bool
    {
        throw new \RuntimeException(
            'Wildberries не поддерживает добавление товаров в поставку через API.'
        );
    }

    /**
     * Забронировать слот приёмки для FBW поставки
     * 
     * WB FBW API не поддерживает бронирование слотов напрямую через API.
     * Бронирование выполняется в личном кабинете WB.
     * 
     * Однако можно проверить доступность слота через getAcceptanceSlots()
     * где allowUnload=true и coefficient=0 или 1 означает доступный слот.
     * 
     * @throws \RuntimeException
     */
    public function bookAcceptanceSlot(string $supplyId, string $slotId): array
    {
        throw new \RuntimeException(
            'Wildberries FBW не поддерживает бронирование слотов через API. ' .
            'Забронируйте слот в личном кабинете WB: https://seller.wildberries.ru/supplies-management/all-supplies'
        );
    }

    /**
     * Получить доступные слоты приёмки на 14 дней вперёд
     * 
     * Использует новый API тарифов: GET /api/tariffs/v1/acceptance/coefficients
     * Слот доступен если: coefficient = 0 или 1 И allowUnload = true
     * 
     * @param string|null $warehouseId ID склада (опционально)
     * @return array Массив доступных слотов
     */
    public function getAvailableAcceptanceSlots(?string $warehouseId = null): array
    {
        $params = [];
        if ($warehouseId) {
            $params['warehouseIDs'] = $warehouseId;
        }

        $response = $this->client->commonGet('/api/tariffs/v1/acceptance/coefficients', $params);

        if (!$response) {
            return [];
        }

        $slots = [];
        foreach ($response as $item) {
            // Слот доступен если coefficient = 0 или 1 И allowUnload = true
            $coefficient = $item['coefficient'] ?? -1;
            $allowUnload = $item['allowUnload'] ?? false;
            
            $isAvailable = in_array($coefficient, [0, 1]) && $allowUnload === true;
            
            $slots[] = [
                'date' => $item['date'] ?? null,
                'warehouse_id' => (string) ($item['warehouseID'] ?? null),
                'warehouse_name' => $item['warehouseName'] ?? null,
                'coefficient' => $coefficient,
                'allow_unload' => $allowUnload,
                'is_available' => $isAvailable,
                'box_type_id' => $item['boxTypeID'] ?? null,
                'is_sorting_center' => $item['isSortingCenter'] ?? false,
                'storage_coefficient' => $item['storageCoef'] ?? null,
                'delivery_coefficient' => $item['deliveryCoef'] ?? null,
                'delivery_base_liter' => $item['deliveryBaseLiter'] ?? null,
                'delivery_additional_liter' => $item['deliveryAdditionalLiter'] ?? null,
                'storage_base_liter' => $item['storageBaseLiter'] ?? null,
                'storage_additional_liter' => $item['storageAdditionalLiter'] ?? null,
            ];
        }

        return $slots;
    }

    /**
     * Получить только доступные слоты (отфильтрованные)
     */
    public function getOnlyAvailableSlots(?string $warehouseId = null): array
    {
        $allSlots = $this->getAvailableAcceptanceSlots($warehouseId);
        
        return array_filter($allSlots, fn($slot) => $slot['is_available'] === true);
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
     * 
     * WB FBW API поддерживает:
     * - Чтение поставок и их деталей
     * - Получение слотов приёмки (через tariffs API)
     * - Получение складов
     * 
     * WB FBW API НЕ поддерживает:
     * - Создание поставок (только через ЛК)
     * - Бронирование слотов (только через ЛК)
     * - Добавление товаров (только через ЛК)
     */
    public function supportsFeature(string $feature): bool
    {
        $supported = [
            'get_supplies' => true,
            'get_supply_details' => true,
            'get_supply_products' => true,
            'get_warehouses' => true,
            'get_acceptance_slots' => true,       // Через tariffs API
            'get_available_slots' => true,        // Через tariffs API с фильтрацией
            'get_acceptance_coefficients' => true,
            'get_transit_tariffs' => true,
            'create_supply' => false,             // Только через ЛК WB
            'add_items' => false,                 // Только через ЛК WB
            'book_slot' => false,                 // Только через ЛК WB
        ];

        return $supported[$feature] ?? false;
    }

    /**
     * Маппинг поставки WB к унифицированному формату
     */
    private function mapSupply(array $supply): array
    {
        $statusCode = $supply['status'] ?? 1;
        
        return [
            'id' => (string) ($supply['ID'] ?? $supply['id'] ?? null),
            'external_id' => $supply['supplyId'] ?? $supply['ID'] ?? null,
            'name' => $supply['name'] ?? "Поставка #{$supply['ID']}",
            'status' => self::SUPPLY_STATUSES[$statusCode] ?? 'unknown',
            'status_code' => $statusCode,
            'marketplace' => 'wildberries',
            'warehouse_id' => (string) ($supply['warehouseId'] ?? null),
            'warehouse_name' => $supply['warehouseName'] ?? null,
            'created_at' => $supply['createdAt'] ?? null,
            'planned_date' => $supply['plannedDate'] ?? null,
            'close_date' => $supply['closeDate'] ?? null,
            'boxes_count' => $supply['boxesCount'] ?? 0,
            'pallets_count' => $supply['palletsCount'] ?? 0,
            'cargo_type' => $supply['cargoType'] ?? null,
            'is_large_cargo' => $supply['isLargeCargo'] ?? false,
            'scan_type' => $supply['scanType'] ?? null,
            'raw_data' => $supply,
        ];
    }
}
