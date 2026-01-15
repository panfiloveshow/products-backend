<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Добавить составные индексы для оптимизации производительности
     */
    public function up(): void
    {
        Schema::table('unit_economics_cache', function (Blueprint $table) {
            // Составной индекс для основной фильтрации (integration + marketplace + scheme)
            $table->index(
                ['integration_id', 'marketplace', 'fulfillment_type', 'net_profit'],
                'ue_cache_filter_idx'
            );
            
            // Индекс для сортировки по марже
            $table->index(
                ['integration_id', 'marketplace', 'fulfillment_type', 'margin_percent'],
                'ue_cache_margin_idx'
            );
            
            // Индекс для поиска по SKU
            $table->index(['sku'], 'ue_cache_sku_idx');
        });
        
        // Индексы для unit_economics (используется в getActualScheme)
        Schema::table('unit_economics', function (Blueprint $table) {
            $table->index(
                ['integration_id', 'marketplace', 'is_actual_scheme'],
                'ue_actual_scheme_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('unit_economics_cache', function (Blueprint $table) {
            $table->dropIndex('ue_cache_filter_idx');
            $table->dropIndex('ue_cache_margin_idx');
            $table->dropIndex('ue_cache_sku_idx');
        });
        
        Schema::table('unit_economics', function (Blueprint $table) {
            $table->dropIndex('ue_actual_scheme_idx');
        });
    }
};
