<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Удаляем старый уникальный индекс на sku
            $table->dropUnique(['sku']);
            
            // Создаём составной уникальный индекс (sku + marketplace)
            // Это позволит хранить один SKU для разных маркетплейсов
            $table->unique(['sku', 'marketplace'], 'products_sku_marketplace_unique');
            
            // Добавляем индекс на marketplace_id для быстрого поиска
            $table->index(['marketplace', 'marketplace_id'], 'products_marketplace_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_sku_marketplace_unique');
            $table->dropIndex('products_marketplace_id_index');
            $table->unique('sku');
        });
    }
};
