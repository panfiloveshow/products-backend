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
            $table->unsignedBigInteger('integration_id')->nullable()->after('warehouse_name');
            $table->index('integration_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_warehouses', function (Blueprint $table) {
            $table->dropIndex(['integration_id']);
            $table->dropColumn('integration_id');
        });
    }
};
