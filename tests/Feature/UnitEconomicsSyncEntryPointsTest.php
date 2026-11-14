<?php

namespace Tests\Feature;

use App\Jobs\RefreshMarketplacePricesJob;
use App\Jobs\SyncInventoryJob;
use App\Jobs\SyncProductsJob;
use App\Jobs\SyncStorageFeesJob;
use App\Jobs\SyncUnitEconomicsJob;
use App\Models\Integration;
use App\Models\Product;
use App\Services\InventoryService;
use App\Services\ProductService;
use App\Services\UnitEconomics\UnitEconomicsSyncCoordinator;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UnitEconomicsSyncEntryPointsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_inventory_refresh_does_not_reopen_a_completed_catalog_in_an_active_full_run(): void
    {
        Queue::fake();
        config(['unit_economics_sync.enabled' => true]);
        $integration = Integration::withoutEvents(fn () => Integration::factory()->ozon()->create(['id' => 62053]));
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        $run = $coordinator->request($integration, 'full');
        $coordinator->updateStage($run->id, 'catalog', 'completed', ['total' => 3, 'succeeded' => 3]);

        app(InventoryService::class)->startSync('ozon', ['api_key' => 'test'], $integration->id);

        $this->assertSame($run->id, $coordinator->latest($integration->id)->id);
        $this->assertSame('completed', $run->fresh()->stages['catalog']['status']);
        $this->assertSame(3, $run->fresh()->stages['catalog']['succeeded']);
        Queue::assertNotPushed(SyncProductsJob::class);
    }

    public function test_inventory_entry_point_does_not_require_a_catalog_job_it_does_not_dispatch(): void
    {
        Queue::fake();
        config(['unit_economics_sync.enabled' => true]);
        foreach (['ozon' => 62051, 'wildberries' => 62052] as $marketplace => $id) {
            $integration = Integration::withoutEvents(fn () => Integration::factory()->create([
                'id' => $id, 'marketplace' => $marketplace,
            ]));
            $log = app(ProductService::class)->startSync($marketplace, ['api_key' => 'test'], $id, 'inventory');
            $run = app(UnitEconomicsSyncCoordinator::class)->latest($id);

            $this->assertNotContains('catalog', $run->required_stages);
            $this->assertArrayNotHasKey('catalog', $run->stages);
            $this->assertContains('enrichment', $run->required_stages);
            $this->assertSame($marketplace === 'wildberries', in_array('finance', $run->required_stages, true));
            Queue::assertPushed(SyncInventoryJob::class, fn ($job) => $job->syncLog->id === $log->id);
        }
        Queue::assertNotPushed(SyncProductsJob::class);
    }

    public function test_nightly_enqueue_uses_the_same_price_and_enrichment_pipeline_and_skips_ineligible_accounts(): void
    {
        Queue::fake();
        config(['unit_economics_sync.enabled' => true]);
        foreach ([62033 => ['is_active' => true, 'credential_health' => 'healthy'],
            62034 => ['is_active' => false, 'credential_health' => 'healthy'],
            62035 => ['is_active' => true, 'credential_health' => 'invalid']] as $id => $state) {
            $integration = Integration::factory()->ozon()->create(['id' => $id, ...$state]);
            Product::factory()->ozon()->create(['integration_id' => $id, 'price' => 1000]);
        }
        $this->artisan('unit-economics:sync', ['--all' => true, '--enqueue' => true])->assertSuccessful();
        Queue::assertPushed(RefreshMarketplacePricesJob::class, 1);
        Queue::assertPushed(SyncUnitEconomicsJob::class, 1);
        $run = app(UnitEconomicsSyncCoordinator::class)->latest(62033);
        Queue::assertPushed(SyncUnitEconomicsJob::class, fn ($job) => $job->unitEconomicsRunId === $run->id && $job->queue === 'unit-economics-enrichment');
        $this->assertSame('queued', $run->stages['enrichment']['status']);
        $this->assertNotSame('completed', $run->status);
    }

    public function test_nonempty_inventory_preserves_run_identity_for_completion_and_the_storage_hop(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([
            'seller-analytics-api.wildberries.ru/api/analytics/v1/stocks-report/wb-warehouses' => Http::response(['data' => ['items' => [[
                'nmId' => 111, 'chrtId' => 1001, 'warehouseId' => 55, 'warehouseName' => 'WB WH', 'quantity' => 7,
            ]]]]),
            'marketplace-api.wildberries.ru/api/v3/warehouses' => Http::response([['id' => 55, 'name' => 'Seller WH', 'cargoType' => 1]]),
            'content-api.wildberries.ru/content/v2/get/cards/list' => Http::response(['cards' => [[
                'nmID' => 111, 'vendorCode' => 'ART-1', 'sizes' => [['chrtID' => 1001, 'skus' => ['BAR-S']]],
            ]], 'cursor' => []]),
            'marketplace-api.wildberries.ru/api/v3/stocks/55' => Http::response(['stocks' => [['chrtId' => 1001, 'amount' => 7]]]),
            '*' => Http::response([]),
        ]);
        $integration = Integration::factory()->wildberries()->create(['id' => 62031]);
        Product::factory()->wildberries()->create(['integration_id' => $integration->id, 'sku' => 'BAR-S']);
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        $run = $coordinator->request($integration, 'full');
        $log = app(InventoryService::class)->startSync('wildberries', ['api_key' => 'test'], $integration->id, 5, $run->id);
        (new SyncInventoryJob($log))->handle();
        $this->assertGreaterThan(0, $log->fresh()->items_synced);
        $this->assertSame($run->id, $log->fresh()->metadata['unit_economics_run_id']);
        $this->assertArrayHasKey('queue_available_at', $log->fresh()->metadata);
        $this->assertSame('completed', $run->fresh()->stages['inventory']['status']);
        Queue::assertPushed(SyncStorageFeesJob::class, fn ($job) => $job->unitEconomicsRunId === $run->id);
    }

    public function test_repeated_full_sync_requests_share_catalog_work_and_require_the_latest_price_version(): void
    {
        Queue::fake();
        config(['unit_economics_sync.integration_ids' => [62023]]);
        $integration = Integration::factory()->wildberries()->create(['id' => 62023]);
        $service = app(ProductService::class);
        $first = $service->startSync('wildberries', ['api_key' => 'test'], $integration->id);
        $second = $service->startSync('wildberries', ['api_key' => 'test'], $integration->id);
        $run = app(UnitEconomicsSyncCoordinator::class)->latest($integration->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($run->id, $second->metadata['unit_economics_run_id']);
        $this->assertSame(2, $run->requested_version);
        Queue::assertPushed(SyncProductsJob::class, 1);
        Queue::assertPushed(RefreshMarketplacePricesJob::class, 1);

        $inventory = app(InventoryService::class)->startSync('wildberries', ['api_key' => 'test'], $integration->id, 5, $run->id);
        $this->assertSame($run->id, $inventory->metadata['unit_economics_run_id']);
        $this->assertSame(2, $run->fresh()->requested_version);
        $this->assertGreaterThan(now()->getTimestamp(), strtotime($inventory->metadata['queue_available_at']));
    }
}
