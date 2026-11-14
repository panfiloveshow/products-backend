<?php

namespace Tests\Feature;

use App\Jobs\ProjectMarketplacePricesJob;
use App\Jobs\RefreshMarketplacePricesJob;
use App\Models\Integration;
use App\Models\Product;
use App\Models\UnitEconomicsCache;
use App\Models\UnitEconomicsSyncState;
use App\Services\UnitEconomics\MarketplacePriceObservation;
use App\Services\UnitEconomics\MarketplacePriceSnapshotFetcher;
use App\Services\UnitEconomics\UnitEconomicsSyncCoordinator;
use App\Services\UnitEconomicsCacheService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MarketplacePricePipelineTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['unit_economics_sync.enabled' => true]);
    }

    public function test_partial_snapshot_publishes_only_confirmed_skus_preserves_missing_cache_and_keeps_retry_state(): void
    {
        Queue::fake();
        $integration = Integration::factory()->wildberries()->create(['id' => 62026]);
        Product::factory()->wildberries()->create(['integration_id' => $integration->id, 'sku' => 'A',
            'price' => 1000, 'wb_data' => ['nmID' => 123, 'sizeID' => 456]]);
        $b = Product::factory()->wildberries()->create(['integration_id' => $integration->id, 'sku' => 'B', 'price' => 1000, 'wb_data' => ['nmID' => 999]]);
        UnitEconomicsCache::create(['integration_id' => $integration->id, 'product_id' => $b->id, 'sku' => 'B',
            'product_name' => 'B', 'marketplace' => 'wildberries', 'fulfillment_type' => 'FBO', 'price' => 1000]);
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        $run = $coordinator->request($integration);
        $fetcher = \Mockery::mock(MarketplacePriceSnapshotFetcher::class)->makePartial();
        $fetcher->shouldReceive('fetch')->once()->andReturn(['complete' => false, 'http_status' => 429, 'retry_after' => 900,
            'prices' => ['123:456' => ['price' => 1000, 'final_price' => 700, 'price_observed_at' => now()->utc()->toIso8601String()]]]);
        $job = new RefreshMarketplacePricesJob($run->id, $integration->id);
        $job->handle($coordinator, $fetcher, app(MarketplacePriceObservation::class));
        Queue::pushed(ProjectMarketplacePricesJob::class)->first()->handle($coordinator, app(UnitEconomicsCacheService::class));

        $this->assertSame(0, $job->tries, 'External cooldown releases must not be capped at three attempts');
        $this->assertSame('waiting_external', $run->fresh()->status);
        $this->assertSame('completed', $run->fresh()->stages['projection']['status']);
        $this->assertTrue($run->fresh()->next_retry_at->isFuture());
        $this->assertEquals(1000, UnitEconomicsCache::where('sku', 'B')->first()->price);
        $this->assertEquals(0, UnitEconomicsCache::where('sku', 'B')->first()->source_version);
        $this->assertSame(0, $run->fresh()->published_version);
        $this->assertSame(5, UnitEconomicsCache::where('sku', 'A')->count());
        $this->assertSame('price_not_returned', UnitEconomicsCache::where('sku', 'B')->first()->marketplace_data['price_sync_error_code']);
        $this->assertNull($b->fresh()->wb_data['price_observed_at'] ?? null);
    }

    public function test_projection_uses_bounded_batches_and_a_repeated_cursor_does_not_count_twice(): void
    {
        Queue::fake();
        $integration = Integration::factory()->wildberries()->create(['id' => 62027]);
        Product::factory()->wildberries()->count(101)->create(['integration_id' => $integration->id,
            'wb_data' => ['price_version' => 1, 'price_observed_at' => now()->utc()->toIso8601String()]]);
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        $run = $coordinator->request($integration);
        $result = $coordinator->observe($run->id, 1, fn () => ['total' => 101, 'matched' => 101, 'missing' => 0, 'stale' => 0], true);
        $count = 0;
        $cache = \Mockery::mock(UnitEconomicsCacheService::class);
        $cache->shouldReceive('publishObservedProduct')->andReturnUsing(function () use (&$count): void { $count++; });
        $first = new ProjectMarketplacePricesJob($run->id, $integration->id, 1, $result['value']['observation_id']);
        $first->handle($coordinator, $cache);
        $this->assertSame(100, $count);
        $this->assertNotSame('completed', $run->fresh()->status);
        $first->handle($coordinator, $cache);
        $this->assertSame(100, $count);
        Queue::pushed(ProjectMarketplacePricesJob::class)->first()->handle($coordinator, $cache);
        $this->assertSame(101, $count);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(101, $run->fresh()->stages['projection']['succeeded']);
    }

    public function test_ozon_promotion_ending_raises_price_and_publishes_all_schemes_without_finance(): void
    {
        if (\Illuminate\Support\Facades\DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real Ozon locality queries require PostgreSQL (make_interval).');
        }
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([
            'api-seller.ozon.ru/v5/product/info/prices' => Http::sequence()
                ->push(['items' => [['offer_id' => 'A', 'price' => ['price' => '1000', 'marketing_seller_price' => '850']]], 'cursor' => ''])
                ->push(['items' => [['offer_id' => 'A', 'price' => ['price' => '1000', 'marketing_seller_price' => '0']]], 'cursor' => '']),
            'api-seller.ozon.ru/v1/actions' => Http::response(['result' => []]),
        ]);
        $integration = Integration::factory()->ozon()->create(['id' => 62018]);
        Product::factory()->ozon()->create(['integration_id' => $integration->id, 'sku' => 'A', 'price' => 1000]);
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        foreach ([850, 1000] as $expected) {
            $run = $coordinator->request($integration);
            (new RefreshMarketplacePricesJob($run->id, $integration->id))->handle($coordinator,
                app(MarketplacePriceSnapshotFetcher::class), app(MarketplacePriceObservation::class));
            Queue::pushed(ProjectMarketplacePricesJob::class)->last()->handle($coordinator, app(UnitEconomicsCacheService::class));
            $rows = UnitEconomicsCache::where('integration_id', $integration->id)->get();
            $this->assertCount(4, $rows);
            $this->assertSame('completed', $run->fresh()->status);
            foreach ($rows as $row) {
                $this->assertEquals($expected, $row->price);
                $this->assertEquals($run->requested_version, $row->source_version);
                $this->assertEqualsWithDelta((float) $row->price * (float) $row->commission_percent / 100, (float) $row->commission_amount, 0.011);
                $this->assertFalse(app(\App\Services\UnitEconomics\UnitEconomicsConsistencyInspector::class)->inspect($row)['has_drift']);
            }
        }
        Http::assertSentCount(3);
    }

    public function test_wb_external_price_is_published_with_dependent_amounts_and_no_finance_calls(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['discounts-prices-api.wildberries.ru/*' => Http::sequence()
            ->push(['data' => ['listGoods' => [['nmID' => 123, 'sizes' => [['sizeID' => 456, 'price' => 1000, 'discountedPrice' => 800]]]]]])
            ->push(['data' => ['listGoods' => []]])]);
        $integration = Integration::factory()->wildberries()->create(['id' => 62014]);
        $product = Product::factory()->wildberries()->create(['integration_id' => $integration->id,
            'sku' => 'A', 'price' => 1000, 'wb_data' => ['nmID' => 123, 'sizeID' => 456]]);
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        $run = $coordinator->request($integration);
        (new RefreshMarketplacePricesJob($run->id, $integration->id))->handle($coordinator,
            app(MarketplacePriceSnapshotFetcher::class), app(MarketplacePriceObservation::class));

        $this->assertNotSame('completed', $run->fresh()->status);
        Queue::assertPushedOn('price-refresh', ProjectMarketplacePricesJob::class);
        Queue::pushed(ProjectMarketplacePricesJob::class)->first()->handle($coordinator, app(UnitEconomicsCacheService::class));

        $rows = UnitEconomicsCache::where('integration_id', $integration->id)->get();
        $this->assertCount(5, $rows);
        foreach ($rows as $row) {
            $this->assertEquals(800, $row->price);
            $this->assertEquals(1, $row->source_version);
            $this->assertNotNull($row->price_observed_at);
            $this->assertEqualsWithDelta((float) $row->revenue - (float) $row->total_costs, (float) $row->net_profit, 0.011);
            $this->assertEqualsWithDelta((float) $row->price * (float) $row->commission_percent / 100, (float) $row->commission_amount, 0.011);
        }
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(1, UnitEconomicsSyncState::find($integration->id)->published_version);
        $this->assertGreaterThan(0, UnitEconomicsSyncState::find($integration->id)->publication_revision);
        Http::assertSentCount(1);
    }

    public function test_newer_request_arriving_during_fetch_rejects_old_response_and_queues_the_latest_version(): void
    {
        Queue::fake();
        $integration = Integration::factory()->ozon()->create(['id' => 62015]);
        $product = Product::factory()->ozon()->create(['integration_id' => $integration->id, 'sku' => 'A', 'price' => 1000]);
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        $run = $coordinator->request($integration);
        $fetcher = \Mockery::mock(MarketplacePriceSnapshotFetcher::class)->makePartial();
        $fetcher->shouldReceive('fetch')->once()->andReturnUsing(function () use ($coordinator, $integration): array {
            $coordinator->request($integration);
            return ['complete' => true, 'http_status' => 200, 'prices' => ['A' => [
                'actual_price' => 800, 'price' => 1000, 'price_observed_at' => now()->utc()->toIso8601String()]]];
        });
        (new RefreshMarketplacePricesJob($run->id, $integration->id))->handle($coordinator, $fetcher, app(MarketplacePriceObservation::class));

        $this->assertEquals(1000, $product->fresh()->price);
        $this->assertSame(2, $run->fresh()->requested_version);
        Queue::assertPushed(RefreshMarketplacePricesJob::class, 1);
        Queue::assertNotPushed(ProjectMarketplacePricesJob::class);
    }
}
