<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shipment_id');
            $table->string('sku', 100);
            $table->string('product_name', 500)->nullable();
            $table->text('image_url')->nullable();
            $table->integer('current_stock')->nullable();
            $table->integer('days_of_stock')->nullable();
            $table->enum('priority', ['critical', 'medium', 'low'])->default('medium');
            $table->integer('quantity');
            $table->decimal('cost_price', 10, 2)->nullable();
            $table->decimal('total_cost', 12, 2)->nullable();
            $table->boolean('ml_recommended')->default(false);
            $table->integer('ml_quantity')->nullable();
            $table->text('ml_reason')->nullable();
            $table->decimal('ml_confidence', 5, 2)->nullable();
            $table->decimal('volume_per_unit', 10, 6)->nullable();
            $table->decimal('weight_per_unit', 10, 3)->nullable();
            $table->decimal('total_volume', 10, 3)->nullable();
            $table->decimal('total_weight', 10, 3)->nullable();
            $table->json('marketplaces')->nullable();
            $table->timestamps();

            $table->index('shipment_id');
            $table->index('sku');

            $table->foreign('shipment_id')->references('id')->on('shipments')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_items');
    }
};
