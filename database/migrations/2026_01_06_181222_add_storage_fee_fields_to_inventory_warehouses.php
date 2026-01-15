<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Добавляет поля для ФАКТИЧЕСКИХ начислений за хранение из еженедельных отчётов WB
     * Это реальные суммы (storage_fee), которые WB начислил к оплате
     */
    public function up(): void
    {
        Schema::table('inventory_warehouses', function (Blueprint $table) {
            // Фактические начисления за хранение из отчётов реализации WB
            $table->decimal('storage_fee_total', 12, 2)->nullable()->after('storage_cost_updated_at')
                ->comment('Фактические начисления за хранение из отчётов WB (сумма за 4 недели)');
            $table->decimal('storage_fee_last_week', 10, 2)->nullable()->after('storage_fee_total')
                ->comment('Начисления за последнюю неделю');
            $table->date('storage_fee_report_from')->nullable()->after('storage_fee_last_week')
                ->comment('Начало периода отчёта');
            $table->date('storage_fee_report_to')->nullable()->after('storage_fee_report_from')
                ->comment('Конец периода отчёта');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_warehouses', function (Blueprint $table) {
            $table->dropColumn([
                'storage_fee_total',
                'storage_fee_last_week', 
                'storage_fee_report_from',
                'storage_fee_report_to'
            ]);
        });
    }
};
