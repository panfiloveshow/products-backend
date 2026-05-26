<?php

namespace Tests\Feature;

use App\Models\AutoSupplyPlan;
use App\Models\AutoSupplyPlanLine;
use App\Models\Integration;
use App\Models\Product;
use App\Domains\Locality\Recommendation\LocalityDraftApplier;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use Mockery;
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

    public function test_ozon_show_keeps_same_sku_separate_by_cluster(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9010]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => [],
            'total_lines' => 2,
            'total_qty' => 15,
        ]);

        $base = [
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU-CLUSTERED',
            'offer_id' => 'OFF-CLUSTERED',
            'product_name' => 'Clustered product',
            'destination_type' => 'cluster',
            'qty_recommended' => 1,
            'current_stock' => 0,
            'in_transit' => 0,
            'risk_level' => 'high',
            'priority' => 'high',
        ];

        AutoSupplyPlanLine::create(array_merge($base, [
            'warehouse_id' => 'cluster:1',
            'warehouse_name' => 'Москва',
            'cluster_id' => 1,
            'cluster_name' => 'Москва',
            'destination' => 'Москва',
            'destination_id' => 'cluster:1',
            'qty_rounded' => 10,
        ]));
        AutoSupplyPlanLine::create(array_merge($base, [
            'warehouse_id' => 'cluster:2',
            'warehouse_name' => 'Санкт-Петербург',
            'cluster_id' => 2,
            'cluster_name' => 'Санкт-Петербург',
            'destination' => 'Санкт-Петербург',
            'destination_id' => 'cluster:2',
            'qty_rounded' => 5,
        ]));

        $response = $this->getJson("/api/auto-supply-plans/{$plan->id}?per_page=50");

        $response->assertOk()
            ->assertJsonPath('data.lines.total', 2)
            ->assertJsonPath('data.lines.data.0.destination_type', 'cluster')
            ->assertJsonPath('data.lines.data.1.destination_type', 'cluster');

        $clusters = collect($response->json('data.lines.data'))->pluck('cluster_id')->sort()->values()->all();
        $this->assertSame([1, 2], $clusters);
    }

    public function test_selected_ozon_cluster_scopes_show_lines_and_cluster_endpoints(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9014]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => ['cluster_ids' => [154]],
            'total_lines' => 2,
            'total_qty' => 15,
        ]);

        $base = [
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU-SCOPED',
            'offer_id' => 'OFF-SCOPED',
            'product_name' => 'Scoped product',
            'destination_type' => 'cluster',
            'qty_recommended' => 1,
            'current_stock' => 0,
            'in_transit' => 0,
            'risk_level' => 'high',
            'priority' => 'high',
            'expected_profit' => 10,
        ];

        foreach ([154 => 10, 155 => 5] as $clusterId => $qty) {
            AutoSupplyPlanLine::create(array_merge($base, [
                'warehouse_id' => 'cluster:' . $clusterId,
                'warehouse_name' => 'Cluster ' . $clusterId,
                'cluster_id' => $clusterId,
                'cluster_name' => 'Cluster ' . $clusterId,
                'destination' => 'Cluster ' . $clusterId,
                'destination_id' => 'cluster:' . $clusterId,
                'qty_rounded' => $qty,
            ]));
        }

        $this->getJson("/api/auto-supply-plans/{$plan->id}?per_page=50")
            ->assertOk()
            ->assertJsonPath('data.summary.total_lines', 1)
            ->assertJsonPath('data.summary.total_qty', 10)
            ->assertJsonPath('data.lines.total', 1)
            ->assertJsonPath('data.lines.data.0.cluster_id', 154);

        $this->getJson("/api/auto-supply-plans/{$plan->id}/lines?per_page=50")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.cluster_id', 154);

        $this->getJson("/api/auto-supply-plans/{$plan->id}/clusters")
            ->assertOk()
            ->assertJsonPath('data.total_clusters', 1)
            ->assertJsonPath('data.clusters.0.cluster_id', 154);

        $this->getJson("/api/auto-supply-plans/{$plan->id}/cluster-split")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.cluster_id', '154');
    }

    public function test_ozon_show_normalizes_old_cluster_rows_without_destination_type(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9011]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => [],
            'total_lines' => 1,
            'total_qty' => 7,
        ]);

        AutoSupplyPlanLine::create([
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU-OLD',
            'offer_id' => 'OFF-OLD',
            'product_name' => 'Old product',
            'warehouse_id' => 'spb-wh',
            'warehouse_name' => 'Склад СПБ',
            'cluster_id' => 55,
            'cluster_name' => 'Санкт-Петербург',
            'destination_type' => 'all',
            'qty_recommended' => 7,
            'qty_rounded' => 7,
            'risk_level' => 'low',
            'priority' => 'low',
        ]);

        $response = $this->getJson("/api/auto-supply-plans/{$plan->id}");

        $response->assertOk()
            ->assertJsonPath('data.lines.data.0.cluster_id', 55)
            ->assertJsonPath('data.lines.data.0.cluster_name', 'Санкт-Петербург')
            ->assertJsonPath('data.lines.data.0.warehouse_name', 'Санкт-Петербург')
            ->assertJsonPath('data.lines.data.0.destination', 'Санкт-Петербург')
            ->assertJsonPath('data.lines.data.0.destination_id', 'cluster:55')
            ->assertJsonPath('data.lines.data.0.destination_type', 'cluster');
    }

    public function test_wb_show_still_aggregates_same_sku_into_single_line(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->create(['id' => 9012, 'marketplace' => 'wildberries']);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'wildberries',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => [],
            'total_lines' => 2,
            'total_qty' => 12,
        ]);

        foreach ([1 => 5, 2 => 7] as $warehouse => $qty) {
            AutoSupplyPlanLine::create([
                'auto_supply_plan_id' => $plan->id,
                'sku' => 'SKU-WB',
                'offer_id' => 'OFF-WB',
                'product_name' => 'WB product',
                'warehouse_id' => 'wb-' . $warehouse,
                'warehouse_name' => 'WB ' . $warehouse,
                'destination_type' => 'warehouse',
                'qty_recommended' => $qty,
                'qty_rounded' => $qty,
                'risk_level' => 'low',
                'priority' => 'low',
            ]);
        }

        $response = $this->getJson("/api/auto-supply-plans/{$plan->id}");

        $response->assertOk()
            ->assertJsonPath('data.lines.total', 1)
            ->assertJsonPath('data.lines.data.0.qty_rounded', 12);
    }

    public function test_create_cluster_drafts_respects_selected_cluster_ids(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9013]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => ['cluster_ids' => [1]],
            'total_lines' => 2,
            'total_qty' => 15,
        ]);

        Product::factory()->ozon()->create([
            'integration_id' => $integration->id,
            'sku' => 'SKU-DRAFT-1',
            'ozon_data' => ['sku' => 111],
        ]);
        Product::factory()->ozon()->create([
            'integration_id' => $integration->id,
            'sku' => 'SKU-DRAFT-2',
            'ozon_data' => ['sku' => 222],
        ]);

        foreach ([1 => 'SKU-DRAFT-1', 2 => 'SKU-DRAFT-2'] as $clusterId => $sku) {
            AutoSupplyPlanLine::create([
                'auto_supply_plan_id' => $plan->id,
                'sku' => $sku,
                'offer_id' => $sku,
                'product_name' => $sku,
                'warehouse_id' => 'cluster:' . $clusterId,
                'warehouse_name' => 'Cluster ' . $clusterId,
                'cluster_id' => $clusterId,
                'cluster_name' => 'Cluster ' . $clusterId,
                'destination_type' => 'cluster',
                'qty_recommended' => 5,
                'qty_rounded' => 5,
                'risk_level' => 'low',
                'priority' => 'low',
            ]);
        }

        $applier = Mockery::mock(LocalityDraftApplier::class);
        $applier->shouldReceive('applyBatch')
            ->once()
            ->withArgs(function (Integration $passedIntegration, array $items, int $clusterId) use ($integration) {
                return $passedIntegration->id === $integration->id
                    && $clusterId === 1
                    && $items === [['sku' => 111, 'quantity' => 5]];
            })
            ->andReturn(['success' => true, 'draft_id' => 'draft-1', 'error' => null]);
        $this->app->instance(LocalityDraftApplier::class, $applier);

        $response = $this->postJson("/api/auto-supply-plans/{$plan->id}/create-cluster-drafts");

        $response->assertOk()
            ->assertJsonPath('data.drafts.0.cluster_id', '1')
            ->assertJsonCount(1, 'data.drafts')
            ->assertJsonCount(0, 'data.errors');
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
