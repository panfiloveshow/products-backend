<?php

namespace Tests\Feature;

use App\Jobs\CalculateAutoSupplyPlanJob;
use App\Models\AutoSupplyConstraintFile;
use App\Models\AutoSupplyPlan;
use App\Models\Integration;
use App\Services\AutoSupplyPlanning\PlanningFactSnapshotService;
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

    public function test_ozon_plan_converts_legacy_cluster_warehouse_ids_to_cluster_ids(): void
    {
        Queue::fake();
        Config::set('services.sellico.skip_permission_check', true);
        $this->fakeSellicoLimits(30);

        $integration = Integration::factory()->ozon()->create([
            'id' => 9104,
            'work_space_id' => 3,
        ]);

        $response = $this
            ->withHeader('X-Workspace-Id', '3')
            ->postJson('/api/auto-supply-plans', [
                'integration_id' => $integration->id,
                'mode' => 'balanced',
                'horizon_days' => 28,
                'warehouse_ids' => ['cluster:154'],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.params.cluster_ids', [154])
            ->assertJsonPath('data.params.warehouse_ids', null);
    }

    public function test_create_plan_accepts_planning_engine_parameters(): void
    {
        Queue::fake();
        Config::set('services.sellico.skip_permission_check', true);
        $this->fakeSellicoLimits(30);

        $integration = Integration::factory()->ozon()->create([
            'id' => 9105,
            'work_space_id' => 3,
        ]);

        $response = $this
            ->withHeader('X-Workspace-Id', '3')
            ->postJson('/api/auto-supply-plans', [
                'integration_id' => $integration->id,
                'planning_mode' => 'post_promo_careful',
                'analysis_period_days' => 56,
                'horizon_days' => 60,
                'include_in_transit' => false,
                'seasonality_multiplier' => 1.15,
                'trend_multiplier' => 0.8,
                'promo_mode' => 'post_promo',
                'budget_limit' => 150000,
                'skip_negative_profit' => true,
                'draft_supply_method' => 'crossdock',
                'drop_off_point_warehouse_id' => 777001,
                'constraint_metadata' => [
                    'file' => ['name' => 'ozon_limits.csv', 'sha256' => 'test-hash'],
                    'summary' => ['parser_version' => 'marketplace-constraints-2', 'constraints_count' => 2],
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.mode', 'post_promo_careful')
            ->assertJsonPath('data.params.planning_mode', 'post_promo_careful')
            ->assertJsonPath('data.params.analysis_period_days', 56)
            ->assertJsonPath('data.params.include_in_transit', false)
            ->assertJsonPath('data.params.trend_multiplier', 0.8)
            ->assertJsonPath('data.params.promo_mode', 'post_promo')
            ->assertJsonPath('data.params.draft_supply_method', 'crossdock')
            ->assertJsonPath('data.params.drop_off_point_warehouse_id', 777001)
            ->assertJsonPath('data.params.constraint_metadata.file.name', 'ozon_limits.csv')
            ->assertJsonPath('data.params.constraint_metadata.summary.parser_version', 'marketplace-constraints-2');
    }

    public function test_create_plan_accepts_legacy_mode_aliases_and_supply_method_alias(): void
    {
        Queue::fake();
        Config::set('services.sellico.skip_permission_check', true);
        $this->fakeSellicoLimits(30);

        $integration = Integration::factory()->ozon()->create([
            'id' => 9109,
            'work_space_id' => 3,
        ]);

        $response = $this
            ->withHeader('X-Workspace-Id', '3')
            ->postJson('/api/auto-supply-plans', [
                'integration_id' => $integration->id,
                'planning_mode' => 'cash_safe',
                'analysis_period_days' => 30,
                'cluster_ids' => [154],
                'supply_method' => 'cross_dock',
                'drop_off_point_warehouse_id' => 777001,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.mode', 'cash_safe')
            ->assertJsonPath('data.params.planning_mode', 'cash_safe')
            ->assertJsonPath('data.params.cluster_ids', [154])
            ->assertJsonPath('data.params.draft_supply_method', 'crossdock')
            ->assertJsonPath('data.params.supply_method', 'crossdock')
            ->assertJsonPath('data.params.drop_off_point_warehouse_id', 777001);

        Queue::assertPushed(CalculateAutoSupplyPlanJob::class);
    }

    public function test_create_plan_rejects_invalid_cluster_id(): void
    {
        Queue::fake();
        Config::set('services.sellico.skip_permission_check', true);
        $this->fakeSellicoLimits(30);

        $integration = Integration::factory()->ozon()->create([
            'id' => 9110,
            'work_space_id' => 3,
        ]);

        $response = $this
            ->withHeader('X-Workspace-Id', '3')
            ->postJson('/api/auto-supply-plans', [
                'integration_id' => $integration->id,
                'planning_mode' => 'balanced',
                'cluster_ids' => [0],
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cluster_ids.0']);

        Queue::assertNothingPushed();
    }

    public function test_capabilities_endpoint_returns_russian_marketplace_contract(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $response = $this
            ->withHeader('X-Workspace-Id', '3')
            ->getJson('/api/auto-supply-plans/capabilities?marketplace=ozon');

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Возможности автопланирования')
            ->assertJsonPath('data.marketplace', 'Ozon')
            ->assertJsonPath('data.storage_point_label', 'кластеры')
            ->assertJsonPath('data.supports_autobooking', false)
            ->assertJsonPath('data.supports_draft_creation', true)
            ->assertJsonPath('data.request_parameters_ru.0', 'период анализа продаж')
            ->assertJsonPath('data.api_inputs_ru.0', 'заказы FBO Ozon')
            ->assertJsonPath('data.calculation_outputs_ru.3', 'на какие кластеры лучше везти')
            ->assertJsonPath('data.marketplace_rules_ru.1', 'черновик Ozon создаётся только после безопасного предпросмотра и ручного подтверждения');
    }

    public function test_capabilities_endpoint_can_use_integration_marketplace(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->create([
            'id' => 9111,
            'work_space_id' => 3,
            'marketplace' => 'wildberries',
        ]);

        $response = $this
            ->withHeader('X-Workspace-Id', '3')
            ->getJson('/api/auto-supply-plans/capabilities?integration_id='.$integration->id);

        $response
            ->assertOk()
            ->assertJsonPath('data.marketplace', 'Wildberries')
            ->assertJsonPath('data.supports_draft_creation', false)
            ->assertJsonPath('data.storage_point_label', 'склады')
            ->assertJsonPath('data.calculation_outputs_ru.3', 'на какие склады лучше везти')
            ->assertJsonPath('data.marketplace_rules_ru.0', 'WB работает как рекомендации и экспорт, без обещания автобронирования');
    }

    public function test_create_plan_can_reuse_saved_constraint_file(): void
    {
        Queue::fake();
        Config::set('services.sellico.skip_permission_check', true);
        $this->fakeSellicoLimits(30);

        $integration = Integration::factory()->ozon()->create([
            'id' => 9107,
            'work_space_id' => 3,
        ]);

        $constraintFile = AutoSupplyConstraintFile::query()->create([
            'integration_id' => $integration->id,
            'marketplace' => 'ozon',
            'file_name' => 'saved_ozon_limits.csv',
            'file_size_bytes' => 456,
            'file_hash' => 'saved-hash',
            'parser_version' => 'marketplace-constraints-2',
            'rows_total' => 1,
            'constraints_count' => 1,
            'warnings_count' => 0,
            'cluster_constraints_json' => [
                ['cluster_id' => 154, 'sku' => 'SKU-1', 'max_qty' => 9],
            ],
            'summary_json' => ['parser_version' => 'marketplace-constraints-2', 'constraints_count' => 1],
            'warnings_json' => [],
            'parsed_at' => now(),
        ]);

        $response = $this
            ->withHeader('X-Workspace-Id', '3')
            ->postJson('/api/auto-supply-plans', [
                'integration_id' => $integration->id,
                'planning_mode' => 'balanced',
                'horizon_days' => 30,
                'constraint_file_id' => $constraintFile->id,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.params.constraint_file_id', $constraintFile->id)
            ->assertJsonPath('data.params.cluster_constraints.0.cluster_id', 154)
            ->assertJsonPath('data.params.cluster_constraints.0.max_qty', 9)
            ->assertJsonPath('data.params.constraint_metadata.file.name', 'saved_ozon_limits.csv');

        $this->assertNotNull($constraintFile->fresh()->last_used_at);
    }

    public function test_create_plan_can_use_latest_saved_constraint_file_as_source(): void
    {
        Queue::fake();
        Config::set('services.sellico.skip_permission_check', true);
        $this->fakeSellicoLimits(30);

        $integration = Integration::factory()->ozon()->create([
            'id' => 9108,
            'work_space_id' => 3,
        ]);

        AutoSupplyConstraintFile::query()->create([
            'integration_id' => $integration->id,
            'marketplace' => 'ozon',
            'file_name' => 'old_limits.csv',
            'file_size_bytes' => 100,
            'file_hash' => 'old-hash',
            'parser_version' => 'marketplace-constraints-2',
            'rows_total' => 1,
            'constraints_count' => 1,
            'warnings_count' => 0,
            'cluster_constraints_json' => [
                ['cluster_id' => 111, 'sku' => 'OLD', 'max_qty' => 1],
            ],
            'summary_json' => ['parser_version' => 'marketplace-constraints-2', 'constraints_count' => 1],
            'warnings_json' => [],
            'parsed_at' => now()->subDay(),
        ]);

        $latest = AutoSupplyConstraintFile::query()->create([
            'integration_id' => $integration->id,
            'marketplace' => 'ozon',
            'file_name' => 'latest_limits.csv',
            'file_size_bytes' => 200,
            'file_hash' => 'latest-hash',
            'parser_version' => 'marketplace-constraints-2',
            'rows_total' => 1,
            'constraints_count' => 1,
            'warnings_count' => 0,
            'cluster_constraints_json' => [
                ['cluster_id' => 154, 'sku' => 'SKU-LATEST', 'max_qty' => 12],
            ],
            'summary_json' => ['parser_version' => 'marketplace-constraints-2', 'constraints_count' => 1],
            'warnings_json' => [],
            'parsed_at' => now(),
        ]);

        $response = $this
            ->withHeader('X-Workspace-Id', '3')
            ->postJson('/api/auto-supply-plans', [
                'integration_id' => $integration->id,
                'planning_mode' => 'balanced',
                'horizon_days' => 30,
                'use_latest_constraint_file' => true,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.params.constraint_file_id', $latest->id)
            ->assertJsonPath('data.params.use_latest_constraint_file', true)
            ->assertJsonPath('data.params.cluster_constraints.0.cluster_id', 154)
            ->assertJsonPath('data.params.cluster_constraints.0.max_qty', 12)
            ->assertJsonPath('data.params.constraint_metadata.file.name', 'latest_limits.csv');

        $this->assertNotNull($latest->fresh()->last_used_at);
    }

    public function test_create_plan_latest_constraint_file_uses_marketplace_need_only_file_as_source(): void
    {
        Queue::fake();
        Config::set('services.sellico.skip_permission_check', true);
        $this->fakeSellicoLimits(30);

        $integration = Integration::factory()->ozon()->create([
            'id' => 9112,
            'work_space_id' => 3,
        ]);

        $needFile = AutoSupplyConstraintFile::query()->create([
            'integration_id' => $integration->id,
            'marketplace' => 'ozon',
            'file_name' => 'ozon_needs_only.csv',
            'file_size_bytes' => 220,
            'file_hash' => 'needs-only-hash',
            'parser_version' => 'marketplace-constraints-2',
            'rows_total' => 1,
            'constraints_count' => 0,
            'warnings_count' => 0,
            'cluster_constraints_json' => [
                [
                    'cluster_id' => 154,
                    'sku' => 'SKU-NEED',
                    'need_qty' => 42,
                    'source_type' => 'marketplace_need',
                ],
            ],
            'summary_json' => [
                'parser_version' => 'marketplace-constraints-2',
                'constraints_count' => 0,
                'marketplace_needs_count' => 1,
                'planning_roles' => [
                    'used_as_constraints' => false,
                    'used_as_marketplace_needs' => true,
                    'used_as_coefficients' => false,
                ],
                'source_type_counts' => [
                    'marketplace_need' => 1,
                ],
            ],
            'warnings_json' => [],
            'parsed_at' => now(),
        ]);

        $response = $this
            ->withHeader('X-Workspace-Id', '3')
            ->postJson('/api/auto-supply-plans', [
                'integration_id' => $integration->id,
                'planning_mode' => 'balanced',
                'horizon_days' => 30,
                'use_latest_constraint_file' => true,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.params.constraint_file_id', $needFile->id)
            ->assertJsonPath('data.params.cluster_constraints.0.cluster_id', 154)
            ->assertJsonPath('data.params.cluster_constraints.0.need_qty', 42)
            ->assertJsonPath('data.params.cluster_constraints.0.source_type', 'marketplace_need')
            ->assertJsonPath('data.params.constraint_metadata.file.name', 'ozon_needs_only.csv');
    }

    public function test_create_plan_latest_constraint_file_skips_empty_newer_file(): void
    {
        Queue::fake();
        Config::set('services.sellico.skip_permission_check', true);
        $this->fakeSellicoLimits(30);

        $integration = Integration::factory()->ozon()->create([
            'id' => 9113,
            'work_space_id' => 3,
        ]);

        $usableFile = AutoSupplyConstraintFile::query()->create([
            'integration_id' => $integration->id,
            'marketplace' => 'ozon',
            'file_name' => 'usable_limits.csv',
            'file_size_bytes' => 220,
            'file_hash' => 'usable-limits-hash',
            'parser_version' => 'marketplace-constraints-2',
            'rows_total' => 1,
            'constraints_count' => 1,
            'warnings_count' => 0,
            'cluster_constraints_json' => [
                ['cluster_id' => 154, 'sku' => 'SKU-LIMIT', 'max_qty' => 7],
            ],
            'summary_json' => [
                'parser_version' => 'marketplace-constraints-2',
                'constraints_count' => 1,
            ],
            'warnings_json' => [],
            'parsed_at' => now()->subHour(),
        ]);

        AutoSupplyConstraintFile::query()->create([
            'integration_id' => $integration->id,
            'marketplace' => 'ozon',
            'file_name' => 'empty_newer.csv',
            'file_size_bytes' => 50,
            'file_hash' => 'empty-newer-hash',
            'parser_version' => 'marketplace-constraints-2',
            'rows_total' => 0,
            'constraints_count' => 0,
            'warnings_count' => 0,
            'cluster_constraints_json' => [],
            'warehouse_constraints_json' => [],
            'summary_json' => [
                'parser_version' => 'marketplace-constraints-2',
                'constraints_count' => 0,
                'marketplace_needs_count' => 0,
                'coefficient_lines_count' => 0,
                'planning_roles' => [],
            ],
            'warnings_json' => [],
            'parsed_at' => now(),
        ]);

        $response = $this
            ->withHeader('X-Workspace-Id', '3')
            ->postJson('/api/auto-supply-plans', [
                'integration_id' => $integration->id,
                'planning_mode' => 'balanced',
                'horizon_days' => 30,
                'use_latest_constraint_file' => true,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.params.constraint_file_id', $usableFile->id)
            ->assertJsonPath('data.params.cluster_constraints.0.cluster_id', 154)
            ->assertJsonPath('data.params.cluster_constraints.0.max_qty', 7)
            ->assertJsonPath('data.params.constraint_metadata.file.name', 'usable_limits.csv');
    }

    public function test_planning_fact_snapshot_links_to_plan(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9106]);
        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_CALCULATING,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => ['cluster_ids' => [154]],
        ]);

        $service = app(PlanningFactSnapshotService::class);
        $snapshot = $service->start($plan, ['constraints' => ['selected_cluster_ids' => [154]]]);
        $service->complete($plan->fresh(), [
            'facts_freshness' => ['inventory_warehouses' => ['items' => 2]],
            'planning_sources' => ['demand' => 'posting_fbo_v3'],
            'summary' => ['total_lines' => 1, 'total_qty' => 10],
        ]);

        $plan->refresh();
        $snapshot->refresh();

        $this->assertSame($snapshot->id, $plan->snapshot_id);
        $this->assertSame('ready', $snapshot->status);
        $this->assertSame('posting_fbo_v3', $snapshot->planning_sources_json['demand']);
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
