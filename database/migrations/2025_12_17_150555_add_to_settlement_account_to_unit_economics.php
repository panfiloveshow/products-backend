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
        Schema::table('unit_economics', function (Blueprint $table) {
            $table->decimal('to_settlement_account', 12, 2)->nullable()->after('net_profit_per_unit')
                ->comment('На РС: Цена - Все затраты - РК (за единицу)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            $table->dropColumn('to_settlement_account');
        });
    }
};
