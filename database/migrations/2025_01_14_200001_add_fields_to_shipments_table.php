<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            // Добавляем новые поля если их нет
            if (!Schema::hasColumn('shipments', 'integration_id')) {
                $table->uuid('integration_id')->nullable()->after('supply_plan_id');
            }
            
            if (!Schema::hasColumn('shipments', 'warehouse_id')) {
                $table->string('warehouse_id')->nullable()->after('integration_id');
            }
            
            if (!Schema::hasColumn('shipments', 'meta')) {
                $table->json('meta')->nullable()->after('logistics_approval');
            }
            
            if (!Schema::hasColumn('shipments', 'planned_date')) {
                $table->date('planned_date')->nullable()->after('warehouse_name');
            }

            // Индексы
            if (!Schema::hasIndex('shipments', 'shipments_integration_id_index')) {
                $table->index('integration_id');
            }
            
            // external_supply_id индекс только если колонка существует
            if (Schema::hasColumn('shipments', 'external_supply_id') && !Schema::hasIndex('shipments', 'shipments_external_supply_id_index')) {
                $table->index('external_supply_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['integration_id', 'warehouse_id', 'meta', 'planned_date']);
        });
    }
};
