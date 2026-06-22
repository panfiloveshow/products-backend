<?php

namespace App\Domains\Uzum;

use App\Domains\Marketplace\Contracts\MarketplaceInterface;
use App\Domains\Uzum\Api\ProductsApi;
use App\Domains\Uzum\Api\UzumClient;
use App\Models\Integration;
use Illuminate\Support\Facades\Log;

/**
 * Адаптер маркетплейса Uzum Market.
 *
 * Phase 1: товары + входные данные для юнит-экономики.
 * Все экономические числа (commission, purchasePrice, paidStorageAmount,
 * returnedPercentage, quantitySold) приходят готовыми в SkuForTable
 * из одного эндпоинта GET /v1/product/shop/{shopId}.
 */
class UzumMarketplace implements MarketplaceInterface
{
    private UzumClient $client;
    private ProductsApi $products;
    private ?Integration $integration;

    public function __construct(array $credentials = [], ?Integration $integration = null)
    {
        $apiKey = $credentials['api_key'] ?? config('services.uzum.api_key');
        $this->client = new UzumClient($apiKey);
        $this->products = new ProductsApi($this->client);
        $this->integration = $integration;
    }

    public function getName(): string
    {
        return 'Uzum';
    }

    public function getCode(): string
    {
        return 'uzum';
    }

    public function testConnection(Integration $integration): bool
    {
        try {
            return ! empty($this->products->getShops());
        } catch (\Throwable $e) {
            Log::warning('Uzum testConnection failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function getSupportedSchemes(): array
    {
        return ['FBS', 'FBO', 'DBS'];
    }

    /**
     * Получить товары: разворачиваем карточки в строки по каждому SKU.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getProducts(): array
    {
        $shopId = $this->resolveShopId();
        if (! $shopId) {
            Log::warning('Uzum getProducts: shopId не найден');
            return [];
        }

        $result = [];
        $page = 0;
        $size = 100;
        $guard = 0;

        do {
            $batch = $this->products->getProducts($shopId, $page, $size);
            $cards = $batch['productList'] ?? [];

            foreach ($cards as $card) {
                foreach (($card['skuList'] ?? []) as $sku) {
                    $row = $this->mapSkuToProduct($card, $sku, $shopId);
                    if ($row !== null) {
                        $result[] = $row;
                    }
                }
            }

            $page++;
            $guard++;
            $fetched = count($cards);
        } while ($fetched >= 1 && $fetched >= $size && $guard < 200);

        return $result;
    }

    /**
     * Резолв shopId: из настроек интеграции либо из GET /v1/shops (первый магазин).
     */
    private function resolveShopId(): ?int
    {
        $settings = $this->integration?->settings ?? [];
        if (! empty($settings['uzum_shop_id'])) {
            return (int) $settings['uzum_shop_id'];
        }

        $shops = $this->products->getShops();
        if (empty($shops)) {
            return null;
        }

        $shopId = (int) ($shops[0]['id'] ?? 0);
        if ($shopId <= 0) {
            return null;
        }

        // Кэшируем в настройках интеграции, чтобы не дёргать /v1/shops каждый раз.
        if ($this->integration) {
            $settings['uzum_shop_id'] = $shopId;
            $this->integration->forceFill(['settings' => $settings])->saveQuietly();
        }

        return $shopId;
    }

    /**
     * SellerProductCard + SkuForTable -> общая форма строки товара.
     *
     * @return array<string, mixed>|null
     */
    private function mapSkuToProduct(array $card, array $sku, int $shopId): ?array
    {
        $skuId = $sku['skuId'] ?? null;
        $code = $sku['sellerItemCode'] ?? $sku['article'] ?? ($skuId !== null ? (string) $skuId : null);
        if (! $code) {
            return null;
        }

        $quantityActive = (int) ($sku['quantityActive'] ?? 0);
        $quantityFbs = (int) ($sku['quantityFbs'] ?? 0);

        $image = $card['image'] ?? $card['previewImg'] ?? null;

        return [
            'sku' => (string) $code,
            'vendor_code' => isset($sku['article']) ? (string) $sku['article'] : null,
            'marketplace_id' => $skuId !== null ? (string) $skuId : null,
            'name' => $sku['skuFullTitle'] ?? $sku['skuTitle'] ?? $card['title'] ?? $sku['productTitle'] ?? null,
            'barcode' => isset($sku['barcode']) ? (string) $sku['barcode'] : null,
            'price' => (float) ($sku['price'] ?? 0),
            'old_price' => null,
            'cost_price' => isset($sku['purchasePrice']) ? (float) $sku['purchasePrice'] : null,
            'stock' => $quantityActive + $quantityFbs,
            'category' => $card['category'] ?? null,
            'brand' => null,
            'images' => $image ? [$image] : [],
            'description' => null,
            'rating' => is_numeric($card['rating'] ?? null) ? (float) $card['rating'] : null,
            'fulfillment_type' => $quantityFbs > 0 ? 'FBS' : 'FBO',
            'marketplace' => 'uzum',
            // Полный набор полей SkuForTable — вход для юнит-экономики (читается в обоих движках расчёта).
            'uzum_data' => [
                'shop_id' => $shopId,
                'product_id' => $card['productId'] ?? null,
                'sku_id' => $skuId,
                'commission' => isset($sku['commission']) ? (float) $sku['commission'] : ($card['commission'] ?? null),
                'purchase_price' => isset($sku['purchasePrice']) ? (float) $sku['purchasePrice'] : null,
                'paid_storage_amount' => isset($sku['paidStorageAmount']) ? (float) $sku['paidStorageAmount'] : null,
                'returned_percentage' => isset($sku['returnedPercentage']) ? (float) $sku['returnedPercentage'] : null,
                'quantity_sold' => isset($sku['quantitySold']) ? (int) $sku['quantitySold'] : null,
                'quantity_returned' => isset($sku['quantityReturned']) ? (int) $sku['quantityReturned'] : null,
                'quantity_active' => $quantityActive,
                'quantity_fbs' => $quantityFbs,
                'avgd_sales' => $sku['avgdsales'] ?? null,
                'avgd_quantity' => $sku['avgdquantity'] ?? null,
                'turnover' => $sku['turnover'] ?? null,
                'dimensional_group' => $sku['dimensionalGroup'] ?? null,
                'ikpu' => $sku['ikpu'] ?? null,
                'article' => $sku['article'] ?? null,
                'seller_item_code' => $sku['sellerItemCode'] ?? null,
            ],
        ];
    }
}
