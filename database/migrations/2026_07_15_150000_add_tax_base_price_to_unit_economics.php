<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Налоговая база на единицу для Ozon: фактическая средняя цена продажи
 * («реализовано со скидкой» из отчёта о реализации). Ozon ставит доп. скидки
 * за счёт продавца («Баллы за скидки»), и налог УСН считается от фактической
 * цены продажи, а не от цены продавца.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            $table->decimal('tax_base_price', 12, 2)->nullable()->after('tax_amount');
            $table->string('tax_base_source', 64)->nullable()->after('tax_base_price');
        });
    }

    public function down(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            $table->dropColumn(['tax_base_price', 'tax_base_source']);
        });
    }
};
