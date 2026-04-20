<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_history', function (Blueprint $table) {
            $table->unsignedBigInteger('integration_id')->nullable()->after('id');
            $table->index(['integration_id', 'sku', 'date'], 'inventory_history_integration_sku_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_history', function (Blueprint $table) {
            $table->dropIndex('inventory_history_integration_sku_date_idx');
            $table->dropColumn('integration_id');
        });
    }
};
