<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Таблица рекомендаций на поставку
 * 
 * Хранит автоматически рассчитанные рекомендации по пополнению запасов
 * на основе продаж, остатков и целевых дней покрытия
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supply_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->onDelete('cascade');
            
            // Товар
            $table->foreignId('product_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('sku', 100)->index();
            $table->string('ozon_product_id', 50)->nullable();
            $table->string('product_name', 500)->nullable();
            
            // Назначение
            $table->string('cluster_id', 50)->nullable()->index();
            $table->string('cluster_name', 200)->nullable();
            $table->string('warehouse_id', 50)->nullable()->index();
            $table->string('warehouse_name', 200)->nullable();
            
            // Расчётные данные
            $table->decimal('avg_sales_7d', 12, 4)->default(0)->comment('Средние продажи за 7 дней');
            $table->decimal('avg_sales_14d', 12, 4)->default(0)->comment('Средние продажи за 14 дней');
            $table->decimal('avg_sales_28d', 12, 4)->default(0)->comment('Средние продажи за 28 дней');
            $table->decimal('avg_sales_used', 12, 4)->default(0)->comment('Использованное значение продаж');
            $table->string('sales_window', 10)->default('14d')->comment('Окно продаж: 7d/14d/28d');
            
            $table->integer('current_stock')->default(0)->comment('Текущий остаток FBO');
            $table->integer('in_transit')->default(0)->comment('В пути (созданные поставки)');
            $table->integer('safety_stock')->default(0)->comment('Страховой запас');
            $table->integer('target_days')->default(14)->comment('Целевые дни покрытия');
            
            // Результат расчёта
            $table->integer('demand')->default(0)->comment('Расчётный спрос');
            $table->integer('need_raw')->default(0)->comment('Потребность до округления');
            $table->integer('recommended_qty')->default(0)->comment('Рекомендуемое кол-во (с кратностью)');
            $table->integer('pack_multiple')->default(1)->comment('Кратность упаковки');
            $table->integer('min_order_qty')->default(1)->comment('Минимальная партия');
            
            // Приоритет и риски
            $table->enum('priority', ['A', 'B', 'C'])->default('B')->comment('ABC приоритет');
            $table->decimal('priority_score', 8, 2)->default(0)->comment('Числовой скор приоритета');
            $table->integer('days_of_stock')->default(0)->comment('Дней запаса при текущих продажах');
            $table->boolean('oos_risk')->default(false)->comment('Риск OOS');
            $table->boolean('overstock_risk')->default(false)->comment('Риск перезатаривания');
            
            // Причины и флаги
            $table->json('reasons')->nullable()->comment('Факторы расчёта');
            $table->json('warnings')->nullable()->comment('Предупреждения');
            $table->json('restrictions')->nullable()->comment('Ограничения');
            
            // Рекомендованные даты
            $table->date('recommended_create_date')->nullable()->comment('Рекомендуемая дата создания заявки');
            $table->date('recommended_delivery_date')->nullable()->comment('Рекомендуемая дата доставки');
            $table->integer('lead_time_days')->default(3)->comment('Lead time в днях');
            
            // Статус рекомендации
            $table->enum('state', [
                'new',           // Новая рекомендация
                'accepted',      // Принята
                'rejected',      // Отклонена
                'postponed',     // Отложена
                'in_plan',       // Добавлена в план
                'in_supply',     // В поставке
                'completed',     // Выполнена
                'expired',       // Устарела
            ])->default('new')->index();
            
            // Действия пользователя
            $table->integer('user_qty')->nullable()->comment('Кол-во, изменённое пользователем');
            $table->text('user_comment')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            
            // Связь с планом/поставкой
            $table->foreignId('supply_plan_id')->nullable()->constrained('supply_plans')->nullOnDelete();
            $table->unsignedBigInteger('supply_id')->nullable()->index();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Индексы
            $table->index(['integration_id', 'state']);
            $table->index(['integration_id', 'sku', 'cluster_id']);
            $table->index(['oos_risk', 'priority']);
            $table->index('recommended_create_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_recommendations');
    }
};
