<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('sku', 100);
            $table->string('warehouse_id', 100)->nullable();
            $table->string('warehouse_name', 200)->nullable();
            $table->enum('type', ['critical', 'warning', 'info']);
            $table->text('message')->nullable();
            $table->enum('action', ['reorder', 'redistribute', 'monitor'])->nullable();
            $table->integer('priority')->nullable();
            $table->boolean('is_resolved')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('sku');
            $table->index('type');
            $table->index('is_resolved');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_alerts');
    }
};
