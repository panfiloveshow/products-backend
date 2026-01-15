<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Добавляем поля для информации об акциях Ozon
     * marketing_seller_price - цена с учётом маркетинговых акций
     */
    public function up(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            // Информация об акциях
            $table->boolean('is_in_promotion')->default(false)->after('marketplace_data');
            $table->decimal('promotion_discount', 5, 2)->nullable()->after('is_in_promotion');
            $table->decimal('seller_price', 12, 2)->nullable()->after('promotion_discount');
            $table->decimal('marketing_seller_price', 12, 2)->nullable()->after('seller_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            $table->dropColumn([
                'is_in_promotion',
                'promotion_discount',
                'seller_price',
                'marketing_seller_price',
            ]);
        });
    }
};
