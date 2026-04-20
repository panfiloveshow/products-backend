<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Обогащение строк плана метриками локальности и полями cluster split.
 * Все поля nullable — обратная совместимость с существующими планами.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_supply_plan_lines', function (Blueprint $table) {
            // Метрики локальности на момент построения плана (snapshot)
            $table->decimal('local_share_percent', 5, 2)->nullable()->after('risk_level')
                ->comment('% локальных продаж SKU на момент плана (из LocalityMetricDaily)');
            $table->decimal('potential_overpayment_rub', 14, 2)->nullable()->after('local_share_percent')
                ->comment('Потенциальная переплата за 28 дней по таблице Ozon');
            $table->decimal('lost_margin_rub', 14, 2)->nullable()->after('potential_overpayment_rub')
                ->comment('Потеря маржи (переплата + дельта базового тарифа)');
            $table->decimal('expected_local_share_after_pp', 5, 2)->nullable()->after('lost_margin_rub')
                ->comment('Прогноз прироста локальности pp от применения плана');
            $table->decimal('expected_savings_rub', 14, 2)->nullable()->after('expected_local_share_after_pp')
                ->comment('Ожидаемая экономия 28 дн. от покрытия LocalityRecommendation');
            $table->string('locality_confidence', 16)->nullable()->after('expected_savings_rub')
                ->comment('low/medium/high — уверенность расчёта локальности');
            $table->json('cluster_split_json')->nullable()->after('locality_confidence')
                ->comment('Массив {cluster_id, cluster_name, qty, expected_savings_rub, rec_id}');
            $table->json('linked_locality_recommendation_ids')->nullable()->after('cluster_split_json')
                ->comment('ID рекомендаций Locality, покрытых этой строкой');

            // Cluster split: родительский ключ и флаги
            $table->string('parent_line_key', 64)->nullable()->after('linked_locality_recommendation_ids')
                ->comment('sku:warehouse_id — объединяет split-строки одного SKU');
            $table->boolean('is_cluster_split')->default(false)->after('parent_line_key')
                ->comment('true если строка — одна из split-child');
            $table->integer('aggregated_qty_rounded')->nullable()->after('is_cluster_split')
                ->comment('Σ qty_rounded всех child-строк SKU — для UI сворачивания');

            $table->index('parent_line_key', 'aspl_parent_line_key_idx');
            $table->index(['auto_supply_plan_id', 'local_share_percent'], 'aspl_plan_local_share_idx');
        });
    }

    public function down(): void
    {
        Schema::table('auto_supply_plan_lines', function (Blueprint $table) {
            $table->dropIndex('aspl_parent_line_key_idx');
            $table->dropIndex('aspl_plan_local_share_idx');
            $table->dropColumn([
                'local_share_percent',
                'potential_overpayment_rub',
                'lost_margin_rub',
                'expected_local_share_after_pp',
                'expected_savings_rub',
                'locality_confidence',
                'cluster_split_json',
                'linked_locality_recommendation_ids',
                'parent_line_key',
                'is_cluster_split',
                'aggregated_qty_rounded',
            ]);
        });
    }
};
