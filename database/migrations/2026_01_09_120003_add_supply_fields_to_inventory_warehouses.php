<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_warehouses', function (Blueprint $table) {
            $table->integer('target_days_of_stock')->nullable()->after('days_of_stock');
            $table->integer('safety_stock_days')->nullable()->after('target_days_of_stock');
            $table->integer('reorder_point')->nullable()->after('safety_stock_days');
            $table->integer('lead_time_days')->nullable()->after('reorder_point');
            $table->integer('optimal_order_quantity')->nullable()->after('lead_time_days');
            $table->timestamp('last_recommendation_at')->nullable()->after('last_restock_date');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_warehouses', function (Blueprint $table) {
            $table->dropColumn([
                'target_days_of_stock',
                'safety_stock_days',
                'reorder_point',
                'lead_time_days',
                'optimal_order_quantity',
                'last_recommendation_at',
            ]);
        });
    }
};
