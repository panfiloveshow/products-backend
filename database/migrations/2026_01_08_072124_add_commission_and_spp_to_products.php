<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Добавляет поля комиссии и СПП для WB товаров
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Комиссия WB (%) — из API /api/v1/tariffs/commission по категории
            $table->decimal('commission', 5, 2)->nullable()->after('card_rating_details')
                ->comment('Комиссия WB (%) по категории товара');
            
            // СПП (%) — Скидка Постоянного Покупателя (редактируемое поле)
            $table->decimal('spp', 5, 2)->nullable()->after('commission')
                ->comment('СПП (%) — Скидка Постоянного Покупателя');
            
            // subject_id для маппинга комиссий по категориям
            $table->unsignedInteger('subject_id')->nullable()->after('spp')
                ->comment('ID категории WB для получения комиссии');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['commission', 'spp', 'subject_id']);
        });
    }
};
