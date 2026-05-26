<?php

namespace Tests\Feature;

use App\Jobs\CalculateAutoSupplyPlanJob;
use App\Models\AutoSupplyPlan;
use App\Models\Integration;
use App\Services\SellicoApiService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AutoSupplyPlanCreateTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_ozon_plan_can_be_created_without_cluster_ids_when_limit_allows(): void
    {
        Queue::fake();
        Config::set('services.sellico.skip_permission_check', true);
        $this->fakeSellicoLimits(30);

        $integration = Integration::factory()->ozon()->create([
            'id' => 9101,
            'work_space_id' => 3,
        ]);

        for ($i = 0; $i < 21; $i++) {
            AutoSupplyPlan::create([
                'integration_id' => $integration->id,
                'mp_account_id' => $integration->id,
                'marketplace' => 'ozon',
                'status' => AutoSupplyPlan::STATUS_READY,
                'mode' => AutoSupplyPlan::MODE_BALANCED,
                'params' => [],
            ]);
        }

        $response = $this
            ->withHeader('X-Workspace-Id', '3')
            ->postJson('/api/auto-supply-plans', [
                'integration_id' => $integration->id,
                'mode' => 'balanced',
                'horizon_days' => 28,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('message', 'План создан, расчёт запущен')
            ->assertJsonPath('data.params.cluster_ids', null);

        $this->assertDatabaseCount('auto_supply_plans', 22);
        Queue::assertPushed(CalculateAutoSupplyPlanJob::class);
    }

    public function test_ozon_plan_preserves_selected_cluster_ids(): void
    {
        Queue::fake();
        Config::set('services.sellico.skip_permission_check', true);
        $this->fakeSellicoLimits(30);

        $integration = Integration::factory()->ozon()->create([
            'id' => 9102,
            'work_space_id' => 3,
        ]);

        $response = $this
            ->withHeader('X-Workspace-Id', '3')
            ->postJson('/api/auto-supply-plans', [
                'integration_id' => $integration->id,
                'mode' => 'balanced',
                'horizon_days' => 28,
                'cluster_ids' => [149, 154],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.params.cluster_ids', [149, 154]);
    }

    public function test_autoplanning_limit_exceeded_still_blocks_creation(): void
    {
        Queue::fake();
        Config::set('services.sellico.skip_permission_check', true);
        $this->fakeSellicoLimits(21);

        $integration = Integration::factory()->ozon()->create([
            'id' => 9103,
            'work_space_id' => 3,
        ]);

        for ($i = 0; $i < 21; $i++) {
            AutoSupplyPlan::create([
                'integration_id' => $integration->id,
                'mp_account_id' => $integration->id,
                'marketplace' => 'ozon',
                'status' => AutoSupplyPlan::STATUS_READY,
                'mode' => AutoSupplyPlan::MODE_BALANCED,
                'params' => [],
            ]);
        }

        $response = $this
            ->withHeader('X-Workspace-Id', '3')
            ->postJson('/api/auto-supply-plans', [
                'integration_id' => $integration->id,
                'mode' => 'balanced',
                'horizon_days' => 28,
            ]);

        $response
            ->assertStatus(403)
            ->assertJsonPath('error', 'limit_exceeded')
            ->assertJsonPath('type', 'autoplanning')
            ->assertJsonPath('current_value', 21)
            ->assertJsonPath('requested_value', 22)
            ->assertJsonPath('limit', 21);

        $this->assertDatabaseCount('auto_supply_plans', 21);
        Queue::assertNothingPushed();
    }

    private function fakeSellicoLimits(int $limit): void
    {
        $this->app->instance(SellicoApiService::class, new class($limit) extends SellicoApiService {
            public function __construct(private int $limit)
            {
            }

            public function syncWorkspaceLimitExternal(int $workspaceId, array $payload): array
            {
                return [
                    'success' => true,
                    'status' => 200,
                    'limit' => [
                        'type' => $payload['type'] ?? 'autoplanning',
                        'limit' => $this->limit,
                        'current_value' => $payload['current_value'] ?? 0,
                    ],
                ];
            }

            public function getWorkspaceLimitsExternal(int $workspaceId, ?string $type = null): array
            {
                return [
                    'success' => true,
                    'status' => 200,
                    'limits' => [
                        [
                            'type' => $type ?? 'autoplanning',
                            'limit' => $this->limit,
                        ],
                    ],
                ];
            }
        });
    }
}
