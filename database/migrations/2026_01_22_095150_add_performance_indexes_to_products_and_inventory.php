<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Добавляем индексы для оптимизации запросов к products и inventory_warehouses
     */
    public function up(): void
    {
        // Индексы для products
        Schema::table('products', function (Blueprint $table) {
            // Составной индекс для фильтрации по integration_id + marketplace (самый частый запрос)
            $table->index(['integration_id', 'marketplace'], 'idx_products_integration_marketplace');
            
            // Индекс для сортировки по created_at (используется по умолчанию)
            $table->index(['integration_id', 'created_at'], 'idx_products_integration_created');
            
            // Индекс для фильтрации по stock (in_stock фильтр)
            $table->index(['integration_id', 'stock'], 'idx_products_integration_stock');
        });

        // Индексы для inventory_warehouses
        Schema::table('inventory_warehouses', function (Blueprint $table) {
            // Составной индекс для связи с products (sku + integration_id)
            $table->index(['sku', 'integration_id'], 'idx_inventory_sku_integration');
            
            // Индекс для фильтрации по складу
            $table->index(['integration_id', 'warehouse_name'], 'idx_inventory_integration_warehouse');
            
            // Индекс для фильтрации по marketplace
            $table->index(['marketplace', 'integration_id'], 'idx_inventory_marketplace_integration');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_integration_marketplace');
            $table->dropIndex('idx_products_integration_created');
            $table->dropIndex('idx_products_integration_stock');
        });

        Schema::table('inventory_warehouses', function (Blueprint $table) {
            $table->dropIndex('idx_inventory_sku_integration');
            $table->dropIndex('idx_inventory_integration_warehouse');
            $table->dropIndex('idx_inventory_marketplace_integration');
        });
    }
};
