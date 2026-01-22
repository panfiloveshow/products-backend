<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Таблица позиций поставки
 * 
 * Детализация товаров в каждой поставке
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supply_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supply_id')->constrained('supplies')->onDelete('cascade');
            $table->foreignId('product_id')->nullable()->constrained()->onDelete('set null');
            
            // Идентификация товара
            $table->string('sku', 100)->index();
            $table->string('ozon_product_id', 50)->nullable();
            $table->string('barcode', 100)->nullable();
            $table->string('product_name', 500)->nullable();
            
            // Количества
            $table->integer('planned_qty')->default(0)->comment('Планируемое кол-во');
            $table->integer('packed_qty')->default(0)->comment('Упакованное кол-во');
            $table->integer('shipped_qty')->default(0)->comment('Отгруженное кол-во');
            $table->integer('accepted_qty')->nullable()->comment('Принятое кол-во');
            $table->integer('rejected_qty')->nullable()->comment('Отклонённое кол-во');
            
            // Упаковка
            $table->integer('pack_multiple')->default(1)->comment('Кратность упаковки');
            $table->integer('boxes_count')->default(0)->comment('Кол-во коробов');
            
            // Габариты (на единицу)
            $table->decimal('weight', 10, 3)->nullable()->comment('Вес единицы, кг');
            $table->decimal('length', 10, 2)->nullable()->comment('Длина, см');
            $table->decimal('width', 10, 2)->nullable()->comment('Ширина, см');
            $table->decimal('height', 10, 2)->nullable()->comment('Высота, см');
            
            // Статус позиции
            $table->enum('status', [
                'pending',      // Ожидает сборки
                'picking',      // В процессе сборки
                'packed',       // Упакован
                'shipped',      // Отгружен
                'accepted',     // Принят
                'rejected',     // Отклонён
                'partial',      // Частично принят
            ])->default('pending');
            
            // Причина отклонения
            $table->string('rejection_reason', 500)->nullable();
            
            // Связь с рекомендацией
            $table->foreignId('recommendation_id')->nullable()
                ->constrained('supply_recommendations')->nullOnDelete();
            
            // Мета
            $table->json('meta')->nullable();
            
            $table->timestamps();
            
            // Индексы
            $table->index(['supply_id', 'sku']);
            $table->index(['supply_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_items');
    }
};
