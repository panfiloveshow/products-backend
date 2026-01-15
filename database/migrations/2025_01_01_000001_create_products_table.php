<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('sku', 100)->unique();
            $table->string('name', 500);
            $table->string('barcode', 50)->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('old_price', 12, 2)->nullable();
            $table->integer('stock')->default(0);
            $table->text('description')->nullable();
            $table->json('images')->nullable();
            $table->string('category', 200)->nullable();
            $table->string('brand', 200)->nullable();
            $table->decimal('rating', 3, 2)->nullable();
            $table->integer('reviews_count')->default(0);
            $table->enum('marketplace', ['wildberries', 'ozon', 'yandex']);
            $table->string('marketplace_id', 100)->nullable();
            $table->text('url')->nullable();
            $table->json('wb_data')->nullable();
            $table->json('ozon_data')->nullable();
            $table->json('yandex_data')->nullable();
            $table->timestamps();

            $table->index('sku');
            $table->index('marketplace');
            $table->index('category');
            $table->index('brand');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
