<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_warehouses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('sku', 100);
            $table->string('warehouse_id', 100);
            $table->string('warehouse_name', 200)->nullable();
            $table->enum('marketplace', ['wildberries', 'ozon', 'yandex']);
            $table->string('region', 200)->nullable();
            $table->integer('quantity')->default(0);
            $table->decimal('average_daily_sales', 10, 2)->nullable();
            $table->integer('days_of_stock')->nullable();
            $table->integer('recommended_quantity')->nullable();
            $table->enum('stock_status', ['critical', 'low', 'optimal', 'excess'])->default('optimal');
            $table->timestamp('last_updated')->useCurrent();
            $table->timestamps();

            $table->unique(['sku', 'warehouse_id']);
            $table->index('sku');
            $table->index('marketplace');
            $table->index('stock_status');

            $table->foreign('sku')->references('sku')->on('products')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_warehouses');
    }
};
