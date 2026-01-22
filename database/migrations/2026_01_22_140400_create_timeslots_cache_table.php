<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Таблица кэша таймслотов
 * 
 * Кэширование доступных слотов приёмки для быстрого отображения
 * и снижения нагрузки на API Ozon
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timeslots_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->onDelete('cascade');
            
            // Привязка к складу/кластеру
            $table->string('cluster_id', 50)->nullable()->index();
            $table->string('cluster_name', 200)->nullable();
            $table->string('warehouse_id', 50)->index();
            $table->string('warehouse_name', 200)->nullable();
            
            // Привязка к черновику (если слоты запрошены для конкретного драфта)
            $table->string('draft_id', 50)->nullable()->index();
            
            // Данные слота
            $table->string('timeslot_id', 50)->index();
            $table->date('slot_date')->index();
            $table->time('time_from');
            $table->time('time_to');
            $table->timestamp('datetime_from');
            $table->timestamp('datetime_to');
            
            // Доступность
            $table->boolean('is_available')->default(true);
            $table->integer('capacity')->nullable()->comment('Вместимость слота');
            $table->integer('booked_count')->default(0)->comment('Уже забронировано');
            $table->integer('remaining_capacity')->nullable()->comment('Остаток вместимости');
            
            // Ограничения
            $table->json('restrictions')->nullable()->comment('Ограничения слота');
            
            // TTL и актуальность
            $table->timestamp('fetched_at')->useCurrent();
            $table->timestamp('expires_at')->index();
            
            $table->timestamps();
            
            // Уникальность
            $table->unique(['integration_id', 'warehouse_id', 'timeslot_id'], 'timeslots_unique');
            
            // Индексы
            $table->index(['integration_id', 'warehouse_id', 'slot_date']);
            $table->index(['is_available', 'slot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timeslots_cache');
    }
};
