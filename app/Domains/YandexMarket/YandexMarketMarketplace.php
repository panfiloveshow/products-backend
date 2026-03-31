<?php

namespace App\Domains\YandexMarket;

use App\Domains\Marketplace\Contracts\MarketplaceInterface;
use App\Domains\YandexMarket\Api\InventoryApi;
use App\Domains\YandexMarket\Api\ProductsApi;
use App\Domains\YandexMarket\Api\SalesApi;
use App\Domains\YandexMarket\Api\YandexMarketClient;
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

    private string $scheme;

    public function __construct(array $credentials = [])
    {
        $token = $credentials['token'] ?? $credentials['api_key'] ?? config('services.yandex_market.token');
        $campaignId = $credentials['campaign_id'] ?? $credentials['client_id'] ?? config('services.yandex_market.campaign_id');
        $businessId = $credentials['business_id'] ?? config('services.yandex_market.business_id');
        // Схема FBY/FBS/DBS/EXPRESS — задаётся в настройках интеграции, по умолчанию FBY
        $this->scheme = strtoupper((string) ($credentials['scheme'] ?? $credentials['fulfillment_type'] ?? 'FBY'));

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
            $this->products->getProducts($integration, ['limit' => 1]);

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
        $products = [];
        $pageToken = null;
        $iterations = 0;
        $maxIterations = 100; // Максимум 100 страниц (10,000 товаров)

        \Illuminate\Support\Facades\Log::info('YM starting products sync');

        do {
            $result = $this->products->getProducts(null, [
                'limit' => 100,
                'page_token' => $pageToken,
            ]);

            $items = $result['items'] ?? [];
            if (empty($items)) {
                break;
            }
            
            foreach ($items as $item) {
                $products[] = $this->transformProduct($item, null);
            }

            $pageToken = $result['paging']['nextPageToken'] ?? null;
            $iterations++;
            
            if ($iterations >= $maxIterations) {
                \Illuminate\Support\Facades\Log::warning('YM products pagination limit reached', [
                    'iterations' => $iterations,
                    'products_count' => count($products),
                ]);
                break;
            }
        } while ($pageToken);

        \Illuminate\Support\Facades\Log::info('YM products loaded', ['count' => count($products)]);

        // Получаем остатки и обогащаем
        $inventory = $this->getInventory();
        if (! empty($inventory)) {
            \Illuminate\Support\Facades\Log::info('YM enriching products with stocks', ['count' => count($inventory)]);
            $stocksBySku = [];
            foreach ($inventory as $item) {
                $sku = $item['sku'] ?? null;
                if ($sku) {
                    if (! isset($stocksBySku[$sku])) {
                        $stocksBySku[$sku] = 0;
                    }
                    // BUG FIX: getInventory() возвращает {sku, warehouses, total} — поле quantity отсутствует на верхнем уровне
                    $stocksBySku[$sku] += (int) ($item['total'] ?? 0);
                }
            }
            foreach ($products as &$product) {
                $sku = $product['sku'] ?? null;
                if ($sku && isset($stocksBySku[$sku])) {
                    $product['stock'] = $stocksBySku[$sku];
                }
            }
            unset($product);
        }

        return $products;
    }

    /**
     * Получить цены с пагинацией
     * Ограничено 5 страницами для предотвращения зависания
     */
    private function getProductPricesWithPagination(): array
    {
        $allPrices = [];
        $pageToken = null;
        $iterations = 0;
        $maxIterations = 5; // Ограничение для предотвращения бесконечного цикла

        do {
            $result = $this->products->getPricesWithPagination($pageToken);
            $items = $result['items'] ?? [];
            
            if (empty($items)) {
                break;
            }
            
            foreach ($items as $item) {
                $offerId = $item['offerId'] ?? $item['shopSku'] ?? null;
                if (! $offerId) {
                    continue;
                }

                // basicPrice — актуальное поле, price — fallback
                $price = null;
                $oldPrice = null;
                if (isset($item['basicPrice']['value'])) {
                    $price = (float) $item['basicPrice']['value'];
                    $oldPrice = isset($item['basicPrice']['discountBase']) 
                        ? (float) $item['basicPrice']['discountBase'] 
                        : null;
                } elseif (isset($item['price']['value'])) {
                    $price = (float) $item['price']['value'];
                }

                if ($price !== null) {
                    $allPrices[$offerId] = [
                        'price' => $price,
                        'old_price' => $oldPrice,
                    ];
                }
            }
            
            $pageToken = $result['paging']['nextPageToken'] ?? null;
            $iterations++;
            
            // Защита от бесконечного цикла
            if ($iterations >= $maxIterations) {
                \Illuminate\Support\Facades\Log::warning('YM prices pagination limit reached', [
                    'iterations' => $iterations,
                    'prices_loaded' => count($allPrices),
                ]);
                break;
            }
        } while ($pageToken);

        return $allPrices;
    }

    private function transformProduct(array $entry, ?int $integrationId = null): array
    {
        $offer = $entry['offer'] ?? [];
        $mapping = $entry['mapping'] ?? [];

        // Получаем цену (basicPrice — актуальное поле, price — fallback)
        $price = null;
        $oldPrice = null;
        if (isset($offer['basicPrice']['value'])) {
            $price = (float) $offer['basicPrice']['value'];
            $oldPrice = isset($offer['basicPrice']['discountBase']) ? (float) $offer['basicPrice']['discountBase'] : null;
        } elseif (isset($offer['price']['value'])) {
            $price = (float) $offer['price']['value'];
        }

        $offerId = trim((string) ($offer['offerId'] ?? ''));
        $shopSku = trim((string) ($offer['shopSku'] ?? ''));
        $vendorCode = trim((string) ($offer['vendorCode'] ?? ''));
        $marketSku = $mapping['marketSku'] ?? null;
        $sku = $offerId !== '' ? $offerId : ($shopSku !== '' ? $shopSku : ($vendorCode !== '' ? $vendorCode : (string) ($marketSku ?? '')));

        return [
            'sku' => $sku,
            'name' => $offer['name'] ?? 'Без названия',
            'barcode' => $offer['barcodes'][0] ?? null,
            'price' => $price,
            'old_price' => $oldPrice,
            'stock' => 0, // Остатки получаем отдельно и обогащаем потом
            'description' => $offer['description'] ?? null,
            'images' => $offer['pictures'] ?? $offer['urls'] ?? [],
            'category' => $offer['category'] ?? $mapping['categoryId'] ?? null,
            'brand' => $offer['vendor'] ?? null,
            'rating' => null,
            'reviews_count' => 0,
            'marketplace' => 'yandex_market',
            'marketplace_id' => (string) ($mapping['marketSku'] ?? $sku),
            'integration_id' => $integrationId, // Добавляем integration_id
            'url' => isset($mapping['marketSku'])
                ? "https://market.yandex.ru/product/{$mapping['marketSku']}"
                : null,
            'yandex_data' => [
                'offerId' => $offer['offerId'] ?? null,
                'shopSku' => $offer['shopSku'] ?? null,
                'marketSku' => $mapping['marketSku'] ?? null,
                'categoryId' => $mapping['categoryId'] ?? null,
                'modelId' => $mapping['modelId'] ?? null,
                'vendorCode' => $offer['vendorCode'] ?? null,
                'availability' => $offer['availability'] ?? null,
                'processingState' => $offer['processingState'] ?? null,
            ],
        ];
    }

    public function getProductPrices(): array
    {
        return $this->products->getPrices();
    }

    // === Inventory ===

    public function getInventory(): array
    {
        return $this->inventory->getStocks(null, [], $this->scheme);
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
