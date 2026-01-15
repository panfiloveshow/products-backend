<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Добавление полей юнит-экономики WB
 * 
 * Поля:
 * - Габариты: length_mm, width_mm, height_mm, weight_g
 * - Цена покупателя (с учётом СПП)
 * - СПП (скидка постоянного покупателя): spp_percent, spp_amount
 * - КС (коэффициент склада): warehouse_coefficient_percent, warehouse_coefficient_amount
 * - Логистика+КС
 * - Итоговый процент расходов
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            // === ГАБАРИТЫ (для WB) ===
            if (!Schema::hasColumn('unit_economics', 'length_mm')) {
                $table->decimal('length_mm', 10, 2)->nullable()->after('volume_liters')->comment('Длина, мм');
            }
            if (!Schema::hasColumn('unit_economics', 'width_mm')) {
                $table->decimal('width_mm', 10, 2)->nullable()->after('length_mm')->comment('Ширина, мм');
            }
            if (!Schema::hasColumn('unit_economics', 'height_mm')) {
                $table->decimal('height_mm', 10, 2)->nullable()->after('width_mm')->comment('Высота, мм');
            }
            if (!Schema::hasColumn('unit_economics', 'weight_g')) {
                $table->decimal('weight_g', 10, 2)->nullable()->after('height_mm')->comment('Вес, г');
            }
            
            // === НАЦЕНКА (множитель x) ===
            if (!Schema::hasColumn('unit_economics', 'markup_multiplier')) {
                $table->decimal('markup_multiplier', 8, 2)->nullable()->after('markup_percent')->comment('Наценка, x (множитель)');
            }
            
            // === ЦЕНА ПОКУПАТЕЛЯ (с учётом СПП) ===
            if (!Schema::hasColumn('unit_economics', 'customer_price')) {
                $table->decimal('customer_price', 12, 2)->nullable()->after('price')->comment('Цена покупателя (с СПП)');
            }
            
            // === СПП (Скидка постоянного покупателя) ===
            if (!Schema::hasColumn('unit_economics', 'spp_percent')) {
                $table->decimal('spp_percent', 6, 2)->nullable()->after('commission_amount')->comment('СПП, %');
            }
            if (!Schema::hasColumn('unit_economics', 'spp_amount')) {
                $table->decimal('spp_amount', 12, 2)->nullable()->after('spp_percent')->comment('СПП, ₽');
            }
            
            // === КС (Коэффициент склада) ===
            if (!Schema::hasColumn('unit_economics', 'warehouse_coefficient_percent')) {
                $table->decimal('warehouse_coefficient_percent', 6, 2)->nullable()->after('spp_amount')->comment('КС, %');
            }
            if (!Schema::hasColumn('unit_economics', 'warehouse_coefficient_amount')) {
                $table->decimal('warehouse_coefficient_amount', 12, 2)->nullable()->after('warehouse_coefficient_percent')->comment('КС, ₽');
            }
            
            // === ЛОГИСТИКА + КС ===
            if (!Schema::hasColumn('unit_economics', 'logistics_with_warehouse')) {
                $table->decimal('logistics_with_warehouse', 12, 2)->nullable()->after('logistics_cost')->comment('Логистика + КС, ₽');
            }
            
            // === ИТОГОВЫЙ ПРОЦЕНТ РАСХОДОВ ===
            if (!Schema::hasColumn('unit_economics', 'total_expenses_percent')) {
                $table->decimal('total_expenses_percent', 8, 2)->nullable()->after('margin_percent')->comment('Итоговый % расходов');
            }
        });
    }

    public function down(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            $columns = [
                'length_mm',
                'width_mm', 
                'height_mm',
                'weight_g',
                'markup_multiplier',
                'customer_price',
                'spp_percent',
                'spp_amount',
                'warehouse_coefficient_percent',
                'warehouse_coefficient_amount',
                'logistics_with_warehouse',
                'total_expenses_percent',
            ];
            
            foreach ($columns as $column) {
                if (Schema::hasColumn('unit_economics', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
