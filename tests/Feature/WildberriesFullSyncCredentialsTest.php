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
use App\Services\UnitEconomics\UnitEconomicsSyncCoordinator;
use App\Services\Wildberries\WildberriesAcquiringSnapshotStore;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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
            'seller-analytics-api.wildberries.ru/api/analytics/v1/stocks-report/wb-warehouses' => Http::response([
                'data' => ['items' => [['nmId' => 111, 'chrtId' => 1001, 'quantity' => 7, 'warehouseId' => -999999, 'warehouseName' => 'Склад WB']]],
            ]),
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
        Queue::assertPushed(SyncStorageFeesJob::class);
    }
}
