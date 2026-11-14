<?php

namespace Tests\Feature;

use App\Jobs\RefreshMarketplacePricesJob;
use App\Models\Integration;
use App\Models\Product;
use App\Models\UnitEconomicsSyncRun;
use App\Services\UnitEconomics\UnitEconomicsSyncCoordinator;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UnitEconomicsPriceRecoveryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_automatic_rotation_skips_known_invalid_credentials(): void
    {
        Queue::fake();
        config(['unit_economics_sync.integration_ids' => [62029]]);
        $integration = Integration::factory()->wildberries()->create(['id' => 62029, 'credential_health' => 'invalid']);
        Product::factory()->wildberries()->create(['integration_id' => $integration->id]);
        $this->artisan('ue:refresh-prices', ['--integration' => [62029], '--stale-only' => true, '--apply' => true])->assertSuccessful();
        Queue::assertNothingPushed();
        $this->assertSame(0, UnitEconomicsSyncRun::count());
    }

    public function test_a_long_external_wait_does_not_cause_an_automatic_repeat_just_after_a_successful_response(): void
    {
        Queue::fake();
        config(['unit_economics_sync.integration_ids' => [62036]]);
        $integration = Integration::factory()->wildberries()->create(['id' => 62036]);
        Product::factory()->wildberries()->create(['integration_id' => $integration->id]);
        $run = app(UnitEconomicsSyncCoordinator::class)->request($integration);
        $this->travel(31)->minutes();
        $stages = $run->stages;
        $stages['prices'] = array_merge($stages['prices'], ['status' => 'completed', 'response_received_at' => now()->utc()->toIso8601String()]);
        $run->update(['status' => 'completed', 'stages' => $stages, 'finished_at' => now()]);
        $this->artisan('ue:refresh-prices', ['--integration' => [62036], '--stale-only' => true, '--apply' => true])->assertSuccessful();
        Queue::assertNothingPushed();
        $this->assertSame(1, $run->fresh()->requested_version);
    }

    public function test_automatic_price_rotation_waits_for_a_first_catalog(): void
    {
        Queue::fake();
        config(['unit_economics_sync.integration_ids' => [62032]]);
        Integration::factory()->wildberries()->create(['id' => 62032]);
        $this->artisan('ue:refresh-prices', ['--integration' => [62032], '--stale-only' => true, '--apply' => true])->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_ready_queue_wait_excludes_intentional_scheduling_delay(): void
    {
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        $run = $coordinator->request(Integration::factory()->ozon()->create(['id' => 62025]));
        $coordinator->updateStage($run->id, 'prices', 'queued', ['available_at' => now()->addMinutes(5)->utc()->toIso8601String()], 1);
        $this->travel(315)->seconds();
        $coordinator->updateStage($run->id, 'prices', 'running', version: 1);
        $this->assertSame(15, $run->fresh()->stages['prices']['queue_wait_seconds']);
    }

    public function test_preview_never_mutates_runs_and_apply_only_dispatches_active_enabled_integrations(): void
    {
        Queue::fake();
        config(['unit_economics_sync.integration_ids' => [62019, 62020]]);
        Integration::factory()->wildberries()->create(['id' => 62019]);
        Integration::factory()->ozon()->create(['id' => 62020, 'is_active' => false]);
        $this->artisan('ue:refresh-prices', ['--integration' => [62019, 62020]])->assertSuccessful();
        $this->assertSame(0, UnitEconomicsSyncRun::count());
        Queue::assertNothingPushed();

        $this->artisan('ue:refresh-prices', ['--integration' => [62019, 62020], '--apply' => true])->assertSuccessful();
        Queue::assertPushed(RefreshMarketplacePricesJob::class, 1);
        $this->assertSame(62019, UnitEconomicsSyncRun::first()->integration_id);
    }

    public function test_recovery_respects_external_retry_window_and_closes_an_expired_run(): void
    {
        Queue::fake();
        config(['unit_economics_sync.integration_ids' => [62021]]);
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        $run = $coordinator->request(Integration::factory()->ozon()->create(['id' => 62021]));
        $coordinator->updateStage($run->id, 'prices', 'waiting_external', ['next_retry_at' => now()->addMinutes(15)->utc()->toIso8601String()], 1);
        $this->travel(11)->minutes();
        $this->artisan('ue:refresh-prices', ['--recover' => true, '--apply' => true])->assertSuccessful();
        Queue::assertNothingPushed();
        $this->assertSame('waiting_external', $run->fresh()->status);

        $this->travel(7)->hours();
        $this->artisan('ue:refresh-prices', ['--recover' => true, '--apply' => true])->assertSuccessful();
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('deadline_exceeded', $run->fresh()->stages['prices']['error_code']);
    }

    public function test_completed_stages_without_a_confirmed_publication_are_not_stuck_running_after_deadline(): void
    {
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        $run = $coordinator->request(Integration::factory()->wildberries()->create(['id' => 62022]));
        $coordinator->updateStage($run->id, 'prices', 'completed', version: 1);
        $coordinator->updateStage($run->id, 'projection', 'completed', version: 1);
        $coordinator->expire($run->id);

        $this->assertSame('partial', $run->fresh()->status);
        $this->assertSame('publication_not_confirmed', $run->fresh()->stages['projection']['error_code']);
        $this->assertNotNull($run->fresh()->finished_at);
    }
}
