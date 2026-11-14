<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AutoSupplyPlan;
use App\Models\AutoSupplyPlanLine;
use App\Models\Integration;
use App\Models\InventoryWarehouse;
use App\Models\UnitEconomics;
use App\Services\AutoSupplyPlanning\DataFreshnessRegistry;
use App\Services\SellicoApiService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ManagerFboRecoveryAndSchemesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private array $permissions = ['auto_supply.view', 'auto_supply.create', 'integrations.view'];

    private int $actor = 42;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.sellico.base_url' => 'https://crm.test/api',
            'services.sellico.skip_permission_check' => true,
            'cache.default' => 'array',
        ]);
        Http::preventStrayRequests();
        $this->mock(SellicoApiService::class, fn ($m) => $m->shouldReceive('getServiceToken')->andReturn('service-session'));
        Http::fake(function ($r) {
            return match ($r->url()) {
                'https://crm.test/api/user' => Http::response(['id' => $this->actor, 'is_service_account' => false]),
                'https://crm.test/api/workspaces/8/user-permissions' => Http::response(['user_id' => $this->actor, 'permissions' => $this->permissions]),
                'https://crm.test/api/get-integrations/8' => Http::response([[
                    'id' => 62700,
                    'work_space_id' => 8,
                    'type' => 'ozon',
                    'status' => 'active',
                    'name' => 'Ozon FBO Shop',
                    'api_key' => 'secret-fbo-key',
                    'client_id' => '888',
                ]]),
                default => throw new \RuntimeException('Unexpected HTTP request: '.$r->url()),
            };
        });
        Integration::factory()->ozon()->create(['id' => 62700, 'work_space_id' => 8]);
        $this->withToken('personal-token')->withHeader('X-Sellico-Workspace', '8');
    }

    public function test_lists_unknown_ozon_fbo_operations_and_resolves_manually_without_native_replay(): void
    {
        $fboOp = (string) Str::uuid();
        $calcOp = (string) Str::uuid();
        $planId = (string) Str::uuid();

        DB::table('manager_ozon_fbo_operations')->insert([
            'id' => $fboOp,
            'workspace_id' => 8,
            'user_id' => 42,
            'integration_id' => 62700,
            'store_name' => 'Ozon FBO Shop',
            'store_binding' => str_repeat('d', 64),
            'operation_type' => 'fbo_ozon_draft_create',
            'fingerprint' => str_repeat('e', 64),
            'status' => 'unknown',
            'plan' => json_encode(['plan_id' => $planId]),
            'native_draft_id' => '998877',
            'expires_at' => now()->addMinutes(5),
            'created_at' => now()->subMinutes(12),
            'updated_at' => now()->subMinutes(6),
        ]);
        DB::table('manager_fbo_resource_holds')->insert([
            'resource_key' => hash('sha256', 'fbo-plan:'.$planId),
            'operation_id' => $fboOp,
            'created_at' => now()->subMinutes(12),
        ]);

        DB::table('manager_supply_calculations')->insert([
            'id' => $calcOp,
            'workspace_id' => 8,
            'integration_id' => 62700,
            'created_by' => 42,
            'marketplace' => 'ozon',
            'integration_name' => 'Ozon FBO Shop',
            'credential_binding' => str_repeat('d', 64),
            'fingerprint' => str_repeat('f', 64),
            'parameters' => json_encode(['horizon_days' => 28]),
            'plan_id' => (string) Str::uuid(),
            'status' => 'unknown',
            'expires_at' => now()->addMinutes(5),
            'events' => json_encode([]),
            'created_at' => now()->subMinutes(15),
            'updated_at' => now()->subMinutes(7),
        ]);

        $list = $this->getJson('/api/manager/ozon-fbo-operations/unknown?integration_id=62700&marketplace=ozon')
            ->assertOk()
            ->assertJsonPath('workspace_id', '8')
            ->assertJsonPath('integration_id', '62700')
            ->assertJsonPath('marketplace', 'ozon')
            ->assertJsonPath('has_more', false)
            ->json();

        $this->assertCount(2, $list['items']);

        $prepared = $this->postJson("/api/manager/ozon-fbo-operations/{$fboOp}/resolution/prepare", [
            'integration_id' => '62700',
            'marketplace' => 'ozon',
            'reason' => 'Черновик поставки 998877 проверен в ЛК Ozon, дублирования нет.',
            'support_reference' => 'ЛК Ozon черновик FBO #998877',
        ])->assertOk()
            ->assertJsonPath('status', 'awaiting_confirmation')
            ->assertJsonPath('operation_type', 'fbo_ozon_draft_create')
            ->assertJsonPath('native_outcome_verified', false)
            ->assertJsonPath('physical_status', null)
            ->json();

        $this->postJson("/api/manager/ozon-fbo-operations/{$fboOp}/resolution/{$prepared['id']}/approve", [
            'fingerprint' => $prepared['fingerprint'],
            'confirmations' => [
                'fbo_cabinet_checked' => true,
                'fbo_no_pending_draft_or_supply' => true,
                'fbo_late_effect_risk_accepted' => true,
                'fbo_hold_release_understood' => true,
            ],
        ])->assertOk()
            ->assertJsonPath('status', 'resolved_manually')
            ->assertJsonPath('hold_active', false)
            ->assertJsonPath('native_outcome_verified', false)
            ->assertJsonPath('physical_status', null);

        $this->assertDatabaseMissing('manager_fbo_resource_holds', ['operation_id' => $fboOp]);
        $this->assertSame('resolved_manually', DB::table('manager_ozon_fbo_operations')->where('id', $fboOp)->value('status'));
        Http::assertNotSent(fn ($r) => ! str_starts_with($r->url(), 'https://crm.test/'));
    }

    public function test_reads_fbo_scheme_handoff_context_and_detects_stale_input_version(): void
    {
        $integration = Integration::findOrFail(62700);
        InventoryWarehouse::factory()->create([
            'integration_id' => 62700,
            'marketplace' => 'ozon',
            'sku' => 'CROSSDOCK-SKU-1',
            'warehouse_id' => 'W1',
            'last_updated' => now(),
        ]);
        UnitEconomics::create([
            'integration_id' => 62700,
            'marketplace' => 'ozon',
            'sku' => 'CROSSDOCK-SKU-1',
            'price' => 1500,
            'cost_price' => 450,
        ]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => 62700,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
        ]);
        AutoSupplyPlanLine::create([
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'CROSSDOCK-SKU-1',
            'qty_recommended' => 24,
            'qty_rounded' => 24,
            'is_excluded' => false,
        ]);

        $health = app(DataFreshnessRegistry::class)->inspect($integration, $plan->id);
        $inputVersion = $health['hash'];

        $fresh = $this->getJson('/api/manager/fbo-scheme-handoff/context?'.http_build_query([
            'integration_id' => 62700,
            'marketplace' => 'ozon',
            'plan_id' => $plan->id,
            'input_version' => $inputVersion,
            'scheme_kind' => 'ozon_crossdock',
            'dropoff_point_id' => 'PPZ-MSK-12',
            'target_cluster_id' => 'CLUSTER-SPB',
        ]))->assertOk()
            ->assertJsonPath('workspace_id', '8')
            ->assertJsonPath('integration_id', '62700')
            ->assertJsonPath('plan_id', $plan->id)
            ->assertJsonPath('input_binding_state', 'fresh')
            ->assertJsonPath('total_skus', 1)
            ->assertJsonPath('total_units', 24)
            ->assertJsonPath('input_provenance.native_origin_verified', false)
            ->assertJsonPath('input_provenance.demand_completeness_verified', false)
            ->assertJsonPath('input_provenance.financial_inputs_complete', false)
            ->json();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fresh['context_fingerprint']);

        // Mismatched input_version reports stale
        $staleVersion = str_repeat('9', 64);
        $this->getJson('/api/manager/fbo-scheme-handoff/context?'.http_build_query([
            'integration_id' => 62700,
            'marketplace' => 'ozon',
            'plan_id' => $plan->id,
            'input_version' => $staleVersion,
            'scheme_kind' => 'ozon_crossdock',
        ]))->assertOk()->assertJsonPath('input_binding_state', 'stale');
    }
}
