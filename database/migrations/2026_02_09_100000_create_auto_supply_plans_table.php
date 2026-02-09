<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_supply_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('integration_id');
            $table->string('marketplace', 20); // ozon, wildberries
            $table->string('status', 20)->default('pending'); // pending, calculating, ready, error
            $table->json('params')->nullable(); // {target_days, safety_days, lead_time_days, ewma_alpha}
            $table->decimal('data_quality_score', 5, 2)->nullable(); // 0.00 - 100.00
            $table->unsignedInteger('total_lines')->default(0);
            $table->unsignedInteger('total_qty')->default(0);
            $table->text('error_message')->nullable();
            $table->json('export_errors')->nullable(); // ошибки при экспорте (barcode not found и т.д.)
            $table->timestamps();

            $table->index(['integration_id', 'marketplace']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_supply_plans');
    }
};
