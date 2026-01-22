<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Добавление поддержки macrolocal_cluster_id для Ozon FBO поставок
 * 
 * С 16 февраля 2026 Ozon переходит на кластерную модель:
 * - warehouse_id остаётся для прямых поставок
 * - macrolocal_cluster_id используется для кросс-докинга
 * 
 * @see https://dev.ozon.ru/news/647-Izmeneniia-v-metodakh-Seller-API-pri-rabote-s-postavkami-FBO/
 */
return new class extends Migration
{
    public function up(): void
    {
        // Добавляем в shipments
        if (Schema::hasTable('shipments') && !Schema::hasColumn('shipments', 'macrolocal_cluster_id')) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->string('macrolocal_cluster_id', 50)->nullable()->after('warehouse_name')
                    ->comment('ID макролокального кластера Ozon (для кросс-дока)');
                $table->string('cluster_name', 200)->nullable()->after('macrolocal_cluster_id')
                    ->comment('Название кластера Ozon');
                $table->enum('supply_method', ['direct', 'crossdock', 'multi_cluster'])->nullable()->after('shipment_type')
                    ->comment('Метод поставки: прямая, кросс-док, мультикластерная');
                $table->enum('delivery_scheme', ['drop_off', 'pick_up'])->nullable()->after('supply_method')
                    ->comment('Схема доставки для кросс-дока: Drop Off или Pick Up');
                
                $table->index('macrolocal_cluster_id');
            });
        }

        // Добавляем в supply_plans
        if (Schema::hasTable('supply_plans') && !Schema::hasColumn('supply_plans', 'macrolocal_cluster_id')) {
            Schema::table('supply_plans', function (Blueprint $table) {
                $table->string('macrolocal_cluster_id', 50)->nullable()->after('marketplace')
                    ->comment('ID макролокального кластера Ozon');
                $table->string('cluster_name', 200)->nullable()->after('macrolocal_cluster_id')
                    ->comment('Название кластера Ozon');
                $table->string('warehouse_id', 50)->nullable()->after('cluster_name')
                    ->comment('ID склада (для прямых поставок)');
                $table->string('warehouse_name', 200)->nullable()->after('warehouse_id')
                    ->comment('Название склада');
                $table->enum('supply_method', ['direct', 'crossdock', 'multi_cluster'])->nullable()->after('warehouse_name')
                    ->comment('Метод поставки');
                $table->enum('delivery_scheme', ['drop_off', 'pick_up'])->nullable()->after('supply_method')
                    ->comment('Схема доставки для кросс-дока');
                
                $table->index('macrolocal_cluster_id');
            });
        }

        // Добавляем в warehouse_slots если есть
        if (Schema::hasTable('warehouse_slots') && !Schema::hasColumn('warehouse_slots', 'macrolocal_cluster_id')) {
            Schema::table('warehouse_slots', function (Blueprint $table) {
                $table->string('macrolocal_cluster_id', 50)->nullable()->after('warehouse_id')
                    ->comment('ID макролокального кластера Ozon');
                $table->string('cluster_name', 200)->nullable()->after('macrolocal_cluster_id')
                    ->comment('Название кластера Ozon');
                
                $table->index('macrolocal_cluster_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('shipments')) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->dropIndex(['macrolocal_cluster_id']);
                $table->dropColumn([
                    'macrolocal_cluster_id',
                    'cluster_name',
                    'supply_method',
                    'delivery_scheme',
                ]);
            });
        }

        if (Schema::hasTable('supply_plans')) {
            Schema::table('supply_plans', function (Blueprint $table) {
                $table->dropIndex(['macrolocal_cluster_id']);
                $table->dropColumn([
                    'macrolocal_cluster_id',
                    'cluster_name',
                    'warehouse_id',
                    'warehouse_name',
                    'supply_method',
                    'delivery_scheme',
                ]);
            });
        }

        if (Schema::hasTable('warehouse_slots')) {
            Schema::table('warehouse_slots', function (Blueprint $table) {
                $table->dropIndex(['macrolocal_cluster_id']);
                $table->dropColumn([
                    'macrolocal_cluster_id',
                    'cluster_name',
                ]);
            });
        }
    }
};
