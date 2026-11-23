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

    public function test_wb_finance_has_a_bounded_day_budget_and_other_requests_keep_the_short_deadline(): void
    {
        $this->travelTo(now()->startOfSecond());
        config(['unit_economics_sync.deadline_hours' => 6, 'unit_economics_sync.wildberries_finance_deadline_hours' => 24]);
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        $wb = Integration::withoutEvents(fn () => Integration::factory()->wildberries()->create(['id' => 62012]));
        $priceRun = $coordinator->request($wb);
        $this->assertSame(now()->addHours(6)->timestamp, $priceRun->deadline_at->timestamp);

        $fullRun = $coordinator->request($wb, 'full');
        $this->assertSame($priceRun->id, $fullRun->id);
        $this->assertSame(now()->addHours(24)->timestamp, $fullRun->deadline_at->timestamp);
        $job = new \App\Jobs\SyncStorageFeesJob($wb->id, [], 4, $fullRun->id);
        $jobId = \Illuminate\Support\Facades\Queue::connection('database')->push($job);
        $payload = json_decode(\Illuminate\Support\Facades\DB::table('jobs')->where('id', $jobId)->value('payload'), true);
        $this->assertSame($fullRun->deadline_at->timestamp, $payload['retryUntil']);

        $this->travel(2)->hours();
        $this->assertSame($fullRun->deadline_at->timestamp, $coordinator->request($wb, 'full')->deadline_at->timestamp);
        $ozon = Integration::withoutEvents(fn () => Integration::factory()->ozon()->create(['id' => 62013]));
        $this->assertSame(now()->addHours(6)->timestamp, $coordinator->request($ozon, 'full')->deadline_at->timestamp);
    }

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

    public function test_complete_finance_atomically_requeues_already_completed_or_partial_enrichment_without_closing_run(): void
    {
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        $integration = Integration::withoutEvents(fn () => Integration::factory()->wildberries()->create(['id' => 62014]));
        $run = $coordinator->request($integration, 'full');
        $coordinator->updateStage($run->id, 'catalog', 'completed');
        $coordinator->updateStage($run->id, 'inventory', 'completed');
        $coordinator->updateStage($run->id, 'prices', 'completed', version: 1);
        $coordinator->withCurrentVersion($run->id, 1, function ($state, $current): void {
            $state->update(['published_version' => 1, 'publication_revision' => 1]);
            $current->update(['published_version' => 1]);
        });
        $coordinator->updateStage($run->id, 'projection', 'completed', version: 1);
        $coordinator->updateStage($run->id, 'finance', 'waiting_external', [
            'error_code' => 'rate_limited',
            'next_retry_at' => now()->addHours(12)->utc()->toIso8601String(),
        ]);
        $coordinator->updateStage($run->id, 'enrichment', 'partial', [
            'source_status' => 'partial',
            'error_code' => 'source_data_incomplete',
            'total' => 5,
            'succeeded' => 4,
            'failed' => 1,
        ]);

        $this->assertSame('waiting_external', $run->fresh()->status);
        $this->assertNull($run->fresh()->finished_at);

        $this->assertTrue($coordinator->completeFinanceAndRequeueEnrichment($run->id, ['total' => 5, 'succeeded' => 5]));

        $fresh = $run->fresh();
        $this->assertSame('completed', $fresh->stages['finance']['status']);
        $this->assertSame(5, $fresh->stages['finance']['succeeded']);
        $this->assertNull($fresh->stages['finance']['next_retry_at']);
        $this->assertSame('queued', $fresh->stages['enrichment']['status']);
        $this->assertNull($fresh->stages['enrichment']['finished_at']);
        $this->assertNull($fresh->stages['enrichment']['error_code'] ?? null);
        $this->assertNull($fresh->stages['enrichment']['source_status'] ?? null);
        $this->assertSame('queued', $fresh->status);
        $this->assertNull($fresh->finished_at);

        $this->assertTrue($coordinator->updateStage($run->id, 'enrichment', 'running'));
        $coordinator->enrichmentPublished($run->id, ['total' => 5, 'success' => 5, 'errors' => 0]);

        $completed = $run->fresh();
        $this->assertSame('completed', $completed->stages['enrichment']['status']);
        $this->assertSame('completed', $completed->status);
        $this->assertNotNull($completed->finished_at);
        $this->assertFalse($coordinator->completeFinanceAndRequeueEnrichment($run->id, ['total' => 5, 'succeeded' => 5]));
    }

    public function test_queue_enrichment_if_pending_only_transitions_from_pending(): void
    {
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        $integration = Integration::withoutEvents(fn () => Integration::factory()->wildberries()->create(['id' => 62015]));
        $run = $coordinator->request($integration, 'full');

        $this->assertSame('pending', $run->stages['enrichment']['status']);
        $this->assertTrue($coordinator->queueEnrichmentIfPending($run->id));
        $this->assertSame('queued', $run->fresh()->stages['enrichment']['status']);
        $this->assertNotNull($run->fresh()->stages['enrichment']['available_at']);

        $this->assertFalse($coordinator->queueEnrichmentIfPending($run->id));
        $coordinator->updateStage($run->id, 'enrichment', 'completed');
        $this->assertFalse($coordinator->queueEnrichmentIfPending($run->id));
    }
}
