<?php

namespace Tests\Unit;

use App\Domains\Wildberries\Api\CardApi;
use App\Domains\Wildberries\Api\SalesApi;
use App\Domains\Wildberries\Api\WildberriesClient;
use App\Domains\Wildberries\UnitEconomics\WildberriesCommissionResolver;
use App\Domains\Wildberries\UnitEconomics\WildberriesSppResolver;
use App\Jobs\SyncWildberriesSppJob;
use App\Models\Integration;
use App\Services\Wildberries\WildberriesSppDataProvider;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WildberriesSppSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_one_sales_report_is_reused_for_sales_and_spp(): void
    {
        Http::fake([
            'statistics-api.wildberries.ru/api/v1/supplier/sales*' => Http::response([
                [
                    'nmId' => 100,
                    'barcode' => 'BAR-1',
                    'supplierArticle' => 'ART-1',
                    'quantity' => 1,
                    'priceWithDisc' => 1000,
                    'spp' => 10,
                    'date' => now()->subDay()->toISOString(),
                ],
                [
                    'nmId' => 100,
                    'barcode' => 'BAR-1',
                    'supplierArticle' => 'ART-1',
                    'quantity' => 1,
                    'priceWithDisc' => 900,
                    'spp' => 20,
                    'date' => now()->subDays(10)->toISOString(),
                ],
            ]),
        ]);

        $api = new SalesApi(new WildberriesClient('test-token'));
        $report = $api->getSalesReport(30);

        $this->assertIsArray($report);
        $sales = $api->buildSalesBySku($report, 30);
        $spp = $api->buildSppFromSales($report);

        $this->assertSame(2, $sales['BAR-1']['sales_30_days']);
        $this->assertSame(10.0, $spp['100']);
        Http::assertSentCount(1);
    }

    public function test_sales_report_does_not_rapidly_retry_global_rate_limit(): void
    {
        Http::fake([
            'statistics-api.wildberries.ru/api/v1/supplier/sales*' => Http::sequence()
                ->push([
                    'title' => 'too many requests',
                    'detail' => 'Limited by global limiter',
                ], 429)
                ->push([], 200),
        ]);

        $report = (new SalesApi(new WildberriesClient('test-token')))->getSalesReport(30);

        $this->assertNull($report);
        Http::assertSentCount(1);
    }

    public function test_orders_report_can_supply_spp_when_sales_report_is_unavailable(): void
    {
        Http::fake([
            'statistics-api.wildberries.ru/api/v1/supplier/orders*' => Http::response([
                ['nmId' => 100, 'spp' => 12],
                ['nmId' => 100, 'spp' => 18],
            ]),
        ]);

        $api = new SalesApi(new WildberriesClient('test-token'));
        $report = $api->getOrdersReport(30);

        $this->assertIsArray($report);
        $this->assertSame(18.0, $api->buildSppFromSales($report)['100']);
        Http::assertSentCount(1);
    }

    public function test_spp_report_prefers_barcode_and_ignores_missing_spp(): void
    {
        $api = new SalesApi(new WildberriesClient('test-token'));
        $maps = $api->buildSppMapsFromReport([
            ['nmId' => 100, 'barcode' => 'SIZE-A', 'spp' => 0],
            ['nmId' => 100, 'barcode' => 'SIZE-B', 'spp' => 20],
            ['nmId' => 100, 'barcode' => 'SIZE-B'],
            ['nmId' => 100, 'barcode' => 'SIZE-B', 'spp' => 'not-a-number'],
        ]);

        $this->assertSame(0.0, $maps['by_sku']['SIZE-A']);
        $this->assertSame(20.0, $maps['by_sku']['SIZE-B']);
        $this->assertSame(20.0, $maps['by_nm_id']['100']);
    }

    public function test_spp_report_uses_latest_order_per_barcode_without_averaging(): void
    {
        $api = new SalesApi(new WildberriesClient('test-token'));
        $maps = $api->buildSppMapsFromReport([
            [
                'nmId' => 100,
                'barcode' => 'SIZE-A',
                'spp' => 31,
                'date' => '2026-07-14T18:00:00+03:00',
                'lastChangeDate' => '2026-07-15T10:00:00+03:00',
            ],
            [
                'nmId' => 100,
                'barcode' => 'SIZE-A',
                'spp' => 0,
                'date' => '2026-07-15T09:00:00+03:00',
                'lastChangeDate' => '2026-07-15T09:01:00+03:00',
            ],
            [
                'nmId' => 100,
                'barcode' => 'SIZE-B',
                'spp' => 22,
                'lastChangeDate' => '2026-07-15T08:00:00+03:00',
            ],
            [
                'nmId' => 200,
                'barcode' => 'SIZE-C',
                'spp' => 14,
                'date' => '2026-07-15T07:00:00+03:00',
                'lastChangeDate' => '2026-07-15T07:01:00+03:00',
            ],
            [
                'nmId' => 200,
                'barcode' => 'SIZE-C',
                'spp' => 16,
                'date' => '2026-07-15T07:00:00+03:00',
                'lastChangeDate' => '2026-07-15T07:02:00+03:00',
            ],
        ]);

        $this->assertSame(0.0, $maps['by_sku']['SIZE-A']);
        $this->assertSame(22.0, $maps['by_sku']['SIZE-B']);
        $this->assertSame(16.0, $maps['by_sku']['SIZE-C']);
        $this->assertSame(0.0, $maps['by_nm_id']['100']);
    }

    public function test_sales_and_orders_share_atomic_token_cooldown(): void
    {
        Http::fake([
            'statistics-api.wildberries.ru/*' => Http::response([], 200),
        ]);

        $client = new WildberriesClient('same-seller-token');
        $this->assertSame([], $client->statistics('/api/v1/supplier/sales'));
        $this->assertNull($client->statistics('/api/v1/supplier/orders'));
        Http::assertSentCount(1);

        $this->travel(66)->seconds();
        $this->assertSame([], $client->statistics('/api/v1/supplier/orders'));
        Http::assertSentCount(2);
    }

    public function test_spp_provider_does_not_call_prices_api_on_report_retry(): void
    {
        Http::fake([
            'statistics-api.wildberries.ru/api/v1/supplier/orders*' => Http::response([
                ['nmId' => 100, 'barcode' => 'BAR-1', 'spp' => 15],
            ]),
            'card.wb.ru/cards/v4/detail*' => Http::response([], 403),
        ]);

        $integration = new class extends Integration
        {
            public function getDecryptedCredentials(): array
            {
                return ['api_key' => 'test-token-no-prices'];
            }
        };
        $integration->id = 76;
        $integration->marketplace = 'wildberries';
        $products = collect([(object) [
            'sku' => 'BAR-1',
            'price' => 1000,
            'wb_data' => ['nmID' => 100],
        ]]);

        // A retry must still prefer orders: sales may not contain a new order yet.
        $result = (new WildberriesSppDataProvider)->fetch($integration, $products, 2);

        $this->assertTrue($result['report_available']);
        $this->assertSame('orders', $result['report_source']);
        $this->assertSame(15.0, $result['spp_by_sku']['BAR-1']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/v1/supplier/sales'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'discounts-prices-api.wildberries.ru'));
    }

    public function test_card_api_stops_after_first_ip_level_forbidden_response(): void
    {
        Http::fake([
            'card.wb.ru/cards/v4/detail*' => Http::response([], 403),
        ]);

        $result = (new CardApi)->getSppByNmIds(range(1, 250));

        $this->assertSame([], $result);
        Http::assertSentCount(1);
    }

    public function test_card_api_calculates_spp_from_current_seller_price_not_crossed_out_price(): void
    {
        Http::fake([
            'card.wb.ru/cards/v4/detail*' => Http::response([
                'products' => [[
                    'id' => 100,
                    'sizes' => [[
                        'price' => [
                            'basic' => 428000,
                            'product' => 129500,
                        ],
                    ]],
                ]],
            ]),
        ]);

        $result = (new CardApi)->getSppByNmIds([100], ['100' => 2011.60]);

        $this->assertSame(35.62, $result['100']);
    }

    public function test_commission_resolver_uses_each_wb_sales_model(): void
    {
        $commission = [
            'fbo' => 48,
            'fbs' => 45,
            'fbs_express' => 3,
            'pickup' => 44.5,
            'booking' => 44,
        ];

        $this->assertSame(48.0, WildberriesCommissionResolver::resolve([], $commission, 'FBO'));
        $this->assertSame(45.0, WildberriesCommissionResolver::resolve([], $commission, 'FBS'));
        $this->assertSame(3.0, WildberriesCommissionResolver::resolve([], $commission, 'EDBS'));
        $this->assertSame(44.5, WildberriesCommissionResolver::resolve([], $commission, 'DBS'));
        $this->assertSame(44.0, WildberriesCommissionResolver::resolve([], $commission, 'DBW'));
    }

    public function test_commission_resolver_prefers_live_snapshot_and_reports_fallback_source(): void
    {
        $resolved = WildberriesCommissionResolver::resolveWithSource(
            ['commissions' => ['fbo' => ['percent' => 19]]],
            ['fbo' => 21],
            'FBO'
        );

        $this->assertSame(['value' => 21.0, 'source' => 'wb_commission_snapshot_scheme'], $resolved);
        $this->assertSame(
            ['value' => 15.0, 'source' => 'default'],
            WildberriesCommissionResolver::resolveWithSource([], null, 'FBO')
        );
    }

    public function test_spp_resolver_preserves_previous_value_only_when_sources_are_missing(): void
    {
        $this->assertSame(21.5, WildberriesSppResolver::resolve(null, null, 21.5));
        $this->assertSame(18.0, WildberriesSppResolver::resolve(18.0, 17.0, 21.5));
        $this->assertSame(17.0, WildberriesSppResolver::resolve(0.0, 17.0, 21.5));
        $this->assertSame(0.0, WildberriesSppResolver::resolve(0.0, null, 21.5));
        $this->assertSame(0.0, WildberriesSppResolver::resolve(null, 0.0, 21.5));

        $exactZero = WildberriesSppResolver::resolveWithSource(0.0, 17.0, 21.5, null, 'sales', true);
        $this->assertSame(['value' => 0.0, 'source' => 'sales', 'fresh' => true], $exactZero);
    }

    public function test_spp_job_is_unique_per_integration_and_has_progressive_backoff(): void
    {
        $job = new SyncWildberriesSppJob(76);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('wildberries-spp:76', $job->uniqueId());
        $this->assertSame(21600, $job->uniqueFor);
        $this->assertSame([300, 900, 3600, 10800], [
            $job->retryDelay(1),
            $job->retryDelay(2),
            $job->retryDelay(3),
            $job->retryDelay(4),
        ]);
    }
}
