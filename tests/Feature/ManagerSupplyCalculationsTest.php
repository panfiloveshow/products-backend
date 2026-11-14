<?php

namespace Tests\Feature;

use App\Jobs\CalculateAutoSupplyPlanJob;
use App\Jobs\RunManagerSupplyCalculation;
use App\Models\AutoSupplyPlan;
use App\Models\Integration;
use App\Models\ManagerSupplyCalculation;
use App\Models\PlanningFactSnapshot;
use App\Services\AutoSupplyPlanService;
use App\Services\AutoSupplyPlanning\OzonPlanMaterializationService;
use App\Services\LimitsSyncService;
use App\Services\Manager\ManagerSupplyCalculationService;
use App\Services\SellicoApiService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ManagerSupplyCalculationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private int $userId = 42;
    private array $permissions = ['auto_supply.view', 'integrations.view', 'auto_supply.create'];
    private string $key = 'vendor-test-only';
    private bool $quota = true;
    private string $marketplace = 'ozon';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sellico.base_url' => 'https://crm.test/api', 'services.sellico.skip_permission_check' => true,
            'services.manager.supply_calculations_enabled' => true, 'services.ozon.access_token' => null]);
        Queue::fake(); Http::preventStrayRequests();
        $this->mock(SellicoApiService::class, fn ($m) => $m->shouldReceive('getServiceToken')->andReturn('service-test-only'));
        $this->mock(LimitsSyncService::class, function ($m) {
            $m->shouldReceive('ensureLimitAvailable')->andReturnUsing(fn () => ['success' => $this->quota, 'status' => $this->quota ? 200 : 403]);
            $m->shouldReceive('syncWorkspaceAutoplanningLimit')->andReturn(['success' => true]);
            $m->shouldReceive('limitResponsePayload')->andReturn(['message' => 'test quota limit']);
        });
        Http::fake(function ($request) {
            return match ($request->url()) {
                'https://crm.test/api/user' => Http::response(['id' => $this->userId, 'is_service_account' => false]),
                'https://crm.test/api/workspaces/8/user-permissions' => Http::response(['user_id' => $this->userId, 'permissions' => $this->permissions]),
                'https://crm.test/api/get-integrations/8' => Http::response([['id' => 62251, 'work_space_id' => 8, 'type' => $this->marketplace,
                    'status' => 'active', 'name' => 'Test', 'api_key' => $this->key, 'client_id' => '555']]),
                default => throw new \RuntimeException('Unexpected test HTTP destination'),
            };
        });
        $this->withToken('human-test-only')->withHeader('X-Sellico-Workspace', '8')->withHeader('X-Workspace-Id', '8');
        Integration::factory()->ozon()->create(['id' => 62251, 'work_space_id' => 8, 'credentials' => ['api_key' => $this->key, 'client_id' => '555']]);
    }

    private function parameters(array $overrides = []): array
    {
        return array_replace(['mode' => 'balanced', 'analysis_period_days' => 28, 'horizon_days' => 30,
            'min_cover_days' => 7, 'target_cover_days' => 21, 'max_cover_days' => 30, 'safety_stock_days' => 0, 'lead_time_days' => 4,
            'turnover_limit_days' => null, 'budget_limit' => '5000', 'include_in_transit' => true, 'skip_negative_profit' => true,
            'destination_ids' => ['cluster:101']], $overrides);
    }

    private function prepare(): array
    {
        return $this->postJson('/api/manager/supply-calculations/prepare', ['integration_id' => 62251, 'parameters' => $this->parameters()])
            ->assertOk()->assertJsonPath('status', 'awaiting_confirmation')->json();
    }

    private function approve(array $op): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/manager/supply-calculations/'.$op['id'].'/approve', ['fingerprint' => $op['fingerprint']]);
    }

    public function test_preparation_creates_only_a_personal_recipe_and_no_plan_or_job(): void
    {
        $op = $this->prepare();
        $this->assertSame('5000.00', $op['parameters']['budget_limit']);
        $this->assertSame(0, $op['parameters']['safety_stock_days']);
        $this->assertNull($op['result']); $this->assertFalse($op['shipment_execution_allowed']);
        $this->assertStringNotContainsString($this->key, json_encode($op));
        $this->assertArrayNotHasKey('credential_binding', $op);
        $this->assertDatabaseCount('manager_supply_calculations', 1); $this->assertDatabaseCount('auto_supply_plans', 0);
        Queue::assertNothingPushed();
    }

    public function test_repeated_approval_keeps_one_plan_and_does_not_claim_the_calculation_is_complete(): void
    {
        $op = $this->prepare();
        $response = $this->approve($op)->assertOk()->assertJsonPath('status', 'verifying')
            ->assertJsonPath('result.calculation_status', 'pending')->assertJsonPath('result.calculation_verified', false)
            ->assertJsonPath('result.queued', true)->assertJsonPath('shipment_execution_allowed', false);
        $this->approve($op)->assertOk()->assertJsonPath('result.plan_id', $response->json('result.plan_id'));
        $this->assertDatabaseCount('auto_supply_plans', 1);
        $plan = AutoSupplyPlan::firstOrFail();
        $this->assertSame($op['id'], $plan->params['manager_calculation_id']);
        $this->assertSame(4, $plan->params['lead_time_days']); $this->assertSame(0, $plan->params['safety_days']);
        Queue::assertPushed(RunManagerSupplyCalculation::class, 1); Queue::assertNotPushed(CalculateAutoSupplyPlanJob::class);
    }

    public function test_lost_queue_receipt_recovers_the_same_request_and_queue_copies_run_it_only_once(): void
    {
        $op = $this->prepare(); $original = app(Dispatcher::class);
        $mock = \Mockery::mock(Dispatcher::class);
        $mock->shouldReceive('dispatch')->once()->andReturnUsing(function ($job) use ($original) {
            $original->dispatch($job);
            throw new \RuntimeException('Simulated receipt loss after push');
        });
        app()->instance(Dispatcher::class, $mock);
        try {
            $this->approve($op)->assertOk()->assertJsonPath('status', 'verifying')->assertJsonPath('result.queued', false);
        } finally { app()->instance(Dispatcher::class, $original); }
        $this->assertDatabaseCount('auto_supply_plans', 1); Queue::assertPushed(RunManagerSupplyCalculation::class, 1);
        $this->travel(31)->seconds();
        $this->artisan('manager:supply-calculations:dispatch')->assertSuccessful();
        Queue::assertPushed(RunManagerSupplyCalculation::class, 2);
        $jobs = Queue::pushed(RunManagerSupplyCalculation::class)->values();
        $jobs[0]->handle(app(ManagerSupplyCalculationService::class), app(AutoSupplyPlanService::class));
        $count = Http::recorded()->count();
        $jobs[1]->handle(app(ManagerSupplyCalculationService::class), app(AutoSupplyPlanService::class));
        $this->assertSame($count, Http::recorded()->count());
        $this->getJson('/api/manager/supply-calculations/'.$op['id'])->assertOk()->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('result.calculation_verified', true)->assertJsonPath('shipment_execution_allowed', false);
        $this->assertDatabaseCount('auto_supply_plans', 1); $this->assertDatabaseCount('planning_fact_snapshots', 1);
        $this->assertNotContains('manager_calculation_unconfirmed', array_column(app(OzonPlanMaterializationService::class)->preflightCheck(AutoSupplyPlan::firstOrFail())['errors'], 'code'));
        $events = ManagerSupplyCalculation::findOrFail($op['id'])->events;
        $this->assertCount(1, array_filter($events, fn ($event) => $event['event'] === 'worker_claimed'));
    }

    public function test_a_missing_or_completed_unclaimed_plan_cannot_be_assumed_to_be_this_workers_result(): void
    {
        $op = $this->prepare(); $response = $this->approve($op)->assertOk();
        AutoSupplyPlan::whereKey($response->json('result.plan_id'))->update(['status' => 'ready']);
        $this->getJson('/api/manager/supply-calculations/'.$op['id'])->assertOk()->assertJsonPath('status', 'verifying')
            ->assertJsonPath('result.calculation_verified', false);
    }

    public function test_a_running_unknown_calculation_is_not_replayed(): void
    {
        $op = $this->prepare(); $this->approve($op)->assertOk();
        $service = app(ManagerSupplyCalculationService::class);
        $this->assertNotNull($service->begin($op['id']));
        $service->finish($op['id'], true);
        $this->assertNull($service->begin($op['id']));
        $this->getJson('/api/manager/supply-calculations/'.$op['id'])->assertOk()->assertJsonPath('status', 'unknown');
        $this->travel(31)->seconds(); $this->artisan('manager:supply-calculations:dispatch')->assertSuccessful();
        Queue::assertPushed(RunManagerSupplyCalculation::class, 1);
    }

    public function test_a_lost_worker_without_a_failure_callback_exposes_unknown_without_replaying(): void
    {
        $op = $this->prepare(); $this->approve($op)->assertOk();
        $service = app(ManagerSupplyCalculationService::class);
        $this->assertNotNull($service->begin($op['id']));
        $this->travel(60)->seconds();
        $this->getJson('/api/manager/supply-calculations/'.$op['id'])->assertOk()->assertJsonPath('status', 'verifying');
        $this->travel(271)->seconds();
        $this->getJson('/api/manager/supply-calculations/'.$op['id'])->assertOk()->assertJsonPath('status', 'unknown')
            ->assertJsonPath('result.calculation_verified', false);
        $this->assertNull($service->begin($op['id']));
        $this->artisan('manager:supply-calculations:dispatch')->assertSuccessful();
        Queue::assertPushed(RunManagerSupplyCalculation::class, 1);
    }

    public function test_changed_parameters_cannot_be_attested_as_the_approved_calculation_result(): void
    {
        $op = $this->prepare(); $this->approve($op)->assertOk();
        $service = app(ManagerSupplyCalculationService::class);
        $planId = $service->begin($op['id']); $this->assertNotNull($planId);
        (new CalculateAutoSupplyPlanJob($planId))->handle(app(AutoSupplyPlanService::class));
        $plan = AutoSupplyPlan::findOrFail($planId);
        $params = $plan->params; $params['lead_time_days'] = 5; $plan->update(['params' => $params]);
        $service->finish($op['id']);
        $this->getJson('/api/manager/supply-calculations/'.$op['id'])->assertOk()->assertJsonPath('status', 'unknown')
            ->assertJsonPath('message_code', 'result_recipe_changed')->assertJsonPath('result.calculation_verified', false);
        $this->assertNull($service->begin($op['id']));
        $this->assertDatabaseCount('planning_fact_snapshots', 1);
    }

    public function test_queue_failure_before_claim_requeues_the_same_request_without_creating_another_plan(): void
    {
        $op = $this->prepare(); $this->approve($op)->assertOk();
        (new RunManagerSupplyCalculation($op['id']))->failed(new \RuntimeException('Queue failure before claim'));
        $this->getJson('/api/manager/supply-calculations/'.$op['id'])->assertOk()->assertJsonPath('status', 'verifying')
            ->assertJsonPath('message_code', 'queue_pending')->assertJsonPath('result.queued', false);
        $this->travel(31)->seconds(); $this->artisan('manager:supply-calculations:dispatch')->assertSuccessful();
        Queue::assertPushed(RunManagerSupplyCalculation::class, 2); $this->assertDatabaseCount('auto_supply_plans', 1);
        $this->assertDatabaseCount('planning_fact_snapshots', 0);
    }

    public function test_failure_of_a_different_queue_copy_does_not_interrupt_the_claimed_attempt(): void
    {
        $op = $this->prepare(); $this->approve($op)->assertOk();
        $first = new RunManagerSupplyCalculation($op['id']);
        $other = unserialize(serialize(new RunManagerSupplyCalculation($op['id'])));
        $this->assertNotSame($first->claimId, $other->claimId);
        $service = app(ManagerSupplyCalculationService::class);
        $this->assertNotNull($service->begin($op['id'], $first->claimId));
        $other->failed(new \RuntimeException('A duplicate queue copy failed'));
        $this->assertSame('verifying', ManagerSupplyCalculation::findOrFail($op['id'])->status);
        unserialize(serialize($first))->failed(new \RuntimeException('The claimed attempt was interrupted'));
        $this->assertSame('unknown', ManagerSupplyCalculation::findOrFail($op['id'])->status);
    }

    public function test_cancel_before_start_prevents_a_queued_copy_from_running(): void
    {
        $op = $this->prepare(); $this->approve($op)->assertOk();
        $this->postJson('/api/manager/supply-calculations/'.$op['id'].'/cancel', ['fingerprint' => $op['fingerprint']])->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertNull(app(ManagerSupplyCalculationService::class)->begin($op['id']));
        $this->assertDatabaseCount('planning_fact_snapshots', 0);
    }

    public function test_global_stop_cancels_an_approved_request_before_the_worker_claim(): void
    {
        $op = $this->prepare(); $this->approve($op)->assertOk(); config(['services.manager.supply_calculations_enabled' => false]);
        $count = Http::recorded()->count();
        $this->assertNull(app(ManagerSupplyCalculationService::class)->begin($op['id']));
        $this->assertSame($count, Http::recorded()->count());
        $this->getJson('/api/manager/supply-calculations/'.$op['id'])->assertOk()->assertJsonPath('status', 'cancelled');
        $this->postJson('/api/manager/supply-calculations/prepare', ['integration_id' => 62251, 'parameters' => $this->parameters()])->assertStatus(503);
    }

    public function test_expiry_revoked_permission_and_another_actor_cannot_approve(): void
    {
        $op = $this->prepare(); $this->permissions = ['auto_supply.view', 'integrations.view'];
        $this->approve($op)->assertForbidden();
        $this->permissions[] = 'auto_supply.create'; $this->userId = 99;
        $this->approve($op)->assertNotFound();
        $this->userId = 42; $this->travel(6)->minutes();
        $this->approve($op)->assertOk()->assertJsonPath('status', 'expired');
        $this->assertDatabaseCount('auto_supply_plans', 0); Queue::assertNothingPushed();
    }

    public function test_old_local_credentials_and_mutated_recipes_cannot_create_a_plan(): void
    {
        Integration::findOrFail(62251)->update(['credentials' => ['api_key' => 'old-other-key', 'client_id' => '555']]);
        $this->postJson('/api/manager/supply-calculations/prepare', ['integration_id' => 62251, 'parameters' => $this->parameters()])->assertStatus(409);
        $this->assertDatabaseCount('manager_supply_calculations', 0);
        Integration::findOrFail(62251)->update(['credentials' => ['api_key' => $this->key, 'client_id' => '555']]);
        $op = $this->prepare(); $row = ManagerSupplyCalculation::findOrFail($op['id']);
        $params = $row->parameters; $params['lead_time_days'] = 5; $row->update(['parameters' => $params]);
        $this->approve($op)->assertStatus(409); $this->assertDatabaseCount('auto_supply_plans', 0);
    }

    public function test_owner_quota_rejection_is_not_bypassed(): void
    {
        $op = $this->prepare(); $this->quota = false;
        $this->approve($op)->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('message_code', 'creation_rejected');
        $this->assertDatabaseCount('auto_supply_plans', 0); Queue::assertNothingPushed();
    }

    public function test_wb_uses_the_same_owner_with_explicit_warehouse_selection(): void
    {
        $this->marketplace = 'wildberries';
        Integration::findOrFail(62251)->update(['marketplace' => 'wildberries']);
        $op = $this->postJson('/api/manager/supply-calculations/prepare', ['integration_id' => 62251,
            'parameters' => $this->parameters(['destination_ids' => ['100'], 'budget_limit' => null])])->assertOk()->json();
        $this->approve($op)->assertOk()->assertJsonPath('marketplace', 'wildberries')->assertJsonPath('status', 'verifying');
        $plan = AutoSupplyPlan::firstOrFail();
        $this->assertSame(['100'], $plan->params['warehouse_ids']); $this->assertNull($plan->budget_limit);
        Queue::assertPushed(RunManagerSupplyCalculation::class, 1);
    }

    public function test_a_reconnected_cabinet_cannot_run_an_old_approved_recipe(): void
    {
        $op = $this->prepare(); $this->approve($op)->assertOk();
        $this->key = 'changed-test-only';
        Integration::findOrFail(62251)->update(['credentials' => ['api_key' => $this->key, 'client_id' => '555']]);
        $this->assertNull(app(ManagerSupplyCalculationService::class)->begin($op['id']));
        $this->getJson('/api/manager/supply-calculations/'.$op['id'])->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertDatabaseCount('planning_fact_snapshots', 0);
    }

    public function test_ready_plan_cannot_bypass_an_unconfirmed_manager_calculation(): void
    {
        $op = $this->prepare(); $response = $this->approve($op)->assertOk();
        $plan = AutoSupplyPlan::findOrFail($response->json('result.plan_id'));
        $plan->update(['status' => AutoSupplyPlan::STATUS_READY]);
        $owner = app(OzonPlanMaterializationService::class);
        foreach (['verifying', 'unknown'] as $status) {
            ManagerSupplyCalculation::whereKey($op['id'])->update(['status' => $status]);
            $codes = array_column($owner->preflightCheck($plan->fresh())['errors'], 'code');
            $this->assertContains('manager_calculation_unconfirmed', $codes);
            foreach (['approve', 'materialize'] as $method) {
                try {
                    $owner->{$method}($plan->fresh(), $this->userId);
                    $this->fail('An unconfirmed calculation must not approve or materialize a plan');
                } catch (\Illuminate\Validation\ValidationException $error) {
                    $this->assertStringContainsString('Расчёт Сели ещё не подтверждён', implode(' ', $error->errors()['plan']));
                }
            }
        }
        $this->assertDatabaseCount('supplies', 0);
    }

    public function test_legacy_unmarked_recalculation_does_not_require_the_new_manager_table(): void
    {
        $op = $this->prepare(); $response = $this->approve($op)->assertOk();
        $plan = AutoSupplyPlan::findOrFail($response->json('result.plan_id'));
        $params = $plan->params; unset($params['manager_calculation_id'], $params['manager_parameter_version']);
        $plan->update(['status' => AutoSupplyPlan::STATUS_READY, 'params' => $params]);
        config(['services.manager.supply_calculations_enabled' => false]);
        \Illuminate\Support\Facades\Schema::drop('manager_supply_calculations');
        $this->postJson('/api/auto-supply-plans/'.$plan->id.'/calculate')->assertOk();
        Queue::assertPushed(CalculateAutoSupplyPlanJob::class, 1);
    }

    public function test_legacy_recalculation_cannot_reset_an_active_manager_plan(): void
    {
        $op = $this->prepare(); $response = $this->approve($op)->assertOk();
        $this->postJson('/api/auto-supply-plans/'.$response->json('result.plan_id').'/calculate')->assertStatus(409);
        Queue::assertNotPushed(CalculateAutoSupplyPlanJob::class);
    }

    public function test_incomplete_inexact_or_force_parameters_are_rejected_before_a_recipe_is_saved(): void
    {
        foreach (['budget_limit' => '0', 'lead_time_days' => '4', 'max_cover_days' => 90, 'force' => true] as $field => $value) {
            $this->postJson('/api/manager/supply-calculations/prepare', ['integration_id' => 62251, 'parameters' => $this->parameters([$field => $value])])->assertUnprocessable();
        }
        $params = $this->parameters(); unset($params['budget_limit']);
        $this->postJson('/api/manager/supply-calculations/prepare', ['integration_id' => 62251, 'parameters' => $params])->assertUnprocessable();
        $this->assertDatabaseCount('manager_supply_calculations', 0); Queue::assertNothingPushed();
    }
}
