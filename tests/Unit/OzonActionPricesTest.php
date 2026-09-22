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

    private function money(float $amount): array
    {
        return ['amount' => (string) $amount, 'currency' => 'RUB'];
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
                // v2: без обёртки result, цены — {amount, currency}
                '/v2/actions/products' => [
                    'products' => [
                        ['id' => 111, 'action_price' => $this->money(250.0)],
                        ['id' => 222, 'action_price' => $this->money(90.0)],
                    ],
                    'last_id' => '',
                    'total' => 2,
                ],
            ],
        ]);

        $prices = $api->getActionPrices();

        $this->assertSame([111 => 250.0, 222 => 90.0], $prices);
    }

    public function test_action_products_paginate_by_last_id_cursor(): void
    {
        // v1 листал offset'ом, который Ozon игнорирует, — читалась только первая сотня.
        $client = Mockery::mock(OzonClient::class);
        $client->shouldReceive('get')->with('/v1/actions')
            ->andReturn(['result' => [['id' => 10, 'is_participating' => true]]]);
        $client->shouldReceive('post')->once()
            ->with('/v2/actions/products', ['action_id' => 10, 'limit' => 100, 'last_id' => ''])
            ->andReturn(['products' => [['id' => 1, 'action_price' => $this->money(100.0)]], 'last_id' => 'c1', 'total' => 3]);
        $client->shouldReceive('post')->once()
            ->with('/v2/actions/products', ['action_id' => 10, 'limit' => 100, 'last_id' => 'c1'])
            ->andReturn(['products' => [
                ['id' => 2, 'action_price' => $this->money(200.0)],
                ['id' => 3, 'action_price' => $this->money(300.0)],
            ], 'last_id' => '', 'total' => 3]);
        $client->shouldNotReceive('post')->with('/v1/actions/products', Mockery::any());

        $prices = (new ProductsApi($client))->getActionPrices();

        $this->assertSame([1 => 100.0, 2 => 200.0, 3 => 300.0], $prices);
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
                '/v2/actions/products' => [
                    'products' => [['id' => 111, 'action_price' => $this->money(300.0)]],
                    'last_id' => '',
                    'total' => 1,
                ],
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
        $this->assertSame('action_price', $prices['3-02/3516']['price_source']);
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
                '/v2/actions/products' => [
                    'products' => [['id' => 111, 'action_price' => $this->money(420.0)]],
                    'last_id' => '',
                    'total' => 1,
                ],
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
        $this->assertSame('marketing_seller_price', $prices['A65']['price_source']);
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
        $this->assertSame('seller_price', $prices['SKU-1']['price_source']);
        $this->assertFalse($prices['SKU-1']['is_in_promotion']);
    }
}
