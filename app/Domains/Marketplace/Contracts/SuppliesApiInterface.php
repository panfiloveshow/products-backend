<?php

namespace App\Domains\Marketplace\Contracts;

use App\Models\Integration;

/**
 * Интерфейс для работы с поставками на маркетплейсы
 * 
 * Реализуется для каждого маркетплейса:
 * - Wildberries: FBW Supplies API
 * - Ozon: FBO Supplies API
 */
interface SuppliesApiInterface
{
    /**
     * Получить список поставок
     * 
     * @param array $filters Фильтры (статусы, даты, лимит)
     * @return array Список поставок
     */
    public function getSupplies(array $filters = []): array;

    /**
     * Получить детали поставки
     * 
     * @param string $supplyId ID поставки
     * @return array|null Детали поставки
     */
    public function getSupplyDetails(string $supplyId): ?array;

    /**
     * Получить товары в поставке
     * 
     * @param string $supplyId ID поставки
     * @return array Список товаров
     */
    public function getSupplyProducts(string $supplyId): array;

    /**
     * Получить список доступных складов для поставки
     * 
     * @return array Список складов с коэффициентами
     */
    public function getAvailableWarehouses(): array;

    /**
     * Получить доступные слоты для приёмки
     * 
     * @param string $warehouseId ID склада
     * @param string|null $dateFrom Дата начала (Y-m-d)
     * @param string|null $dateTo Дата окончания (Y-m-d)
     * @return array Список слотов
     */
    public function getAcceptanceSlots(string $warehouseId, ?string $dateFrom = null, ?string $dateTo = null): array;

    /**
     * Получить коэффициенты приёмки по складам
     * 
     * @return array [warehouseId => coefficient]
     */
    public function getAcceptanceCoefficients(): array;

    /**
     * Создать черновик поставки
     * 
     * @param array $data Данные поставки
     * @return array Созданная поставка
     */
    public function createSupplyDraft(array $data): array;

    /**
     * Добавить товары в поставку
     * 
     * @param string $supplyId ID поставки
     * @param array $items Товары [{sku, quantity, ...}]
     * @return bool Успешность
     */
    public function addItemsToSupply(string $supplyId, array $items): bool;

    /**
     * Забронировать слот приёмки
     * 
     * @param string $supplyId ID поставки
     * @param string $slotId ID слота
     * @return array Результат бронирования
     */
    public function bookAcceptanceSlot(string $supplyId, string $slotId): array;

    /**
     * Получить статусы поставок (маппинг)
     * 
     * @return array [statusCode => statusName]
     */
    public function getSupplyStatuses(): array;

    /**
     * Проверить поддержку функционала
     * 
     * @param string $feature Название функции
     * @return bool
     */
    public function supportsFeature(string $feature): bool;
}
