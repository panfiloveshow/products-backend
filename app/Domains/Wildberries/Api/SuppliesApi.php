<?php

namespace App\Domains\Wildberries\Api;

use App\Domains\Marketplace\Contracts\SuppliesApiInterface;
use Illuminate\Support\Facades\Log;

/**
 * API для работы с поставками Wildberries (FBW Supplies), хост supplies-api.
 *
 * Поддерживаемые операции (чтение):
 * - POST /api/v1/supplies — список поставок
 * - GET /api/v1/supplies/{ID} — детали поставки
 * - GET /api/v1/supplies/{ID}/goods — товары в поставке
 * - GET /api/v1/supplies/{ID}/package — упаковка поставки
 *
 * С 15.08.2026 WB временно отключил (RN-570), замены пока нет — не вызываем:
 * - GET /api/tariffs/v1/acceptance/coefficients — коэффициенты/слоты приёмки
 * - POST /api/v1/acceptance/options — опции приёмки
 * - GET /api/v1/warehouses — склады
 * - GET /api/v1/transit-tariffs — тарифы транзита
 * Коэффициенты складов берём из тарифов коробов (/api/v1/tariffs/box).
 *
 * НЕ поддерживается через API (только через ЛК WB):
 * - Создание поставок
 * - Бронирование слотов приёмки
 * - Добавление товаров в поставку
 *
 * @see https://dev.wildberries.ru/openapi/orders-fbw
 * @see https://dev.wildberries.ru/openapi/wb-tariffs
 */
class SuppliesApi implements SuppliesApiInterface
{
    public const DISABLED_REASON = 'WB временно отключил методы приёмки и складов поставок с 15.08.2026 (RN-570), замены пока нет';

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
     * POST /api/v1/supplies?limit=&offset= — тело models.SuppliesFiltersRequest
     *
     * @param array $filters [
     *   'limit' => int (default 1000, max 1000),
     *   'offset' => int (default 0),
     *   'statuses' => int[] (1-6),
     *   'date_from' => string (Y-m-d),
     *   'date_to' => string (Y-m-d),
     *   'date_type' => factDate|createDate|supplyDate|updatedDate (default createDate),
     * ]
     */
    public function getSupplies(array $filters = []): array
    {
        $query = http_build_query([
            'limit' => min(1000, (int) ($filters['limit'] ?? 1000)),
            'offset' => (int) ($filters['offset'] ?? 0),
        ]);

        // Тело обязательно и должно быть объектом: пустой массив клиент отправит
        // как JSON-массив, поэтому без фильтра статусов передаём все шесть.
        $body = [
            'statusIDs' => array_values(array_map('intval', ($filters['statuses'] ?? []) ?: array_keys(self::SUPPLY_STATUSES))),
        ];
        if (! empty($filters['date_from']) || ! empty($filters['date_to'])) {
            $body['dates'] = [array_filter([
                'from' => $filters['date_from'] ?? null,
                'till' => $filters['date_to'] ?? null,
                'type' => $filters['date_type'] ?? 'createDate',
            ])];
        }

        $response = $this->client->suppliesPost('/api/v1/supplies?'.$query, $body);

        if (! is_array($response)) {
            return [];
        }

        // Ответ — массив models.Supply
        return array_map(fn ($supply) => $this->mapSupply($supply), $response);
    }

    /**
     * Получить детали поставки
     *
     * GET /api/v1/supplies/{ID}
     */
    public function getSupplyDetails(string $supplyId): ?array
    {
        $response = $this->client->suppliesGet("/api/v1/supplies/{$supplyId}");

        if (! $response) {
            return null;
        }

        return $this->mapSupply($response, $supplyId);
    }

    /**
     * Получить товары в поставке
     *
     * GET /api/v1/supplies/{ID}/goods — массив models.GoodInSupply, limit ≤ 1000 + offset
     */
    public function getSupplyProducts(string $supplyId): array
    {
        $limit = 1000;
        $offset = 0;
        $goods = [];

        do {
            $page = $this->client->suppliesGet("/api/v1/supplies/{$supplyId}/goods", [
                'limit' => $limit,
                'offset' => $offset,
            ]);
            if (! is_array($page) || $page === []) {
                break;
            }
            $goods = array_merge($goods, $page);
            $offset += $limit;
        } while (count($page) === $limit && $offset < 50000);

        return array_map(fn ($item) => [
            'sku' => $item['barcode'] ?? null,
            'nm_id' => $item['nmID'] ?? null,
            'vendor_code' => $item['vendorCode'] ?? null,
            'tech_size' => $item['techSize'] ?? null,
            'quantity' => (int) ($item['quantity'] ?? 0),
            'quantity_accepted' => (int) ($item['acceptedQuantity'] ?? 0),
            'quantity_ready_for_sale' => (int) ($item['readyForSaleQuantity'] ?? 0),
        ], $goods);
    }

