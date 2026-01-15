<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supply_recommendations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            $table->unsignedBigInteger('integration_id')->nullable();
            $table->string('marketplace', 20);
            $table->string('warehouse_id', 100)->nullable();
            $table->string('warehouse_name')->nullable();
            
            $table->string('priority', 20)->default('medium');
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('reason')->nullable();
            
            $table->json('critical_items')->nullable();
            $table->json('recommended_items')->nullable();
            
            $table->integer('total_items')->default(0);
            $table->integer('total_quantity')->default(0);
            $table->decimal('total_cost', 14, 2)->default(0);
            $table->decimal('total_volume', 10, 3)->default(0);
            $table->decimal('total_weight', 10, 3)->default(0);
            
            $table->decimal('estimated_delivery_cost', 10, 2)->nullable();
            $table->decimal('estimated_storage_cost', 10, 2)->nullable();
            $table->decimal('estimated_profit', 14, 2)->nullable();
            
            $table->date('deadline')->nullable();
            $table->json('seasonal_factors')->nullable();
            
            $table->boolean('is_used')->default(false);
            $table->uuid('used_in_shipment_id')->nullable();
            $table->timestamp('used_at')->nullable();
            
            $table->boolean('is_dismissed')->default(false);
            $table->timestamp('dismissed_at')->nullable();
            $table->string('dismissed_reason')->nullable();
            
            $table->timestamps();
            
            $table->index(['marketplace', 'priority']);
            $table->index(['integration_id']);
            $table->index(['is_used', 'is_dismissed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_recommendations');
    }
};
