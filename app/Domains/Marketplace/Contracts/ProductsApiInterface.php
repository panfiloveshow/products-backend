<?php

namespace App\Domains\Marketplace\Contracts;

use App\Models\Integration;

/**
 * Интерфейс для работы с товарами маркетплейса
 */
interface ProductsApiInterface
{
    /**
     * Получить список товаров
     * 
     * @param Integration $integration
     * @param array $options Дополнительные параметры (limit, offset, filter и т.д.)
     * @return array Массив товаров
     */
    public function getProducts(?Integration $integration = null, array $options = []): array;

    /**
     * Получить информацию о товаре по SKU
     */
    public function getProductBySku(string $sku, ?Integration $integration = null): ?array;

    /**
     * Получить цены товаров
     */
    public function getPrices(?Integration $integration = null, array $skus = []): array;
}
