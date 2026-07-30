<?php

namespace Tests\Feature;

use App\Models\AutoSupplyPlan;
use App\Models\AutoSupplyPlanLine;
use App\Models\Integration;
use App\Models\OzonWarehouseCluster;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class OzonAutoSupplyPlanReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.sellico.skip_permission_check', true);
    }

    public function test_review_filters_expose_budget_cut_and_aggregated_explanation(): void
    {
        $plan = $this->makePlan(9301);

        $this->makeLine($plan, [
            'sku' => 'BUDGET-CUT',
            'qty_rounded' => 0,
            'original_qty_rounded' => 12,
            'is_excluded' => true,
            'risk_level' => 'low',
            'explain_json' => [
                'inputs' => ['daily_demand' => 2, 'target_cover_days' => 14],
                'math' => ['safety_stock' => 3, 'needed_before_caps' => 12, 'needed_after_caps' => 12],
                'confidence' => ['confidence_level' => 'good', 'confidence_reasons' => []],
                'not_recommended_reason' => 'budget_limit',
                'optimizer_rejection' => [
                    'reason' => 'budget_limit',
                    'candidate_qty' => 12,
                    'candidate_economics' => ['supply_cost_estimate' => 120],
                ],
            ],
        ]);

        $response = $this->getJson("/api/auto-supply-plans/{$plan->id}/lines?budget_cut=1");

        $response->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.sku', 'BUDGET-CUT')
            ->assertJsonPath('data.data.0.review_status', 'blocked')
            ->assertJsonPath('data.data.0.candidate_quantity', 12)
            ->assertJsonPath('data.data.0.not_recommended_reason', 'budget_limit')
            ->assertJsonPath('data.data.0.quantity_explanation.aggregation_scope', 'sku_cluster')
            ->assertJsonPath('review.budget_cut_lines_count', 1);
    }

    public function test_aggregated_line_uses_worst_risk_and_consistent_cluster_math(): void
    {
        $plan = $this->makePlan(9302);
        $baseExplain = [
            'inputs' => ['daily_demand' => 1, 'target_cover_days' => 14, 'min_cover_days' => 10],
            'math' => ['safety_stock' => 2, 'needed_before_caps' => 5, 'needed_after_caps' => 4],
            'confidence' => ['confidence_level' => 'good', 'confidence_reasons' => []],
        ];
        $this->makeLine($plan, [
            'sku' => 'AGG',
            'warehouse_id' => 'w1',
            'qty_rounded' => 3,
            'current_stock' => 5,
            'in_transit' => 1,
            'demand_daily' => 1,
            'risk_level' => 'low',
            'priority' => 'low',
            'explain_json' => $baseExplain,
        ]);
        $this->makeLine($plan, [
            'sku' => 'AGG',
            'warehouse_id' => 'w2',
            'qty_rounded' => 4,
            'current_stock' => 7,
            'in_transit' => 2,
            'demand_daily' => 2,
            'risk_level' => 'high',
            'priority' => 'critical',
            'explain_json' => [
                'inputs' => ['daily_demand' => 2, 'target_cover_days' => 14, 'min_cover_days' => 10],
                'math' => ['safety_stock' => 3, 'needed_before_caps' => 6, 'needed_after_caps' => 5],
                'confidence' => ['confidence_level' => 'warning', 'confidence_reasons' => ['low_posting_volume']],
            ],
        ]);

        $response = $this->getJson("/api/auto-supply-plans/{$plan->id}/lines");

        $response->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.qty_rounded', 7)
            ->assertJsonPath('data.data.0.risk_level', 'high')
            ->assertJsonPath('data.data.0.priority', 'critical')
            ->assertJsonPath('data.data.0.demand_daily', '3.0000')
            ->assertJsonPath('data.data.0.quantity_explanation.stock_now', 12)
            ->assertJsonPath('data.data.0.quantity_explanation.in_transit', 3)
            ->assertJsonPath('data.data.0.quantity_explanation.daily_demand', 3)
            ->assertJsonPath('data.data.0.quantity_explanation.qty_rounded', 7)
            // deficit_qty должен считаться от агрегированного спроса кластера (3/день),
            // а не от спроса MIN()-подстроки: ceil(10×3 − (12+3)) = 15.
            ->assertJsonPath('data.data.0.deficit_qty', 15)
            ->assertJsonPath('data.data.0.surplus_qty', 0)
            ->assertJsonPath('data.data.0.confidence', 'warning');
    }

    public function test_manual_quantity_recalculates_budget_and_invalidates_approval(): void
    {
        $plan = $this->makePlan(9303, [
            'budget_limit' => 60,
            'business_status' => AutoSupplyPlan::BUSINESS_STATUS_APPROVED,
            'approved_at' => now(),
            'approval_fingerprint' => str_repeat('a', 64),
        ]);
        $line = $this->makeLine($plan, [
            'sku' => 'MANUAL',
            'qty_rounded' => 5,
            'original_qty_rounded' => 5,
            'cost_price' => 10,
            'supply_cost_estimate' => 50,
            'expected_revenue' => 100,
            'expected_profit' => 40,
            'demand_daily' => 1,
            'explain_json' => [
                'inputs' => ['daily_demand' => 1, 'target_cover_days' => 14, 'pack_multiple' => 1],
                'math' => [],
                'confidence' => ['confidence_level' => 'good', 'confidence_reasons' => []],
            ],
        ]);

        $response = $this->putJson("/api/auto-supply-plans/{$plan->id}/lines/{$line->id}", [
            'qty_rounded' => 8,
            'reason' => 'Увеличиваем запас перед сезоном',
            'comment' => 'Согласовано менеджером',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.new_qty', 8)
            ->assertJsonPath('data.plan_total_qty', 8)
            ->assertJsonPath('data.plan_total_supply_cost', 80)
            ->assertJsonPath('data.budget_overrun', 20);

        $this->assertDatabaseHas('auto_supply_plan_lines', [
            'id' => $line->id,
            'qty_rounded' => 8,
            'supply_cost_estimate' => 80,
            'expected_revenue' => 160,
            'expected_profit' => 64,
            'is_excluded' => false,
        ]);
        $this->assertDatabaseHas('auto_supply_plans', [
            'id' => $plan->id,
            'total_qty' => 8,
            'business_status' => AutoSupplyPlan::BUSINESS_STATUS_REVIEW_REQUIRED,
            'approval_fingerprint' => null,
        ]);
        $this->assertSame(20.0, (float) $plan->fresh()->result_json['economics_summary']['budget_overrun']);
        $this->assertDatabaseHas('auto_supply_plan_adjustments', [
            'auto_supply_plan_id' => $plan->id,
            'auto_supply_plan_line_id' => $line->id,
            'action' => 'quantity_change',
        ]);
    }

    public function test_compare_returns_sku_cluster_deltas(): void
    {
        $previous = $this->makePlan(9304);
        $this->makeLine($previous, [
            'sku' => 'SAME',
            'qty_rounded' => 5,
            'supply_cost_estimate' => 50,
        ]);

        $current = AutoSupplyPlan::create([
            'integration_id' => $previous->integration_id,
            'mp_account_id' => $previous->integration_id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'business_status' => AutoSupplyPlan::BUSINESS_STATUS_REVIEW_REQUIRED,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => [],
            'total_lines' => 2,
            'total_qty' => 11,
        ]);
        $this->makeLine($current, [
            'sku' => 'SAME',
            'qty_rounded' => 8,
            'supply_cost_estimate' => 80,
        ]);
        $this->makeLine($current, [
            'sku' => 'ADDED',
            'qty_rounded' => 3,
            'supply_cost_estimate' => 30,
        ]);

        $response = $this->getJson(
            "/api/auto-supply-plans/{$current->id}/compare?with_plan_id={$previous->id}&changed_only=1"
        );

        $response->assertOk()
            ->assertJsonPath('data.summary.changed_rows', 2)
            ->assertJsonPath('data.summary.added_rows', 1)
            ->assertJsonPath('data.summary.increased_rows', 1)
            ->assertJsonPath('data.summary.previous_total_qty', 5)
            ->assertJsonPath('data.summary.current_total_qty', 11)
            ->assertJsonPath('data.summary.delta_qty', 6)
            ->assertJsonPath('data.summary.delta_supply_cost', 60);
    }

    public function test_destination_change_is_audited_and_requires_revalidation(): void
    {
        $plan = $this->makePlan(9305, ['params' => ['cluster_ids' => [101]]]);
        OzonWarehouseCluster::query()->create([
            'warehouse_name' => 'КАЗАНЬ_ТЕСТ_РФЦ',
            'warehouse_name_normalized' => 'КАЗАНЬ_ТЕСТ_РФЦ',
            'cluster_id' => 202,
            'cluster_name' => 'Казань',
            'region' => 'Казань',
        ]);
        $line = $this->makeLine($plan, [
            'sku' => 'MOVE',
            'cluster_id' => '101',
            'cluster_name' => 'Москва',
            'destination' => 'Москва',
            'destination_id' => 'cluster:101',
            'warehouse_id' => 'cluster:101',
            'warehouse_name' => 'Москва',
            'qty_rounded' => 5,
        ]);

        $response = $this->patchJson("/api/auto-supply-plans/{$plan->id}/lines/{$line->id}", [
            'cluster_id' => 202,
            'cluster_name' => 'Казань',
            'reason' => 'Переносим в кластер с дефицитом',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.old_cluster_id', '101')
            ->assertJsonPath('data.new_cluster_id', '202')
            ->assertJsonPath('data.line.cluster_id', 202);

        $this->assertDatabaseHas('auto_supply_plan_lines', [
            'id' => $line->id,
            'cluster_id' => '202',
            'cluster_name' => 'Казань',
            'destination_id' => 'cluster:202',
        ]);
        $this->assertContains(202, $plan->fresh()->params['cluster_ids']);
        $this->assertNotContains(101, $plan->fresh()->params['cluster_ids']);
        $this->assertDatabaseHas('auto_supply_plan_adjustments', [
            'auto_supply_plan_id' => $plan->id,
            'action' => 'destination_change',
        ]);
    }

    public function test_destination_change_rejects_unknown_ozon_cluster(): void
    {
        $plan = $this->makePlan(9306, ['params' => ['cluster_ids' => [101]]]);
        $line = $this->makeLine($plan, [
            'sku' => 'MOVE-UNKNOWN',
            'cluster_id' => '101',
            'qty_rounded' => 5,
        ]);

        $this->patchJson("/api/auto-supply-plans/{$plan->id}/lines/{$line->id}", [
            'cluster_id' => 999999,
            'reason' => 'Проверяем защиту от неизвестного назначения',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Кластер назначения отсутствует в актуальной карте складов Ozon');
    }

    private function makePlan(int $integrationId, array $overrides = []): AutoSupplyPlan
    {
        $integration = Integration::factory()->ozon()->create(['id' => $integrationId]);

        return AutoSupplyPlan::create(array_merge([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'business_status' => AutoSupplyPlan::BUSINESS_STATUS_REVIEW_REQUIRED,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => [],
            'total_lines' => 1,
            'total_qty' => 0,
        ], $overrides));
    }

    private function makeLine(AutoSupplyPlan $plan, array $overrides = []): AutoSupplyPlanLine
    {
        return AutoSupplyPlanLine::create(array_merge([
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU',
            'offer_id' => 'OFFER',
            'product_name' => 'Товар',
            'warehouse_id' => 'cluster:101',
            'warehouse_name' => 'Москва',
            'cluster_id' => '101',
            'cluster_name' => 'Москва',
            'destination' => 'Москва',
            'destination_id' => 'cluster:101',
            'destination_type' => 'cluster',
            'qty_recommended' => 5,
            'qty_rounded' => 5,
            'current_stock' => 0,
            'in_transit' => 0,
            'demand_daily' => 1,
            'risk_level' => 'low',
            'priority' => 'low',
            'is_excluded' => false,
            'explain_json' => [
                'inputs' => ['daily_demand' => 1, 'target_cover_days' => 14],
                'math' => [],
                'confidence' => ['confidence_level' => 'good', 'confidence_reasons' => []],
            ],
        ], $overrides));
    }
}
