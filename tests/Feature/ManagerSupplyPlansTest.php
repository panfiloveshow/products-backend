<?php

namespace Tests\Feature;

use App\Models\AutoSupplyPlan;
use App\Models\AutoSupplyPlanLine;
use App\Models\Integration;
use App\Services\SellicoApiService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ManagerSupplyPlansTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sellico.base_url' => 'https://crm.test/api', 'services.sellico.skip_permission_check' => true]);
        Http::preventStrayRequests(); Queue::fake();
        $this->mock(SellicoApiService::class, fn ($m) => $m->shouldReceive('getServiceToken')->andReturn('service-test-only'));
        $this->withToken('human-test-only')->withHeader('X-Sellico-Workspace', '8');
    }

    private function remote(string $mp = 'ozon', ?array $permissions = null, int $workspace = 8): void
    {
        Http::fake(['crm.test/api/user' => Http::response(['id' => 42, 'is_service_account' => false]),
            'crm.test/api/workspaces/8/user-permissions' => Http::response(['user_id' => 42, 'permissions' => $permissions ?? ['auto_supply.view', 'integrations.view']]),
            'crm.test/api/get-integrations/8' => Http::response([['id' => 62201, 'work_space_id' => $workspace, 'type' => $mp,
                'status' => 'active', 'name' => 'Test', 'api_key' => 'vendor-key-must-not-escape', 'client_id' => '555']])]);
    }

    private function plan(string $mp = 'ozon', int $workspace = 8): AutoSupplyPlan
    {
        $integration = Integration::factory()->{$mp}()->create(['id' => 62201, 'work_space_id' => $workspace]);

        return AutoSupplyPlan::create(['integration_id' => $integration->id, 'marketplace' => $mp, 'status' => 'ready', 'business_status' => 'review_required',
            'mode' => 'balanced', 'horizon_days' => 28, 'target_cover_days' => 21, 'safety_stock_days' => 5, 'algorithm_version' => 'test-only',
            'params' => ['lead_time_days' => 9, 'include_in_transit' => true, ...($mp === 'ozon' ? ['cluster_ids' => [10]] : [])],
            'effective_params_json' => ['analysis_period_days' => 28, 'target_cover_days' => 14, 'safety_stock_days' => 3, 'lead_time_days' => 4, 'include_in_transit' => false],
            'error_message' => 'private-error-must-not-escape', 'result_json' => ['credential' => 'private-data-must-not-escape']]);
    }

    private function line(AutoSupplyPlan $plan, string $sku = 'SKU-A', int $cluster = 10): AutoSupplyPlanLine
    {
        return AutoSupplyPlanLine::create(['auto_supply_plan_id' => $plan->id, 'sku' => $sku, 'offer_id' => $sku, 'product_name' => 'Test product',
            'warehouse_id' => '100', 'warehouse_name' => 'Test warehouse', 'cluster_id' => $cluster, 'cluster_name' => 'Test cluster',
            'destination_id' => '100', 'destination_type' => 'warehouse', 'qty_recommended' => 12.5, 'qty_rounded' => 12,
            'current_stock' => 0, 'in_transit' => 6, 'demand_daily' => 1.2345, 'is_excluded' => false,
            'manual_comment' => 'private-comment-must-not-escape', 'cost_price' => 600, 'expected_profit' => 200,
            'explain_json' => ['private_key' => 'private-explanation-must-not-escape', 'confidence' => ['confidence_level' => 'warning', 'confidence_reasons' => ['low_posting_volume'],
                'sources' => ['stock' => 'analytics_stocks']], 'inputs' => ['daily_demand' => 1.2345, 'target_cover_days' => 21, 'demand_source' => 'posting_fbo_v3'],
                'math' => ['needed_before_caps' => 20, 'needed_after_caps' => 12, 'caps_applied' => ['budget_limit']]]]);
    }

    public function test_lists_only_saved_plans_in_the_selected_store_with_explicit_pagination(): void
    {
        $plan = $this->plan(); $this->remote();
        AutoSupplyPlan::create(['integration_id' => $plan->integration_id, 'marketplace' => 'ozon', 'status' => 'calculating', 'mode' => 'balanced']);
        $foreign = Integration::factory()->ozon()->create(['id' => 62202, 'work_space_id' => 9]);
        AutoSupplyPlan::create(['integration_id' => $foreign->id, 'marketplace' => 'ozon', 'status' => 'ready', 'mode' => 'balanced']);
        $response = $this->getJson('/api/manager/supply-plans?integration_id=62201&limit=1')->assertOk()
            ->assertJsonPath('workspace_id', '8')->assertJsonPath('total', 2)->assertJsonPath('has_more', true)
            ->assertJsonPath('next_page', 2)->assertJsonCount(1, 'items')->assertJsonPath('execution_allowed', false);
        $this->assertStringNotContainsString('must-not-escape', $response->getContent());
        Http::assertSentCount(3); Queue::assertNothingPushed();
    }

    public function test_reads_the_existing_ozon_owner_with_selected_clusters_without_writes_or_financial_leaks(): void
    {
        $plan = $this->plan(); $this->line($plan); $this->line($plan, 'NOT-SELECTED', 20); $this->remote();
        $mutations = [];
        DB::listen(function ($query) use (&$mutations) { if (preg_match('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $query->sql)) $mutations[] = $query->sql; });
        $response = $this->getJson('/api/manager/supply-plans/detail?integration_id=62201&plan_id='.$plan->id)->assertOk()
            ->assertJsonPath('plan.id', $plan->id)->assertJsonPath('plan.lead_time_days', 4)
            ->assertJsonPath('plan.target_cover_days', 14)->assertJsonPath('plan.include_in_transit', false)
            ->assertJsonPath('plan.configured_parameters.lead_time_days', 9)
            ->assertJsonPath('plan.configured_parameters.target_cover_days', 21)
            ->assertJsonPath('plan.calculated_at', null)->assertJsonPath('data_updated_at', null)
            ->assertJsonPath('plan.review_path', '/products/shipments/auto-plans/'.$plan->id)
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.current_stock', 0)->assertJsonPath('items.0.in_transit', 6)
            ->assertJsonPath('items.0.daily_demand', 1.2345)->assertJsonPath('items.0.destination_id', 'cluster:10')
            ->assertJsonPath('partial', true)->assertJsonPath('execution_allowed', false);
        $this->assertArrayNotHasKey('cost_price', $response->json('items.0'));
        $this->assertArrayNotHasKey('expected_profit', $response->json('items.0'));
        $this->assertStringNotContainsString('must-not-escape', $response->getContent());
        $this->assertStringNotContainsString('NOT-SELECTED', $response->getContent());
        $this->assertSame([], $mutations); Queue::assertNothingPushed();
        Http::assertNotSent(fn ($r) => ! str_starts_with($r->url(), 'https://crm.test/'));
    }

    public function test_reads_wb_rows_and_keeps_missing_lead_time_unknown(): void
    {
        $plan = $this->plan('wildberries'); $plan->update(['params' => [], 'effective_params_json' => []]); $this->line($plan); $this->remote('wildberries');
        $this->getJson('/api/manager/supply-plans/detail?integration_id=62201&plan_id='.$plan->id)->assertOk()
            ->assertJsonPath('marketplace', 'wildberries')->assertJsonPath('plan.lead_time_days', null)
            ->assertJsonPath('plan.include_in_transit', null)->assertJsonPath('items.0.qty_rounded', 12);
        Queue::assertNothingPushed();
    }

    public function test_detail_pages_do_not_claim_to_contain_all_plan_lines(): void
    {
        $plan = $this->plan(); $this->line($plan); $this->line($plan, 'SKU-B'); $this->remote();
        $this->getJson('/api/manager/supply-plans/detail?integration_id=62201&plan_id='.$plan->id.'&limit=1&page=2')->assertOk()
            ->assertJsonPath('page', 2)->assertJsonPath('total', 2)->assertJsonPath('has_more', false)
            ->assertJsonPath('next_page', null)->assertJsonCount(1, 'items')->assertJsonPath('partial', true);
    }

    public function test_foreign_workspace_is_denied_before_service_credentials_are_resolved(): void
    {
        $this->plan(workspace: 9); $this->remote();
        $this->getJson('/api/manager/supply-plans?integration_id=62201')->assertNotFound();
        Http::assertSentCount(2);
    }

    public function test_foreign_plan_cannot_be_selected_by_a_uuid_in_an_owned_store(): void
    {
        $this->plan(); $this->remote();
        $foreign = Integration::factory()->ozon()->create(['id' => 62202, 'work_space_id' => 9]);
        $plan = AutoSupplyPlan::create(['integration_id' => $foreign->id, 'marketplace' => 'ozon', 'status' => 'ready', 'mode' => 'balanced']);
        $this->getJson('/api/manager/supply-plans/detail?integration_id=62201&plan_id='.$plan->id)->assertNotFound();
        Http::assertSentCount(2);
    }

    public function test_revoked_permission_cannot_use_cached_plans(): void
    {
        $this->plan(); $this->remote(permissions: ['integrations.view']);
        $this->getJson('/api/manager/supply-plans?integration_id=62201')->assertForbidden();
        Http::assertSentCount(2);
    }

    public function test_changed_crm_binding_cannot_use_cached_plans(): void
    {
        $this->plan(); $this->remote(workspace: 9);
        $this->getJson('/api/manager/supply-plans?integration_id=62201')->assertStatus(503);
    }

    public function test_unrecognized_action_parameters_and_invalid_ids_cannot_trigger_planning(): void
    {
        $this->plan(); $this->remote();
        foreach (['&execute=1', '&limit=51', '&page=0'] as $suffix) {
            $this->getJson('/api/manager/supply-plans?integration_id=62201'.$suffix)->assertUnprocessable();
        }
        $this->getJson('/api/manager/supply-plans/detail?integration_id=62201&plan_id=123')->assertUnprocessable();
        Queue::assertNothingPushed();
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'get-integrations'));
    }
}
