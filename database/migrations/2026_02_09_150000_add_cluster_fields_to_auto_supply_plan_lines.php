<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_supply_plan_lines', function (Blueprint $table) {
            $table->integer('cluster_id')->nullable()->after('warehouse_name')->comment('ID кластера доставки Ozon');
            $table->string('cluster_name', 100)->nullable()->after('cluster_id')->comment('Название кластера');
            $table->string('region', 100)->nullable()->after('cluster_name')->comment('Регион/город склада');

            $table->index('cluster_id');
            $table->index(['auto_supply_plan_id', 'cluster_id']);
        });
    }

    public function down(): void
    {
        Schema::table('auto_supply_plan_lines', function (Blueprint $table) {
            $table->dropIndex(['cluster_id']);
            $table->dropIndex(['auto_supply_plan_id', 'cluster_id']);
            $table->dropColumn(['cluster_id', 'cluster_name', 'region']);
        });
    }
};
