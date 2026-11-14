<?php

namespace Tests\Feature;

use App\Jobs\RecalculateUnitEconomicsCacheJob;
use App\Jobs\SyncUnitEconomicsJob;
use App\Models\Integration;
use App\Services\UnitEconomics\UnitEconomicsSyncCoordinator;
use App\Services\UnitEconomicsCacheService;
use App\Services\UnitEconomicsService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UnitEconomicsJobOutcomeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_source_failures_remain_distinct_from_sku_calculation_failures_after_publication(): void
    {
        Queue::fake();
        $integration = Integration::factory()->create(['id' => 62037, 'marketplace' => 'uzum']);
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        $run = $coordinator->request($integration, 'prices', ['enrichment']);
        $service = $this->mock(UnitEconomicsService::class);
        $service->shouldReceive('syncFromRealData')->once()->andReturn(['total' => 10, 'synced' => 10,
            'succeeded' => 10, 'errors' => 0, 'source_errors' => 2, 'status' => 'partial']);
        (new SyncUnitEconomicsJob($integration->id, unitEconomicsRunId: $run->id))->handle($service);
        $coordinator->enrichmentPublished($run->id, ['total' => 10, 'success' => 10, 'errors' => 0]);
        $stage = $run->fresh()->stages['enrichment'];
        $this->assertSame('partial', $stage['status']);
        $this->assertSame(2, $stage['source_errors']);
        $this->assertSame(0, $stage['failed']);
        $this->assertSame('source_data_incomplete', $stage['error_code']);
    }

    public function test_total_sync_failure_is_not_a_successful_job(): void
    {
        Queue::fake();
        $integration = Integration::factory()->create(['id' => 62004, 'marketplace' => 'uzum']);
        $service = $this->mock(UnitEconomicsService::class);
        $service->shouldReceive('syncFromRealData')->once()->andReturn(['synced' => 0, 'errors' => 7, 'status' => 'failed']);

        $this->expectException(\RuntimeException::class);
        try {
            (new SyncUnitEconomicsJob($integration->id))->handle($service);
        } finally {
            Queue::assertNotPushed(RecalculateUnitEconomicsCacheJob::class);
        }
    }

    public function test_partial_sync_returns_real_outcome_and_projects_the_successful_data_once(): void
    {
        Queue::fake();
        $integration = Integration::factory()->create(['id' => 62005, 'marketplace' => 'uzum']);
        $outcome = ['synced' => 8, 'errors' => 2, 'status' => 'partial'];
        $service = $this->mock(UnitEconomicsService::class);
        $service->shouldReceive('syncFromRealData')->once()->andReturn($outcome);

        $actual = (new SyncUnitEconomicsJob($integration->id))->handle($service);

        $this->assertSame($outcome, $actual);
        Queue::assertPushed(RecalculateUnitEconomicsCacheJob::class, 1);
        Queue::assertPushedOn('unit-economics', RecalculateUnitEconomicsCacheJob::class);
    }

    public function test_projection_with_no_successful_sku_fails_instead_of_silently_completing(): void
    {
        $service = $this->mock(UnitEconomicsCacheService::class);
        $service->shouldReceive('recalculateIntegration')->once()->with(62006)
            ->andReturn(['total' => 2, 'success' => 0, 'errors' => 2, 'status' => 'failed']);

        $this->expectException(\RuntimeException::class);
        (new RecalculateUnitEconomicsCacheJob(62006))->handle($service);
    }
}
