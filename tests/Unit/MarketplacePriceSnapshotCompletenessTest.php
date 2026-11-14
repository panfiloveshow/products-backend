<?php

namespace Tests\Unit;

use App\Domains\Ozon\Api\OzonClient;
use App\Domains\Ozon\Api\ProductsApi as OzonProductsApi;
use App\Domains\Wildberries\Api\ProductsApi as WbProductsApi;
use App\Domains\Wildberries\Api\WildberriesClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketplacePriceSnapshotCompletenessTest extends TestCase
{
    public function test_wb_short_terminal_page_does_not_spend_a_second_base_token_window_on_an_empty_probe(): void
    {
        Http::preventStrayRequests();
        Http::fake(['discounts-prices-api.wildberries.ru/*' => Http::response(['data' => ['listGoods' => [
            ['nmID' => 123, 'sizes' => [['sizeID' => 456, 'price' => 1000, 'discountedPrice' => 800]]],
        ]]])]);
        $snapshot = (new WbProductsApi(new WildberriesClient('wb-test')))->getPriceSnapshot();
        $this->assertTrue($snapshot['complete']);
        $this->assertSame(800.0, $snapshot['prices']['123:456']['final_price']);
        Http::assertSentCount(1);
    }

    public function test_wb_second_page_rate_limit_keeps_successful_rows_but_never_reports_a_complete_snapshot(): void
    {
        $items = array_map(fn ($id) => ['nmID' => $id, 'sizes' => [['sizeID' => $id, 'price' => 1000, 'discountedPrice' => 800]]], range(1, 1000));
        Http::preventStrayRequests();
        Http::fake(['discounts-prices-api.wildberries.ru/*' => Http::sequence()
            ->push(['data' => ['listGoods' => $items]])
            ->push(['error' => 'limited'], 429, ['X-Ratelimit-Retry' => '900'])]);

        $snapshot = (new WbProductsApi(new WildberriesClient('wb-test')))->getPriceSnapshot();

        $this->assertFalse($snapshot['complete']);
        $this->assertSame(429, $snapshot['http_status']);
        $this->assertSame(900, $snapshot['retry_after']);
        $this->assertSame(800.0, $snapshot['prices']['1']['final_price']);
        $this->assertNotEmpty($snapshot['prices']['1']['price_observed_at']);
        Http::assertSentCount(2);
    }

    public function test_ozon_malformed_later_page_is_not_silently_treated_as_complete(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api-seller.ozon.ru/v5/product/info/prices' => Http::sequence()
            ->push(['items' => [['offer_id' => 'A', 'price' => ['price' => '1000', 'marketing_seller_price' => '850']]], 'cursor' => 'next'])
            ->push(['unexpected' => 'payload'])]);

        $snapshot = (new OzonProductsApi(new OzonClient('test', 'test')))->getPriceSnapshot();

        $this->assertFalse($snapshot['complete']);
        $this->assertSame(850.0, $snapshot['prices']['A']['actual_price']);
        Http::assertSentCount(2);
    }

    public function test_ozon_missing_archived_offer_is_recovered_by_an_explicit_offer_filter(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api-seller.ozon.ru/v5/product/info/prices' => Http::sequence()
            ->push(['items' => [], 'cursor' => ''])
            ->push(['items' => [['product_id' => 7, 'offer_id' => 'ARCHIVED',
                'price' => ['price' => '3009', 'old_price' => '6018', 'marketing_seller_price' => '3009']]], 'cursor' => ''])]);
        $snapshot = (new OzonProductsApi(new OzonClient('test', 'test')))->getPriceSnapshot(skus: ['ARCHIVED']);
        $this->assertTrue($snapshot['complete']);
        $this->assertSame(3009.0, $snapshot['prices']['ARCHIVED']['actual_price']);
        Http::assertSent(fn ($request) => ($request->data()['filter']['offer_id'] ?? []) === ['ARCHIVED']);
        Http::assertSentCount(2);
    }

    public function test_ozon_targeted_recovery_rate_limit_keeps_bulk_prices_and_reports_an_incomplete_snapshot(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api-seller.ozon.ru/v5/product/info/prices' => Http::sequence()
            ->push(['items' => [['offer_id' => 'ACTIVE', 'price' => ['price' => '1000', 'marketing_seller_price' => '850']]], 'cursor' => ''])
            ->push(['message' => 'limited'], 429, ['Retry-After' => '300'])]);
        $snapshot = (new OzonProductsApi(new OzonClient('test', 'test')))->getPriceSnapshot(skus: ['ACTIVE', 'ARCHIVED']);
        $this->assertFalse($snapshot['complete']);
        $this->assertSame(429, $snapshot['http_status']);
        $this->assertSame(300, $snapshot['retry_after']);
        $this->assertSame(850.0, $snapshot['prices']['ACTIVE']['actual_price']);
        $this->assertArrayNotHasKey('ARCHIVED', $snapshot['prices']);
        Http::assertSentCount(2);
    }

    public function test_ozon_known_marketing_price_does_not_wait_for_unneeded_action_reports(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api-seller.ozon.ru/v5/product/info/prices' => Http::response([
            'items' => [['offer_id' => 'A', 'price' => ['price' => '1000', 'marketing_seller_price' => '850']]], 'cursor' => '',
        ])]);

        $snapshot = (new OzonProductsApi(new OzonClient('test', 'test')))->getPriceSnapshot();

        $this->assertTrue($snapshot['complete']);
        $this->assertSame(850.0, $snapshot['prices']['A']['actual_price']);
        Http::assertSentCount(1);
    }

    public function test_ozon_action_listing_finishes_at_total_even_when_terminal_cursor_is_not_empty(): void
    {
        Http::preventStrayRequests();
        $products = array_map(fn ($id) => ['id' => $id, 'action_price' => ['amount' => '800']], range(1, 100));
        Http::fake([
            'api-seller.ozon.ru/v5/product/info/prices' => Http::response([
                'items' => [['product_id' => 101, 'offer_id' => 'A', 'price' => ['price' => '1000', 'marketing_seller_price' => '0']]], 'cursor' => '',
            ]),
            'api-seller.ozon.ru/v1/actions' => Http::response(['result' => [['id' => 5, 'participating_products_count' => 101]]]),
            'api-seller.ozon.ru/v2/actions/products' => Http::sequence()
                ->push(['products' => $products, 'total' => 101, 'last_id' => 'page-two'])
                ->push(['products' => [['id' => 101, 'action_price' => ['amount' => '750']]], 'total' => 101, 'last_id' => 'last-product']),
        ]);
        $snapshot = (new OzonProductsApi(new OzonClient('test', 'test')))->getPriceSnapshot();
        $this->assertTrue($snapshot['complete']);
        $this->assertSame(750.0, $snapshot['prices']['A']['actual_price']);
        Http::assertSentCount(4);
    }

    public function test_ozon_repeated_action_cursor_before_total_does_not_confirm_a_partial_action_price(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api-seller.ozon.ru/v5/product/info/prices' => Http::response([
                'items' => [['product_id' => 1, 'offer_id' => 'A', 'price' => ['price' => '1000', 'marketing_seller_price' => '0']]], 'cursor' => '',
            ]),
            'api-seller.ozon.ru/v1/actions' => Http::response(['result' => [['id' => 5, 'participating_products_count' => 3]]]),
            'api-seller.ozon.ru/v2/actions/products' => Http::sequence()
                ->push(['products' => [['id' => 1, 'action_price' => ['amount' => '750']]], 'total' => 3, 'last_id' => 'repeated'])
                ->push(['products' => [], 'total' => 3, 'last_id' => 'repeated']),
        ]);
        $snapshot = (new OzonProductsApi(new OzonClient('test', 'test')))->getPriceSnapshot();
        $this->assertFalse($snapshot['complete']);
        $this->assertSame([], $snapshot['prices']);
        Http::assertSentCount(4);
    }

    public function test_ozon_unavailable_required_action_fallback_does_not_invent_a_fresh_base_price(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api-seller.ozon.ru/v5/product/info/prices' => Http::response([
                'items' => [['offer_id' => 'A', 'price' => ['price' => '1000', 'marketing_seller_price' => '0']]], 'cursor' => '',
            ]),
            'api-seller.ozon.ru/v1/actions' => Http::response(['message' => 'limited'], 429, ['Retry-After' => '300']),
        ]);

        $snapshot = (new OzonProductsApi(new OzonClient('test', 'test')))->getPriceSnapshot();

        $this->assertFalse($snapshot['complete']);
        $this->assertSame([], $snapshot['prices']);
        $this->assertSame(429, $snapshot['http_status']);
        $this->assertSame(300, $snapshot['retry_after']);
        Http::assertSentCount(2);
    }
}
