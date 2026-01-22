<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Таблица настроек поставок
 * 
 * Хранит правила расчёта рекомендаций, предпочтения слотов,
 * ограничения и уведомления для каждой интеграции
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supply_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->onDelete('cascade');
            
            // === Правила рекомендаций ===
            
            // Окно продаж по умолчанию
            $table->enum('default_sales_window', ['7d', '14d', '28d'])->default('14d');
            
            // Целевые дни покрытия по ABC
            $table->integer('target_days_a')->default(21)->comment('Дни покрытия для категории A');
            $table->integer('target_days_b')->default(14)->comment('Дни покрытия для категории B');
            $table->integer('target_days_c')->default(7)->comment('Дни покрытия для категории C');
            
            // Страховой запас
            $table->integer('safety_stock_days')->default(3)->comment('Страховой запас в днях');
            $table->decimal('safety_stock_percent', 5, 2)->default(10)->comment('Страховой запас в %');
            $table->enum('safety_stock_mode', ['days', 'percent', 'max'])->default('days');
            
            // Lead time
            $table->integer('default_lead_time_days')->default(3)->comment('Lead time по умолчанию');
            
            // Минимальные партии
            $table->integer('min_order_qty')->default(1)->comment('Минимальная партия');
            $table->integer('default_pack_multiple')->default(1)->comment('Кратность по умолчанию');
            
            // Пороги рисков
            $table->integer('oos_risk_days')->default(3)->comment('Порог OOS риска в днях');
            $table->integer('overstock_days')->default(60)->comment('Порог перезатаривания в днях');
            
            // === Правила слотов ===
            
            // Предпочтительные дни недели (1=Пн, 7=Вс)
            $table->json('preferred_weekdays')->nullable()->comment('[1,2,3,4,5]');
            
            // Предпочтительные часы
            $table->time('preferred_time_from')->nullable();
            $table->time('preferred_time_to')->nullable();
            
            // Лимиты
            $table->integer('max_supplies_per_day')->default(3)->comment('Макс. поставок в день');
            $table->integer('max_items_per_supply')->default(100)->comment('Макс. SKU в поставке');
            $table->integer('max_qty_per_supply')->default(10000)->comment('Макс. единиц в поставке');
            
            // Автобронирование
            $table->boolean('auto_book_slot')->default(false)->comment('Автобронирование при OOS риске');
            $table->integer('auto_book_oos_threshold_days')->default(2)->comment('Порог для автобронирования');
            
            // === Уведомления ===
            $table->boolean('notify_no_slots')->default(true);
            $table->integer('notify_no_slots_days')->default(7)->comment('Нет слотов на N дней');
            
            $table->boolean('notify_oos_risk')->default(true);
            $table->boolean('notify_stuck_supply')->default(true);
            $table->integer('notify_stuck_hours')->default(24)->comment('Поставка зависла > N часов');
            
            $table->boolean('notify_api_errors')->default(true);
            $table->boolean('notify_acceptance_issues')->default(true);
            
            // Каналы уведомлений
            $table->json('notification_channels')->nullable()->comment('["email", "telegram"]');
            $table->json('notification_recipients')->nullable()->comment('User IDs или emails');
            
            // === Исключения ===
            $table->json('excluded_skus')->nullable()->comment('SKU исключённые из рекомендаций');
            $table->json('excluded_categories')->nullable()->comment('Категории исключённые');
            $table->json('restricted_skus')->nullable()->comment('SKU с ограничениями');
            
            // === Мета ===
            $table->boolean('is_active')->default(true);
            $table->json('custom_rules')->nullable()->comment('Кастомные правила');
            
            $table->timestamps();
            
            // Уникальность
            $table->unique('integration_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_settings');
    }
};
