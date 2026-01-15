<?php

namespace App\Domains\Wildberries\Api;

/**
 * API для работы с FBS поставками Wildberries
 * 
 * FBS (Fulfillment by Seller) — продавец сам хранит товары и отправляет их на склад WB
 * 
 * Поддерживаемые операции:
 * - POST /api/v3/supplies — создать новую поставку
 * - GET /api/v3/supplies — список поставок
 * - GET /api/v3/supplies/{supplyId} — детали поставки
 * - DELETE /api/v3/supplies/{supplyId} — удалить пустую поставку
 * - POST /api/v3/supplies/{supplyId}/orders — добавить заказы в поставку
 * - PATCH /api/v3/supplies/{supplyId}/deliver — передать в доставку
 * - GET /api/v3/supplies/{supplyId}/orders — заказы в поставке
 * - GET /api/v3/supplies/{supplyId}/trbx — коробки поставки
 * - GET /api/v3/supplies/{supplyId}/barcodes — штрихкоды/этикетки
 * 
 * @see https://dev.wildberries.ru/openapi/orders-fbs
 */
class FbsSuppliesApi
{
    private const BASE_URL = 'https://marketplace-api.wildberries.ru';

    public function __construct(
        private WildberriesClient $client
    ) {}

    /**
     * Создать новую FBS поставку
     * 
     * POST /api/v3/supplies
     * 
     * @param string $name Название поставки (опционально)
     * @return array ['id' => 'WB-GI-...']
     */
    public function createSupply(?string $name = null): array
    {
        $body = [];
        if ($name) {
            $body['name'] = $name;
        }

        $response = $this->client->post(self::BASE_URL . '/api/v3/supplies', $body);

        if (!$response || empty($response['id'])) {
            throw new \RuntimeException(
                'Не удалось создать поставку WB FBS: ' . json_encode($response)
            );
        }

        return [
            'id' => $response['id'],
            'name' => $name ?? $response['id'],
            'created_at' => now()->toIso8601String(),
            'status' => 'new',
        ];
    }

    /**
     * Получить список FBS поставок
     * 
     * GET /api/v3/supplies
     * 
     * @param array $filters [
     *   'limit' => int (default 1000),
     *   'next' => int (cursor для пагинации),
     * ]
     */
    public function getSupplies(array $filters = []): array
    {
        $params = [
            'limit' => $filters['limit'] ?? 1000,
        ];

        if (!empty($filters['next'])) {
            $params['next'] = $filters['next'];
        }

        $response = $this->client->get(self::BASE_URL . '/api/v3/supplies', $params);

        if (!$response) {
            return [];
        }

        $supplies = $response['supplies'] ?? [];

        return array_map(fn($supply) => $this->mapSupply($supply), $supplies);
    }

    /**
     * Получить детали поставки
     * 
     * GET /api/v3/supplies/{supplyId}
     */
    public function getSupplyDetails(string $supplyId): ?array
    {
        $response = $this->client->get(self::BASE_URL . "/api/v3/supplies/{$supplyId}");

        if (!$response) {
            return null;
        }

        return $this->mapSupply($response);
    }

    /**
     * Удалить пустую поставку
     * 
     * DELETE /api/v3/supplies/{supplyId}
     * 
     * Можно удалить только если поставка активна и не содержит заказов
     */
    public function deleteSupply(string $supplyId): bool
    {
        $response = $this->client->delete(self::BASE_URL . "/api/v3/supplies/{$supplyId}");

        return $response !== null;
    }

    /**
     * Добавить заказы (сборочные задания) в поставку
     * 
     * POST /api/v3/supplies/{supplyId}/orders
     * До 100 заказов за запрос
     * 
     * @param string $supplyId ID поставки
     * @param array $orderIds Массив ID заказов (до 100)
     */
    public function addOrdersToSupply(string $supplyId, array $orderIds): array
    {
        if (count($orderIds) > 100) {
            throw new \InvalidArgumentException('Максимум 100 заказов за запрос');
        }

        $response = $this->client->post(
            self::BASE_URL . "/api/v3/supplies/{$supplyId}/orders",
            ['orders' => array_map(fn($id) => (int) $id, $orderIds)]
        );

        if (!$response) {
            throw new \RuntimeException('Не удалось добавить заказы в поставку');
        }

        return [
            'supply_id' => $supplyId,
            'added_count' => count($orderIds),
            'orders' => $orderIds,
        ];
    }

    /**
     * Передать поставку в доставку
     * 
     * PATCH /api/v3/supplies/{supplyId}/deliver
     * 
     * После этого поставка закрывается и можно получить QR-код
     */
    public function deliverSupply(string $supplyId): bool
    {
        $response = $this->client->patch(self::BASE_URL . "/api/v3/supplies/{supplyId}/deliver");

        return $response !== null;
    }

