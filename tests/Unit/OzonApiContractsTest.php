<?php

namespace Tests\Unit;

use App\Domains\Ozon\Api\FboPostingsApi;
use App\Domains\Ozon\Api\OzonClient;
use App\Domains\Ozon\Api\SalesApi;
use App\Domains\Ozon\Api\StockAnalyticsApi;
use App\Domains\Ozon\Api\SuppliesApi;
use App\Exceptions\OzonAmbiguousRemoteStateException;
use App\Exceptions\OzonPreconditionException;
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

    public function test_no_production_fbo_list_client_uses_deprecated_v2_endpoint(): void
    {
        $files = [
            app_path('Domains/Ozon/Api/WarehousesApi.php'),
            app_path('Services/PostingService.php'),
            app_path('Services/Marketplace/OzonService.php'),
        ];

        foreach ($files as $file) {
            $this->assertStringNotContainsString(
                '/v2/posting/fbo/list',
                (string) file_get_contents($file),
                "{$file} must use the current FBO postings list contract"
            );
        }
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
        $this->assertSame(14, $row['sales_28_days']);
        $this->assertSame(14, $row['sales_30_days']);
        $this->assertSame(14, $row['sales_56_days']);
        $this->assertSame(14, $row['ordered_units_total']);
        $this->assertSame(10, $row['peak_day_units']);
        $this->assertEqualsWithDelta(0.7142, $row['peak_share'], 0.001);
        $this->assertSame(2, $row['active_days']);
    }

    public function test_draft_creation_keeps_operation_separate_until_draft_is_ready(): void
    {
        Http::fake([
            'api-seller.ozon.ru/v1/draft/create' => Http::response([
                'operation_id' => 'operation-42',
            ]),
            'api-seller.ozon.ru/v1/draft/create/info' => Http::response([
                'status' => 'CALCULATION_STATUS_IN_PROGRESS',
                'draft_id' => 0,
            ]),
        ]);

        $result = (new SuppliesApi(new OzonClient('client', 'key')))
            ->createDirectDraft([
                'cluster_id' => 101,
                'items' => [['sku' => 700001, 'quantity' => 5]],
            ]);

        $this->assertNull($result['draft_id']);
        $this->assertSame('operation-42', $result['operation_id']);
        $this->assertSame('pending', $result['status']);
        Http::assertSentCount(2);
    }

    public function test_supply_creation_never_falls_back_to_a_second_money_path_request(): void
    {
        Http::fake([
            'api-seller.ozon.ru/v1/cluster/list' => Http::response([
                'clusters' => [],
            ]),
            'api-seller.ozon.ru/v2/draft/supply/create' => Http::response([
                'code' => 'TEMPORARY_ERROR',
                'message' => 'timeout after processing',
            ], 500),
        ]);

        try {
            (new SuppliesApi(new OzonClient('client', 'key')))
                ->createSupplyFromDraft(
                    draftId: 9001,
                    warehouseId: 701,
                    timeslot: [
                        'from' => '2026-08-01T10:00:00+03:00',
                        'to' => '2026-08-01T12:00:00+03:00',
                    ],
                    clusterId: 101
                );
            $this->fail('Expected ambiguous state exception.');
        } catch (OzonAmbiguousRemoteStateException) {
            $this->assertTrue(true);
        }

        Http::assertSent(
            fn ($request): bool =>
                $request->url() === 'https://api-seller.ozon.ru/v2/draft/supply/create'
                && $request['timeslot']['from_in_timezone'] === '2026-08-01T10:00:00+03:00'
                && $request['timeslot']['to_in_timezone'] === '2026-08-01T12:00:00+03:00'
        );
        Http::assertNotSent(
            fn ($request): bool =>
                $request->url() === 'https://api-seller.ozon.ru/v1/draft/supply/create'
        );
    }

    public function test_supply_creation_without_timeslot_fails_before_external_request(): void
    {
        Http::fake();

        $this->expectException(OzonPreconditionException::class);
        $this->expectExceptionMessage('обязателен актуальный интервал');

        try {
            (new SuppliesApi(new OzonClient('client', 'key')))
                ->createSupplyFromDraft(
                    draftId: 9001,
                    warehouseId: 701,
                    timeslot: null,
                    clusterId: 101
                );
        } finally {
            Http::assertNothingSent();
        }
    }
}
