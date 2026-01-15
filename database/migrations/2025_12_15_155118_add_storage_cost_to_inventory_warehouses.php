<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inventory_warehouses', function (Blueprint $table) {
            $table->decimal('storage_cost_per_day', 10, 2)->nullable()->after('stock_status');
            $table->decimal('storage_cost_per_month', 10, 2)->nullable()->after('storage_cost_per_day');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_warehouses', function (Blueprint $table) {
            $table->dropColumn(['storage_cost_per_day', 'storage_cost_per_month']);
        });
    }
};
