<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_warehouses', function (Blueprint $table) {
            $table->integer('days_in_stock_30')->default(30)->after('turnover_days');
            $table->decimal('effective_daily_sales', 10, 2)->nullable()->after('days_in_stock_30');
            $table->decimal('effective_turnover_days', 10, 1)->nullable()->after('effective_daily_sales');
            $table->date('last_stockout_date')->nullable()->after('effective_turnover_days');
            $table->date('last_restock_date')->nullable()->after('last_stockout_date');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_warehouses', function (Blueprint $table) {
            $table->dropColumn([
                'days_in_stock_30',
                'effective_daily_sales',
                'effective_turnover_days',
                'last_stockout_date',
                'last_restock_date',
            ]);
        });
    }
};
