<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Удаляем старый уникальный индекс (sku, warehouse_id) — не включал integration_id,
        // что приводило к коллизиям между разными интеграциями
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_warehouses DROP CONSTRAINT IF EXISTS inventory_warehouses_sku_warehouse_id_unique');
        }
        DB::statement('DROP INDEX IF EXISTS inventory_warehouses_sku_warehouse_id_unique');

        // Создаём новый уникальный индекс с учётом integration_id
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS inventory_warehouses_sku_warehouse_id_integration_unique ON inventory_warehouses (sku, warehouse_id, COALESCE(integration_id, -1))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS inventory_warehouses_sku_warehouse_id_integration_unique');

        Schema::table('inventory_warehouses', function (Blueprint $table) {
            $table->unique(['sku', 'warehouse_id']);
        });
    }
};
