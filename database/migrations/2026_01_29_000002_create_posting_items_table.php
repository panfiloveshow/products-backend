<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posting_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('posting_id')->index();
            
            // Идентификаторы товара
            $table->string('sku')->index(); // Наш SKU
            $table->string('marketplace_sku')->nullable(); // SKU в маркетплейсе (product_id для Ozon)
            $table->string('offer_id')->nullable(); // Артикул продавца
            $table->string('barcode')->nullable();
            
            // Информация о товаре
            $table->string('name');
            $table->string('image_url')->nullable();
            
            // Количество и цена
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('price', 12, 2)->default(0); // Цена за единицу
            $table->decimal('total_price', 12, 2)->default(0); // Общая цена
            
            // Финансы
            $table->decimal('commission_amount', 12, 2)->nullable();
            $table->decimal('commission_percent', 5, 2)->nullable();
            $table->decimal('payout', 12, 2)->nullable();
            
            // Габариты
            $table->decimal('weight', 10, 3)->nullable(); // Вес в граммах
            $table->decimal('volume', 10, 6)->nullable(); // Объём в литрах
            
            // Статус позиции
            $table->string('status', 50)->nullable(); // assembled, packed, shipped
            $table->boolean('is_assembled')->default(false);
            $table->boolean('is_packed')->default(false);
            
            // Метаданные
            $table->json('meta')->nullable();
            
            $table->timestamps();
            
            // Foreign key
            $table->foreign('posting_id')
                ->references('id')
                ->on('postings')
                ->onDelete('cascade');
            
            // Индексы
            $table->index(['posting_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posting_items');
    }
};
