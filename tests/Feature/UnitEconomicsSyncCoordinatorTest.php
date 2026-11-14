<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\UnitEconomicsSyncRun;
use App\Models\UnitEconomicsSyncState;
use App\Services\UnitEconomics\UnitEconomicsSyncCoordinator;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class UnitEconomicsSyncCoordinatorTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_coalesced_request_requires_new_observation_and_rejects_old_publication(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 62008]);
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        $first = $coordinator->request($integration);
        $coordinator->updateStage($first->id, 'prices', 'running', version: $first->requested_version);
        $second = $coordinator->request($integration);
        $called = false;

        $result = $coordinator->withCurrentVersion($first->id, $first->requested_version, function () use (&$called): void {
            $called = true;
        });

        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, $second->requested_version);
        $this->assertFalse($result['accepted']);
        $this->assertFalse($called);
        $this->assertSame(1, UnitEconomicsSyncRun::count());
        $this->assertFalse($coordinator->updateStage($first->id, 'prices', 'completed', version: 1));
        $this->assertSame('queued', $second->fresh()->stages['prices']['status']);
    }

    public function test_old_cache_or_stage_completion_cannot_complete_a_run_without_publication(): void
    {
        $run = app(UnitEconomicsSyncCoordinator::class)->request(Integration::factory()->ozon()->create(['id' => 62009]));
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        $coordinator->updateStage($run->id, 'prices', 'completed', version: 1);
        $coordinator->updateStage($run->id, 'projection', 'completed', version: 1);

        $this->assertNotSame('completed', $run->fresh()->status);
        $this->assertNull($run->fresh()->finished_at);
        $coordinator->withCurrentVersion($run->id, 1, function ($state, $current): void {
            $state->update(['published_version' => 1, 'publication_revision' => 1]);
            $current->update(['published_version' => 1]);
        });
        $coordinator->updateStage($run->id, 'projection', 'completed', version: 1);

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertNotNull($run->fresh()->finished_at);
        $this->assertFalse($coordinator->updateStage($run->id, 'projection', 'failed', version: 1));
    }

    public function test_finance_failure_does_not_stop_the_independent_price_workflow(): void
    {
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        $run = $coordinator->request(Integration::factory()->ozon()->create(['id' => 62010]), 'full', ['prices', 'projection', 'enrichment']);
        $coordinator->updateStage($run->id, 'enrichment', 'failed', ['error_code' => 'finance_unavailable']);

        $this->assertNotSame('failed', $run->fresh()->status);
        $this->assertTrue($coordinator->withCurrentVersion($run->id, 1, fn () => true)['accepted']);
        $coordinator->updateStage($run->id, 'prices', 'completed', version: 1);
        $coordinator->updateStage($run->id, 'projection', 'completed', version: 1);

        $this->assertSame('partial', $run->fresh()->status);
    }

    public function test_rate_limit_state_keeps_its_retry_time_and_terminal_failure_creates_a_new_run(): void
    {
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        $integration = Integration::factory()->wildberries()->create(['id' => 62011]);
        $run = $coordinator->request($integration);
        $retryAt = now()->addMinutes(15)->utc()->toIso8601String();
        $coordinator->updateStage($run->id, 'prices', 'waiting_external', ['next_retry_at' => $retryAt], 1);
        $this->assertSame('waiting_external', $run->fresh()->status);
        $this->assertSame($retryAt, $run->fresh()->next_retry_at->utc()->toIso8601String());

        $coordinator->updateStage($run->id, 'prices', 'failed', ['error_code' => 'deadline_exceeded'], 1);
        $coordinator->updateStage($run->id, 'projection', 'skipped', ['error_code' => 'price_unavailable'], 1);
        $this->assertSame('failed', $run->fresh()->status);
        $next = $coordinator->request($integration);
        $this->assertNotSame($run->id, $next->id);
        $this->assertSame(2, UnitEconomicsSyncState::find($integration->id)->requested_version);
        $this->assertFalse($coordinator->withCurrentVersion($run->id, 1, fn () => true)['accepted']);
    }
}
