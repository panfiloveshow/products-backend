<?php

namespace App\Domains\Marketplace\Contracts;

use App\Models\Integration;

/**
 * Интерфейс для работы с остатками маркетплейса
 */
interface InventoryApiInterface
{
    /**
     * Получить остатки по всем складам
     * 
     * @param Integration $integration
     * @param array $skus Фильтр по SKU (опционально)
     * @return array Массив остатков по складам
     */
    public function getStocks(?Integration $integration = null, array $skus = []): array;

    /**
     * Получить список складов маркетплейса
     */
    public function getWarehouses(?Integration $integration = null): array;

    /**
     * Получить остатки по конкретному складу
     */
    public function getStocksByWarehouse(string $warehouseId, ?Integration $integration = null): array;
}
