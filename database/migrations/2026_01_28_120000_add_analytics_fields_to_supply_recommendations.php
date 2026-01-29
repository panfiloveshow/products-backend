<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Добавляет расширенные аналитические поля для рекомендаций на поставку
 * 
 * Новые метрики позволяют:
 * - Отслеживать тренд продаж (рост/падение)
 * - Рассчитывать упущенную выручку из-за OOS
 * - Прогнозировать дату окончания запасов
 * - Использовать динамический страховой запас
 * - Оценивать ROI поставки
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supply_recommendations', function (Blueprint $table) {
            // === ТРЕНД ПРОДАЖ ===
            // Показывает динамику: растут продажи или падают
            // Используется для корректировки прогноза
            if (!Schema::hasColumn('supply_recommendations', 'sales_trend')) {
                $table->string('sales_trend', 20)->nullable()->after('avg_sales_used')
                    ->comment('Тренд продаж: growing, stable, declining');
            }
            if (!Schema::hasColumn('supply_recommendations', 'sales_trend_percent')) {
                $table->decimal('sales_trend_percent', 8, 2)->nullable()->after('sales_trend')
                    ->comment('Изменение продаж в % (7d vs 14d)');
            }
            
            // === ПРОГНОЗ OOS ===
            // Дата когда закончится товар при текущих продажах
            if (!Schema::hasColumn('supply_recommendations', 'oos_date')) {
                $table->date('oos_date')->nullable()->after('oos_risk')
                    ->comment('Прогнозируемая дата OOS');
            }
            if (!Schema::hasColumn('supply_recommendations', 'days_until_oos')) {
                $table->integer('days_until_oos')->nullable()->after('oos_date')
                    ->comment('Дней до OOS (с учётом товаров в пути)');
            }
            
            // === УПУЩЕННАЯ ВЫРУЧКА ===
            // Сколько денег теряем из-за отсутствия товара
            if (!Schema::hasColumn('supply_recommendations', 'lost_revenue_daily')) {
                $table->decimal('lost_revenue_daily', 12, 2)->nullable()->after('days_until_oos')
                    ->comment('Упущенная выручка в день при OOS (руб)');
            }
            if (!Schema::hasColumn('supply_recommendations', 'lost_revenue_potential')) {
                $table->decimal('lost_revenue_potential', 12, 2)->nullable()->after('lost_revenue_daily')
                    ->comment('Потенциальная упущенная выручка до поставки (руб)');
            }
            
            // === ДИНАМИЧЕСКИЙ СТРАХОВОЙ ЗАПАС ===
            // Рассчитывается на основе волатильности продаж
            if (!Schema::hasColumn('supply_recommendations', 'sales_volatility')) {
                $table->decimal('sales_volatility', 8, 4)->nullable()->after('safety_stock')
                    ->comment('Волатильность продаж (коэф. вариации)');
            }
            if (!Schema::hasColumn('supply_recommendations', 'safety_stock_dynamic')) {
                $table->integer('safety_stock_dynamic')->nullable()->after('sales_volatility')
                    ->comment('Динамический страховой запас (шт)');
            }
            
            // === ROI ПОСТАВКИ ===
            // Окупаемость с учётом всех затрат
            if (!Schema::hasColumn('supply_recommendations', 'supply_cost_estimate')) {
                $table->decimal('supply_cost_estimate', 12, 2)->nullable()->after('lost_revenue_potential')
                    ->comment('Оценка стоимости поставки (руб)');
            }
            if (!Schema::hasColumn('supply_recommendations', 'expected_revenue')) {
                $table->decimal('expected_revenue', 12, 2)->nullable()->after('supply_cost_estimate')
                    ->comment('Ожидаемая выручка от поставки (руб)');
            }
            if (!Schema::hasColumn('supply_recommendations', 'expected_profit')) {
                $table->decimal('expected_profit', 12, 2)->nullable()->after('expected_revenue')
                    ->comment('Ожидаемая прибыль от поставки (руб)');
            }
            if (!Schema::hasColumn('supply_recommendations', 'roi_percent')) {
                $table->decimal('roi_percent', 8, 2)->nullable()->after('expected_profit')
                    ->comment('ROI поставки в %');
            }
            
            // === ЦЕНА И МАРЖА ===
            if (!Schema::hasColumn('supply_recommendations', 'price')) {
                $table->decimal('price', 12, 2)->nullable()->after('product_name')
                    ->comment('Цена продажи (руб)');
            }
            if (!Schema::hasColumn('supply_recommendations', 'cost_price')) {
                $table->decimal('cost_price', 12, 2)->nullable()->after('price')
                    ->comment('Себестоимость (руб)');
            }
            if (!Schema::hasColumn('supply_recommendations', 'margin_percent')) {
                $table->decimal('margin_percent', 8, 2)->nullable()->after('cost_price')
                    ->comment('Маржинальность в %');
            }
            
            // === ОПИСАНИЯ ДЛЯ FRONTEND ===
            // JSON с человекочитаемыми описаниями всех метрик
            if (!Schema::hasColumn('supply_recommendations', 'explanations')) {
                $table->json('explanations')->nullable()->after('reasons')
                    ->comment('Описания метрик для пользователя');
            }
        });
    }

    public function down(): void
    {
        Schema::table('supply_recommendations', function (Blueprint $table) {
            $columns = [
                'sales_trend', 'sales_trend_percent',
                'oos_date', 'days_until_oos',
                'lost_revenue_daily', 'lost_revenue_potential',
                'sales_volatility', 'safety_stock_dynamic',
                'supply_cost_estimate', 'expected_revenue', 'expected_profit', 'roi_percent',
                'price', 'cost_price', 'margin_percent',
                'explanations',
            ];
            
            foreach ($columns as $column) {
                if (Schema::hasColumn('supply_recommendations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
