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
        Schema::table('unit_economics_cache', function (Blueprint $table) {
            $table->boolean('is_in_promotion')->default(false)->after('sales_count');
            $table->decimal('promotion_discount', 5, 2)->default(0)->after('is_in_promotion');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('unit_economics_cache', function (Blueprint $table) {
            $table->dropColumn(['is_in_promotion', 'promotion_discount']);
        });
    }
};
