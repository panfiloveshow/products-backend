<?php

namespace Tests\Feature;

use App\Models\AutoSupplyPlan;
use App\Models\AutoSupplyPlanLine;
use App\Models\Integration;
use App\Models\PlanningFactSnapshot;
use App\Models\Product;
use App\Models\Supply;
use App\Services\Supply\SupplyService;
use App\Services\AutoSupplyPlanning\DataFreshnessRegistry;
use App\Services\AutoSupplyPlanning\AutoSupplyPlanExecutionService;
use App\Jobs\ExecuteOzonSupplyDraftJob;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OzonAutoSupplyPlanMaterializationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const WORKSPACE_ID = 91;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.sellico.skip_permission_check', true);
        $registry = \Mockery::mock(DataFreshnessRegistry::class);
        $registry->shouldReceive('inspect')->andReturn([
            'status' => 'ready',
            'can_calculate' => true,
            'can_approve' => true,
            'blocking_errors' => [],
            'warnings' => [],
            'sources' => [],
            'hash' => str_repeat('a', 64),
        ]);
        $this->app->instance(DataFreshnessRegistry::class, $registry);
    }

    public function test_ready_ozon_plan_can_be_approved_and_materialized_by_cluster(): void
    {
        [$plan, $lines] = $this->makeReadyPlan([
            ['sku' => 'SKU-A', 'cluster_id' => '101', 'cluster_name' => 'Москва', 'qty' => 5, 'ozon_sku' => 700001],
            ['sku' => 'SKU-B', 'cluster_id' => '101', 'cluster_name' => 'Москва', 'qty' => 3, 'ozon_sku' => 700002],
            ['sku' => 'SKU-C', 'cluster_id' => '202', 'cluster_name' => 'Казань', 'qty' => 4, 'ozon_sku' => 700003],
        ]);
        $this->validateForApproval($plan);
        $this->postJson(
            "/api/auto-supply-plans/{$plan->id}/approve",
            [],
            $this->workspaceHeaders()
        )
            ->assertOk()
            ->assertJsonPath('data.plan.business_status', AutoSupplyPlan::BUSINESS_STATUS_APPROVED)
            ->assertJsonPath('data.approval_check.lines_count', 3)
            ->assertJsonPath('data.approval_check.clusters_count', 2);

        $plan->refresh();
        $this->assertNotNull($plan->approved_at);
        $this->assertSame(64, strlen((string) $plan->approval_fingerprint));

        $this->postJson(
            "/api/auto-supply-plans/{$plan->id}/materialize-supplies",
            [],
            $this->workspaceHeaders()
        )
            ->assertCreated()
            ->assertJsonPath('data.created_count', 2)
            ->assertJsonPath('data.idempotent', false)
            ->assertJsonPath('data.external_api_called', false);

        $this->assertDatabaseCount('supplies', 2);
        $this->assertDatabaseCount('supply_items', 3);
        $this->assertDatabaseHas('supplies', [
            'auto_supply_plan_id' => $plan->id,
            'integration_id' => $plan->integration_id,
            'cluster_id' => '101',
            'status' => 'draft',
        ]);
        $this->assertDatabaseHas('supply_items', [
            'auto_supply_plan_line_id' => $lines[0]->id,
            'ozon_product_id' => '700001',
            'planned_qty' => 5,
        ]);
        $this->assertNotNull($plan->fresh()->materialized_at);
    }

    public function test_materialization_is_idempotent_and_does_not_duplicate_supplies_or_items(): void
    {
        [$plan] = $this->makeReadyPlan([
            ['sku' => 'SKU-A', 'cluster_id' => '101', 'cluster_name' => 'Москва', 'qty' => 5, 'ozon_sku' => 700001],
        ]);
        $this->validateForApproval($plan);

        $this->postJson(
            "/api/auto-supply-plans/{$plan->id}/approve",
            [],
            $this->workspaceHeaders()
        )->assertOk();

        $this->postJson(
            "/api/auto-supply-plans/{$plan->id}/materialize-supplies",
            [],
            $this->workspaceHeaders()
        )->assertCreated();

        $firstSupplyId = (int) $plan->fresh()->supplies()->value('id');

        $this->postJson(
            "/api/auto-supply-plans/{$plan->id}/materialize-supplies",
            [],
            $this->workspaceHeaders()
        )
            ->assertOk()
            ->assertJsonPath('data.created_count', 0)
            ->assertJsonPath('data.idempotent', true)
            ->assertJsonPath('data.supplies.0.id', $firstSupplyId);

        $this->assertDatabaseCount('supplies', 1);
        $this->assertDatabaseCount('supply_items', 1);
    }

    public function test_changed_plan_is_rejected_after_approval(): void
    {
        [$plan, $lines] = $this->makeReadyPlan([
            ['sku' => 'SKU-A', 'cluster_id' => '101', 'cluster_name' => 'Москва', 'qty' => 5, 'ozon_sku' => 700001],
        ]);
        $this->validateForApproval($plan);

        $this->postJson(
            "/api/auto-supply-plans/{$plan->id}/approve",
            [],
            $this->workspaceHeaders()
        )->assertOk();

        $lines[0]->update(['qty_rounded' => 6]);

        $this->postJson(
            "/api/auto-supply-plans/{$plan->id}/materialize-supplies",
            [],
            $this->workspaceHeaders()
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan');

        $this->assertDatabaseCount('supplies', 0);
    }

    public function test_quality_gate_blocks_approval(): void
    {
        [$plan] = $this->makeReadyPlan(
            [
                ['sku' => 'SKU-A', 'cluster_id' => '101', 'cluster_name' => 'Москва', 'qty' => 5, 'ozon_sku' => 700001],
            ],
            [
                'result_json' => [
                    'plan_quality_audit' => [
                        'status' => 'bad',
                        'summary_ru' => 'Недостаточно качественных данных.',
                        'acceptance_gates' => [
                            'can_create_ozon_draft' => false,
                            'manual_review_reason_ru' => 'Исправьте исходные данные.',
                        ],
                    ],
                ],
            ]
        );

        $this->postJson(
            "/api/auto-supply-plans/{$plan->id}/approve",
            [],
            $this->workspaceHeaders()
        )
            ->assertUnprocessable()
            ->assertJsonPath('error', 'approval_blocked')
            ->assertJsonPath('data.approval_check.errors.0.code', 'quality_gate_blocked');

        $this->assertDatabaseHas('auto_supply_plans', [
            'id' => $plan->id,
            'business_status' => AutoSupplyPlan::BUSINESS_STATUS_REVIEW_REQUIRED,
            'approved_at' => null,
        ]);
    }

    public function test_missing_numeric_ozon_sku_blocks_approval(): void
    {
        [$plan] = $this->makeReadyPlan([
            ['sku' => 'SKU-A', 'cluster_id' => '101', 'cluster_name' => 'Москва', 'qty' => 5, 'ozon_sku' => null],
        ]);

        $this->postJson(
            "/api/auto-supply-plans/{$plan->id}/approve",
            [],
            $this->workspaceHeaders()
        )
            ->assertUnprocessable()
            ->assertJsonPath('data.approval_check.errors.0.code', 'ozon_sku_unresolved');
    }

    public function test_crossdock_requires_drop_off_point_before_approval(): void
    {
        [$plan] = $this->makeReadyPlan(
            [
                ['sku' => 'SKU-A', 'cluster_id' => '101', 'cluster_name' => 'Москва', 'qty' => 5, 'ozon_sku' => 700001],
            ],
            ['params' => ['supply_method' => 'crossdock']]
        );

        $this->postJson(
            "/api/auto-supply-plans/{$plan->id}/approve",
            [],
            $this->workspaceHeaders()
        )
            ->assertUnprocessable()
            ->assertJsonPath('data.approval_check.errors.0.code', 'drop_off_point_required');
    }

    public function test_validation_blocks_cluster_marked_unavailable_by_ozon_constraints(): void
    {
        [$plan] = $this->makeReadyPlan([
            ['sku' => 'SKU-A', 'cluster_id' => '101', 'cluster_name' => 'Москва', 'qty' => 5, 'ozon_sku' => 700001],
        ], [
            'params' => [
                'cluster_ids' => [101],
                'cluster_constraints' => [[
                    'cluster_id' => '101',
                    'cluster_name' => 'Москва',
                    'is_available' => false,
                    'reason' => 'Нет доступных складов приёмки',
                ]],
            ],
        ]);

        $this->postJson(
            "/api/auto-supply-plans/{$plan->id}/validate",
            [],
            $this->workspaceHeaders()
        )
            ->assertUnprocessable()
            ->assertJsonPath('data.validation.allowed', false)
            ->assertJsonPath('data.validation.errors.0.code', 'cluster_unavailable')
            ->assertJsonPath('data.validation.groups.0.status', 'blocked');
    }

    public function test_unvalidated_plan_cannot_be_approved(): void
    {
        [$plan] = $this->makeReadyPlan([
            ['sku' => 'SKU-A', 'cluster_id' => '101', 'cluster_name' => 'Москва', 'qty' => 5, 'ozon_sku' => 700001],
        ]);

        $this->postJson(
            "/api/auto-supply-plans/{$plan->id}/approve",
            [],
            $this->workspaceHeaders()
        )
            ->assertUnprocessable()
            ->assertJsonPath('data.approval_check.errors.0.code', 'validation_required');
    }

    public function test_only_selected_clusters_are_materialized(): void
    {
        [$plan] = $this->makeReadyPlan(
            [
                ['sku' => 'SKU-A', 'cluster_id' => '101', 'cluster_name' => 'Москва', 'qty' => 5, 'ozon_sku' => 700001],
                ['sku' => 'SKU-B', 'cluster_id' => '202', 'cluster_name' => 'Казань', 'qty' => 3, 'ozon_sku' => 700002],
            ],
            ['params' => ['supply_method' => 'direct', 'cluster_ids' => [101]]]
        );
        $this->validateForApproval($plan);

        $this->postJson(
            "/api/auto-supply-plans/{$plan->id}/approve",
            [],
            $this->workspaceHeaders()
        )
            ->assertOk()
            ->assertJsonPath('data.approval_check.clusters_count', 1)
            ->assertJsonPath('data.approval_check.total_qty', 5);

        $this->postJson(
            "/api/auto-supply-plans/{$plan->id}/materialize-supplies",
            [],
            $this->workspaceHeaders()
        )->assertCreated()->assertJsonPath('data.created_count', 1);

        $this->assertDatabaseHas('supplies', [
            'auto_supply_plan_id' => $plan->id,
            'cluster_id' => '101',
        ]);
        $this->assertDatabaseMissing('supplies', [
            'auto_supply_plan_id' => $plan->id,
            'cluster_id' => '202',
        ]);
    }

    public function test_materialized_plan_cannot_be_changed_recalculated_or_deleted(): void
    {
        [$plan, $lines] = $this->makeReadyPlan([
            ['sku' => 'SKU-A', 'cluster_id' => '101', 'cluster_name' => 'Москва', 'qty' => 5, 'ozon_sku' => 700001],
        ]);
        $this->validateForApproval($plan);

        $this->postJson(
            "/api/auto-supply-plans/{$plan->id}/approve",
            [],
            $this->workspaceHeaders()
        )->assertOk();
        $this->postJson(
            "/api/auto-supply-plans/{$plan->id}/materialize-supplies",
            [],
            $this->workspaceHeaders()
        )->assertCreated();

        $this->putJson(
            "/api/auto-supply-plans/{$plan->id}/lines/{$lines[0]->id}",
            ['qty_rounded' => 8, 'reason' => 'Проверка блокировки материализованного плана'],
            $this->workspaceHeaders()
        )->assertConflict()->assertJsonPath('error', 'plan_already_materialized');

        $this->postJson(
            "/api/auto-supply-plans/{$plan->id}/calculate",
            [],
            $this->workspaceHeaders()
        )->assertConflict()->assertJsonPath('error', 'plan_already_materialized');

        $this->deleteJson(
            "/api/auto-supply-plans/{$plan->id}",
            [],
            $this->workspaceHeaders()
        )->assertConflict()->assertJsonPath('error', 'plan_already_materialized');

        $this->assertDatabaseHas('auto_supply_plan_lines', [
            'id' => $lines[0]->id,
            'qty_rounded' => 5,
        ]);
        $this->assertDatabaseHas('auto_supply_plans', ['id' => $plan->id]);
    }

    public function test_supply_statuses_update_auto_plan_business_lifecycle(): void
    {
        [$plan] = $this->makeReadyPlan([
            ['sku' => 'SKU-A', 'cluster_id' => '101', 'cluster_name' => 'Москва', 'qty' => 5, 'ozon_sku' => 700001],
        ]);
        $this->validateForApproval($plan);

        $this->postJson(
            "/api/auto-supply-plans/{$plan->id}/approve",
            [],
            $this->workspaceHeaders()
        )->assertOk();
        $this->postJson(
            "/api/auto-supply-plans/{$plan->id}/materialize-supplies",
            [],
            $this->workspaceHeaders()
        )->assertCreated();

        $supply = $plan->fresh()->supplies()->firstOrFail();
        $this->assertSame(AutoSupplyPlan::BUSINESS_STATUS_APPROVED, $plan->fresh()->business_status);

        $supply->updateStatus(Supply::STATUS_DRAFT_OZON);
        $this->assertSame(AutoSupplyPlan::BUSINESS_STATUS_EXECUTING, $plan->fresh()->business_status);

        $supply->updateStatus(Supply::STATUS_IN_TRANSIT);
        $this->assertSame(AutoSupplyPlan::BUSINESS_STATUS_IN_TRANSIT, $plan->fresh()->business_status);

        $supply->updateStatus(Supply::STATUS_ACCEPTED_FULL);
        $this->assertSame(AutoSupplyPlan::BUSINESS_STATUS_RECEIVED, $plan->fresh()->business_status);

        $supply->updateStatus(Supply::STATUS_CLOSED);
        $this->assertSame(AutoSupplyPlan::BUSINESS_STATUS_RECONCILED, $plan->fresh()->business_status);
    }

    public function test_existing_ozon_draft_is_returned_without_a_second_api_call(): void
    {
        [$plan] = $this->makeReadyPlan([
            ['sku' => 'SKU-A', 'cluster_id' => '101', 'cluster_name' => 'Москва', 'qty' => 5, 'ozon_sku' => 700001],
        ]);
        $this->validateForApproval($plan);

        $this->postJson(
            "/api/auto-supply-plans/{$plan->id}/approve",
            [],
            $this->workspaceHeaders()
        )->assertOk();
        $this->postJson(
            "/api/auto-supply-plans/{$plan->id}/materialize-supplies",
            [],
            $this->workspaceHeaders()
        )->assertCreated();

        $supply = $plan->fresh()->supplies()->firstOrFail();
        $supply->update([
            'status' => Supply::STATUS_DRAFT_OZON,
            'ozon_draft_id' => '987654',
            'ozon_response' => ['draft_id' => '987654'],
        ]);

        $result = app(SupplyService::class)->createOzonDraft($supply);

        $this->assertSame('987654', $result['draft_id']);
        $this->assertTrue($result['idempotent']);
        $this->assertDatabaseCount('supply_events', 1);
    }

    public function test_confirmed_execution_is_queued_and_idempotent(): void
    {
        Queue::fake();
        [$plan] = $this->makeReadyPlan([
            ['sku' => 'SKU-A', 'cluster_id' => '101', 'cluster_name' => 'Москва', 'qty' => 5, 'ozon_sku' => 700001],
            ['sku' => 'SKU-B', 'cluster_id' => '202', 'cluster_name' => 'Казань', 'qty' => 3, 'ozon_sku' => 700002],
        ]);
        $this->validateForApproval($plan);
        $this->postJson(
            "/api/auto-supply-plans/{$plan->id}/approve",
            [],
            $this->workspaceHeaders()
        )->assertOk();

        $payload = [
            'idempotency_key' => 'execution-test-0001',
            'confirmation_text' => AutoSupplyPlanExecutionService::CONFIRMATION_PHRASE,
            'auto_book_timeslot' => false,
        ];
        $this->postJson(
            "/api/auto-supply-plans/{$plan->id}/execute",
            $payload,
            $this->workspaceHeaders()
        )
            ->assertAccepted()
            ->assertJsonPath('data.idempotent', false)
            ->assertJsonPath('data.execution.status', 'running');

        $this->postJson(
            "/api/auto-supply-plans/{$plan->id}/execute",
            $payload,
            $this->workspaceHeaders()
        )
            ->assertOk()
            ->assertJsonPath('data.idempotent', true);

        $this->assertDatabaseCount('auto_supply_plan_executions', 1);
        $this->assertSame(2, $plan->fresh()->supplies()->count());
        $this->assertSame(
            AutoSupplyPlan::BUSINESS_STATUS_EXECUTING,
            $plan->fresh()->business_status
        );
        Queue::assertPushed(ExecuteOzonSupplyDraftJob::class, 2);
    }

    public function test_bulk_review_change_is_audited_and_invalidates_validation(): void
    {
        [$plan, $lines] = $this->makeReadyPlan([
            ['sku' => 'SKU-A', 'cluster_id' => '101', 'cluster_name' => 'Москва', 'qty' => 5, 'ozon_sku' => 700001],
            ['sku' => 'SKU-B', 'cluster_id' => '101', 'cluster_name' => 'Москва', 'qty' => 3, 'ozon_sku' => 700002],
        ]);
        $this->validateForApproval($plan);

        $this->postJson(
            "/api/auto-supply-plans/{$plan->id}/lines/bulk",
            [
                'line_ids' => [$lines[0]->id],
                'action' => 'exclude',
                'reason' => 'Товар временно не готов к поставке',
            ],
            $this->workspaceHeaders()
        )
            ->assertOk()
            ->assertJsonPath('data.updated_count', 1)
            ->assertJsonPath('data.total_qty', 3)
            ->assertJsonPath(
                'data.business_status',
                AutoSupplyPlan::BUSINESS_STATUS_REVIEW_REQUIRED
            );

        $this->assertTrue($lines[0]->fresh()->is_excluded);
        $this->assertNull($plan->fresh()->validation_fingerprint);
        $this->assertDatabaseHas('auto_supply_plan_adjustments', [
            'auto_supply_plan_id' => $plan->id,
            'auto_supply_plan_line_id' => $lines[0]->id,
            'action' => 'exclude',
            'reason' => 'Товар временно не готов к поставке',
        ]);
    }

    /**
     * @param  list<array{sku: string, cluster_id: string, cluster_name: string, qty: int, ozon_sku: int|null}>  $lineDefinitions
     * @param  array<string, mixed>  $planOverrides
     * @return array{AutoSupplyPlan, list<AutoSupplyPlanLine>}
     */
    private function makeReadyPlan(array $lineDefinitions, array $planOverrides = []): array
    {
        $integration = Integration::factory()->ozon()->create([
            'id' => random_int(100000, 999999),
            'work_space_id' => self::WORKSPACE_ID,
        ]);

        $plan = AutoSupplyPlan::create(array_merge([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'business_status' => AutoSupplyPlan::BUSINESS_STATUS_REVIEW_REQUIRED,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => ['supply_method' => 'direct'],
            'result_json' => [
                'plan_quality_audit' => [
                    'status' => 'good',
                    'acceptance_gates' => ['can_create_ozon_draft' => true],
                ],
            ],
            'total_lines' => count($lineDefinitions),
            'total_qty' => array_sum(array_column($lineDefinitions, 'qty')),
        ], $planOverrides));

        $lines = [];
        foreach ($lineDefinitions as $definition) {
            Product::factory()->ozon()->create([
                'integration_id' => $integration->id,
                'sku' => $definition['sku'],
                'marketplace_id' => $definition['ozon_sku'] === null
                    ? null
                    : (string) $definition['ozon_sku'],
                'ozon_data' => $definition['ozon_sku'] === null
                    ? []
                    : ['sku' => $definition['ozon_sku']],
            ]);
            $lines[] = AutoSupplyPlanLine::create([
                'auto_supply_plan_id' => $plan->id,
                'sku' => $definition['sku'],
                'offer_id' => 'OFFER-'.$definition['sku'],
                'product_name' => 'Товар '.$definition['sku'],
                'cluster_id' => $definition['cluster_id'],
                'cluster_name' => $definition['cluster_name'],
                'destination_type' => 'cluster',
                'qty_recommended' => $definition['qty'],
                'qty_rounded' => $definition['qty'],
                'risk_level' => 'low',
                'priority' => 'low',
            ]);
        }

        $snapshot = PlanningFactSnapshot::create([
            'auto_supply_plan_id' => $plan->id,
            'integration_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => 'ready',
            'captured_at' => now(),
            'params_json' => ['effective' => $plan->params],
            'facts_freshness_json' => ['status' => 'ready'],
            'summary_json' => ['stage' => 'completed'],
        ]);
        $plan->update(['snapshot_id' => $snapshot->id]);

        return [$plan, $lines];
    }

    private function validateForApproval(AutoSupplyPlan $plan): void
    {
        $this->postJson(
            "/api/auto-supply-plans/{$plan->id}/validate",
            [],
            $this->workspaceHeaders()
        )->assertOk()->assertJsonPath('data.validation.allowed', true);
    }

    /**
     * @return array<string, string>
     */
    private function workspaceHeaders(): array
    {
        return ['X-Sellico-Workspace' => (string) self::WORKSPACE_ID];
    }
}
