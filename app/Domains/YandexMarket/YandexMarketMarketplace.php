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

        $this->client = new YandexMarketClient($token, $campaignId, $businessId);
        $this->products = new ProductsApi($this->client);
        $this->inventory = new InventoryApi($this->client);
        $this->sales = new SalesApi($this->client);

        // Схема FBY/FBS/DBS/EXPRESS — из credentials или автоопределение из API кампании
        $explicitScheme = $credentials['scheme'] ?? $credentials['fulfillment_type'] ?? null;
        if (! empty($explicitScheme)) {
            $this->scheme = strtoupper((string) $explicitScheme);
        } else {
            $this->scheme = $this->detectSchemeFromApi();
        }
    }

    /**
     * Автоопределение схемы из API кампании (placementType: FBS/FBY/DBS/CROSSBORDER)
     */
    private function detectSchemeFromApi(): string
    {
        try {
            $cid = $this->client->getCampaignId();
            if ($cid === '') {
                return 'FBY';
            }
            $response = $this->client->get('/campaigns/{campaignId}');
            $placementType = strtoupper((string) (
                data_get($response, 'campaign.placementType')
                ?? data_get($response, 'result.campaign.placementType')
                ?? ''
            ));

            return match ($placementType) {
                'FBS', 'DBS', 'EXPRESS' => 'FBS',
                'FBY', 'FBY_PLUS' => 'FBY',
                default => 'FBY',
            };
        } catch (\Exception $e) {
            return 'FBY';
        }
    }

    public function getScheme(): string
    {
        return $this->scheme;
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

        // Получаем реальные тарифы из API и обогащаем yandex_data
        $this->enrichWithTariffs($products);

        return $products;
    }

    /**
     * Обогатить товары реальными тарифами из Yandex Market API
     */
    private function enrichWithTariffs(array &$products): void
    {
        if (empty($products)) {
            return;
        }

        // Собираем данные для запроса тарифов
        $offersForTariffs = [];
        $skuIndex = [];
        foreach ($products as $i => $product) {
            $sku = $product['sku'] ?? null;
            $price = $product['price'] ?? 0;
            if (! $sku || $price <= 0) {
                continue;
            }

            $yd = $product['yandex_data'] ?? [];
            $categoryId = $yd['categoryId'] ?? null;
            // Габариты в см и кг (из weightDimensions)
            $lengthCm = ($yd['length_mm'] ?? 0) / 10;
            $widthCm = ($yd['width_mm'] ?? 0) / 10;
            $heightCm = ($yd['height_mm'] ?? 0) / 10;
            $weightKg = ($yd['weight_g'] ?? 0) / 1000;

            $offer = ['offerId' => $sku, 'price' => (float) $price];
            if ($categoryId) {
                $offer['categoryId'] = (int) $categoryId;
            }
            if ($lengthCm > 0) {
                $offer['length'] = $lengthCm;
            }
            if ($widthCm > 0) {
                $offer['width'] = $widthCm;
            }
            if ($heightCm > 0) {
                $offer['height'] = $heightCm;
            }
            if ($weightKg > 0) {
                $offer['weight'] = $weightKg;
            }

            $offersForTariffs[] = $offer;
            $skuIndex[$sku] = $i;
        }

        if (empty($offersForTariffs)) {
            return;
        }

        $tariffsBySku = $this->calculateTariffs($offersForTariffs, $this->scheme);

        if (empty($tariffsBySku)) {
            \Illuminate\Support\Facades\Log::info('YM tariffs: empty response, skipping enrichment');
            return;
        }

        \Illuminate\Support\Facades\Log::info('YM tariffs loaded', ['count' => count($tariffsBySku)]);

        foreach ($tariffsBySku as $sku => $tariffs) {
            if (! isset($skuIndex[$sku])) {
                continue;
            }
            $idx = $skuIndex[$sku];
            $products[$idx]['yandex_data']['tariffs'] = $tariffs;
        }
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

        // Габариты из weightDimensions (длина/ширина/высота в см, вес в кг)
        $wd = $offer['weightDimensions'] ?? [];
        $lengthCm = isset($wd['length']) ? (float) $wd['length'] : null;
        $widthCm = isset($wd['width']) ? (float) $wd['width'] : null;
        $heightCm = isset($wd['height']) ? (float) $wd['height'] : null;
        $weightKg = isset($wd['weight']) ? (float) $wd['weight'] : null;

        // Конвертируем в мм и граммы для единообразия с WB/Ozon
        $depthMm = $lengthCm !== null ? $lengthCm * 10 : null;
        $widthMm = $widthCm !== null ? $widthCm * 10 : null;
        $heightMm = $heightCm !== null ? $heightCm * 10 : null;
        $weightG = $weightKg !== null ? $weightKg * 1000 : null;

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
            'integration_id' => $integrationId,
            'depth' => $depthMm,
            'width' => $widthMm,
            'height' => $heightMm,
            'weight' => $weightG,
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
                // Габариты для UE расчёта (мм и граммы)
                'length_mm' => $depthMm,
                'width_mm' => $widthMm,
                'height_mm' => $heightMm,
                'weight_g' => $weightG,
            ],
        ];
    }

    public function getProductPrices(): array
    {
        return $this->products->getPrices();
    }

    // === Tariffs ===

    /**
     * Рассчитать реальные тарифы для товаров через Yandex Market API
     *
     * POST /v2/tariffs/calculate?campaignId={campaignId}
     *
     * @param  array  $offers  [{offerId, categoryId, price, length, width, height, weight}, ...]
     * @param  string  $sellingProgram  FBY|FBS|DBS|EXPRESS
     * @return array<string, array>  offerId => [tariffs => [...]]
     */
    public function calculateTariffs(array $offers, string $sellingProgram = 'FBY'): array
    {
        $campaignId = $this->client->getCampaignId();
        if ($campaignId === '') {
            return [];
        }

        $result = [];
        // API принимает до 200 товаров за раз
        foreach (array_chunk($offers, 200, true) as $chunk) {
            $chunkOfferIds = array_values(array_column($chunk, 'offerId'));
            $chunkValues = array_values($chunk);

            try {
                $response = $this->client->post('/v2/tariffs/calculate', [
                    'parameters' => [
                        'sellingProgram' => $sellingProgram,
                    ],
                    'offers' => $chunkValues,
                ], ['campaignId' => $campaignId]);

                foreach ($response['result']['offers'] ?? [] as $idx => $item) {
                    // API возвращает offers в том же порядке — маппим по индексу
                    $offerId = $item['offer']['offerId'] ?? ($chunkOfferIds[$idx] ?? null);
                    if ($offerId) {
                        $result[$offerId] = $item['tariffs'] ?? [];
                    }
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Yandex tariffs/calculate failed', [
                    'error' => $e->getMessage(),
                    'chunk_size' => count($chunk),
                ]);
            }
        }

        return $result;
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
