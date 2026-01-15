<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Добавляем поля для данных выкупа из API аналитики (Premium)
     */
    public function up(): void
    {
        Schema::table('unit_economics_cache', function (Blueprint $table) {
            $table->string('redemption_source')->nullable()->after('redemption_rate');
            $table->integer('orders_count')->nullable()->after('redemption_source');
            $table->integer('returns_count')->nullable()->after('orders_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('unit_economics_cache', function (Blueprint $table) {
            $table->dropColumn(['redemption_source', 'orders_count', 'returns_count']);
        });
    }
};
