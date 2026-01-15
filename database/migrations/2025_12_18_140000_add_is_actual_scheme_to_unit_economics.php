<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Добавляем поле is_actual_scheme для фиксации фактической схемы работы товара.
     * Теперь для каждого товара создаются записи для всех схем (FBO, FBS, realFBS, Express),
     * но только одна из них помечена как фактическая.
     */
    public function up(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            // Флаг фактической схемы работы (true = товар реально работает по этой схеме)
            $table->boolean('is_actual_scheme')->default(false)->after('fulfillment_type');
            
            // Добавляем индекс для быстрого поиска по схеме
            $table->index(['integration_id', 'sku', 'fulfillment_type'], 'unit_economics_scheme_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            $table->dropIndex('unit_economics_scheme_idx');
            $table->dropColumn('is_actual_scheme');
        });
    }
};
