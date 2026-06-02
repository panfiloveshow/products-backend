<?php

namespace Tests\Unit;

use App\Domains\Ozon\Api\FboPostingsApi;
use App\Domains\Ozon\Api\OzonClient;
use App\Domains\Ozon\Api\SalesApi;
use App\Domains\Ozon\Api\StockAnalyticsApi;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OzonApiContractsTest extends TestCase
{
    public function test_stock_analytics_uses_post_body_contract(): void
    {
        Http::fake([
            'api-seller.ozon.ru/v1/analytics/stocks' => Http::response([
                'items' => [
                    [
                        'sku' => 123,
                        'offer_id' => 'SKU-1',
                        'cluster_id' => 154,
                        'warehouse_name' => 'Москва',
                    ],
                ],
            ]),
        ]);

        $api = new StockAnalyticsApi(new OzonClient('client', 'key'));
        $items = $api->getStockAnalytics([123]);

        $this->assertCount(1, $items);
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://api-seller.ozon.ru/v1/analytics/stocks'
                && $request['limit'] === 1000
                && $request['offset'] === 0
                && $request['warehouse_type'] === 'ALL';
        });
    }

    public function test_fbo_postings_list_uses_v3_endpoint(): void
    {
        Http::fake([
            'api-seller.ozon.ru/v3/posting/fbo/list' => Http::response([
                'result' => ['postings' => []],
            ]),
        ]);

        $api = new FboPostingsApi(new OzonClient('client', 'key'));
        $api->list(['status' => 'delivered']);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://api-seller.ozon.ru/v3/posting/fbo/list';
        });
    }

    public function test_fbo_posting_sales_use_real_date_windows_and_spike_stats(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-26 12:00:00'));

        Http::fake([
            'api-seller.ozon.ru/v3/posting/fbo/list' => Http::response([
                'result' => [
                    'postings' => [
                        [
                            'delivered_at' => '2026-05-20T10:00:00Z',
                            'analytics_data' => ['warehouse_id' => 'wh-1', 'warehouse_name' => 'Москва'],
                            'products' => [['sku' => 1001, 'offer_id' => 'SKU-1', 'quantity' => 4]],
                        ],
                        [
                            'delivered_at' => '2026-05-10T10:00:00Z',
                            'analytics_data' => ['warehouse_id' => 'wh-1', 'warehouse_name' => 'Москва'],
                            'products' => [['sku' => 1001, 'offer_id' => 'SKU-1', 'quantity' => 10]],
                        ],
                    ],
                ],
            ]),
        ]);

        $api = new SalesApi(new OzonClient('client', 'key'));
        $sales = $api->getSalesBySkuAndWarehouse(28, ['1001' => 'SKU-1']);

        Carbon::setTestNow();

        $row = $sales['SKU-1']['wh-1'];
        $this->assertSame(4, $row['sales_7_days']);
        $this->assertSame(4, $row['sales_14_days']);
        $this->assertSame(14, $row['sales_30_days']);
        $this->assertSame(14, $row['ordered_units_total']);
        $this->assertSame(10, $row['peak_day_units']);
        $this->assertEqualsWithDelta(0.7142, $row['peak_share'], 0.001);
        $this->assertSame(2, $row['active_days']);
    }
}
