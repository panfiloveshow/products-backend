<?php

namespace Tests\Unit;

use App\Domains\Ozon\Api\OzonClient;
use App\Domains\Ozon\Api\ProductsApi;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OzonProductsApiTest extends TestCase
{
    public function test_get_prices_normalizes_price_indexes_from_product_info_prices(): void
    {
        Http::fake([
            'https://api-seller.ozon.ru/v5/product/info/prices' => Http::response([
                'items' => [
                    [
                        'offer_id' => '3-02/4011',
                        'product_id' => 2990459081,
                        'volume_weight' => 0.4,
                        'price' => [
                            'price' => 3000,
                            'old_price' => 6000,
                            'min_price' => 3000,
                            'marketing_seller_price' => 1600,
                        ],
                        'price_indexes' => [
                            'external_index_data' => [
                                'min_price' => 652,
                                'min_price_currency' => 'RUB',
                                'price_index_value' => 1.39,
                            ],
                            'ozon_index_data' => [
                                'min_price' => 689,
                                'min_price_currency' => 'RUB',
                                'price_index_value' => 1.35,
                            ],
                            'color_index' => 'RED',
                            'self_marketplaces_index_data' => [
                                'min_price' => 697,
                                'min_price_currency' => 'RUB',
                                'price_index_value' => 1.34,
                            ],
                        ],
                    ],
                ],
                'cursor' => '',
            ]),
        ]);

        $api = new ProductsApi(new OzonClient('client', 'key'));

        $result = $api->getPrices();

        $this->assertSame(1600.0, $result['3-02/4011']['actual_price']);
        $this->assertSame('RED', $result['3-02/4011']['price_index_color']);
        $this->assertSame('Невыгодный', $result['3-02/4011']['price_index_label']);
        $this->assertSame(1.35, $result['3-02/4011']['price_index_value']);
        $this->assertSame(689.0, $result['3-02/4011']['competitor_price']);
        $this->assertSame('ozon_index_data', $result['3-02/4011']['competitor_price_source']);
        $this->assertSame(652.0, $result['3-02/4011']['price_indexes']['external_index_data']['min_price']);
    }

    public function test_get_pricing_strategy_product_info_normalizes_competitor_price(): void
    {
        Http::fake([
            'https://api-seller.ozon.ru/v1/pricing-strategy/product/info' => Http::response([
                'result' => [
                    'strategy_id' => '123',
                    'is_enabled' => true,
                    'strategy_product_price' => '990.50',
                    'price_downloaded_at' => '2026-05-22T09:10:00Z',
                    'strategy_competitor_id' => 'marketplace',
                    'strategy_competitor_product_url' => 'https://example.test/product',
                ],
            ]),
        ]);

        $api = new ProductsApi(new OzonClient('client', 'key'));

        $result = $api->getPricingStrategyProductInfo([7856197312], maxRequests: 10, sleepMicros: 0);

        $this->assertSame(990.50, $result['7856197312']['competitor_price']);
        $this->assertSame('available', $result['7856197312']['status']);
        $this->assertSame('pricing_strategy_product_info', $result['7856197312']['source']);

        Http::assertSent(fn ($request) => $request->url() === 'https://api-seller.ozon.ru/v1/pricing-strategy/product/info'
            && $request['product_id'] === 7856197312);
    }

    public function test_get_pricing_strategy_product_info_does_not_return_rows_for_api_errors(): void
    {
        Http::fake([
            'https://api-seller.ozon.ru/v1/pricing-strategy/product/info' => Http::response([
                'error' => ['message' => 'rate limit'],
            ], 500),
        ]);

        $api = new ProductsApi(new OzonClient('client', 'key'));

        $this->assertSame([], $api->getPricingStrategyProductInfo([7856197312], maxRequests: 10, sleepMicros: 0));
    }

    public function test_get_pricing_strategy_product_info_marks_disabled_strategy_without_price(): void
    {
        Http::fake([
            'https://api-seller.ozon.ru/v1/pricing-strategy/product/info' => Http::response([
                'result' => [
                    'strategy_id' => '',
                    'is_enabled' => false,
                    'strategy_product_price' => 0,
                    'price_downloaded_at' => '',
                    'strategy_competitor_id' => 0,
                    'strategy_competitor_product_url' => '',
                ],
            ]),
        ]);

        $api = new ProductsApi(new OzonClient('client', 'key'));

        $result = $api->getPricingStrategyProductInfo([7856197312], maxRequests: 10, sleepMicros: 0);

        $this->assertSame(0.0, $result['7856197312']['competitor_price']);
        $this->assertFalse($result['7856197312']['is_enabled']);
        $this->assertSame('disabled', $result['7856197312']['status']);
    }
}
