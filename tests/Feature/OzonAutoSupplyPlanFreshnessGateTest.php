<?php

namespace Tests\Feature;

use App\Models\AutoSupplyPlan;
use App\Models\AutoSupplyPlanLine;
use App\Models\Integration;
use App\Models\PlanningFactSnapshot;
use App\Models\Product;
use App\Services\AutoSupplyPlanning\DataFreshnessRegistry;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Сквозная цепочка «свежесть фактов → validate → approve».
 *
 * Отдельно от OzonAutoSupplyPlanMaterializationTest, где реестр свежести
 * замокан как всегда готовый: здесь проверяется именно то, что устаревшие или
 * изменившиеся факты не дают довести план до исполнения.
 */
class OzonAutoSupplyPlanFreshnessGateTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const WORKSPACE_ID = 93;

    /** @var array<string, mixed> */
    private array $health;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.sellico.skip_permission_check', true);
        $this->health = $this->readyHealth(str_repeat('a', 64));

        $registry = \Mockery::mock(DataFreshnessRegistry::class);
        $registry->shouldReceive('inspect')->andReturnUsing(fn (): array => $this->health);
        $this->app->instance(DataFreshnessRegistry::class, $registry);
    }

    public function test_plan_without_ready_snapshot_cannot_be_validated(): void
    {
        $plan = $this->makeReadyPlan(withSnapshot: false);

        $this->postJson("/api/auto-supply-plans/{$plan->id}/validate", [], $this->workspaceHeaders())
            ->assertStatus(422)
            ->assertJsonPath('data.validation.allowed', false)
            ->assertJsonFragment(['code' => 'facts_snapshot_required']);

        $this->assertSame(
            AutoSupplyPlan::BUSINESS_STATUS_VALIDATION_BLOCKED,
            $plan->fresh()->business_status
        );
        $this->assertNull($plan->fresh()->validation_fingerprint);
    }

    public function test_plan_without_snapshot_cannot_be_approved(): void
    {
        $plan = $this->makeReadyPlan(withSnapshot: false);
        $this->postJson("/api/auto-supply-plans/{$plan->id}/validate", [], $this->workspaceHeaders())
            ->assertStatus(422);

        $this->postJson("/api/auto-supply-plans/{$plan->id}/approve", [], $this->workspaceHeaders())
            ->assertStatus(422)
            ->assertJsonPath('error', 'approval_blocked');

        $this->assertNull($plan->fresh()->approved_at);
    }

    public function test_stale_blocking_source_blocks_validation(): void
    {
        $plan = $this->makeReadyPlan();
        $this->health = [
            'status' => 'blocked',
            'can_calculate' => false,
            'can_approve' => false,
            'blocking_errors' => [[
                'source' => 'inventory',
                'status' => 'stale',
                'message' => 'Остатки не обновлялись дольше SLA.',
            ]],
            'warnings' => [],
            'sources' => [],
            'hash' => str_repeat('b', 64),
        ];

        $this->postJson("/api/auto-supply-plans/{$plan->id}/validate", [], $this->workspaceHeaders())
            ->assertStatus(422)
            ->assertJsonFragment(['code' => 'facts_inventory_stale'])
            ->assertJsonFragment(['message' => 'Остатки не обновлялись дольше SLA.']);

        $this->assertSame(
            AutoSupplyPlan::BUSINESS_STATUS_VALIDATION_BLOCKED,
            $plan->fresh()->business_status
        );
    }

    public function test_facts_refreshed_after_validation_block_approval(): void
    {
        $plan = $this->makeReadyPlan();
        $this->postJson("/api/auto-supply-plans/{$plan->id}/validate", [], $this->workspaceHeaders())
            ->assertOk()
            ->assertJsonPath('data.validation.allowed', true);

        // Между проверкой и утверждением прошёл синк — исходные факты уже другие.
        $this->health = $this->readyHealth(str_repeat('c', 64));

        $this->postJson("/api/auto-supply-plans/{$plan->id}/approve", [], $this->workspaceHeaders())
            ->assertStatus(422)
            ->assertJsonFragment(['code' => 'facts_changed_after_validation']);

        $this->assertNull($plan->fresh()->approved_at);
        $this->assertNotSame(
            AutoSupplyPlan::BUSINESS_STATUS_APPROVED,
            $plan->fresh()->business_status
        );
    }

    public function test_facts_gone_stale_after_validation_block_approval(): void
    {
        $plan = $this->makeReadyPlan();
        $this->postJson("/api/auto-supply-plans/{$plan->id}/validate", [], $this->workspaceHeaders())
            ->assertOk();

        // Тот же hash, но источники уже вышли за SLA.
        $this->health = array_merge($this->health, [
            'status' => 'blocked',
            'can_approve' => false,
            'blocking_errors' => [[
                'source' => 'demand',
                'status' => 'stale',
                'message' => 'Спрос устарел.',
            ]],
        ]);

        $this->postJson("/api/auto-supply-plans/{$plan->id}/approve", [], $this->workspaceHeaders())
            ->assertStatus(422)
            ->assertJsonFragment(['code' => 'facts_changed_after_validation']);

        $this->assertNull($plan->fresh()->approved_at);
    }

    public function test_unchanged_fresh_facts_allow_approval(): void
    {
        $plan = $this->makeReadyPlan();
        $this->postJson("/api/auto-supply-plans/{$plan->id}/validate", [], $this->workspaceHeaders())
            ->assertOk();

        $this->postJson("/api/auto-supply-plans/{$plan->id}/approve", [], $this->workspaceHeaders())
            ->assertOk()
            ->assertJsonPath('data.plan.business_status', AutoSupplyPlan::BUSINESS_STATUS_APPROVED);

        $this->assertNotNull($plan->fresh()->approved_at);
    }

    public function test_new_facts_cannot_be_validated_onto_quantities_calculated_from_an_older_version(): void
    {
        $plan = $this->makeReadyPlan();
        $this->health = $this->readyHealth(str_repeat('c', 64));
        $this->postJson("/api/auto-supply-plans/{$plan->id}/validate", [], $this->workspaceHeaders())
            ->assertStatus(422)->assertJsonFragment(['code' => 'facts_changed_since_calculation']);
        $this->assertNull($plan->fresh()->validation_fingerprint);
    }

    public function test_a_changed_fact_matrix_after_validation_cannot_reach_approval(): void
    {
        $plan = $this->makeReadyPlan();
        $this->postJson("/api/auto-supply-plans/{$plan->id}/validate", [], $this->workspaceHeaders())->assertOk();
        $snapshot = $plan->snapshot;
        $stock = $snapshot->stock_facts_json;
        $stock['lines'][0]['current_stock'] = 999;
        $snapshot->update(['stock_facts_json' => $stock]);
        $this->postJson("/api/auto-supply-plans/{$plan->id}/approve", [], $this->workspaceHeaders())
            ->assertStatus(422)->assertJsonFragment(['code' => 'facts_snapshot_changed']);
        $this->assertNull($plan->fresh()->approved_at);
    }

    public function test_a_legacy_snapshot_without_an_input_receipt_requires_recalculation(): void
    {
        $plan = $this->makeReadyPlan();
        $plan->snapshot->update(['summary_json' => ['stage' => 'completed']]);
        $this->postJson("/api/auto-supply-plans/{$plan->id}/validate", [], $this->workspaceHeaders())
            ->assertStatus(422)->assertJsonFragment(['code' => 'facts_snapshot_evidence_missing']);
    }

    public function test_validation_without_its_exact_snapshot_digest_cannot_authorize_approval(): void
    {
        $plan = $this->makeReadyPlan();
        $this->postJson("/api/auto-supply-plans/{$plan->id}/validate", [], $this->workspaceHeaders())->assertOk();
        $validation = $plan->fresh()->validation_json;
        unset($validation['snapshot_hash']);
        $plan->update(['validation_json' => $validation]);
        $this->postJson("/api/auto-supply-plans/{$plan->id}/approve", [], $this->workspaceHeaders())
            ->assertStatus(422)->assertJsonFragment(['code' => 'facts_snapshot_changed_after_validation']);
        $this->assertNull($plan->fresh()->approved_at);
    }

    public function test_a_snapshot_from_another_plan_cannot_explain_this_plan(): void
    {
        $plan = $this->makeReadyPlan();
        $other = $plan->replicate();
        $other->save();
        $this->assertSame('facts_snapshot_scope_mismatch', \App\Services\AutoSupplyPlanning\PlanningSnapshotEvidence::errors($other)[0]['code']);
        $this->postJson("/api/auto-supply-plans/{$other->id}/validate", [], $this->workspaceHeaders())
            ->assertStatus(422)->assertJsonFragment(['code' => 'facts_snapshot_required']);
    }

    public function test_even_a_sealed_empty_matrix_cannot_explain_stored_positive_quantities(): void
    {
        $plan = $this->makeReadyPlan();
        $snapshot = $plan->snapshot;
        $snapshot->update(['stock_facts_json' => ['lines' => []]]);
        $snapshot->refresh();
        $snapshot->update(['summary_json' => \App\Services\AutoSupplyPlanning\PlanningSnapshotEvidence::seal($snapshot)]);
        $this->postJson("/api/auto-supply-plans/{$plan->id}/validate", [], $this->workspaceHeaders())
            ->assertStatus(422)->assertJsonFragment(['code' => 'facts_snapshot_lines_missing']);
    }

    public function test_materialized_supply_cannot_start_a_new_native_draft_after_inputs_change(): void
    {
        $plan = $this->makeReadyPlan();
        $this->postJson("/api/auto-supply-plans/{$plan->id}/validate", [], $this->workspaceHeaders())->assertOk();
        $materializer = app(\App\Services\AutoSupplyPlanning\OzonPlanMaterializationService::class);
        $plan = $materializer->approve($plan->fresh());
        $supply = $materializer->materialize($plan)['supplies']->first();
        $this->health = $this->readyHealth(str_repeat('d', 64));
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        try {
            app(\App\Services\Supply\SupplyService::class)->createOzonDraft($supply);
            $this->fail('A stale plan must not create a new Native draft');
        } catch (\App\Exceptions\OzonPreconditionException $e) {
            $this->assertStringContainsString('Исходные данные или утверждение автоплана', $e->getMessage());
        }
        \Illuminate\Support\Facades\Http::assertNothingSent();
        $this->assertNull($supply->fresh()->ozon_draft_id);
        // Reading a recorded result remains possible without a new command.
        $supply->update(['ozon_draft_id' => '900001', 'ozon_response' => ['status' => 'success']]);
        $this->assertSame('900001', app(\App\Services\Supply\SupplyService::class)->createOzonDraft($supply)['draft_id']);
    }

    public function test_input_changes_during_calculation_are_not_relabelled_as_a_stable_result(): void
    {
        $plan = $this->makeReadyPlan();
        $capture = app(\App\Services\AutoSupplyPlanning\PlanningFactSnapshotService::class);
        $capture->start($plan);
        $facts = app(\App\Services\AutoSupplyPlanning\OzonPlanningFactsBuilder::class)->buildLineFacts($plan->lines()->get()->toArray());
        $this->health = $this->readyHealth(str_repeat('c', 64));
        $capture->complete($plan, ['demand_facts' => ['lines' => $facts['demand']], 'stock_facts' => ['lines' => $facts['stock']],
            'supply_facts' => ['lines' => $facts['supply']], 'economics_facts' => ['lines' => $facts['economics']]]);
        $this->postJson("/api/auto-supply-plans/{$plan->id}/validate", [], $this->workspaceHeaders())
            ->assertStatus(422)->assertJsonFragment(['code' => 'facts_changed_during_calculation']);
    }

    /**
     * @return array<string, mixed>
     */
    private function readyHealth(string $hash): array
    {
        return [
            'status' => 'ready',
            'can_calculate' => true,
            'can_approve' => true,
            'blocking_errors' => [],
            'warnings' => [],
            'sources' => [],
            'hash' => $hash,
        ];
    }

    private function makeReadyPlan(bool $withSnapshot = true): AutoSupplyPlan
    {
        $integration = Integration::factory()->ozon()->create([
            'id' => random_int(100000, 999999),
            'work_space_id' => self::WORKSPACE_ID,
        ]);

        $plan = AutoSupplyPlan::create([
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
            'total_lines' => 1,
            'total_qty' => 5,
        ]);

        Product::factory()->ozon()->create([
            'integration_id' => $integration->id,
            'sku' => 'SKU-A',
            'marketplace_id' => '700001',
            'ozon_data' => ['sku' => 700001],
        ]);

        AutoSupplyPlanLine::create([
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU-A',
            'offer_id' => 'OFFER-SKU-A',
            'product_name' => 'Товар SKU-A',
            'cluster_id' => '101',
            'cluster_name' => 'Москва',
            'destination_type' => 'cluster',
            'qty_recommended' => 5,
            'qty_rounded' => 5,
            'risk_level' => 'low',
            'priority' => 'low',
        ]);

        if ($withSnapshot) {
            $capture = app(\App\Services\AutoSupplyPlanning\PlanningFactSnapshotService::class);
            $capture->start($plan);
            $facts = app(\App\Services\AutoSupplyPlanning\OzonPlanningFactsBuilder::class)->buildLineFacts($plan->lines()->get()->toArray());
            $capture->complete($plan, ['demand_facts' => ['lines' => $facts['demand']], 'stock_facts' => ['lines' => $facts['stock']],
                'supply_facts' => ['lines' => $facts['supply']], 'economics_facts' => ['lines' => $facts['economics']]]);
        }

        return $plan->fresh();
    }

    /**
     * @return array<string, string>
     */
    private function workspaceHeaders(): array
    {
        return ['X-Sellico-Workspace' => (string) self::WORKSPACE_ID];
    }
}
