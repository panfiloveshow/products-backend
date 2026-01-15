<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            // Стоимость за единицу товара
            $table->decimal('logistics_per_unit', 12, 2)->nullable()->after('logistics_cost');
            $table->decimal('last_mile_per_unit', 12, 2)->nullable()->default(25.00)->after('last_mile_cost');
            $table->decimal('commission_per_unit', 12, 2)->nullable()->after('commission_amount');
            $table->decimal('acquiring_per_unit', 12, 2)->nullable()->after('acquiring_amount');
            $table->decimal('storage_per_unit', 12, 2)->nullable()->after('storage_cost');
            $table->decimal('total_costs_per_unit', 12, 2)->nullable()->after('total_costs');
            $table->decimal('net_profit_per_unit', 12, 2)->nullable()->after('net_profit');
        });
    }

    public function down(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            $table->dropColumn([
                'logistics_per_unit',
                'last_mile_per_unit',
                'commission_per_unit',
                'acquiring_per_unit',
                'storage_per_unit',
                'total_costs_per_unit',
                'net_profit_per_unit',
            ]);
        });
    }
};
