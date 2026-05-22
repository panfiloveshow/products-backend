<?php

namespace Tests\Unit;

use App\Domains\Ozon\Api\OzonClient;
use App\Domains\Ozon\Api\ProductsApi;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OzonProductsApiTest extends TestCase
{
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
}
