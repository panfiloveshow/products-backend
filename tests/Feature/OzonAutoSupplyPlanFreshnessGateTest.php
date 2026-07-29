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
