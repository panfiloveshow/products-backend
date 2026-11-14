<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Product;
use App\Models\UnitEconomicsCache;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnitEconomicsPostgresMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_wb_scheme_migration_preserves_existing_cache_and_unrelated_checks_on_postgresql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL enum CHECK transition requires a real PostgreSQL database.');
        }
        $integration = Integration::factory()->wildberries()->create(['id' => 73905]);
        $product = Product::factory()->wildberries()->create(['integration_id' => $integration->id, 'sku' => 'migration-cache']);
        $row = UnitEconomicsCache::create(['integration_id' => $integration->id, 'sku' => 'migration-cache',
            'product_id' => $product->id,
            'product_name' => 'Synthetic migration fixture', 'marketplace' => 'wildberries',
            'fulfillment_type' => 'FBO', 'net_profit' => 123.45, 'marketplace_data' => ['marker' => 'preserve-existing-row']]);
        $before = DB::table('unit_economics_cache')->where('id', $row->id)->first();
        DB::statement("ALTER TABLE unit_economics_cache ADD CONSTRAINT unit_economics_cache_fulfillment_type_check CHECK (fulfillment_type IN ('FBO', 'FBS', 'RFBS', 'EXPRESS'))");
        DB::statement('ALTER TABLE unit_economics_cache ADD CONSTRAINT test_positive_cache_id CHECK (id > 0)');

        $migration = require database_path('migrations/2026_01_08_120835_add_wb_schemes_to_unit_economics_cache.php');
        $migration->up();

        $this->assertEquals($before, DB::table('unit_economics_cache')->where('id', $row->id)->first());
        $this->assertNotNull(DB::selectOne("SELECT conname FROM pg_constraint WHERE conrelid = 'unit_economics_cache'::regclass AND conname = 'test_positive_cache_id'"));
        foreach (['FBS', 'DBS', 'EDBS', 'DBW'] as $scheme) {
            UnitEconomicsCache::create(['integration_id' => $integration->id, 'sku' => $row->sku,
                'product_id' => $product->id,
                'product_name' => $row->product_name, 'marketplace' => 'wildberries', 'fulfillment_type' => $scheme]);
        }
        $this->assertSame(5, UnitEconomicsCache::where('integration_id', $integration->id)->count());
    }
}
