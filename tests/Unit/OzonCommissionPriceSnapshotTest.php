<?php

namespace Tests\Unit;

use App\Domains\Ozon\Api\OzonClient;
use App\Domains\Ozon\Api\ProductsApi;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OzonCommissionPriceSnapshotTest extends TestCase
{
    public function test_legacy_price_reader_keeps_identity_raw_fees_and_response_time(): void
    {
        $at = now()->utc()->startOfSecond();
        $this->travelTo($at);
        try {
            Http::preventStrayRequests();
            Http::fake([
                'api-seller.ozon.ru/v1/actions' => Http::response(['result' => []]),
                'api-seller.ozon.ru/v5/product/info/prices' => Http::response(['items' => [[
                    'product_id' => 10001, 'offer_id' => 'A', 'price' => ['price' => '1000', 'marketing_seller_price' => '850'],
                    'commissions' => ['sales_percent_fbo' => 20.001, 'sales_percent_fbs' => 0],
                ]], 'cursor' => '']),
            ]);
            $row = (new ProductsApi(new OzonClient('test', 'test')))->getPrices()['A'];
            $this->assertSame('A', $row['offer_id']);
            $this->assertSame(10001, $row['product_id']);
            $this->assertSame(['sales_percent_fbo' => 20.001, 'sales_percent_fbs' => 0], $row['commissions']);
            $this->assertSame($at->toIso8601String(), $row['price_observed_at']);
            $this->assertSame($at->format('Y-m-d\TH:i:s.uP'), $row['commissions_observed_at']);
        } finally {
            $this->travelBack();
        }
    }

    public function test_deferred_action_read_does_not_move_the_fee_observation_time(): void
    {
        $at = now()->utc()->startOfSecond();
        $this->travelTo($at);
        try {
            Http::preventStrayRequests();
            Http::fake(function ($request) use ($at) {
                if (str_ends_with($request->url(), '/v5/product/info/prices')) {
                    return Http::response(['items' => [['product_id' => 10001, 'offer_id' => 'A',
                        'price' => ['price' => '1000', 'marketing_seller_price' => '0'],
                        'commissions' => ['sales_percent_fbo' => 20]]], 'cursor' => '']);
                }
                if (str_ends_with($request->url(), '/v1/actions')) {
                    $this->travelTo($at->copy()->addMinute());

                    return Http::response(['result' => [['id' => 7, 'participating_products_count' => 1]]]);
                }
                if (str_ends_with($request->url(), '/v2/actions/products')) {
                    return Http::response(['products' => [['id' => 10001, 'action_price' => ['amount' => '900']]], 'total' => 1, 'last_id' => '']);
                }

                return Http::response([], 404);
            });
            $snapshot = (new ProductsApi(new OzonClient('test', 'test')))->getPriceSnapshot();
            $this->assertTrue($snapshot['complete']);
            $row = $snapshot['prices']['A'];
            $this->assertSame(900.0, $row['actual_price']);
            $this->assertSame($at->toIso8601String(), $row['price_observed_at']);
            $this->assertSame($at->format('Y-m-d\TH:i:s.uP'), $row['commissions_observed_at']);
            $this->assertSame('A', $row['offer_id']);
            Http::assertSentCount(3);
        } finally {
            $this->travelBack();
        }
    }

    public function test_offer_zero_remains_a_valid_identity_in_both_readers(): void
    {
        Http::preventStrayRequests();
        foreach (['getPrices', 'getPriceSnapshot'] as $method) {
            Http::fake([
                'api-seller.ozon.ru/v1/actions' => Http::response(['result' => []]),
                'api-seller.ozon.ru/v5/product/info/prices' => Http::response(['items' => [[
                    'product_id' => 10001, 'offer_id' => '0', 'price' => ['price' => '1000', 'marketing_seller_price' => '850'],
                    'commissions' => ['sales_percent_fbo' => 20],
                ]], 'cursor' => '']),
            ]);
            $result = (new ProductsApi(new OzonClient('test', 'test')))->$method();
            $prices = $method === 'getPrices' ? $result : $result['prices'];
            $this->assertArrayHasKey('0', $prices);
            $this->assertSame('0', $prices['0']['offer_id']);
        }
    }
}
