<?php

namespace Tests\Feature;

use App\Models\AutoSupplyPlan;
use App\Models\AutoSupplyPlanLine;
use App\Models\Integration;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class AutoSupplyPlanShowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_show_returns_ok_with_grouped_line_pagination(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create([
            'id' => 9001,
        ]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => [],
            'total_lines' => 2,
            'total_qty' => 10,
        ]);

        $base = [
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU-1',
            'offer_id' => 'OFF-1',
            'product_name' => 'Product',
            'warehouse_id' => 'w1',
            'warehouse_name' => 'WH1',
            'destination' => null,
            'destination_id' => null,
            'destination_type' => 'all',
            'cluster_name' => null,
            'region' => null,
            'qty_recommended' => 5,
            'qty_rounded' => 5,
            'risk_level' => 'high',
            'priority' => 'high',
        ];

        AutoSupplyPlanLine::create($base);
        AutoSupplyPlanLine::create(array_merge($base, [
            'warehouse_id' => 'w2',
            'warehouse_name' => 'WH2',
            'qty_rounded' => 5,
            'risk_level' => 'low',
        ]));

        $response = $this->getJson("/api/auto-supply-plans/{$plan->id}?page=1&per_page=50");

        $response->assertOk()
            ->assertJsonPath('message', 'OK')
            ->assertJsonPath('data.plan.id', $plan->id);
    }

    public function test_lines_endpoint_aggregates_with_optional_filters(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9002]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => [],
            'total_lines' => 1,
            'total_qty' => 3,
        ]);

        AutoSupplyPlanLine::create([
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU-F',
            'offer_id' => 'OFF-F',
            'product_name' => 'Filtered',
            'warehouse_id' => 'w1',
            'warehouse_name' => 'WH1',
            'destination' => null,
            'destination_id' => null,
            'destination_type' => 'all',
            'cluster_name' => null,
            'region' => null,
            'qty_recommended' => 3,
            'qty_rounded' => 3,
            'risk_level' => 'high',
            'priority' => 'high',
        ]);

        $response = $this->getJson("/api/auto-supply-plans/{$plan->id}/lines?risk_level=high&per_page=10");

        $response->assertOk()
            ->assertJsonPath('message', 'OK')
            ->assertJsonPath('data.total', 1);
    }

    public function test_show_summary_financials_match_line_aggregates(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9003]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => [],
            'total_lines' => 1,
            'total_qty' => 2,
        ]);

        AutoSupplyPlanLine::create([
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU-X',
            'offer_id' => 'OFF-X',
            'product_name' => 'X',
            'warehouse_id' => 'w1',
            'warehouse_name' => 'WH1',
            'destination' => null,
            'destination_id' => null,
            'destination_type' => 'all',
            'cluster_name' => null,
            'region' => null,
            'qty_recommended' => 2,
            'qty_rounded' => 2,
            'risk_level' => 'med',
            'priority' => 'medium',
            'sales_trend' => 'growing',
            'supply_cost_estimate' => 10.5,
            'expected_revenue' => 100,
            'expected_profit' => 20,
            'lost_revenue_daily' => 1.25,
            'roi_percent' => 15.5,
            'turnover_days' => 12.3,
        ]);

        $response = $this->getJson("/api/auto-supply-plans/{$plan->id}");

        $response->assertOk()
            ->assertJsonPath('data.summary.financials.total_supply_cost', 10.5)
            ->assertJsonPath('data.summary.financials.total_expected_revenue', 100)
            ->assertJsonPath('data.summary.financials.total_expected_profit', 20)
            ->assertJsonPath('data.summary.financials.total_lost_revenue_daily', 1.25)
            ->assertJsonPath('data.summary.financials.avg_roi_percent', 15.5)
            ->assertJsonPath('data.summary.financials.avg_turnover_days', 12.3)
            ->assertJsonPath('data.summary.risk_breakdown.med', 1)
            ->assertJsonPath('data.summary.priority_breakdown.medium', 1)
            ->assertJsonPath('data.summary.trend_breakdown.growing', 1);
    }

    public function test_show_forbidden_when_workspace_does_not_match_integration(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create([
            'id' => 9004,
            'work_space_id' => 100,
        ]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => [],
            'total_lines' => 0,
            'total_qty' => 0,
        ]);

        $response = $this->getJson("/api/auto-supply-plans/{$plan->id}", [
            'X-Sellico-Workspace' => '999',
        ]);

        $response->assertForbidden();
    }

    public function test_non_uuid_plan_id_returns_not_found(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $this->getJson('/api/auto-supply-plans/not-a-uuid')->assertNotFound();
    }
}
