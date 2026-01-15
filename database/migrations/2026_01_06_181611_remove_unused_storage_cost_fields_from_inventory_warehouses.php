<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Удаляем неиспользуемые поля storage_cost_total, storage_cost_period_days, storage_cost_updated_at
     * Теперь используем только storage_fee_* из еженедельных отчётов реализации WB
     */
    public function up(): void
    {
        Schema::table('inventory_warehouses', function (Blueprint $table) {
            $table->dropColumn([
                'storage_cost_total',
                'storage_cost_period_days',
                'storage_cost_updated_at'
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_warehouses', function (Blueprint $table) {
            $table->decimal('storage_cost_total', 10, 2)->nullable();
            $table->integer('storage_cost_period_days')->nullable();
            $table->timestamp('storage_cost_updated_at')->nullable();
        });
    }
};
