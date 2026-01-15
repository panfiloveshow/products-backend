<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Добавляет поле для фактических начислений за хранение от WB
     * storage_cost_total - сумма начислений за период (7 дней)
     * storage_cost_period_days - количество дней в периоде
     */
    public function up(): void
    {
        Schema::table('inventory_warehouses', function (Blueprint $table) {
            // Фактические начисления за хранение от WB (сумма за период)
            $table->decimal('storage_cost_total', 10, 2)->nullable()->after('storage_cost_per_month')
                ->comment('Фактические начисления за хранение от WB за период');
            // Количество дней в периоде начислений
            $table->integer('storage_cost_period_days')->nullable()->after('storage_cost_total')
                ->comment('Количество дней в периоде начислений');
            // Дата последнего обновления данных о хранении
            $table->timestamp('storage_cost_updated_at')->nullable()->after('storage_cost_period_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_warehouses', function (Blueprint $table) {
            $table->dropColumn(['storage_cost_total', 'storage_cost_period_days', 'storage_cost_updated_at']);
        });
    }
};
