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
            $table->decimal('storage_fee_prev_month', 12, 2)->nullable()->after('storage_fee_report_to');
            $table->string('storage_fee_prev_month_period', 50)->nullable()->after('storage_fee_prev_month');
            $table->decimal('storage_fee_all_time', 12, 2)->nullable()->after('storage_fee_prev_month_period');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_warehouses', function (Blueprint $table) {
            $table->dropColumn(['storage_fee_prev_month', 'storage_fee_prev_month_period', 'storage_fee_all_time']);
        });
    }
};
