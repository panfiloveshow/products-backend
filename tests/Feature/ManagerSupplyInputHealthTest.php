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
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ManagerSupplyInputHealthTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $marketplace = 'ozon';

    private int $crmWorkspace = 8;

    private array $permissions = ['auto_supply.view', 'integrations.view'];

    private int $bindingReads = 0;

    private bool $rotate = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDateTime(2026, 11, 9, 12, 0, 0));
        config(['services.sellico.base_url' => 'https://crm.test/api', 'services.sellico.skip_permission_check' => true, 'cache.default' => 'array']);
        Http::preventStrayRequests();
        Queue::fake();
        $this->mock(SellicoApiService::class, fn ($m) => $m->shouldReceive('getServiceToken')->andReturn('isolated-service'));
        Http::fake(function ($request) {
            return match ($request->url()) {
                'https://crm.test/api/user' => Http::response(['id' => 42, 'is_service_account' => false]),
                'https://crm.test/api/workspaces/8/user-permissions' => Http::response(['user_id' => 42, 'permissions' => $this->permissions]),
                'https://crm.test/api/get-integrations/8' => Http::response([['id' => 62341, 'work_space_id' => $this->crmWorkspace,
                    'type' => $this->marketplace, 'status' => 'active', 'name' => 'Isolated shop',
                    'api_key' => ($this->rotate && ++$this->bindingReads > 1) ? 'rotated-secret' : 'must-not-escape', 'client_id' => '555']]),
                default => throw new \RuntimeException('Unexpected external health request'),
            };
        });
        Integration::factory()->ozon()->create(['id' => 62341, 'work_space_id' => 8]);
        $this->withToken('personal-test-only')->withHeader('X-Sellico-Workspace', '8');
    }

    private function row(string $sku = '0007', string $warehouse = 'W1', mixed $time = null): InventoryWarehouse
    {
        return InventoryWarehouse::factory()->create(['integration_id' => 62341, 'marketplace' => $this->marketplace, 'sku' => $sku,
            'warehouse_id' => $warehouse, 'last_updated' => $time ?? now()]);
    }

    public function test_read_projects_all_warehouse_dates_without_promoting_them_to_native_proof_or_writing(): void
    {
        $this->row(time: now()->subDays(3));
        $this->row(warehouse: 'W2');
        UnitEconomics::create(['integration_id' => 62341, 'marketplace' => 'ozon', 'sku' => '0007', 'price' => 100, 'cost_price' => 20]);
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $response = $this->getJson('/api/manager/supply-input-health?integration_id=62341')->assertOk()
            ->assertJsonPath('workspace_id', '8')->assertJsonPath('integration_id', '62341')->assertJsonPath('items.0.sku', '0007')
            ->assertJsonPath('items.0.inventory.status', 'stale')->assertJsonPath('items.0.inventory.rows', 2)
            ->assertJsonPath('items.0.inventory.stale_rows', 1)->assertJsonPath('items.0.inventory.local_freshness_verified', false)
            ->assertJsonPath('items.0.economics.status', 'ready')->assertJsonPath('items.0.economics.native_origin_verified', false)
            ->assertJsonPath('native_origin_verified', false)->assertJsonPath('financial_inputs_complete', false)
            ->assertJsonPath('execution_allowed', false)->assertJsonPath('data_updated_at', null)->assertJsonPath('plan_binding', null);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $response->json('input_version'));
        $this->assertStringNotContainsString('must-not-escape', $response->getContent());
        $this->assertSame([], $writes);
        Queue::assertNothingPushed();
        Http::assertNotSent(fn ($request) => ! str_starts_with($request->url(), 'https://crm.test/'));
        $fixture = getenv('SELYA_INPUT_HEALTH_FIXTURE');
        if (is_string($fixture) && str_starts_with($fixture, '/tmp/selya-manager-eight-2026-11-05/')) {
            file_put_contents($fixture, json_encode(['unbound' => $response->json()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        }
    }

    public function test_wb_distinct_sku_paging_is_complete_after_one_hundred_and_preserves_zeroes(): void
    {
        $this->marketplace = 'wildberries';
        Integration::findOrFail(62341)->update(['marketplace' => 'wildberries']);
        for ($n = 0; $n < 101; $n++) {
            $this->row('SKU-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT));
        }
        $this->row('SKU-000', 'duplicate-warehouse');
        $first = $this->getJson('/api/manager/supply-input-health?integration_id=62341&limit=100')->assertOk()
            ->assertJsonPath('marketplace', 'wildberries')->assertJsonPath('total', 101)->assertJsonPath('has_more', true)->assertJsonPath('next_page', 2);
        $this->assertCount(100, $first->json('items'));
        $this->getJson('/api/manager/supply-input-health?integration_id=62341&limit=100&page=2')->assertOk()
            ->assertJsonPath('total', 101)->assertJsonPath('items.0.sku', 'SKU-100')->assertJsonPath('has_more', false)->assertJsonPath('next_page', null);
    }

    public function test_empty_observations_and_missing_economics_stay_unknown(): void
    {
        $this->getJson('/api/manager/supply-input-health?integration_id=62341')->assertOk()->assertJsonPath('total', 0)->assertJsonPath('items', []);
        $this->row(time: now()->addDay());
        $this->getJson('/api/manager/supply-input-health?integration_id=62341')->assertOk()
            ->assertJsonPath('items.0.inventory.status', 'missing')->assertJsonPath('items.0.inventory.future_row_dates', 1)
            ->assertJsonPath('items.0.inventory.oldest_row_at', null)->assertJsonPath('items.0.economics.rows', 0)
            ->assertJsonPath('items.0.economics.local_freshness_verified', false)->assertJsonPath('items.0.demand_origin_verified', false);
        $this->assertDatabaseCount('supply_settings', 0);
        $this->assertDatabaseCount('auto_supply_plans', 0);
        Queue::assertNothingPushed();
    }

    public function test_plan_scope_selects_only_its_positive_included_skus_and_reports_missing_receipt(): void
    {
        $plan = AutoSupplyPlan::create(['integration_id' => 62341, 'marketplace' => 'ozon', 'status' => 'ready', 'mode' => 'balanced']);
        foreach ([['0007', 5, false], ['excluded', 5, true], ['zero', 0, false]] as [$sku, $qty, $excluded]) {
            AutoSupplyPlanLine::create(['auto_supply_plan_id' => $plan->id, 'sku' => $sku, 'qty_recommended' => $qty, 'qty_rounded' => $qty, 'is_excluded' => $excluded]);
        }
        $this->row();
        $this->row('unselected');
        $response = $this->getJson('/api/manager/supply-input-health?integration_id=62341&plan_id='.$plan->id)->assertOk()
            ->assertJsonPath('scope', 'selected_plan_skus')->assertJsonPath('total', 1)->assertJsonPath('items.0.sku', '0007')
            ->assertJsonPath('plan_binding.snapshot_present', false)->assertJsonPath('plan_binding.unchanged_since_calculation', null)
            ->assertJsonPath('plan_binding.errors.0.code', 'facts_snapshot_required')->assertJsonPath('plan_binding.review_path', '/products/shipments/auto-plans/'.$plan->id);
        $fixture = getenv('SELYA_INPUT_HEALTH_FIXTURE');
        if (is_string($fixture) && str_starts_with($fixture, '/tmp/selya-manager-eight-2026-11-05/')) {
            $all = json_decode(file_get_contents($fixture), true, flags: JSON_THROW_ON_ERROR);
            $all['plan'] = $response->json();
            file_put_contents($fixture, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        }
        $plan->update(['integration_id' => 99999]);
        $this->getJson('/api/manager/supply-input-health?integration_id=62341&plan_id='.$plan->id)->assertNotFound();
    }

    public function test_local_and_crm_tenant_scope_and_current_permissions_are_required(): void
    {
        Integration::findOrFail(62341)->update(['work_space_id' => 9]);
        $this->getJson('/api/manager/supply-input-health?integration_id=62341')->assertNotFound();
        Integration::findOrFail(62341)->update(['work_space_id' => 8]);
        $this->permissions = ['integrations.view'];
        $this->getJson('/api/manager/supply-input-health?integration_id=62341')->assertForbidden();
        $this->permissions[] = 'auto_supply.view';
        $this->crmWorkspace = 9;
        $this->getJson('/api/manager/supply-input-health?integration_id=62341')->assertStatus(503);
    }

    public function test_rotated_binding_during_read_is_not_reported_as_stable(): void
    {
        $this->row();
        $this->rotate = true;
        $this->getJson('/api/manager/supply-input-health?integration_id=62341')->assertStatus(409);
    }

    public function test_input_drift_during_read_is_not_reported_as_stable(): void
    {
        $this->row();
        $integration = Integration::findOrFail(62341);
        $health = (new DataFreshnessRegistry)->inspect($integration);
        $changed = array_replace($health, ['hash' => str_repeat('f', 64)]);
        $this->mock(DataFreshnessRegistry::class, fn ($m) => $m->shouldReceive('inspect')->twice()->andReturn($health, $changed));
        $this->getJson('/api/manager/supply-input-health?integration_id=62341')->assertStatus(409);
    }

    public function test_only_exact_read_parameters_are_accepted(): void
    {
        foreach (['&execute=1', '&page=0', '&limit=101', '&plan_id=invented'] as $suffix) {
            $this->getJson('/api/manager/supply-input-health?integration_id=62341'.$suffix)->assertUnprocessable();
        }
        Queue::assertNothingPushed();
    }
}
