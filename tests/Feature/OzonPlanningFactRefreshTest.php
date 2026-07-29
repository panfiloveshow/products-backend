<?php

namespace Tests\Feature;

use App\Jobs\SyncOzonPlanningOperationalDataJob;
use App\Jobs\SyncProductsJob;
use App\Models\Integration;
use App\Services\AutoSupplyPlanning\DataFreshnessRegistry;
use App\Services\AutoSupplyPlanning\OzonPlanningFactRefreshService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class OzonPlanningFactRefreshTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_full_fact_refresh_queues_catalog_and_operational_pipelines(): void
    {
        Bus::fake();

        $integration = Integration::factory()->ozon()->create([
            'id' => 818181,
            'work_space_id' => 81,
            'is_active' => true,
        ]);

        $result = app(OzonPlanningFactRefreshService::class)->queue($integration, 0);

        $this->assertSame($integration->id, $result['integration_id']);
        $this->assertNotEmpty($result['refresh_id']);
        $this->assertSame(
            "/api/auto-supply-plans/data-health?integration_id={$integration->id}",
            $result['progress_url']
        );
        $this->assertSame([
            'products',
            'inventory_and_sales',
            'unit_economics',
            'postings',
            'supplies',
            'constraints',
            'credential_health',
        ], $result['pipeline']);

        Bus::assertDispatched(SyncProductsJob::class);
        Bus::assertDispatched(
            SyncOzonPlanningOperationalDataJob::class,
            fn (SyncOzonPlanningOperationalDataJob $job): bool =>
                $job->integrationId === $integration->id
        );

        $integration->refresh();
        $this->assertSame('running', $integration->settings['autoplanning_fact_refresh']['status']);
        $health = app(DataFreshnessRegistry::class)->inspect($integration);
        $this->assertSame($result['refresh_id'], $health['sync_progress']['id']);
        $this->assertSame('running', $health['sync_progress']['status']);
        $this->assertSame(7, $health['sync_progress']['total_stages']);
    }

    public function test_refresh_rejects_integration_without_ozon_credentials(): void
    {
        Bus::fake();

        $integration = Integration::factory()->ozon()->create([
            'id' => 818182,
            'work_space_id' => 81,
            'credentials' => [],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('credentials Ozon');

        app(OzonPlanningFactRefreshService::class)->queue($integration, 0);
    }
}
