<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Хранение Ozon на одну ПРОДАННУЮ единицу: начисления отчёта
            // placement/by-products за календарный месяц ÷ заказы SKU за тот же
            // месяц. storage_cost хранит месячную сумму (окно неоднозначно) —
            // делить её на лету нельзя, поэтому отдельная колонка, посчитанная
            // там, где окно известно точно (SyncStorageCostJob).
            $table->decimal('storage_cost_per_unit', 10, 2)->nullable()->after('storage_cost');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('storage_cost_per_unit');
        });
    }
};
