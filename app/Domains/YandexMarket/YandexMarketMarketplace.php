<?php

namespace App\Domains\YandexMarket;

use App\Domains\Marketplace\Contracts\MarketplaceInterface;
use App\Domains\YandexMarket\Api\YandexMarketClient;
use App\Domains\YandexMarket\Api\ProductsApi;
use App\Domains\YandexMarket\Api\InventoryApi;
use App\Domains\YandexMarket\Api\SalesApi;
use App\Models\Integration;

/**
 * Фасад для работы с Yandex Market API
 * 
 * Объединяет все компоненты:
 * - ProductsApi — товары
 * - InventoryApi — остатки
 * - SalesApi — продажи
 */
class YandexMarketMarketplace implements MarketplaceInterface
{
    private YandexMarketClient $client;
    private ProductsApi $products;
    private InventoryApi $inventory;
    private SalesApi $sales;

    public function __construct(array $credentials = [])
    {
        $token = $credentials['token'] ?? config('services.yandex_market.token');
        $campaignId = $credentials['campaign_id'] ?? config('services.yandex_market.campaign_id');
        $businessId = $credentials['business_id'] ?? config('services.yandex_market.business_id');

        $this->client = new YandexMarketClient($token, $campaignId, $businessId);
        $this->products = new ProductsApi($this->client);
        $this->inventory = new InventoryApi($this->client);
        $this->sales = new SalesApi($this->client);
    }

    // === MarketplaceInterface ===

    public function getName(): string
    {
        return 'Yandex Market';
    }

    public function getCode(): string
    {
        return 'yandex_market';
    }

    public function testConnection(Integration $integration): bool
    {
        try {
            $products = $this->products->getProducts(1);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getSupportedSchemes(): array
    {
        return ['FBY', 'FBS', 'DBS', 'EXPRESS'];
    }

    // === Products ===

    public function getProducts(): array
    {
        return $this->products->getProducts();
    }

    public function getProductPrices(): array
    {
        return $this->products->getPrices();
    }

    // === Inventory ===

    public function getInventory(): array
    {
        return $this->inventory->getStocks();
    }

    public function getWarehouses(): array
    {
        return $this->inventory->getWarehouses();
    }

    // === Sales ===

    public function getSalesStats(string $dateFrom, string $dateTo): array
    {
        return $this->sales->getSalesStats($dateFrom, $dateTo);
    }

    public function getSalesBySku(): array
    {
        return $this->sales->getSalesBySku();
    }

    // === Direct API access ===

    public function getClient(): YandexMarketClient
    {
        return $this->client;
    }

    public function api(): YandexMarketClient
    {
        return $this->client;
    }
}
