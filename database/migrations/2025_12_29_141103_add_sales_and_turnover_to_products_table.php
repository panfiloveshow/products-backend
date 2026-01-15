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
        Schema::table('products', function (Blueprint $table) {
            $table->integer('sales_28_days')->default(0)->after('stock');
            $table->decimal('avg_daily_sales', 10, 2)->default(0)->after('sales_28_days');
            $table->decimal('turnover_days', 10, 1)->nullable()->after('avg_daily_sales');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['sales_28_days', 'avg_daily_sales', 'turnover_days']);
        });
    }
};
