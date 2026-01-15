<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            // Среднее время доставки и коэффициенты
            $table->integer('avg_delivery_time_hours')->nullable()->default(29)->after('ozon_compensation');
            $table->decimal('logistics_coefficient', 5, 3)->nullable()->default(1.000)->after('avg_delivery_time_hours');
            $table->decimal('additional_commission_percent', 5, 2)->nullable()->default(0.00)->after('logistics_coefficient');
            $table->decimal('additional_commission_amount', 12, 2)->nullable()->after('additional_commission_percent');
            
            // Детализация логистики
            $table->decimal('base_logistics_cost', 12, 2)->nullable()->after('additional_commission_amount');
            $table->decimal('logistics_with_coefficient', 12, 2)->nullable()->after('base_logistics_cost');
            
            // Обработка возврата
            $table->decimal('return_processing_cost', 12, 2)->nullable()->default(15.00)->after('return_logistics_cost');
        });
    }

    public function down(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            $table->dropColumn([
                'avg_delivery_time_hours',
                'logistics_coefficient',
                'additional_commission_percent',
                'additional_commission_amount',
                'base_logistics_cost',
                'logistics_with_coefficient',
                'return_processing_cost',
            ]);
        });
    }
};
