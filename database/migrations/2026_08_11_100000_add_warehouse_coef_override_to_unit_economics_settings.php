<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ручной КС (коэффициент склада) WB на уровне SKU.
 *
 * Авто-КС берётся из складов товара (inventory_warehouses.warehouse_coefficient).
 * На FBS у складов продавца коэффициента WB нет, поэтому там всегда выходит 100%
 * — менеджеру нужна возможность поставить реальный коэффициент руками.
 * null = авто.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_economics_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('unit_economics_settings', 'warehouse_coef_percent_override')) {
                $table->decimal('warehouse_coef_percent_override', 6, 2)
                    ->nullable()
                    ->after('spp_percent')
                    ->comment('Ручной КС WB, % (100 = 1.0). null = авто');
            }

            // Платная приёмка на единицу: WB её в юнит-экономику не отдаёт,
            // фактическая сумма есть только в еженедельном отчёте реализации.
            if (! Schema::hasColumn('unit_economics_settings', 'acceptance_cost')) {
                $table->decimal('acceptance_cost', 12, 2)
                    ->nullable()
                    ->after('warehouse_coef_percent_override')
                    ->comment('Платная приёмка на единицу, ₽ (ручной ввод)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('unit_economics_settings', function (Blueprint $table) {
            if (Schema::hasColumn('unit_economics_settings', 'warehouse_coef_percent_override')) {
                $table->dropColumn('warehouse_coef_percent_override');
            }

            if (Schema::hasColumn('unit_economics_settings', 'acceptance_cost')) {
                $table->dropColumn('acceptance_cost');
            }
        });
    }
};
