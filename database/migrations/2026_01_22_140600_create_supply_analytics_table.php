<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Таблица аналитики поставок
 * 
 * Агрегированные метрики для оценки качества планирования и исполнения
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supply_analytics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->onDelete('cascade');
            
            // Период
            $table->date('date')->index();
            $table->enum('period_type', ['daily', 'weekly', 'monthly'])->default('daily');
            
            // Привязка (опционально)
            $table->string('cluster_id', 50)->nullable()->index();
            $table->string('warehouse_id', 50)->nullable()->index();
            $table->string('sku', 100)->nullable()->index();
            
            // === Метрики планирования ===
            
            // Рекомендации
            $table->integer('recommendations_generated')->default(0);
            $table->integer('recommendations_accepted')->default(0);
            $table->integer('recommendations_rejected')->default(0);
            $table->integer('recommendations_expired')->default(0);
            $table->decimal('fill_rate', 5, 2)->nullable()->comment('% принятых рекомендаций');
            
            // OOS
            $table->integer('oos_skus_count')->default(0)->comment('Кол-во SKU в OOS');
            $table->decimal('oos_rate', 5, 2)->nullable()->comment('% SKU в OOS');
            $table->decimal('oos_revenue_lost', 15, 2)->nullable()->comment('Потерянная выручка от OOS');
            
            // Overstock
            $table->integer('overstock_skus_count')->default(0);
            $table->decimal('overstock_rate', 5, 2)->nullable();
            $table->decimal('overstock_value', 15, 2)->nullable()->comment('Стоимость излишков');
            
            // Точность прогноза
            $table->decimal('forecast_accuracy', 5, 2)->nullable()->comment('MAPE прогноза');
            $table->decimal('demand_vs_actual', 8, 2)->nullable()->comment('Прогноз / Факт');
            
            // === Метрики исполнения ===
            
            // Поставки
            $table->integer('supplies_created')->default(0);
            $table->integer('supplies_completed')->default(0);
            $table->integer('supplies_cancelled')->default(0);
            $table->integer('supplies_with_errors')->default(0);
            
            // Слоты
            $table->integer('slots_booked')->default(0);
            $table->integer('slots_changed')->default(0);
            $table->integer('slots_missed')->default(0);
            
            // Lead time
            $table->decimal('avg_lead_time_days', 5, 2)->nullable();
            $table->decimal('planned_vs_actual_lead_time', 5, 2)->nullable();
            
            // Приёмка
            $table->integer('items_shipped')->default(0);
            $table->integer('items_accepted')->default(0);
            $table->integer('items_rejected')->default(0);
            $table->decimal('acceptance_rate', 5, 2)->nullable()->comment('% принятых');
            $table->integer('discrepancies_count')->default(0);
            
            // SLA
            $table->decimal('sla_on_time_rate', 5, 2)->nullable()->comment('% вовремя');
            $table->integer('sla_violations')->default(0);
            
            // === Финансовые метрики ===
            $table->decimal('total_supplied_value', 15, 2)->nullable()->comment('Стоимость поставленного');
            $table->decimal('logistics_cost', 15, 2)->nullable()->comment('Затраты на логистику');
            
            $table->timestamps();
            
            // Уникальность
            $table->unique(
                ['integration_id', 'date', 'period_type', 'cluster_id', 'warehouse_id', 'sku'],
                'supply_analytics_unique'
            );
            
            // Индексы
            $table->index(['integration_id', 'date', 'period_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_analytics');
    }
};
