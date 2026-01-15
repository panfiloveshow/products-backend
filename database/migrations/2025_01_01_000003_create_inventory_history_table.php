<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('sku', 100);
            $table->string('warehouse_id', 100);
            $table->date('date');
            $table->integer('quantity')->nullable();
            $table->integer('sales')->nullable();
            $table->timestamps();

            $table->unique(['sku', 'warehouse_id', 'date']);
            $table->index(['sku', 'warehouse_id']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_history');
    }
};
