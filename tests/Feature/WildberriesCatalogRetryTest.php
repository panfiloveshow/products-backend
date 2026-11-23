<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\SyncLog;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WildberriesCatalogRetryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_manual_restart_during_catalog_retry_reuses_the_actual_queued_job(): void
    {
        config(['unit_economics_sync.enabled' => false, 'unit_economics_sync.integration_ids' => [],
            'queue.default' => 'database', 'queue.connections.database.queue' => 'wb-catalog-retry-test',
            'queue.failed.driver' => 'null']);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['title' => 'unauthorized'], 401)]);
        $integration = Integration::withoutEvents(fn () => Integration::factory()->wildberries()->create([
            'id' => 99241, 'credentials' => ['api_key' => 'catalog-retry-key'],
        ]));
        $service = app(ProductService::class);
        $first = $service->startSync('wildberries', ['api_key' => 'catalog-retry-key'], $integration->id);
        $worker = app('queue.worker');
        $options = new WorkerOptions(backoff: 0, sleep: 0, maxTries: 3, force: true);

        $worker->runNextJob('database', 'wb-catalog-retry-test', $options);

        $this->assertSame('pending', $first->fresh()->status, 'A scheduled retry still owns its unique job.');
        $again = $service->startSync('wildberries', ['api_key' => 'catalog-retry-key'], $integration->id);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, SyncLog::where('integration_id', $integration->id)->count());
        $this->assertSame(1, DB::table('jobs')->where('queue', 'wb-catalog-retry-test')->count());

        $this->travel(31)->seconds();
        $worker->runNextJob('database', 'wb-catalog-retry-test', $options);
        $this->assertSame('pending', $first->fresh()->status);
        $this->travel(121)->seconds();
        $worker->runNextJob('database', 'wb-catalog-retry-test', $options);
        $this->assertSame('failed', $first->fresh()->status);
        $this->assertSame(0, DB::table('jobs')->where('queue', 'wb-catalog-retry-test')->count());

        $next = $service->startSync('wildberries', ['api_key' => 'catalog-retry-key'], $integration->id);
        $this->assertNotSame($first->id, $next->id, 'A terminal failure releases the unique lock for the next sync.');
        $this->assertSame(1, DB::table('jobs')->where('queue', 'wb-catalog-retry-test')->count());
    }
}
