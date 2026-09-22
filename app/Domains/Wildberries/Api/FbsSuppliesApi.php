<?php

namespace App\Domains\Wildberries\Api;

/**
 * API для работы с FBS поставками Wildberries
 * 
 * FBS (Fulfillment by Seller) — продавец сам хранит товары и отправляет их на склад WB
 * 
 * Поддерживаемые операции (marketplace-api; клиент сам подставляет хост,
 * в endpoint передаём только путь):
 * - POST /api/v3/supplies — создать новую поставку
 * - GET /api/v3/supplies — список поставок
 * - GET /api/v3/supplies/{supplyId} — детали поставки
 * - DELETE /api/v3/supplies/{supplyId} — удалить пустую поставку
 * - GET /api/v3/offices, GET /api/v3/warehouses — офисы и склады продавца
 *
 * @see https://dev.wildberries.ru/openapi/orders-fbs
 */
class FbsSuppliesApi
{
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

        $response = $this->client->post('/api/v3/supplies', $body);

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
        // limit и next обязательны: next = 0 для первой страницы
        $params = [
            'limit' => $filters['limit'] ?? 1000,
            'next' => $filters['next'] ?? 0,
        ];

        $response = $this->client->get('/api/v3/supplies', $params);

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
        $response = $this->client->get("/api/v3/supplies/{$supplyId}");

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
        $response = $this->client->delete("/api/v3/supplies/{$supplyId}");

        return $response !== null;
    }

    /**
     * Получить список офисов WB для FBS
     * 
     * GET /api/v3/offices
     */
    public function getOffices(): array
    {
        // endpoint и base URL раздельно: client->get сам клеит хост, полный URL
        // в endpoint давал «https://…https://…» и пустой ответ.
        $response = $this->client->get('/api/v3/offices');

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
        $response = $this->client->get('/api/v3/warehouses');

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
            'get_supplies' => true,
            'get_supply_details' => true,
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
