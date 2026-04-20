<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ozon_warehouse_clusters', function (Blueprint $table) {
            $table->string('ozon_source', 32)->nullable()->after('is_jewelry');
            $table->timestamp('last_refreshed_at')->nullable()->after('ozon_source');
            $table->json('logistic_clusters')->nullable()->after('last_refreshed_at');
        });
    }

    public function down(): void
    {
        Schema::table('ozon_warehouse_clusters', function (Blueprint $table) {
            $table->dropColumn(['ozon_source', 'last_refreshed_at', 'logistic_clusters']);
        });
    }
};
