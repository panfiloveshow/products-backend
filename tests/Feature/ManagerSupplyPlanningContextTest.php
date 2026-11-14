<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\InventoryWarehouse;
use App\Models\OzonWarehouseCluster;
use App\Models\SupplySettings;
use App\Services\SellicoApiService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ManagerSupplyPlanningContextTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $marketplace = 'ozon';
    private int $crmWorkspace = 8;
    private array $permissions = ['auto_supply.view', 'integrations.view'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sellico.base_url' => 'https://crm.test/api', 'services.sellico.skip_permission_check' => true, 'cache.default' => 'array']);
        Cache::forget('ozon_warehouse_clusters_mapping');
        Http::preventStrayRequests(); Queue::fake();
        $this->mock(SellicoApiService::class, fn ($m) => $m->shouldReceive('getServiceToken')->andReturn('service-test-only'));
        Http::fake(function ($request) {
            return match ($request->url()) {
                'https://crm.test/api/user' => Http::response(['id' => 42, 'is_service_account' => false]),
                'https://crm.test/api/workspaces/8/user-permissions' => Http::response(['user_id' => 42, 'permissions' => $this->permissions]),
                'https://crm.test/api/get-integrations/8' => Http::response([['id' => 62251, 'work_space_id' => $this->crmWorkspace,
                    'type' => $this->marketplace, 'status' => 'active', 'name' => 'Test', 'api_key' => 'secret-must-not-escape', 'client_id' => '555']]),
                default => throw new \RuntimeException('Unexpected external context request'),
            };
        });
        Integration::factory()->ozon()->create(['id' => 62251, 'work_space_id' => 8]);
        $this->withToken('human-test-only')->withHeader('X-Sellico-Workspace', '8');
    }

    private function warehouse(string $id = '100', string $name = 'Test warehouse', ?string $date = null): void
    {
        InventoryWarehouse::factory()->create(['integration_id' => 62251, 'marketplace' => $this->marketplace,
            'sku' => 'SKU-'.$id, 'warehouse_id' => $id, 'warehouse_name' => $name, 'last_updated' => $date ?? now()->subDays(45)->toDateTimeString()]);
    }

    public function test_ozon_context_reuses_owner_cluster_mapping_and_exposes_only_unverified_local_observations(): void
    {
        OzonWarehouseCluster::create(['warehouse_name' => 'Test warehouse', 'warehouse_name_normalized' => 'TEST_WAREHOUSE',
            'cluster_id' => 101, 'cluster_name' => 'Central cluster']);
        $this->warehouse(date: now()->subHours(2)->toDateTimeString());
        $this->warehouse('200', 'Unmapped warehouse');
        SupplySettings::create(['integration_id' => 62251, 'safety_stock_days' => 0, 'default_lead_time_days' => 4,
            'custom_rules' => ['private' => 'secret-must-not-escape'], 'notification_recipients' => ['secret-must-not-escape']]);
        $writes = [];
        DB::listen(function ($q) use (&$writes) { if (preg_match('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $q->sql)) $writes[] = $q->sql; });
        $response = $this->getJson('/api/manager/supply-planning-context?integration_id=62251')->assertOk()
            ->assertJsonPath('marketplace', 'ozon')->assertJsonPath('scope', 'observed_supply_destinations')
            ->assertJsonPath('items.0.id', 'cluster:101')->assertJsonPath('items.0.name', 'Central cluster')
            ->assertJsonPath('items.0.marketplace_available', null)->assertJsonPath('unmapped_warehouse_count', 1)
            ->assertJsonPath('settings.present', true)->assertJsonPath('settings.safety_stock_days', 0)
            ->assertJsonPath('settings.lead_time_days', 4)->assertJsonPath('inventory_observation.rows', 2)
            ->assertJsonPath('inventory_observation.missing_row_dates', 0)->assertJsonPath('source_freshness_verified', false)
            ->assertJsonPath('data_updated_at', null)->assertJsonPath('partial', true)->assertJsonPath('execution_allowed', false);
        $this->assertStringNotContainsString('secret-must-not-escape', $response->getContent());
        $this->assertSame([], $writes); Queue::assertNothingPushed();
        Http::assertNotSent(fn ($r) => ! str_starts_with($r->url(), 'https://crm.test/'));
    }

    public function test_wb_uses_warehouse_ids_and_pages_are_not_a_complete_marketplace_catalog(): void
    {
        $this->marketplace = 'wildberries'; Integration::findOrFail(62251)->update(['marketplace' => 'wildberries']);
        $this->warehouse('100', 'Warehouse A', now()->addDay()->toDateTimeString()); $this->warehouse('200', 'Warehouse B', now()->addDay()->toDateTimeString());
        $this->getJson('/api/manager/supply-planning-context?integration_id=62251&limit=1')->assertOk()
            ->assertJsonPath('total', 2)->assertJsonPath('has_more', true)->assertJsonPath('next_page', 2)
            ->assertJsonPath('items.0.id', '100')->assertJsonPath('items.0.type', 'warehouse')
            ->assertJsonPath('inventory_observation.oldest_row_at', null)->assertJsonPath('source_freshness_verified', false);
        $this->getJson('/api/manager/supply-planning-context?integration_id=62251&limit=1&page=2')->assertOk()
            ->assertJsonPath('items.0.id', '200')->assertJsonPath('has_more', false)->assertJsonPath('next_page', null);
    }

    public function test_empty_context_does_not_create_settings_plans_or_jobs(): void
    {
        $this->getJson('/api/manager/supply-planning-context?integration_id=62251')->assertOk()
            ->assertJsonPath('total', 0)->assertJsonPath('settings.present', false)->assertJsonPath('settings.safety_stock_days', null)
            ->assertJsonPath('settings.lead_time_days', null)->assertJsonPath('inventory_observation.rows', 0)
            ->assertJsonPath('inventory_observation.newest_row_at', null)->assertJsonPath('source_freshness_verified', false);
        $this->assertDatabaseCount('supply_settings', 0); $this->assertDatabaseCount('auto_supply_plans', 0); Queue::assertNothingPushed();
    }

    public function test_other_workspaces_and_revoked_permissions_cannot_read_the_context(): void
    {
        Integration::findOrFail(62251)->update(['work_space_id' => 9]);
        $this->getJson('/api/manager/supply-planning-context?integration_id=62251')->assertNotFound();
        Integration::findOrFail(62251)->update(['work_space_id' => 8]); $this->permissions = ['integrations.view'];
        $this->getJson('/api/manager/supply-planning-context?integration_id=62251')->assertForbidden();
    }

    public function test_changed_crm_binding_and_extra_action_parameters_are_not_accepted(): void
    {
        $this->crmWorkspace = 9;
        $this->getJson('/api/manager/supply-planning-context?integration_id=62251')->assertStatus(503);
        $this->getJson('/api/manager/supply-planning-context?integration_id=62251&execute=1')->assertUnprocessable();
        $this->getJson('/api/manager/supply-planning-context?integration_id=62251&limit=51')->assertUnprocessable();
        Queue::assertNothingPushed();
    }
}
