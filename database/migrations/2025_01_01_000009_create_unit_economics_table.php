<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_economics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('product_name', 500)->nullable();
            $table->string('sku', 100);
            $table->enum('marketplace', ['ozon', 'wildberries', 'yandex_market']);
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->integer('sales_count')->nullable();
            $table->decimal('revenue', 14, 2)->nullable();
            $table->decimal('total_costs', 14, 2)->nullable();
            $table->decimal('gross_profit', 14, 2)->nullable();
            $table->decimal('net_profit', 14, 2)->nullable();
            $table->decimal('margin_percent', 6, 2)->nullable();
            $table->decimal('roi_percent', 6, 2)->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->json('marketplace_data')->nullable();
            $table->timestamps();

            $table->unique(['sku', 'marketplace', 'period_start', 'period_end'], 'unit_economics_unique');
            $table->index('sku');
            $table->index('marketplace');
            $table->index(['period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_economics');
    }
};
