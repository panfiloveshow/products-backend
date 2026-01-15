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
            $table->integer('sales_28_days')->default(0)->after('quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_warehouses', function (Blueprint $table) {
            $table->dropColumn('sales_28_days');
        });
    }
};
