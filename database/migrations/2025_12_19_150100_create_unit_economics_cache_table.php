<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Таблица кэша юнит-экономики (автоматический расчёт)
     * Хранит предрассчитанные данные для каждой схемы работы.
     * Обновляется автоматически при:
     * - синхронизации товаров
     * - изменении настроек пользователя
     */
    public function up(): void
    {
        Schema::create('unit_economics_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->onDelete('cascade');
            $table->uuid('product_id');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->string('sku', 255);
            $table->string('product_name', 500)->nullable();
            $table->string('marketplace', 50)->default('ozon');
            $table->enum('fulfillment_type', ['FBO', 'FBS', 'RFBS', 'EXPRESS'])->default('FBO');
            
            // Базовые данные (из API)
            $table->decimal('price', 12, 2)->default(0)->comment('Цена продажи');
            $table->decimal('old_price', 12, 2)->nullable()->comment('Старая цена');
            $table->integer('sales_count')->default(0)->comment('Продажи за 30 дней');
            
            // Габариты (из API)
            $table->decimal('volume_liters', 10, 4)->nullable()->comment('Объём в литрах');
            $table->decimal('volume_weight', 10, 4)->nullable()->comment('Объёмный вес');
            $table->decimal('depth', 10, 2)->nullable()->comment('Длина мм');
            $table->decimal('width', 10, 2)->nullable()->comment('Ширина мм');
            $table->decimal('height', 10, 2)->nullable()->comment('Высота мм');
            $table->decimal('weight', 10, 2)->nullable()->comment('Вес г');
            
            // Комиссия (из API)
            $table->decimal('commission_percent', 5, 2)->default(0)->comment('% комиссии');
            $table->decimal('commission_amount', 12, 2)->default(0)->comment('Сумма комиссии за шт');
            
            // Логистика (расчёт по тарифам)
            $table->integer('avg_delivery_time_hours')->default(29)->comment('Среднее время доставки');
            $table->decimal('logistics_coefficient', 5, 3)->default(1.0)->comment('Коэффициент времени');
            $table->decimal('additional_commission_percent', 5, 2)->default(0)->comment('Доп. % от цены');
            $table->decimal('base_logistics_cost', 12, 2)->default(0)->comment('Базовый тариф логистики');
            $table->decimal('logistics_cost', 12, 2)->default(0)->comment('Логистика за шт');
            $table->decimal('last_mile_cost', 12, 2)->default(0)->comment('Последняя миля');
            $table->decimal('processing_cost', 12, 2)->default(0)->comment('Обработка отправления (FBS)');
            $table->decimal('storage_cost', 12, 2)->default(0)->comment('Хранение');
            
            // Возвраты (из API + расчёт)
            $table->decimal('redemption_rate', 5, 2)->default(100)->comment('% выкупа');
            $table->decimal('return_logistics_cost', 12, 2)->default(0)->comment('Обратная логистика');
            $table->decimal('return_processing_cost', 12, 2)->default(0)->comment('Обработка возврата');
            $table->decimal('expected_return_cost', 12, 2)->default(0)->comment('Ожидаемые возвраты за шт');
            $table->decimal('effective_logistics', 12, 2)->default(0)->comment('Эффективная логистика');
            
            // Эквайринг
            $table->decimal('acquiring_percent', 5, 2)->default(1.5)->comment('% эквайринга');
            $table->decimal('acquiring_amount', 12, 2)->default(0)->comment('Сумма эквайринга');
            
            // Настройки пользователя (копия из settings)
            $table->decimal('cost_price', 12, 2)->default(0)->comment('Себестоимость');
            $table->decimal('drr_percent', 5, 2)->default(0)->comment('РК %');
            $table->decimal('drr_amount', 12, 2)->default(0)->comment('РК сумма');
            $table->decimal('our_share_percent', 5, 2)->default(0)->comment('Наша часть %');
            $table->decimal('our_share_amount', 12, 2)->default(0)->comment('Наша часть сумма');
            $table->decimal('tax_percent', 5, 2)->default(6)->comment('Налог %');
            $table->decimal('tax_amount', 12, 2)->default(0)->comment('Налог сумма');
            $table->decimal('vat_percent', 5, 2)->default(0)->comment('НДС %');
            $table->decimal('vat_amount', 12, 2)->default(0)->comment('НДС сумма');
            
            // Итоговые расчёты
            $table->decimal('revenue', 12, 2)->default(0)->comment('Выручка');
            $table->decimal('total_costs', 12, 2)->default(0)->comment('Все затраты');
            $table->decimal('gross_profit', 12, 2)->default(0)->comment('Валовая прибыль');
            $table->decimal('net_profit', 12, 2)->default(0)->comment('Чистая прибыль за шт');
            $table->decimal('to_settlement_account', 12, 2)->default(0)->comment('На расчётный счёт');
            $table->decimal('margin_percent', 5, 2)->default(0)->comment('Маржа %');
            $table->decimal('markup_percent', 5, 2)->default(0)->comment('Наценка %');
            $table->decimal('roi_percent', 8, 2)->default(0)->comment('ROI %');
            
            // Метаданные
            $table->timestamp('calculated_at')->nullable()->comment('Время расчёта');
            $table->integer('data_version')->default(1)->comment('Версия данных');
            $table->timestamps();
            
            // Индексы
            $table->unique(['integration_id', 'sku', 'fulfillment_type'], 'ue_cache_unique');
            $table->index(['integration_id', 'marketplace']);
            $table->index(['integration_id', 'fulfillment_type']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_economics_cache');
    }
};
