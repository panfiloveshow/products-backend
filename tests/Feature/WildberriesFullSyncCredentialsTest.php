<?php

namespace Tests\Feature;

use App\Jobs\SyncInventoryJob;
use App\Jobs\SyncProductsJob;
use App\Jobs\SyncStorageFeesJob;
use App\Jobs\SyncUnitEconomicsJob;
use App\Models\Integration;
use App\Models\Product;
use App\Models\SyncLog;
use App\Services\InventoryService;
use App\Services\UnitEconomics\UnitEconomicsFreshness;
use App\Services\UnitEconomics\UnitEconomicsSyncCoordinator;
use App\Services\Wildberries\WildberriesAcquiringSnapshotStore;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WildberriesFullSyncCredentialsTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_finance_dispatches_share_one_queued_job_for_the_same_run(): void
    {
        Queue::fake();
        SyncStorageFeesJob::dispatch(99235, ['api_key' => 'test'], 4, 'same-full-run');
        SyncStorageFeesJob::dispatch(99235, ['api_key' => 'test'], 4, 'same-full-run');
        Queue::assertPushed(SyncStorageFeesJob::class, 1);
        SyncStorageFeesJob::dispatch(99235, ['api_key' => 'test'], 4, 'another-full-run');
        Queue::assertPushed(SyncStorageFeesJob::class, 2);
        SyncStorageFeesJob::dispatch(99235, ['api_key' => 'test'], 2, 'same-full-run');
        Queue::assertPushed(SyncStorageFeesJob::class, 3);
    }

    public function test_inventory_cooldown_releases_the_job_without_overwriting_existing_stock(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([
            'content-api.wildberries.ru/content/v2/get/cards/list' => Http::response([
                'cards' => [['nmID' => 111, 'sizes' => [['chrtID' => 1001, 'skus' => ['BAR-S']]]]],
                'cursor' => ['total' => 1, 'nmID' => 111, 'updatedAt' => '2026-11-02T00:00:00Z'],
            ]),
            'seller-analytics-api.wildberries.ru/api/analytics/v1/stocks-report/wb-warehouses' => Http::response(['detail' => 'token does not satisfy additional requirements'], 403),
            'seller-analytics-api.wildberries.ru/api/v1/warehouse_remains*' => Http::response(['detail' => 'rate limited'], 429, ['X-RateLimit-Retry' => '3600']),
            '*' => Http::response([]),
        ]);
        $integration = Integration::withoutEvents(fn () => Integration::factory()->wildberries()->create([
            'id' => 99234, 'credentials' => ['api_key' => 'inventory-defer-key'],
        ]));
        $product = Product::factory()->wildberries()->create(['integration_id' => $integration->id, 'stock' => 77]);
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        $run = $coordinator->request($integration, 'full');
        $log = SyncLog::create(['integration_id' => $integration->id, 'marketplace' => 'wildberries',
            'sync_type' => 'inventory', 'status' => 'pending', 'credentials' => [],
            'metadata' => ['unit_economics_run_id' => $run->id]]);
        $queueJob = \Mockery::mock(Job::class);
        $queueJob->shouldReceive('release')->once()->with(3615);
        $job = new SyncInventoryJob($log);
        $job->setJob($queueJob);

        $job->handle();

        $this->assertSame('pending', $log->fresh()->status);
        $this->assertSame('waiting_external', $run->fresh()->stages['inventory']['status']);
        $this->assertSame('rate_limited', $run->fresh()->stages['inventory']['error_code']);
        $this->assertSame(77, $product->fresh()->stock);
        Queue::assertNotPushed(SyncStorageFeesJob::class);
    }

    public function test_catalog_with_empty_queued_credentials_continues_to_inventory_using_its_integration(): void
    {
        config(['unit_economics_sync.enabled' => true]);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([
            'content-api.wildberries.ru/content/v2/get/cards/list' => Http::response([
                'cards' => [['nmID' => 111, 'vendorCode' => 'ART-1', 'title' => 'Test card',
                    'sizes' => [['chrtID' => 1001, 'skus' => ['BAR-S']]]]],
                'cursor' => ['total' => 1],
            ]),
            'seller-analytics-api.wildberries.ru/api/analytics/v1/stocks-report/wb-warehouses' => Http::response([
                'data' => ['items' => [['nmId' => 111, 'chrtId' => 1001, 'quantity' => 7, 'warehouseId' => -999999, 'warehouseName' => 'Склад WB']]],
            ]),
            '*' => Http::response([]),
        ]);
        $integration = Integration::withoutEvents(fn () => Integration::factory()->wildberries()->create([
            'id' => 99232, 'credentials' => ['api_key' => 'integration-owned-key'],
        ]));
        $log = SyncLog::create(['integration_id' => $integration->id, 'marketplace' => 'wildberries',
            'sync_type' => 'products', 'status' => 'pending', 'credentials' => []]);

        (new SyncProductsJob($log))->handle(app(InventoryService::class));

        $this->assertSame('completed', $log->fresh()->status);
        $this->assertSame(1, $log->fresh()->items_synced);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'discounts-prices-api.wildberries.ru'));
        Queue::assertPushed(SyncInventoryJob::class, fn ($job) => $job->syncLog->integration_id === $integration->id);
    }

    public function test_finance_stage_resolves_credentials_when_the_queued_payload_has_no_key(): void
    {
        config(['services.wildberries.api_key' => 'unrelated-global-key']);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([
            'finance-api.wildberries.ru/api/finance/v1/sales-reports/detailed' => Http::response('', 204),
        ]);
        $integration = Integration::withoutEvents(fn () => Integration::factory()->wildberries()->create([
            'id' => 99231,
            'credentials' => ['api_key' => 'integration-owned-key'],
        ]));

        (new SyncStorageFeesJob($integration->id, []))->handle(app(WildberriesAcquiringSnapshotStore::class));

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'integration-owned-key'));
        Queue::assertPushed(SyncUnitEconomicsJob::class);
    }

    public function test_finance_honours_the_full_twelve_hour_retry_without_marking_data_fresh(): void
    {
        $this->travelTo(now()->startOfSecond());
        config(['unit_economics_sync.enabled' => true]);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([
            'finance-api.wildberries.ru/api/finance/v1/sales-reports/detailed' => Http::sequence()
                ->push(['detail' => 'rate limited'], 429, ['X-Ratelimit-Retry' => '43200'])
                ->push('', 204),
        ]);
        $integration = Integration::withoutEvents(fn () => Integration::factory()->wildberries()->create([
            'id' => 99236, 'credentials' => ['api_key' => 'finance-defer-key'],
        ]));
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        $run = $coordinator->request($integration, 'full');
        $queueJob = \Mockery::mock(Job::class);
        $queueJob->shouldReceive('release')->once()->with(43215);
        $job = new SyncStorageFeesJob($integration->id, [], 4, $run->id);
        $job->setJob($queueJob);

        $job->handle(app(WildberriesAcquiringSnapshotStore::class));

        $freshRun = $run->fresh();
        $finance = $freshRun->stages['finance'];
        $this->assertSame('waiting_external', $finance['status']);
        $this->assertSame('rate_limited', $finance['error_code']);
        $this->assertSame(now()->addSeconds(43215)->utc()->toIso8601String(), $finance['next_retry_at']);
        $this->assertGreaterThan(now()->addSeconds(43215)->timestamp, $job->retryUntil()->getTimestamp());
        $this->assertSame('queued', $freshRun->stages['enrichment']['status']);
        Http::assertSentCount(1);
        Queue::assertPushed(SyncUnitEconomicsJob::class, 1);
        Queue::assertPushed(SyncUnitEconomicsJob::class, fn ($dispatched) => $dispatched->integrationId === $integration->id
            && $dispatched->unitEconomicsRunId === $run->id);

        $freshness = app(UnitEconomicsFreshness::class)->forIntegration($integration);
        $this->assertFalse($freshness['overall']['is_fresh']);
        $this->assertSame('waiting_external', $freshness['status']);

        // Second rate-limited attempt while enrichment already ran must not duplicate SyncUnitEconomicsJob or HTTP calls.
        $coordinator->enrichmentPublished($run->id, ['total' => 1, 'success' => 1, 'errors' => 0]);
        $this->travel(1)->hours();
        $secondQueueJob = \Mockery::mock(Job::class);
        $secondQueueJob->shouldReceive('release')->once()->with(39615);
        $secondJob = new SyncStorageFeesJob($integration->id, [], 4, $run->id);
        $secondJob->setJob($secondQueueJob);
        $secondJob->handle(app(WildberriesAcquiringSnapshotStore::class));
        Http::assertSentCount(1);
        Queue::assertPushed(SyncUnitEconomicsJob::class, 1);

        // Once the cooldown clears and Finance succeeds, enrichment is atomically re-queued for the fresh snapshot.
        $this->travel(12)->hours();
        (new SyncStorageFeesJob($integration->id, [], 4, $run->id))->handle(app(WildberriesAcquiringSnapshotStore::class));
        $afterSuccess = $run->fresh();
        $this->assertSame('completed', $afterSuccess->stages['finance']['status']);
        $this->assertSame('queued', $afterSuccess->stages['enrichment']['status']);
        $this->assertSame('queued', $afterSuccess->status);
        Http::assertSentCount(2);
        Queue::assertPushed(SyncUnitEconomicsJob::class, 2);
    }

    public function test_finance_terminal_failure_still_dispatches_unit_economics_sync(): void
    {
        Queue::fake();
        $coordinator = app(UnitEconomicsSyncCoordinator::class);

        $activeIntegration = Integration::withoutEvents(fn () => Integration::factory()->wildberries()->create(['id' => 99237]));
        $activeRun = $coordinator->request($activeIntegration, 'full');
        (new SyncStorageFeesJob($activeIntegration->id, [], 4, $activeRun->id))
            ->failed(new MaxAttemptsExceededException('too many attempts'));

        $this->assertSame('failed', $activeRun->fresh()->stages['finance']['status']);
        $this->assertSame('queued', $activeRun->fresh()->stages['enrichment']['status']);
        Queue::assertPushed(SyncUnitEconomicsJob::class, fn ($dispatched) => $dispatched->integrationId === $activeIntegration->id
            && $dispatched->unitEconomicsRunId === $activeRun->id);

        $expiredIntegration = Integration::withoutEvents(fn () => Integration::factory()->wildberries()->create(['id' => 99238]));
        $expiredRun = $coordinator->request($expiredIntegration, 'full');
        $expiredRun->update(['deadline_at' => now()->subMinute()]);
        (new SyncStorageFeesJob($expiredIntegration->id, [], 4, $expiredRun->id))
            ->failed(new MaxAttemptsExceededException('too many attempts'));

        Queue::assertPushed(SyncUnitEconomicsJob::class, fn ($dispatched) => $dispatched->integrationId === $expiredIntegration->id
            && $dispatched->unitEconomicsRunId === null);

        $alreadyEnriched = Integration::withoutEvents(fn () => Integration::factory()->wildberries()->create(['id' => 99239]));
        $enrichedRun = $coordinator->request($alreadyEnriched, 'full');
        $coordinator->enrichmentPublished($enrichedRun->id, ['total' => 2, 'success' => 2, 'errors' => 0]);
        (new SyncStorageFeesJob($alreadyEnriched->id, [], 4, $enrichedRun->id))
            ->failed(new MaxAttemptsExceededException('too many attempts'));

        Queue::assertNotPushed(SyncUnitEconomicsJob::class, fn ($dispatched) => $dispatched->integrationId === $alreadyEnriched->id);
    }

    public function test_coalesced_inventory_sync_dispatches_enrichment_when_finance_is_already_waiting_external(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([
            'content-api.wildberries.ru/content/v2/get/cards/list' => Http::response([
                'cards' => [['nmID' => 111, 'vendorCode' => 'ART-1',
                    'sizes' => [['chrtID' => 1001, 'skus' => ['BAR-S']]]]],
                'cursor' => ['total' => 1],
            ]),
            'seller-analytics-api.wildberries.ru/api/analytics/v1/stocks-report/wb-warehouses' => Http::response([
                'data' => ['items' => [['nmId' => 111, 'chrtId' => 1001, 'quantity' => 7, 'warehouseId' => -999999, 'warehouseName' => 'Склад WB']]],
            ]),
            '*' => Http::response([]),
        ]);
        $integration = Integration::withoutEvents(fn () => Integration::factory()->wildberries()->create([
            'id' => 99240, 'credentials' => ['api_key' => 'coalesced-key'],
        ]));
        Product::factory()->wildberries()->create(['integration_id' => $integration->id,
            'sku' => 'BAR-S', 'wb_data' => ['nmID' => 111, 'sizes' => [['chrtID' => 1001, 'skus' => ['BAR-S']]]]]);
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        $run = $coordinator->request($integration, 'full');
        $coordinator->updateStage($run->id, 'finance', 'waiting_external', [
            'error_code' => 'rate_limited',
            'next_retry_at' => now()->addHours(10)->utc()->toIso8601String(),
        ]);
        $coordinator->enrichmentPublished($run->id, ['total' => 1, 'success' => 1, 'errors' => 0]);
        // Coalesced scheduled full sync resets enrichment back to pending while finance stays waiting_external.
        $coordinator->request($integration, 'full', ['inventory', 'prices', 'projection', 'enrichment', 'finance']);
        $this->assertSame('pending', $run->fresh()->stages['enrichment']['status']);

        $log = SyncLog::create(['integration_id' => $integration->id, 'marketplace' => 'wildberries',
            'sync_type' => 'inventory', 'status' => 'pending', 'credentials' => [],
            'metadata' => ['unit_economics_run_id' => $run->id]]);

        (new SyncInventoryJob($log))->handle();

        $this->assertSame('queued', $run->fresh()->stages['enrichment']['status']);
        Queue::assertPushed(SyncUnitEconomicsJob::class, fn ($dispatched) => $dispatched->integrationId === $integration->id
            && $dispatched->unitEconomicsRunId === $run->id);
    }

    public function test_inventory_with_empty_queued_credentials_continues_to_finance(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([
            'content-api.wildberries.ru/content/v2/get/cards/list' => Http::response([
                'cards' => [['nmID' => 111, 'vendorCode' => 'ART-1',
                    'sizes' => [['chrtID' => 1001, 'skus' => ['BAR-S']]]]],
                'cursor' => ['total' => 1],
            ]),
            'seller-analytics-api.wildberries.ru/api/analytics/v1/stocks-report/wb-warehouses' => Http::sequence()
                ->push(['data' => ['items' => [['nmId' => 111, 'chrtId' => 1001, 'quantity' => 7, 'warehouseId' => -999999, 'warehouseName' => 'Склад WB']]]])
                ->push(['detail' => 'rate limited'], 429, ['X-RateLimit-Retry' => '900']),
            'seller-analytics-api.wildberries.ru/api/v1/warehouse_remains*' => Http::response(['detail' => 'rate limited'], 429, ['X-RateLimit-Retry' => '900']),
            '*' => Http::response([]),
        ]);
        $integration = Integration::withoutEvents(fn () => Integration::factory()->wildberries()->create([
            'id' => 99233, 'credentials' => ['api_key' => 'integration-owned-key'],
        ]));
        Product::factory()->wildberries()->create(['integration_id' => $integration->id,
            'sku' => 'BAR-S', 'wb_data' => ['nmID' => 111, 'sizes' => [['chrtID' => 1001, 'skus' => ['BAR-S']]]]]);
        $log = SyncLog::create(['integration_id' => $integration->id, 'marketplace' => 'wildberries',
            'sync_type' => 'inventory', 'status' => 'pending', 'credentials' => []]);

        (new SyncInventoryJob($log))->handle();

        $this->assertSame('completed', $log->fresh()->status);
        $this->assertGreaterThan(0, $log->fresh()->items_synced);
        $this->assertCount(1, Http::recorded(fn (Request $request) => str_contains($request->url(), '/stocks-report/wb-warehouses')),
            'An already persisted inventory snapshot must not be requested again for warehouse coefficients.');
        $this->assertSame(7, \App\Models\InventoryWarehouse::where('integration_id', $integration->id)->where('sku', 'BAR-S')->value('quantity'));
        Queue::assertPushed(SyncStorageFeesJob::class);
    }
}
