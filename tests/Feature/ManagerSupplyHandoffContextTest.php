<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AutoSupplyPlan;
use App\Models\AutoSupplyPlanLine;
use App\Models\Integration;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Services\AutoSupplyPlanning\OzonPlanningFactsBuilder;
use App\Services\AutoSupplyPlanning\PlanningFactSnapshotService;
use App\Services\SellicoApiService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ManagerSupplyHandoffContextTest extends TestCase
{
    use LazilyRefreshDatabase;

    private array $permissions = ['auto_supply.view', 'integrations.view'];

    private int $crmWorkspace = 8;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDateTime(2026, 11, 9, 12, 0, 0));
        config(['services.sellico.base_url' => 'https://crm.test/api', 'services.sellico.skip_permission_check' => true, 'cache.default' => 'array']);
        Queue::fake();
        Http::preventStrayRequests();
        $this->mock(SellicoApiService::class, fn ($m) => $m->shouldReceive('getServiceToken')->andReturn('test-service-only'));
        Http::fake(fn ($r) => match ($r->url()) {
            'https://crm.test/api/user' => Http::response(['id' => 42, 'is_service_account' => false]),
            'https://crm.test/api/workspaces/8/user-permissions' => Http::response(['user_id' => 42, 'permissions' => $this->permissions]),
            'https://crm.test/api/get-integrations/8' => Http::response([['id' => 62341, 'work_space_id' => $this->crmWorkspace, 'type' => 'wildberries', 'status' => 'active', 'name' => 'Isolated WB', 'api_key' => 'must-not-escape']]),
            default => throw new \RuntimeException('Unexpected handoff context request'),
        });
        Integration::factory()->wildberries()->create(['id' => 62341, 'work_space_id' => 8]);
        $this->withToken('test-personal-only')->withHeader('X-Sellico-Workspace', '8');
    }

    private function plan(): AutoSupplyPlan
    {
        Product::factory()->wildberries()->create(['integration_id' => 62341, 'sku' => '0007', 'barcode' => '0007']);
        InventoryWarehouse::factory()->wildberries()->create(['integration_id' => 62341, 'sku' => '0007', 'warehouse_id' => '101',
            'quantity' => 1, 'sales_30_days' => 30, 'average_daily_sales' => 1, 'effective_daily_sales' => 1, 'last_updated' => now()]);
        $plan = AutoSupplyPlan::create(['integration_id' => 62341, 'marketplace' => 'wildberries', 'status' => 'ready', 'mode' => 'balanced']);
        foreach ([['0007', '0007', 5, false], ['0008', null, 6, false], ['excluded', 'x', 7, true], ['zero', 'z', 0, false]] as [$sku, $barcode, $qty, $excluded]) {
            AutoSupplyPlanLine::create(['auto_supply_plan_id' => $plan->id, 'sku' => $sku, 'barcode' => $barcode, 'product_name' => 'Товар '.$sku,
                'warehouse_id' => '101', 'warehouse_name' => 'Склад из расчёта', 'qty_rounded' => $qty, 'qty_recommended' => $qty, 'is_excluded' => $excluded]);
        }
        $capture = app(PlanningFactSnapshotService::class);
        $capture->start($plan);
        $facts = app(OzonPlanningFactsBuilder::class)->buildLineFacts($plan->lines()->get()->toArray());
        $capture->complete($plan, ['demand_facts' => ['lines' => $facts['demand']], 'stock_facts' => ['lines' => $facts['stock']],
            'supply_facts' => ['lines' => $facts['supply']], 'economics_facts' => ['lines' => $facts['economics']]]);

        return $plan->fresh();
    }

    public function test_full_owned_manifest_is_stable_includes_unknown_barcodes_and_does_not_create_a_supply_or_job(): void
    {
        $plan = $this->plan();
        $writes = [];
        DB::listen(function ($q) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $q->sql)) {
                $writes[] = $q->sql;
            }
        });
        $url = '/api/manager/supply-handoff-context?integration_id=62341&plan_id='.$plan->id;
        $response = $this->getJson($url)->assertOk()->assertJsonPath('can_prepare_task', true)->assertJsonPath('lines_count', 2)
            ->assertJsonPath('total_units', 11)->assertJsonPath('missing_barcode_count', 1)->assertJsonPath('composition_complete', true)
            ->assertJsonPath('execution_allowed', false)->assertJsonPath('acceptance_verified', false)->assertJsonPath('financial_inputs_complete', false);
        $items = collect($response->json('items'))->keyBy('sku');
        $this->assertSame('0007', $items['0007']['barcode']);
        $this->assertNull($items['0008']['barcode']);
        $this->assertSame('101', $items['0007']['warehouse_id']);
        $this->assertSame($response->json('context_fingerprint'), $this->getJson($url)->assertOk()->json('context_fingerprint'));
        $this->assertSame([], $writes);
        Queue::assertNothingPushed();
        Http::assertNotSent(fn ($r) => ! str_starts_with($r->url(), 'https://crm.test/'));
        $this->assertStringNotContainsString('must-not-escape', $response->getContent());
        $fixture = getenv('SELYA_HANDOFF_PRODUCTS_FIXTURE');
        if (is_string($fixture) && str_starts_with($fixture, '/tmp/selya-manager-eight-2026-11-05/')) {
            file_put_contents($fixture, json_encode($response->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        }
    }

    public function test_changed_inputs_or_unfinished_calculation_cannot_authorize_handoff(): void
    {
        $plan = $this->plan();
        DB::table('inventory_warehouses')->where('integration_id', 62341)->update(['quantity' => 50]);
        $this->getJson('/api/manager/supply-handoff-context?integration_id=62341&plan_id='.$plan->id)->assertOk()
            ->assertJsonPath('can_prepare_task', false)->assertJsonFragment(['code' => 'facts_changed_since_calculation']);
        $plan->update(['status' => 'calculating']);
        $this->getJson('/api/manager/supply-handoff-context?integration_id=62341&plan_id='.$plan->id)->assertOk()
            ->assertJsonPath('can_prepare_task', false)->assertJsonFragment(['code' => 'plan_not_ready']);
    }

    public function test_tenants_current_permissions_and_exact_read_parameters_are_enforced(): void
    {
        $plan = $this->plan();
        $url = '/api/manager/supply-handoff-context?integration_id=62341&plan_id='.$plan->id;
        $this->getJson($url.'&execute=1')->assertUnprocessable();
        Integration::findOrFail(62341)->update(['work_space_id' => 9]);
        $this->getJson($url)->assertNotFound();
        Integration::findOrFail(62341)->update(['work_space_id' => 8]);
        $this->permissions = ['integrations.view'];
        $this->getJson($url)->assertForbidden();
        $this->permissions[] = 'auto_supply.view';
        $this->crmWorkspace = 9;
        $this->getJson($url)->assertStatus(503);
    }
}
