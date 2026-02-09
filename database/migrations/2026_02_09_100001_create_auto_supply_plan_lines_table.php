<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_supply_plan_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('auto_supply_plan_id');
            $table->string('sku'); // offer_id (Ozon) или barcode (WB)
            $table->string('offer_id')->nullable(); // артикул продавца
            $table->string('product_name')->nullable();
            $table->string('barcode')->nullable(); // штрихкод (для WB экспорта)
            $table->string('warehouse_id')->nullable();
            $table->string('warehouse_name')->nullable();
            $table->string('destination')->nullable(); // склад назначения
            $table->decimal('qty_raw', 10, 2)->default(0);
            $table->unsignedInteger('qty_rounded')->default(0);
            $table->unsignedInteger('current_stock')->default(0);
            $table->unsignedInteger('in_transit')->default(0);
            $table->decimal('avg_daily_sales', 10, 4)->default(0);
            $table->decimal('ewma_daily_sales', 10, 4)->default(0);
            $table->json('explain_json')->nullable();
            $table->string('risk_level', 10)->default('low'); // low, medium, high, critical
            $table->json('simulation_json')->nullable(); // [{day, stock, sales_forecast}]
            $table->timestamps();

            $table->foreign('auto_supply_plan_id')
                ->references('id')
                ->on('auto_supply_plans')
                ->cascadeOnDelete();

            $table->index(['auto_supply_plan_id', 'sku']);
            $table->index('risk_level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_supply_plan_lines');
    }
};
