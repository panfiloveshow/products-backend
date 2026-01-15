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
            $table->decimal('drr_amount', 12, 2)->nullable()->after('drr_percent')->comment('РК сумма за единицу');
            $table->decimal('our_share_amount', 12, 2)->nullable()->after('our_share_percent')->comment('Наша часть сумма за единицу');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            $table->dropColumn(['drr_amount', 'our_share_amount']);
        });
    }
};
