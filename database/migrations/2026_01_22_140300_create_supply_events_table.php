<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Таблица событий поставки (audit trail)
 * 
 * Полный журнал всех изменений, запросов к API и ошибок
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supply_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supply_id')->constrained('supplies')->onDelete('cascade');
            
            // Тип события
            $table->enum('event_type', [
                'created',              // Создана
                'updated',              // Обновлена
                'status_changed',       // Смена статуса
                'draft_created',        // Черновик создан в Ozon
                'slot_requested',       // Запрошены слоты
                'slot_booked',          // Слот забронирован
                'slot_changed',         // Слот изменён
                'slot_cancelled',       // Слот отменён
                'item_added',           // Добавлена позиция
                'item_removed',         // Удалена позиция
                'item_qty_changed',     // Изменено кол-во
                'shipped',              // Отгружено
                'accepted',             // Принято
                'rejected',             // Отклонено
                'error',                // Ошибка
                'api_request',          // Запрос к API
                'api_response',         // Ответ API
                'notification_sent',    // Уведомление отправлено
                'comment_added',        // Добавлен комментарий
                'document_generated',   // Сгенерирован документ
            ])->index();
            
            // Детали события
            $table->string('title', 200)->nullable();
            $table->text('description')->nullable();
            
            // Изменения (для status_changed, item_qty_changed и т.д.)
            $table->string('old_value', 500)->nullable();
            $table->string('new_value', 500)->nullable();
            $table->json('changes')->nullable()->comment('Детальные изменения');
            
            // API данные (для api_request/api_response)
            $table->string('api_method', 100)->nullable();
            $table->string('api_endpoint', 200)->nullable();
            $table->json('api_request_body')->nullable();
            $table->json('api_response_body')->nullable();
            $table->integer('api_response_code')->nullable();
            $table->integer('api_duration_ms')->nullable();
            
            // Ошибки
            $table->string('error_code', 50)->nullable();
            $table->text('error_message')->nullable();
            $table->json('error_context')->nullable();
            $table->boolean('is_critical')->default(false);
            $table->boolean('is_resolved')->default(false);
            $table->timestamp('resolved_at')->nullable();
            
            // Кто инициировал
            $table->enum('initiated_by', ['user', 'system', 'api', 'scheduler'])->default('system');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            
            $table->timestamp('created_at')->useCurrent();
            
            // Индексы
            $table->index(['supply_id', 'event_type']);
            $table->index(['supply_id', 'created_at']);
            $table->index(['event_type', 'is_critical']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_events');
    }
};
