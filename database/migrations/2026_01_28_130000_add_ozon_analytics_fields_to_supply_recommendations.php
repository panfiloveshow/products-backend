<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Добавляем поля для данных Ozon аналитики по доставке
     * 
     * Эти данные берутся из API Ozon:
     * - POST /v1/analytics/average-delivery-time/details
     * - POST /v1/cluster/list
     */
    public function up(): void
    {
        Schema::table('supply_recommendations', function (Blueprint $table) {
            // Рекомендации Ozon
            $table->integer('ozon_recommended_supply')->nullable()->comment('Рекомендуемая поставка от Ozon');
            $table->decimal('ozon_lost_profit', 12, 2)->nullable()->comment('Упущенная прибыль из-за долгой доставки');
            $table->integer('ozon_avg_delivery_time')->nullable()->comment('Среднее время доставки в часах');
            $table->string('ozon_attention_level', 20)->nullable()->comment('Уровень внимания: LOW, ATTENTION_MEDIUM, ATTENTION_HI');
            $table->decimal('ozon_impact_share', 8, 4)->nullable()->comment('Доля влияния на общий показатель');
            
            // Кластер доставки (куда доставляется товар)
            $table->integer('delivery_cluster_id')->nullable()->comment('ID кластера доставки Ozon');
            $table->string('delivery_cluster_name', 100)->nullable()->comment('Название кластера доставки');
            
            // Детальные данные по всем кластерам (JSON)
            $table->json('ozon_clusters_data')->nullable()->comment('Данные по всем кластерам доставки');
        });
    }

    public function down(): void
    {
        Schema::table('supply_recommendations', function (Blueprint $table) {
            $table->dropColumn([
                'ozon_recommended_supply',
                'ozon_lost_profit',
                'ozon_avg_delivery_time',
                'ozon_attention_level',
                'ozon_impact_share',
                'delivery_cluster_id',
                'delivery_cluster_name',
                'ozon_clusters_data',
            ]);
        });
    }
};
