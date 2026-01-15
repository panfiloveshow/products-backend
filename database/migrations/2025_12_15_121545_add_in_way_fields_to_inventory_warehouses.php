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
            $table->integer('in_way_to_client')->default(0)->after('in_transit');
            $table->integer('in_way_from_client')->default(0)->after('in_way_to_client');
            $table->decimal('cost_price', 10, 2)->nullable()->after('in_way_from_client');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_warehouses', function (Blueprint $table) {
            $table->dropColumn(['in_way_to_client', 'in_way_from_client', 'cost_price']);
        });
    }
};
