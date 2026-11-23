<?php

namespace Tests\Unit;

use App\Domains\Wildberries\Api\InventoryApi;
use App\Domains\Wildberries\Api\ProductsApi;
use App\Domains\Wildberries\Api\RealizationReportApi;
use App\Domains\Wildberries\Api\SalesApi;
use App\Domains\Wildberries\Api\WildberriesClient;
use App\Domains\Wildberries\Api\WildberriesRateLimitException;
use App\Domains\Wildberries\WildberriesMarketplace;
use App\Models\Integration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class WildberriesApiCompatibilityTest extends TestCase
{
    public function test_full_inventory_prefetches_the_localization_window_without_expanding_30_day_metrics(): void
    {
        Http::preventStrayRequests();
        $recent = now()->subDays(5)->format('Y-m-d\TH:i:s');
        $old = now()->subDays(60)->format('Y-m-d\TH:i:s');
        $rows = [
            ['barcode' => 'RECENT', 'date' => $recent, 'lastChangeDate' => $recent, 'warehouseName' => 'WB', 'quantity' => 2],
            ['barcode' => 'OLD', 'date' => $old, 'lastChangeDate' => $old, 'warehouseName' => 'WB', 'quantity' => 9],
        ];
        Http::fake(['statistics-api.wildberries.ru/*' => Http::response($rows)]);
        $sales = new SalesApi(new WildberriesClient('inventory-prefetch-token'));

        $metrics = $sales->getSalesByWarehouse(30, 91);

        $this->assertSame(2, $metrics['RECENT']['WB']['sales_30_days']);
        $this->assertArrayNotHasKey('OLD', $metrics);
        Http::assertSent(fn (Request $r) => $r['dateFrom'] === now()->subDays(91)->format('Y-m-d'));
        $this->assertSame($rows, (new WildberriesClient('inventory-prefetch-token'))->statistics('/api/v1/supplier/sales',
            ['dateFrom' => now()->subDays(91)->format('Y-m-d')]));
        Http::assertSentCount(1);
    }

    public function test_recent_statistics_reuses_the_wider_response_with_the_documented_last_change_filter(): void
    {
        Http::preventStrayRequests();
        $rows = [
            ['id' => 'recent-sale', 'date' => '2026-10-15T10:00:00', 'lastChangeDate' => '2026-10-16T10:00:00'],
            ['id' => 'old-sale-updated-recently', 'date' => '2026-08-01T10:00:00', 'lastChangeDate' => '2026-10-17T10:00:00'],
            ['id' => 'old-change', 'date' => '2026-08-01T10:00:00', 'lastChangeDate' => '2026-08-02T10:00:00'],
        ];
        Http::fake(['statistics-api.wildberries.ru/*' => Http::response($rows)]);
        $first = new WildberriesClient('statistics-reuse-token');
        $this->assertSame($rows, $first->statistics('/api/v1/supplier/sales', ['dateFrom' => '2026-08-01']));

        $second = new WildberriesClient('statistics-reuse-token');
        $narrow = $second->statistics('/api/v1/supplier/sales', ['dateFrom' => '2026-10-01']);

        $this->assertSame(['recent-sale', 'old-sale-updated-recently'], array_column($narrow, 'id'));
        $this->assertSame(200, $second->getLastResponseStatus());
        Http::assertSentCount(1);
        (new WildberriesClient('another-seller-token'))->statistics('/api/v1/supplier/sales', ['dateFrom' => '2026-10-01']);
        Http::assertSentCount(2);
        $this->travel(66)->seconds();
        $second->statistics('/api/v1/supplier/sales', ['dateFrom' => '2026-08-01']);
        Http::assertSentCount(3);
    }

    public function test_statistics_does_not_substitute_a_narrow_snapshot_for_a_wider_period(): void
    {
        Http::preventStrayRequests();
        Http::fake(['statistics-api.wildberries.ru/*' => Http::response([
            ['lastChangeDate' => '2026-10-15T10:00:00'],
        ])]);
        $client = new WildberriesClient('statistics-narrow-token');
        $client->statistics('/api/v1/supplier/sales', ['dateFrom' => '2026-10-01']);

        $this->assertNull($client->statistics('/api/v1/supplier/sales', ['dateFrom' => '2026-08-01']));
        $this->assertSame(429, $client->getLastResponseStatus());
        Http::assertSentCount(1);
    }

    public function test_inventory_report_cooldown_is_exposed_without_blocking_or_repeating_content_pages(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'content-api.wildberries.ru/content/v2/get/cards/list' => Http::response([
                'cards' => [['nmID' => 123, 'sizes' => [['chrtID' => 456, 'skus' => ['BAR']]]]],
                'cursor' => ['total' => 1, 'nmID' => 123, 'updatedAt' => '2026-11-02T00:00:00Z'],
            ]),
            'seller-analytics-api.wildberries.ru/api/analytics/v1/stocks-report/wb-warehouses' => Http::response(['detail' => 'token does not satisfy additional requirements'], 403),
            'seller-analytics-api.wildberries.ru/api/v1/warehouse_remains*' => Http::response(['detail' => 'rate limited'], 429, ['X-RateLimit-Retry' => '3600']),
            '*' => Http::response([]),
        ]);
        $client = new WildberriesClient('inventory-defer-test');
        $inventory = new InventoryApi($client);
        $started = microtime(true);

        $this->assertSame([], $inventory->getStocks());

        $this->assertLessThan(10, microtime(true) - $started);
        $this->assertSame(3600, $inventory->getDeferredRetryAfter());
        $this->assertCount(0, Http::recorded(fn (Request $r) => str_contains($r->url(), '/content/v2/get/cards/list')));
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/api/v1/warehouse_remains?groupByBarcode=true&groupBySa=true')));
        $this->assertSame(429, $client->getLastResponseStatus());
        $this->assertSame(3600, $client->getLastRateLimitRetryAfter());

        $this->expectException(WildberriesRateLimitException::class);
        (new WildberriesMarketplace(['api_key' => 'inventory-defer-test']))->getInventory();
    }

    public function test_marketplace_resolves_an_integration_key_when_the_job_has_no_local_key(): void
    {
        config(['services.wildberries.api_key' => 'unrelated-global-key']);
        $integration = Mockery::mock(Integration::class)->makePartial();
        $integration->shouldReceive('resolveCredentials')->once()->andReturn(['api_key' => 'resolved-integration-key']);
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => Http::response(['authorization' => $request->header('Authorization')[0]], 200));

        $marketplace = new WildberriesMarketplace(['api_key' => ''], $integration);
        $response = $marketplace->getClient()->commonGet('/api/v1/tariffs/commission');

        $this->assertSame('resolved-integration-key', $response['authorization']);
        Http::assertSentCount(1);
    }

    public function test_an_explicit_job_key_takes_precedence_over_resolved_credentials(): void
    {
        $integration = Mockery::mock(Integration::class)->makePartial();
        $integration->shouldNotReceive('resolveCredentials');
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => Http::response(['authorization' => $request->header('Authorization')[0]], 200));

        $marketplace = new WildberriesMarketplace(['api_key' => 'explicit-job-key'], $integration);
        $response = $marketplace->getClient()->commonGet('/api/v1/tariffs/commission');

        $this->assertSame('explicit-job-key', $response['authorization']);
    }

    public function test_a_zero_buyout_percentage_from_the_official_funnel_is_preserved(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'seller-analytics-api.wildberries.ru/api/analytics/v3/sales-funnel/products' => Http::response([
                'data' => ['products' => [[
                    'product' => ['nmId' => 123, 'productRating' => 8, 'feedbackRating' => 4.5],
                    'statistic' => ['selected' => [
                        'orderCount' => 10,
                        'buyoutCount' => 3,
                        'conversions' => ['buyoutPercent' => 0],
                    ]],
                ]]],
            ], 200),
        ]);

        $ratings = (new ProductsApi(new WildberriesClient('test-key')))->getCardRatings([123]);

        // WB's cohort percentage excludes deliveries still in transit; 3/10 is
        // a different metric and must not replace a valid zero from the API.
        $this->assertSame(0.0, $ratings[123]['redemption_rate']);
        $this->assertSame('wb_sales_funnel', $ratings[123]['redemption_source']);
    }

    public function test_catalog_without_the_documented_cursor_cannot_be_declared_complete(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'content-api.wildberries.ru/content/v2/get/cards/list' => Http::response([
                'cards' => [['nmID' => 123, 'vendorCode' => 'A-1']],
            ], 200),
        ]);

        $page = (new ProductsApi(new WildberriesClient('test-key')))->getProducts();

        $this->assertCount(1, $page['cards']);
        $this->assertFalse($page['complete']);
    }

    public function test_a_finance_transport_failure_is_not_a_fresh_empty_report(): void
    {
        Http::preventStrayRequests();
        Http::fake(fn () => throw new ConnectionException('connection unavailable'));

        $snapshot = (new RealizationReportApi(new WildberriesClient('test-key')))->getStorageAndAcquiringBySku();

        $this->assertSame('unavailable', $snapshot['status']);
        $this->assertNull($snapshot['acquiring']['observed_at']);
    }

    public function test_an_invalid_finance_response_is_not_a_fresh_empty_report(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'finance-api.wildberries.ru/api/finance/v1/sales-reports/detailed' => Http::response(['unexpected' => 'object'], 200),
        ]);

        $snapshot = (new RealizationReportApi(new WildberriesClient('test-key')))->getStorageAndAcquiringBySku();

        $this->assertSame('unavailable', $snapshot['status']);
        $this->assertNull($snapshot['acquiring']['observed_at']);
    }

    public function test_the_documented_finance_204_is_a_successful_empty_report(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'finance-api.wildberries.ru/api/finance/v1/sales-reports/detailed' => Http::response('', 204),
        ]);

        $snapshot = (new RealizationReportApi(new WildberriesClient('test-key')))->getStorageAndAcquiringBySku();

        $this->assertSame('success', $snapshot['status']);
        $this->assertNotNull($snapshot['acquiring']['observed_at']);
        $this->assertSame([], $snapshot['storage_by_sku']);
    }
}
