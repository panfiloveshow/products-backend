<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('postings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('integration_id')->index();
            $table->string('marketplace', 50)->index(); // ozon, wildberries, yandex
            
            // Идентификаторы маркетплейса
            $table->string('posting_number')->index(); // Номер отправления в МП
            $table->string('order_id')->nullable(); // ID заказа в МП
            $table->string('order_number')->nullable(); // Номер заказа
            
            // Статус
            $table->string('status', 50)->default('awaiting_packaging')->index();
            $table->string('substatus', 50)->nullable(); // Подстатус (для Ozon)
            $table->string('external_status', 100)->nullable(); // Оригинальный статус из МП
            
            // Даты
            $table->timestamp('shipment_date')->nullable(); // Дата отгрузки
            $table->timestamp('delivering_date')->nullable(); // Дата доставки
            $table->timestamp('in_process_at')->nullable(); // Начало обработки
            $table->timestamp('packed_at')->nullable(); // Упакован
            $table->timestamp('shipped_at')->nullable(); // Отгружен
            $table->timestamp('delivered_at')->nullable(); // Доставлен
            $table->timestamp('cancelled_at')->nullable(); // Отменён
            
            // Склад
            $table->string('warehouse_id')->nullable();
            $table->string('warehouse_name')->nullable();
            
            // Доставка
            $table->string('delivery_method')->nullable(); // courier, pickup_point
            $table->string('delivery_type')->nullable(); // fbs, express
            $table->string('tpl_integration_type')->nullable(); // Тип интеграции с ТПЛ
            
            // Данные покупателя (анонимизированные)
            $table->json('customer')->nullable(); // name, phone (masked), address
            
            // Финансы
            $table->decimal('total_price', 12, 2)->default(0);
            $table->decimal('products_total', 12, 2)->default(0);
            $table->decimal('commission', 12, 2)->default(0);
            $table->decimal('delivery_cost', 12, 2)->default(0);
            $table->decimal('payout', 12, 2)->default(0);
            $table->json('financial_data')->nullable(); // Детальные финансовые данные
            
            // Счётчики
            $table->unsignedInteger('items_count')->default(0);
            $table->unsignedInteger('total_quantity')->default(0);
            
            // Отмена
            $table->unsignedInteger('cancel_reason_id')->nullable();
            $table->string('cancel_reason_message')->nullable();
            
            // Метаданные
            $table->json('meta')->nullable(); // Дополнительные данные из МП
            $table->json('analytics_data')->nullable(); // Аналитика
            $table->json('barcodes')->nullable(); // Штрихкоды
            
            // Синхронизация
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('last_status_change_at')->nullable();
            
            $table->timestamps();
            
            // Индексы
            $table->index(['integration_id', 'status']);
            $table->index(['integration_id', 'shipment_date']);
            $table->index(['marketplace', 'posting_number']);
            $table->unique(['integration_id', 'posting_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postings');
    }
};
