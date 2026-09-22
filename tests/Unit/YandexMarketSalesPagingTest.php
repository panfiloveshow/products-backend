<?php

namespace Tests\Unit;

use App\Domains\YandexMarket\Api\SalesApi;
use App\Domains\YandexMarket\Api\YandexMarketClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * stats/orders: limit/pageToken — query-параметры. pagerFrom/pagerSize в теле ЯМ
 * игнорирует → 100 заказов по умолчанию и обрезанное окно продаж.
 */
class YandexMarketSalesPagingTest extends TestCase
{
    public function test_orders_follow_page_token_in_query(): void
    {
        Http::fake(function (Request $request) {
            $order = fn (string $sku) => ['status' => 'DELIVERED', 'items' => [['shopSku' => $sku, 'count' => 1]]];

            return str_contains($request->url(), 'pageToken=t1')
                ? Http::response(['result' => ['orders' => [$order('B')], 'paging' => []]])
                : Http::response(['result' => ['orders' => [$order('A'), $order('B')], 'paging' => ['nextPageToken' => 't1']]]);
        });

        $api = new SalesApi(new YandexMarketClient('ACMA:key', '22'));
        $method = new \ReflectionMethod(SalesApi::class, 'fetchOrdersBySku');
        $result = $method->invoke($api, '2026-09-01', '2026-09-22');

        $this->assertSame(1, $result['A']['total']);
        $this->assertSame(2, $result['B']['total']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.partner.market.yandex.ru/v2/campaigns/22/stats/orders?limit=200')
            && ! isset($r['pagerFrom']));
    }
}
