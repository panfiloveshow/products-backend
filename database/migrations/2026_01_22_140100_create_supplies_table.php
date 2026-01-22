<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Таблица поставок Ozon FBO
 * 
 * Основная сущность для отслеживания жизненного цикла поставки
 * от черновика до приёмки на складе
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->onDelete('cascade');
            
            // Идентификаторы
            $table->string('crm_number', 50)->unique()->comment('Внутренний номер CRM');
            $table->string('ozon_supply_id', 50)->nullable()->index()->comment('ID поставки в Ozon');
            $table->string('ozon_draft_id', 50)->nullable()->index()->comment('ID черновика в Ozon');
            
            // Тип и схема поставки
            $table->enum('supply_type', ['fbo', 'fbs', 'realfbs'])->default('fbo');
            $table->enum('supply_method', ['direct', 'crossdock', 'multi_cluster'])->default('direct');
            $table->enum('delivery_scheme', ['drop_off', 'pick_up'])->nullable();
            
            // Назначение
            $table->string('cluster_id', 50)->nullable()->index();
            $table->string('cluster_name', 200)->nullable();
            $table->string('warehouse_id', 50)->nullable()->index();
            $table->string('warehouse_name', 200)->nullable();
            
            // Для кросс-дока
            $table->string('drop_off_point_id', 50)->nullable();
            $table->string('drop_off_point_type', 50)->nullable();
            $table->string('seller_warehouse_id', 50)->nullable();
            
            // Слот приёмки
            $table->string('timeslot_id', 50)->nullable();
            $table->timestamp('timeslot_from')->nullable();
            $table->timestamp('timeslot_to')->nullable();
            $table->date('planned_delivery_date')->nullable()->index();
            
            // Статистика позиций
            $table->integer('items_count')->default(0)->comment('Кол-во SKU');
            $table->integer('total_quantity')->default(0)->comment('Общее кол-во единиц');
            $table->integer('total_boxes')->default(0)->comment('Кол-во коробов');
            $table->decimal('total_weight', 12, 3)->default(0)->comment('Общий вес, кг');
            $table->decimal('total_volume', 12, 6)->default(0)->comment('Общий объём, м³');
            
            // Статус (внутренний CRM)
            $table->enum('status', [
                'draft',                    // Черновик CRM
                'draft_ozon',               // Черновик создан в Ozon
                'slot_pending',             // Ожидает выбора слота
                'slot_booked',              // Слот забронирован
                'preparing',                // Сборка в процессе
                'ready_to_ship',            // Готово к отгрузке
                'shipped',                  // Отгружено
                'in_transit',               // В пути
                'at_warehouse',             // На приёмке
                'accepted_partial',         // Принято частично
                'accepted_full',            // Принято полностью
                'closed',                   // Закрыто
                'cancelled',                // Отменено
                'error',                    // Ошибка
            ])->default('draft')->index();
            
            // Статус Ozon (как есть из API)
            $table->string('ozon_status', 100)->nullable();
            $table->string('ozon_status_description', 500)->nullable();
            
            // Даты жизненного цикла
            $table->timestamp('created_in_ozon_at')->nullable();
            $table->timestamp('slot_booked_at')->nullable();
            $table->timestamp('preparing_started_at')->nullable();
            $table->timestamp('ready_to_ship_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            
            // Результаты приёмки
            $table->integer('accepted_quantity')->nullable()->comment('Принятое кол-во');
            $table->integer('rejected_quantity')->nullable()->comment('Отклонённое кол-во');
            $table->json('acceptance_discrepancies')->nullable()->comment('Расхождения при приёмке');
            
            // Ответственные
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            
            // Связь с планом
            $table->foreignId('supply_plan_id')->nullable()->constrained('supply_plans')->nullOnDelete();
            
            // Мета
            $table->text('comment')->nullable();
            $table->json('meta')->nullable()->comment('Дополнительные данные');
            $table->json('ozon_response')->nullable()->comment('Последний ответ Ozon API');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Индексы
            $table->index(['integration_id', 'status']);
            $table->index(['integration_id', 'created_at']);
            $table->index(['status', 'planned_delivery_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplies');
    }
};
