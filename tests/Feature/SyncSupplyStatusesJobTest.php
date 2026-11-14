<?php

namespace Tests\Feature;

use App\Jobs\SyncSupplyStatusesJob;
use App\Jobs\SyncSupplyStatusJob;
use App\Jobs\SyncSupplyStatusBatchJob;
use App\Models\Integration;
use App\Models\Supply;
use App\Services\Supply\SupplyService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Фоновый трекинг поставок (расписание каждые 15 минут).
 */
class SyncSupplyStatusesJobTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_only_active_supplies_with_ozon_ids_are_synced(): void
    {
        $integration = $this->integration();

        $tracked = $this->supply($integration, ['ozon_order_id' => '900001', 'status' => Supply::STATUS_SHIPPED]);
        $draftOnly = $this->supply($integration, ['ozon_draft_id' => '900002', 'status' => Supply::STATUS_DRAFT_OZON]);
        $this->supply($integration, ['status' => Supply::STATUS_DRAFT]);            // ещё не в Ozon
        $this->supply($integration, ['ozon_order_id' => '900003', 'status' => Supply::STATUS_CLOSED]);
        $this->supply($integration, ['ozon_order_id' => '900004', 'status' => Supply::STATUS_CANCELLED]);

        $synced = [];
        $service = \Mockery::mock(SupplyService::class);
        $service->shouldReceive('syncStatus')
            ->andReturnUsing(function (Supply $supply) use (&$synced): void {
                $synced[] = $supply->id;
            });

        $this->assertSame([], $this->runDispatchedStatuses($service));

        sort($synced);
        $expected = [$tracked->id, $draftOnly->id];
        sort($expected);
        $this->assertSame($expected, $synced);
    }

    public function test_one_broken_supply_does_not_stop_the_rest(): void
    {
        $integration = $this->integration();
        $failing = $this->supply($integration, ['ozon_order_id' => '900101', 'status' => Supply::STATUS_SHIPPED]);
        $healthy = $this->supply($integration, ['ozon_order_id' => '900102', 'status' => Supply::STATUS_SHIPPED]);

        $synced = [];
        $service = \Mockery::mock(SupplyService::class);
        $service->shouldReceive('syncStatus')
            ->andReturnUsing(function (Supply $supply) use (&$synced, $failing): void {
                if ($supply->id === $failing->id) {
                    throw new \RuntimeException('Ozon вернул 500');
                }
                $synced[] = $supply->id;
            });

        $this->assertCount(1, $this->runDispatchedStatuses($service));

        $this->assertSame([$healthy->id], $synced);
    }

    public function test_integration_scope_does_not_leak_across_accounts(): void
    {
        $mine = $this->integration();
        $foreign = $this->integration();
        $ours = $this->supply($mine, ['ozon_order_id' => '900201', 'status' => Supply::STATUS_SHIPPED]);
        $this->supply($foreign, ['ozon_order_id' => '900202', 'status' => Supply::STATUS_SHIPPED]);

        $synced = [];
        $service = \Mockery::mock(SupplyService::class);
        $service->shouldReceive('syncStatus')
            ->andReturnUsing(function (Supply $supply) use (&$synced): void {
                $synced[] = $supply->id;
            });

        $this->assertSame([], $this->runDispatchedStatuses($service, $mine->id));

        $this->assertSame([$ours->id], $synced);
    }

    public function test_status_jobs_are_isolated_and_deduplicated_per_supply(): void
    {
        Queue::fake();
        $integration = $this->integration();
        $supply = $this->supply($integration, ['ozon_order_id' => '900301']);

        SyncSupplyStatusJob::dispatch($supply->id, $integration->id);
        SyncSupplyStatusJob::dispatch($supply->id, $integration->id);

        $this->assertSame('supply-statuses', (new SyncSupplyStatusesJob)->queue);
        $this->assertInstanceOf(ShouldBeUnique::class, new SyncSupplyStatusesJob);
        Queue::assertPushedOn('supply-statuses', SyncSupplyStatusJob::class);
        Queue::assertPushed(SyncSupplyStatusJob::class, 1);
    }

    public function test_dispatcher_performs_no_external_sync_and_excludes_inactive_accounts(): void
    {
        Queue::fake();
        $active = $this->integration();
        $inactive = $this->integration();
        $inactive->update(['is_active' => false]);
        $tracked = $this->supply($active, ['ozon_order_id' => '900401']);
        $this->supply($inactive, ['ozon_order_id' => '900402']);

        (new SyncSupplyStatusesJob)->handle();

        Queue::assertPushed(SyncSupplyStatusBatchJob::class, 1);
        Queue::assertPushed(SyncSupplyStatusBatchJob::class, fn ($job) => $job->supplyIds === [$tracked->id]);
    }

    public function test_child_rechecks_account_scope_and_closed_status_before_calling_api(): void
    {
        $integration = $this->integration();
        $supply = $this->supply($integration, ['ozon_order_id' => '900501']);
        $job = new SyncSupplyStatusJob($supply->id, $integration->id);
        $supply->update(['status' => Supply::STATUS_CLOSED]);
        $service = \Mockery::mock(SupplyService::class);
        $service->shouldNotReceive('syncStatus');

        $job->handle($service);
    }

    public function test_queued_request_already_satisfied_by_a_newer_observation_does_not_call_api_again(): void
    {
        $integration = $this->integration();
        $supply = $this->supply($integration, ['ozon_order_id' => '900601', 'external_last_synced_at' => now()]);
        $service = \Mockery::mock(SupplyService::class);
        $service->shouldNotReceive('syncStatus');

        (new SyncSupplyStatusJob($supply->id, $integration->id, now()->subMinute()->getTimestamp()))->handle($service);
    }

    private function runDispatchedStatuses(SupplyService $service, ?int $integrationId = null): array
    {
        Queue::fake();
        (new SyncSupplyStatusesJob($integrationId))->handle();
        $errors = [];
        // Verify the selected scope against the same legacy single-supply seam.
        // Batch HTTP behavior is covered separately using the real service.
        foreach (Queue::pushed(SyncSupplyStatusBatchJob::class) as $pending) {
            foreach ($pending->supplyIds as $id) {
                try {
                    (new SyncSupplyStatusJob($id, $pending->integrationId))->handle($service);
                } catch (\RuntimeException $e) {
                    $errors[] = $e;
                }
            }
        }

        return $errors;
    }

    public function test_batch_fetches_once_and_never_assigns_another_orders_status_to_a_missing_supply(): void
    {
        $integration = $this->integration();
        $missing = $this->supply($integration, ['ozon_order_id' => '901001', 'status' => Supply::STATUS_SHIPPED]);
        $healthy = $this->supply($integration, ['ozon_order_id' => '901002', 'status' => Supply::STATUS_SHIPPED]);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake(['api-seller.ozon.ru/v3/supply-order/get' =>
            \Illuminate\Support\Facades\Http::response(['orders' => [['id' => 901002, 'state' => 'CLOSED']]])]);

        $stats = app(SupplyService::class)->syncStatusesBatch(collect([$missing, $healthy]));

        $this->assertSame(['total' => 2, 'succeeded' => 1, 'failed' => 1], $stats);
        $this->assertNull($missing->fresh()->external_last_synced_at);
        $this->assertSame(Supply::STATUS_SHIPPED, $missing->fresh()->status);
        $this->assertSame(Supply::STATUS_CLOSED, $healthy->fresh()->status);
        (new SyncSupplyStatusJob($missing->id, $integration->id))->handle(app(SupplyService::class));
        \Illuminate\Support\Facades\Http::assertSentCount(1);
    }

    public function test_dispatcher_bounds_batches_and_excludes_recent_confirmations(): void
    {
        Queue::fake();
        $integration = $this->integration();
        foreach (range(1, 51) as $id) {
            $this->supply($integration, ['ozon_order_id' => (string) (902000 + $id)]);
        }
        $this->supply($integration, ['ozon_order_id' => '903000', 'external_last_synced_at' => now()]);
        (new SyncSupplyStatusesJob)->handle();
        $jobs = Queue::pushed(SyncSupplyStatusBatchJob::class);
        $this->assertCount(2, $jobs);
        $this->assertSame(51, $jobs->sum(fn ($job) => count($job->supplyIds)));
        $this->assertTrue($jobs->every(fn ($job) => count($job->supplyIds) <= 50 && $job->queue === 'supply-status-batches'));
    }

    private function integration(): Integration
    {
        return Integration::factory()->ozon()->create([
            'id' => random_int(100000, 999999),
            'work_space_id' => 95,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function supply(Integration $integration, array $overrides): Supply
    {
        return Supply::create(array_merge([
            'integration_id' => $integration->id,
            'supply_type' => Supply::TYPE_FBO,
            'supply_method' => Supply::METHOD_DIRECT,
            'cluster_id' => '101',
            'cluster_name' => 'Москва',
            'status' => Supply::STATUS_DRAFT,
        ], $overrides));
    }
}
