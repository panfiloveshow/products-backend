<?php

namespace Tests\Unit;

use App\Domains\Ozon\Api\OzonClient;
use App\Domains\Ozon\Api\ProductsApi;
use Mockery;
use Tests\TestCase;

class OzonActionPricesTest extends TestCase
{
    private function makeApi(array $clientReturns): ProductsApi
    {
        $client = Mockery::mock(OzonClient::class);

        $client->shouldReceive('get')->andReturnUsing(
            fn (string $endpoint, array $params = []) => $clientReturns['get'][$endpoint] ?? null
        );
        $client->shouldReceive('post')->andReturnUsing(
            fn (string $endpoint, array $data = []) => $clientReturns['post'][$endpoint] ?? null
        );

        return new ProductsApi($client);
    }

    public function test_get_action_prices_collects_participating_actions(): void
    {
        $api = $this->makeApi([
            'get' => [
                '/v1/actions' => ['result' => [
                    ['id' => 10, 'participating_products_count' => 2],
                    ['id' => 20, 'participating_products_count' => 0], // не участвует — пропускаем
                ]],
            ],
            'post' => [
                '/v1/actions/products' => ['result' => [
                    'products' => [
                        ['id' => 111, 'action_price' => 250.0],
                        ['id' => 222, 'action_price' => 90.0],
                    ],
                    'total' => 2,
                ]],
            ],
        ]);

        $prices = $api->getActionPrices();

        $this->assertSame([111 => 250.0, 222 => 90.0], $prices);
    }

    public function test_get_prices_uses_action_price_as_actual_price(): void
    {
        $api = $this->makeApi([
            'get' => [
                '/v1/actions' => ['result' => [
                    ['id' => 10, 'participating_products_count' => 1],
                ]],
            ],
            'post' => [
                '/v1/actions/products' => ['result' => [
                    'products' => [['id' => 111, 'action_price' => 300.0]],
                    'total' => 1,
                ]],
                '/v5/product/info/prices' => [
                    'items' => [[
                        'offer_id' => '3-02/3516',
                        'product_id' => 111,
                        // marketing_seller_price Ozon больше не заполняет
                        'price' => ['price' => 600.0, 'old_price' => 900.0, 'marketing_seller_price' => 0],
                    ]],
                    'cursor' => '',
                ],
            ],
        ]);

        $prices = $api->getPrices();

        $this->assertSame(300.0, $prices['3-02/3516']['actual_price']);
        $this->assertTrue($prices['3-02/3516']['is_in_promotion']);
        $this->assertSame(50.0, $prices['3-02/3516']['promotion_discount']);
        $this->assertSame(600.0, $prices['3-02/3516']['price']);
    }

    public function test_get_prices_prefers_marketing_seller_price_over_lower_action_price(): void
    {
        // Кейс A65: витрина 668 (marketing_seller_price), а /v1/actions отдаёт 420
        // (цена участия в неактивной акции). Актуальная цена = 668, НЕ min(668, 420).
        $api = $this->makeApi([
            'get' => [
                '/v1/actions' => ['result' => [
                    ['id' => 10, 'participating_products_count' => 1],
                ]],
            ],
            'post' => [
                '/v1/actions/products' => ['result' => [
                    'products' => [['id' => 111, 'action_price' => 420.0]],
                    'total' => 1,
                ]],
                '/v5/product/info/prices' => [
                    'items' => [[
                        'offer_id' => 'A65',
                        'product_id' => 111,
                        'price' => ['price' => 693.0, 'old_price' => 1050.0, 'marketing_seller_price' => 668.0],
                    ]],
                    'cursor' => '',
                ],
            ],
        ]);

        $prices = $api->getPrices();

        $this->assertSame(668.0, $prices['A65']['actual_price']);
        $this->assertTrue($prices['A65']['is_in_promotion']);
    }

    public function test_get_prices_falls_back_to_base_price_without_actions(): void
    {
        $api = $this->makeApi([
            'get' => ['/v1/actions' => ['result' => []]],
            'post' => [
                '/v5/product/info/prices' => [
                    'items' => [[
                        'offer_id' => 'SKU-1',
                        'product_id' => 999,
                        'price' => ['price' => 500.0, 'marketing_seller_price' => 0],
                    ]],
                    'cursor' => '',
                ],
            ],
        ]);

        $prices = $api->getPrices();

        $this->assertSame(500.0, $prices['SKU-1']['actual_price']);
        $this->assertFalse($prices['SKU-1']['is_in_promotion']);
    }
}
