<?php

namespace Tests\Feature;

use App\Jobs\CalculateAutoSupplyPlanJob;
use App\Models\AutoSupplyPlan;
use App\Models\Integration;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Models\Supply;
use App\Models\SupplyItem;
use App\Models\UnitEconomics;
use App\Services\AutoSupplyPlanning\DataFreshnessRegistry;
use App\Services\AutoSupplyPlanning\PlanningSourceFingerprint;
use App\Services\AutoSupplyPlanService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PlanningFreshnessIntegrityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDateTime(2026, 11, 9, 12, 0, 0));
        Http::preventStrayRequests();
    }

    private function integration(): Integration
    {
        return Integration::factory()->wildberries()->create(['id' => 62341, 'work_space_id' => 8]);
    }

    private function row(Integration $integration, string $sku, string $warehouse, mixed $time): InventoryWarehouse
    {
        return InventoryWarehouse::factory()->wildberries()->create(['integration_id' => $integration->id, 'sku' => $sku,
            'warehouse_id' => $warehouse, 'quantity' => 5, 'reserved' => 0, 'in_transit' => 0, 'sales_7_days' => 1, 'sales_14_days' => 2,
            'sales_30_days' => 3, 'average_daily_sales' => 1, 'effective_daily_sales' => 1, 'last_updated' => $time]);
    }

    public function test_a_fresh_sku_or_warehouse_does_not_mask_an_old_inventory_row(): void
    {
        $integration = $this->integration();
        Product::factory()->wildberries()->create(['integration_id' => $integration->id, 'sku' => 'A']);
        $this->row($integration, 'A', 'old', now()->subDays(3));
        $this->row($integration, 'A', 'fresh', now());
        $health = app(DataFreshnessRegistry::class)->inspect($integration);
        $this->assertSame('stale', $health['sources']['inventory']['status']);
        $this->assertFalse($health['can_approve']);
        $this->assertSame(1, $health['sources']['inventory']['meta']['stale_rows']);
        $this->assertSame(['A'], $health['sources']['inventory']['meta']['stale_sku_examples']);
    }

    public function test_an_input_value_change_is_not_hidden_by_an_unchanged_latest_timestamp(): void
    {
        $integration = $this->integration();
        Product::factory()->wildberries()->create(['integration_id' => $integration->id, 'sku' => 'A']);
        $row = $this->row($integration, 'A', 'first', now()->subHour());
        $this->row($integration, 'A', 'latest', now());
        $first = app(DataFreshnessRegistry::class)->inspect($integration);
        DB::table('inventory_warehouses')->where('id', $row->id)->update(['quantity' => 17]);
        $second = app(DataFreshnessRegistry::class)->inspect($integration);
        $this->assertNotSame($first['hash'], $second['hash']);
    }

    public function test_inventory_without_demand_does_not_refresh_an_old_posting_source(): void
    {
        $integration = $this->integration();
        Product::factory()->wildberries()->create(['integration_id' => $integration->id, 'sku' => 'A']);
        $row = $this->row($integration, 'A', 'stock-only', now());
        $row->update(['sales_7_days' => 0, 'sales_14_days' => 0, 'sales_30_days' => 0, 'average_daily_sales' => 0, 'effective_daily_sales' => 0]);
        DB::table('postings')->insert(['id' => '33333333-3333-4333-8333-333333333333', 'integration_id' => (string) $integration->id,
            'marketplace' => 'wildberries', 'posting_number' => 'old-posting', 'status' => 'delivered', 'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3), 'synced_at' => now()->subDays(3)]);
        $health = app(DataFreshnessRegistry::class)->inspect($integration);
        $this->assertSame('stale', $health['sources']['demand']['status']);
        $this->assertFalse($health['can_approve']);
    }

    public function test_future_inventory_timestamp_is_not_fresh_evidence(): void
    {
        $integration = $this->integration();
        $this->row($integration, 'A', 'future', now()->addDay());
        $health = app(DataFreshnessRegistry::class)->inspect($integration);
        $this->assertNotSame('ready', $health['sources']['inventory']['status']);
        $this->assertFalse($health['can_approve']);
    }

    public function test_recent_product_and_economics_rows_do_not_mask_old_rows(): void
    {
        $integration = $this->integration();
        foreach (['A', 'B'] as $sku) {
            Product::factory()->wildberries()->create(['integration_id' => $integration->id, 'sku' => $sku]);
            UnitEconomics::create(['integration_id' => $integration->id, 'marketplace' => 'wildberries', 'sku' => $sku,
                'price' => 100, 'cost_price' => 30, 'net_profit_per_unit' => 20]);
        }
        DB::table('products')->where('sku', 'A')->update(['updated_at' => now()->subDays(4)]);
        DB::table('unit_economics')->where('sku', 'A')->update(['updated_at' => now()->subDays(4)]);
        $health = app(DataFreshnessRegistry::class)->inspect($integration);
        $this->assertSame('stale', $health['sources']['products']['status']);
        $this->assertSame('stale', $health['sources']['economics']['status']);
        $this->assertFalse($health['can_approve']);
    }

    public function test_fingerprint_is_scoped_and_versions_all_rows_beyond_the_first_thousand(): void
    {
        $integration = $this->integration();
        $other = Integration::factory()->wildberries()->create(['id' => 62342, 'work_space_id' => 9]);
        $this->row($other, '0007', 'other', now());
        for ($n = 0; $n < 1001; $n++) {
            $this->row($integration, '0007', 'W-'.$n, now());
        }
        $fingerprints = app(PlanningSourceFingerprint::class);
        $first = $fingerprints->capture($integration);
        $this->assertSame(1001, $first['sources']['inventory']['rows']);
        DB::table('inventory_warehouses')->where('integration_id', $other->id)->update(['quantity' => 99]);
        $this->assertSame($first['hash'], $fingerprints->capture($integration)['hash']);
        DB::table('inventory_warehouses')->where('integration_id', $integration->id)->where('warehouse_id', 'W-1000')->update(['quantity' => 99]);
        $this->assertNotSame($first['hash'], $fingerprints->capture($integration)['hash']);
        $this->assertStringNotContainsString('quantity', json_encode($first));
    }

    public function test_own_materialization_does_not_change_input_version_but_another_supply_item_does(): void
    {
        $integration = Integration::factory()->ozon()->create(['id' => 62341, 'work_space_id' => 8]);
        $plan = AutoSupplyPlan::create(['integration_id' => $integration->id, 'marketplace' => 'ozon', 'status' => 'ready', 'mode' => 'balanced']);
        $fingerprints = app(PlanningSourceFingerprint::class);
        $before = $fingerprints->capture($integration, (string) $plan->id);
        $own = Supply::create(['integration_id' => $integration->id, 'auto_supply_plan_id' => $plan->id,
            'supply_type' => 'fbo', 'supply_method' => 'direct', 'cluster_id' => '101', 'status' => 'slot_booked']);
        SupplyItem::create(['supply_id' => $own->id, 'sku' => 'A', 'product_name' => 'A', 'planned_qty' => 500]);
        $this->assertSame($before['hash'], $fingerprints->capture($integration, (string) $plan->id)['hash']);
        $other = Supply::create(['integration_id' => $integration->id, 'supply_type' => 'fbo', 'supply_method' => 'direct',
            'cluster_id' => '101', 'status' => 'slot_booked']);
        $item = SupplyItem::create(['supply_id' => $other->id, 'sku' => 'A', 'product_name' => 'A', 'planned_qty' => 3]);
        $after = $fingerprints->capture($integration, (string) $plan->id);
        $this->assertNotSame($before['hash'], $after['hash']);
        DB::table('supply_items')->where('id', $item->id)->update(['planned_qty' => 4]);
        $this->assertNotSame($after['hash'], $fingerprints->capture($integration, (string) $plan->id)['hash']);
        $job = new CalculateAutoSupplyPlanJob((string) $plan->id);
        $method = new \ReflectionMethod($job, 'getOzonInTransitFromSuppliesByCluster');
        $this->assertSame(['A|101' => 4], $method->invoke($job, $integration->id, ['unused' => []], ['slot_booked'], (string) $plan->id));
    }

    public function test_wb_calculation_does_not_borrow_economics_without_a_store_owner(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $integration = $this->integration();
        Product::factory()->wildberries()->create(['integration_id' => $integration->id, 'sku' => '0007', 'barcode' => '0007', 'price' => 500]);
        $this->row($integration, '0007', '100', now());
        UnitEconomics::create(['integration_id' => null, 'marketplace' => 'wildberries', 'sku' => '0007',
            'price' => 500, 'cost_price' => 123456, 'net_profit_per_unit' => -123456, 'sales_count' => 30]);
        $plan = AutoSupplyPlan::create(['integration_id' => $integration->id, 'marketplace' => 'wildberries',
            'status' => 'pending', 'mode' => 'balanced', 'params' => ['skip_negative_profit' => false]]);
        (new CalculateAutoSupplyPlanJob((string) $plan->id))->handle(app(AutoSupplyPlanService::class));
        $plan->refresh();
        $this->assertSame('ready', $plan->status, (string) $plan->error_message);
        $this->assertSame(0, $plan->snapshot->facts_freshness_json['unit_economics']['items']);
        $this->assertSame(0, $plan->snapshot->facts_freshness_json['registry']['input_evidence']['sources']['economics']['rows']);
        $this->assertStringNotContainsString('123456', json_encode($plan->snapshot->economics_facts_json));
    }

    public function test_barcode_cost_schema_preserves_existing_rows_and_refuses_a_destructive_downgrade(): void
    {
        DB::table('wb_barcode_costs')->insert(['integration_id' => 62341, 'barcode' => '0007', 'nm_id' => '123', 'cost_price' => 0]);
        $migration = require database_path('migrations/2026_11_09_230000_create_wb_barcode_costs_for_planning.php');
        $migration->up();
        $this->assertDatabaseHas('wb_barcode_costs', ['integration_id' => 62341, 'barcode' => '0007', 'cost_price' => 0]);
        try {
            $migration->down();
            $this->fail('A nonempty cost table must not be dropped');
        } catch (\RuntimeException $e) {
            $this->assertSame('Barcode cost data prevents downgrade', $e->getMessage());
        }
        $this->assertDatabaseCount('wb_barcode_costs', 1);
    }
}
