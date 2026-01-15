<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supply_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            
            $table->date('period_start');
            $table->date('period_end');
            
            $table->unsignedBigInteger('integration_id')->nullable();
            $table->string('marketplace', 20);
            
            $table->integer('target_days_of_stock')->default(30);
            $table->integer('safety_stock_days')->default(7);
            
            $table->integer('total_items')->default(0);
            $table->integer('total_quantity')->default(0);
            $table->decimal('total_cost', 14, 2)->default(0);
            $table->decimal('total_volume', 10, 3)->default(0);
            $table->decimal('total_weight', 10, 3)->default(0);
            
            $table->decimal('estimated_storage_cost', 12, 2)->nullable();
            $table->decimal('estimated_profit', 14, 2)->nullable();
            $table->decimal('estimated_roi', 6, 2)->nullable();
            
            $table->string('status', 30)->default('draft');
            
            $table->uuid('created_by')->nullable();
            $table->string('created_by_name')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->string('approved_by_name')->nullable();
            $table->timestamp('approved_at')->nullable();
            
            $table->timestamps();
            
            $table->index(['marketplace', 'status']);
            $table->index(['integration_id']);
            $table->index(['period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_plans');
    }
};
