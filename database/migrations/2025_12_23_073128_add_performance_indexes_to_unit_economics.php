<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            // Составной индекс для быстрой выборки по интеграции и маркетплейсу
            $table->index(['integration_id', 'marketplace'], 'idx_ue_integration_marketplace');
            
            // Индекс для фильтрации по схеме работы
            $table->index(['integration_id', 'fulfillment_type'], 'idx_ue_integration_fulfillment');
            
            // Индекс для поиска по SKU
            $table->index(['integration_id', 'sku'], 'idx_ue_integration_sku');
            
            // Индекс для сортировки по марже
            $table->index(['integration_id', 'marketplace', 'margin_percent'], 'idx_ue_margin');
            
            // Индекс для актуальных схем
            $table->index(['integration_id', 'marketplace', 'is_actual_scheme'], 'idx_ue_actual_scheme');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            $table->dropIndex('idx_ue_integration_marketplace');
            $table->dropIndex('idx_ue_integration_fulfillment');
            $table->dropIndex('idx_ue_integration_sku');
            $table->dropIndex('idx_ue_margin');
            $table->dropIndex('idx_ue_actual_scheme');
        });
    }
};
