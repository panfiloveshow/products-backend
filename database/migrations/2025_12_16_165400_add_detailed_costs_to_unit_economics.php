<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            // Комиссии маркетплейса (реальные из API)
            $table->decimal('commission_percent', 6, 2)->nullable()->after('roi_percent');
            $table->decimal('commission_amount', 12, 2)->nullable()->after('commission_percent');
            
            // Логистика
            $table->decimal('delivery_cost', 12, 2)->nullable()->after('commission_amount');
            $table->decimal('return_cost', 12, 2)->nullable()->after('delivery_cost');
            $table->decimal('last_mile_cost', 12, 2)->nullable()->after('return_cost');
            
            // Хранение
            $table->decimal('storage_cost', 12, 2)->nullable()->after('last_mile_cost');
            $table->integer('storage_days')->nullable()->after('storage_cost');
            
            // Реклама
            $table->decimal('advertising_cost', 12, 2)->nullable()->after('storage_days');
            $table->decimal('drr_percent', 6, 2)->nullable()->after('advertising_cost'); // ДРР - доля рекламных расходов
            
            // Процент выкупа
            $table->decimal('redemption_rate', 6, 2)->nullable()->after('drr_percent');
            $table->integer('orders_count')->nullable()->after('redemption_rate');
            $table->integer('returns_count')->nullable()->after('orders_count');
            
            // Эквайринг
            $table->decimal('acquiring_percent', 6, 2)->nullable()->after('returns_count');
            $table->decimal('acquiring_amount', 12, 2)->nullable()->after('acquiring_percent');
            
            // Упаковка
            $table->decimal('packaging_cost', 12, 2)->nullable()->after('acquiring_amount');
            
            // Тип фулфилмента
            $table->string('fulfillment_type', 10)->nullable()->after('packaging_cost'); // FBO, FBS, RFBS, FBP
        });
    }

    public function down(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            $table->dropColumn([
                'commission_percent',
                'commission_amount',
                'delivery_cost',
                'return_cost',
                'last_mile_cost',
                'storage_cost',
                'storage_days',
                'advertising_cost',
                'drr_percent',
                'redemption_rate',
                'orders_count',
                'returns_count',
                'acquiring_percent',
                'acquiring_amount',
                'packaging_cost',
                'fulfillment_type',
            ]);
        });
    }
};