    /**
     * Получить упаковку поставки
     *
     * GET /api/v1/supplies/{ID}/package — массив models.Box {packageCode, quantity, barcodes}
     */
    public function getSupplyPackage(string $supplyId): array
    {
        $response = $this->client->suppliesGet("/api/v1/supplies/{$supplyId}/package");

        if (! is_array($response)) {
            return [];
        }

        return [
            'boxes_count' => count($response),
            'boxes' => $response,
        ];
    }

    /**
     * Список складов (GET /api/v1/warehouses) — временно отключён WB, не вызываем.
     */
    public function getAvailableWarehouses(): array
    {
        return $this->disabledMethod('GET /api/v1/warehouses');
    }

    /**
     * Опции приёмки (POST /api/v1/acceptance/options) — временно отключены WB, не вызываем.
     */
    public function getAcceptanceSlots(string $warehouseId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        return $this->disabledMethod('POST /api/v1/acceptance/options');
    }

    /**
     * Коэффициенты складов из тарифов коробов (common-api /api/v1/tariffs/box):
     * пока «Тарифы на поставку» отключены, это единственный источник.
     *
     * @return array [нормализованное имя склада => ['warehouse_name', 'delivery_coef', 'storage_coef', ...]]
     */
    public function getAcceptanceCoefficients(): array
    {
        return (new StorageApi($this->client))->getWarehouseCoefficients();
    }

    private function disabledMethod(string $method): array
    {
        Log::info('WB SuppliesApi: '.self::DISABLED_REASON, ['method' => $method]);

        return [];
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
     * - Чтение поставок, их деталей, товаров и упаковки
     * - Коэффициенты складов из тарифов коробов
     *
     * Временно отключены WB (с 15.08.2026): склады, слоты/коэффициенты приёмки, транзит.
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
            'get_warehouses' => false,            // Временно отключено WB (RN-570)
            'get_acceptance_slots' => false,      // Временно отключено WB (RN-570)
            'get_available_slots' => false,       // Временно отключено WB (RN-570)
            'get_acceptance_coefficients' => true, // Из тарифов коробов
            'get_transit_tariffs' => false,       // Временно отключено WB (RN-570)
            'create_supply' => false,             // Только через ЛК WB
            'add_items' => false,                 // Только через ЛК WB
            'book_slot' => false,                 // Только через ЛК WB
        ];

        return $supported[$feature] ?? false;
    }

    /**
     * Маппинг поставки WB (models.Supply / models.SupplyDetails) к унифицированному формату
     */
    private function mapSupply(array $supply, ?string $supplyId = null): array
    {
        $statusCode = (int) ($supply['statusID'] ?? 1);
        // supplyID = null — незапланированная поставка (заказ), её ID — preorderID.
        $id = $supplyId ?? (string) ($supply['supplyID'] ?? $supply['preorderID'] ?? '');

        return [
            'id' => $id,
            'external_id' => isset($supply['supplyID']) ? (string) $supply['supplyID'] : $supplyId,
            'preorder_id' => $supply['preorderID'] ?? null,
            'name' => "Поставка #{$id}",
            'status' => self::SUPPLY_STATUSES[$statusCode] ?? 'unknown',
            'status_code' => $statusCode,
            'marketplace' => 'wildberries',
            'warehouse_id' => isset($supply['warehouseID']) ? (string) $supply['warehouseID'] : null,
            'warehouse_name' => $supply['warehouseName'] ?? null,
            'created_at' => $supply['createDate'] ?? null,
            'planned_date' => $supply['supplyDate'] ?? null,
            'fact_date' => $supply['factDate'] ?? null,
            'box_type_id' => $supply['boxTypeID'] ?? null,
            'raw_data' => $supply,
        ];
    }
}
