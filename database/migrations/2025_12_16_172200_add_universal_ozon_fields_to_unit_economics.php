<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            // Габариты и вес
            $table->decimal('volume_liters', 10, 2)->nullable()->after('fulfillment_type');
            $table->decimal('volume_weight', 10, 2)->nullable()->after('volume_liters');
            $table->decimal('actual_weight', 10, 2)->nullable()->after('volume_weight');
            
            // Логистика (детализация)
            $table->decimal('processing_cost', 12, 2)->nullable()->after('actual_weight'); // Обработка отправления (FBS)
            $table->decimal('logistics_cost', 12, 2)->nullable()->after('processing_cost'); // Логистика до сортировки
            $table->decimal('localization_index', 6, 2)->nullable()->after('logistics_cost'); // Индекс локализации (0-100%)
            
            // Хранение (детализация для FBO)
            $table->integer('turnover_days')->nullable()->after('localization_index'); // Оборачиваемость в днях
            $table->decimal('litrobonus', 12, 2)->nullable()->after('turnover_days'); // Литробонусы (компенсация)
            
            // Возвраты (детализация)
            $table->decimal('return_logistics_cost', 12, 2)->nullable()->after('litrobonus'); // Обратная логистика
            
            // Собственная логистика (для realFBS/DBS)
            $table->decimal('own_delivery_cost', 12, 2)->nullable()->after('return_logistics_cost'); // Своя доставка
            $table->decimal('ozon_compensation', 12, 2)->nullable()->after('own_delivery_cost'); // Компенсация от Ozon
        });
    }

    public function down(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            $table->dropColumn([
                'volume_liters',
                'volume_weight',
                'actual_weight',
                'processing_cost',
                'logistics_cost',
                'localization_index',
                'turnover_days',
                'litrobonus',
                'return_logistics_cost',
                'own_delivery_cost',
                'ozon_compensation',
            ]);
        });
    }
};
