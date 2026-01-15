<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_recommendations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('priority', ['urgent', 'high', 'medium']);
            $table->string('title', 300)->nullable();
            $table->text('description')->nullable();
            $table->json('critical_items')->nullable();
            $table->json('recommended_items')->nullable();
            $table->decimal('total_cost', 12, 2)->nullable();
            $table->decimal('total_volume', 10, 3)->nullable();
            $table->decimal('estimated_delivery_cost', 10, 2)->nullable();
            $table->text('reason')->nullable();
            $table->date('deadline')->nullable();
            $table->json('seasonal_factors')->nullable();
            $table->boolean('is_used')->default(false);
            $table->timestamps();

            $table->index('priority');
            $table->index('is_used');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_recommendations');
    }
};
