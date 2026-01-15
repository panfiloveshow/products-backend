<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inventory_warehouses', function (Blueprint $table) {
            // Тип фулфилмента: fbo, fbs, crossborder, express и т.д.
            $table->string('fulfillment_type', 50)->nullable()->after('marketplace');
            
            // Продажи за периоды
            $table->integer('sales_7_days')->default(0)->after('quantity');
            $table->integer('sales_14_days')->default(0)->after('sales_7_days');
            $table->integer('sales_30_days')->default(0)->after('sales_14_days');
            
            // Оборачиваемость в днях (остаток / средние продажи в день)
            $table->decimal('turnover_days', 8, 1)->nullable()->after('days_of_stock');
            
            // Зарезервировано (в пути к клиенту, заморожено)
            $table->integer('reserved')->default(0)->after('quantity');
            $table->integer('in_transit')->default(0)->after('reserved');
            
            // Индексы для быстрой фильтрации
            $table->index('fulfillment_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_warehouses', function (Blueprint $table) {
            $table->dropIndex(['fulfillment_type']);
            $table->dropColumn([
                'fulfillment_type',
                'sales_7_days',
                'sales_14_days',
                'sales_30_days',
                'turnover_days',
                'reserved',
                'in_transit',
            ]);
        });
    }
};