    /**
     * Получить заказы в поставке
     * 
     * GET /api/v3/supplies/{supplyId}/orders
     */
    public function getSupplyOrders(string $supplyId): array
    {
        $response = $this->client->get(self::BASE_URL . "/api/v3/supplies/{$supplyId}/orders");

        if (!$response) {
            return [];
        }

        return $response['orders'] ?? [];
    }

    /**
     * Получить коробки поставки
     * 
     * GET /api/v3/supplies/{supplyId}/trbx
     */
    public function getSupplyBoxes(string $supplyId): array
    {
        $response = $this->client->get(self::BASE_URL . "/api/v3/supplies/{$supplyId}/trbx");

        if (!$response) {
            return [];
        }

        return $response['trbxes'] ?? [];
    }

    /**
     * Получить штрихкоды/этикетки поставки
     * 
     * GET /api/v3/supplies/{supplyId}/barcode
     * 
     * @param string $supplyId ID поставки
     * @param string $type Тип: svg, zplv, zplh, png
     */
    public function getSupplyBarcode(string $supplyId, string $type = 'png'): ?array
    {
        $response = $this->client->get(
            self::BASE_URL . "/api/v3/supplies/{$supplyId}/barcode",
            ['type' => $type]
        );

        if (!$response) {
            return null;
        }

        return [
            'type' => $type,
            'file' => $response['file'] ?? null,
            'barcode' => $response['barcode'] ?? null,
        ];
    }

    /**
     * Получить QR-код поставки (после передачи в доставку)
     * 
     * GET /api/v3/supplies/{supplyId}/trbx/qr
     */
    public function getSupplyQRCode(string $supplyId): ?string
    {
        $response = $this->client->get(self::BASE_URL . "/api/v3/supplies/{$supplyId}/trbx/qr");

        return $response['file'] ?? null;
    }

    /**
     * Получить список офисов WB для FBS
     * 
     * GET /api/v3/offices
     */
    public function getOffices(): array
    {
        $response = $this->client->get(self::BASE_URL . '/api/v3/offices');

        if (!$response) {
            return [];
        }

        return array_map(fn($office) => [
            'id' => $office['id'] ?? null,
            'name' => $office['name'] ?? null,
            'address' => $office['address'] ?? null,
            'city' => $office['city'] ?? null,
            'longitude' => $office['longitude'] ?? null,
            'latitude' => $office['latitude'] ?? null,
            'cargo_type' => $office['cargoType'] ?? null,
            'delivery_type' => $office['deliveryType'] ?? null,
            'selected' => $office['selected'] ?? false,
        ], $response);
    }

    /**
     * Получить склады продавца для FBS
     * 
     * GET /api/v3/warehouses
     */
    public function getSellerWarehouses(): array
    {
        $response = $this->client->get(self::BASE_URL . '/api/v3/warehouses');

        if (!$response) {
            return [];
        }

        return array_map(fn($wh) => [
            'id' => (string) ($wh['id'] ?? null),
            'name' => $wh['name'] ?? null,
            'office_id' => $wh['officeId'] ?? null,
            'cargo_type' => $wh['cargoType'] ?? null,
            'delivery_type' => $wh['deliveryType'] ?? null,
        ], $response);
    }

    /**
     * Проверить поддержку функционала
     */
    public function supportsFeature(string $feature): bool
    {
        $supported = [
            'create_supply' => true,
            'delete_supply' => true,
            'add_orders' => true,
            'deliver_supply' => true,
            'get_supplies' => true,
            'get_supply_details' => true,
            'get_supply_orders' => true,
            'get_barcode' => true,
            'get_qr_code' => true,
            'get_offices' => true,
            'get_warehouses' => true,
        ];

        return $supported[$feature] ?? false;
    }

    /**
     * Маппинг поставки к унифицированному формату
     */
    private function mapSupply(array $supply): array
    {
        return [
            'id' => $supply['id'] ?? null,
            'external_id' => $supply['id'] ?? null,
            'name' => $supply['name'] ?? $supply['id'] ?? null,
            'done' => $supply['done'] ?? false,
            'status' => ($supply['done'] ?? false) ? 'closed' : 'active',
            'marketplace' => 'wildberries',
            'supply_type' => 'FBS',
            'created_at' => $supply['createdAt'] ?? null,
            'closed_at' => $supply['closedAt'] ?? null,
            'scan_dt' => $supply['scanDt'] ?? null,
            'cargo_type' => $supply['cargoType'] ?? null,
            'destination_office_id' => $supply['destinationOfficeId'] ?? null,
            'raw_data' => $supply,
        ];
    }
}
