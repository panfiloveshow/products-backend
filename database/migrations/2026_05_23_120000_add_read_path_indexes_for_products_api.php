<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndex('unit_economics_cache', ['integration_id', 'marketplace', 'fulfillment_type', 'sku'], 'ue_cache_market_scheme_sku_idx');
        $this->addIndex('unit_economics_cache', ['integration_id', 'marketplace', 'sku'], 'ue_cache_market_sku_idx');
        $this->addIndex('unit_economics_cache', ['integration_id', 'marketplace', 'fulfillment_type', 'price'], 'ue_cache_market_scheme_price_idx');
        $this->addIndex('unit_economics_cache', ['integration_id', 'marketplace', 'fulfillment_type', 'updated_at'], 'ue_cache_market_scheme_updated_idx');

        $this->addIndex('products', ['integration_id', 'marketplace', 'fulfillment_type', 'sku'], 'products_integration_market_scheme_sku_idx');
        $this->addIndex('products', ['integration_id', 'marketplace', 'sku'], 'products_integration_market_sku_idx');
        $this->addIndex('products', ['integration_id', 'marketplace', 'barcode'], 'products_integration_market_barcode_idx');
        $this->addIndex('products', ['integration_id', 'marketplace', 'vendor_code'], 'products_integration_market_vendor_idx');
        $this->addIndex('products', ['integration_id', 'marketplace', 'stock'], 'products_integration_market_stock_idx');

        $this->addIndex('inventory_warehouses', ['integration_id', 'marketplace', 'sku', 'fulfillment_type'], 'inventory_integration_market_sku_scheme_idx');
        $this->addIndex('inventory_warehouses', ['integration_id', 'marketplace', 'sku', 'quantity'], 'inventory_integration_market_sku_qty_idx');
        $this->addIndex('inventory_warehouses', ['integration_id', 'marketplace', 'fulfillment_type'], 'inventory_integration_market_scheme_idx');

        $this->addIndex('unit_economics_settings', ['integration_id', 'sku', 'cost_price'], 'ue_settings_integration_sku_cost_idx');

        $this->addIndex('integrations', ['work_space_id', 'id'], 'integrations_workspace_id_idx');
        $this->addActiveAlertsIndex();
    }

    public function down(): void
    {
        $this->dropIndex('unit_economics_cache', 'ue_cache_market_scheme_sku_idx');
        $this->dropIndex('unit_economics_cache', 'ue_cache_market_sku_idx');
        $this->dropIndex('unit_economics_cache', 'ue_cache_market_scheme_price_idx');
        $this->dropIndex('unit_economics_cache', 'ue_cache_market_scheme_updated_idx');

        $this->dropIndex('products', 'products_integration_market_scheme_sku_idx');
        $this->dropIndex('products', 'products_integration_market_sku_idx');
        $this->dropIndex('products', 'products_integration_market_barcode_idx');
        $this->dropIndex('products', 'products_integration_market_vendor_idx');
        $this->dropIndex('products', 'products_integration_market_stock_idx');

        $this->dropIndex('inventory_warehouses', 'inventory_integration_market_sku_scheme_idx');
        $this->dropIndex('inventory_warehouses', 'inventory_integration_market_sku_qty_idx');
        $this->dropIndex('inventory_warehouses', 'inventory_integration_market_scheme_idx');

        $this->dropIndex('unit_economics_settings', 'ue_settings_integration_sku_cost_idx');

        $this->dropIndex('integrations', 'integrations_workspace_id_idx');
        $this->dropActiveAlertsIndex();
    }

    private function addIndex(string $table, array $columns, string $name): void
    {
        if ($this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name) {
            $blueprint->index($columns, $name);
        });
    }

    private function dropIndex(string $table, string $name): void
    {
        if (! $this->indexExists($table, $name)) {
            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("DROP INDEX IF EXISTS {$name}");

            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name) {
            $blueprint->dropIndex($name);
        });
    }

    private function indexExists(string $table, string $name): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list({$table})");

            foreach ($indexes as $index) {
                if (($index->name ?? null) === $name) {
                    return true;
                }
            }

            return false;
        }

        if ($driver === 'pgsql') {
            return DB::table('pg_indexes')
                ->where('tablename', $table)
                ->where('indexname', $name)
                ->exists();
        }

        return count(DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$name])) > 0;
    }

    private function addActiveAlertsIndex(): void
    {
        if (! Schema::hasTable('inventory_alerts') || $this->indexExists('inventory_alerts', 'inventory_alerts_active_sku_idx')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE INDEX inventory_alerts_active_sku_idx ON inventory_alerts (sku) WHERE is_resolved = false');

            return;
        }

        $this->addIndex('inventory_alerts', ['is_resolved', 'sku'], 'inventory_alerts_active_sku_idx');
    }

    private function dropActiveAlertsIndex(): void
    {
        $this->dropIndex('inventory_alerts', 'inventory_alerts_active_sku_idx');
    }
};
