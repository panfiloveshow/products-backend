<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_slots', function (Blueprint $table) {
            $table->unsignedBigInteger('booked_by_supply_id')->nullable()->after('booked_by_shipment_id');
            
            $table->index('booked_by_supply_id');
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_slots', function (Blueprint $table) {
            $table->dropIndex(['booked_by_supply_id']);
            $table->dropColumn('booked_by_supply_id');
        });
    }
};
