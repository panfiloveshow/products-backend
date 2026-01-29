<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supply_recommendations', function (Blueprint $table) {
            // Данные из unit_economics для улучшения рекомендаций
            $table->decimal('redemption_rate', 5, 2)->nullable()->after('roi_percent')
                ->comment('Процент выкупа из unit_economics');
            $table->decimal('turnover_days_ue', 8, 2)->nullable()->after('redemption_rate')
                ->comment('Оборачиваемость в днях из unit_economics');
            $table->decimal('localization_index', 5, 2)->nullable()->after('turnover_days_ue')
                ->comment('Индекс локализации из unit_economics');
            $table->decimal('storage_cost', 12, 2)->nullable()->after('localization_index')
                ->comment('Стоимость хранения из unit_economics');
            $table->decimal('delivery_cost', 12, 2)->nullable()->after('storage_cost')
                ->comment('Стоимость доставки из unit_economics');
            $table->decimal('drr_percent', 5, 2)->nullable()->after('delivery_cost')
                ->comment('ДРР процент из unit_economics');
        });
    }

    public function down(): void
    {
        Schema::table('supply_recommendations', function (Blueprint $table) {
            $table->dropColumn([
                'redemption_rate',
                'turnover_days_ue',
                'localization_index',
                'storage_cost',
                'delivery_cost',
                'drr_percent',
            ]);
        });
    }
};
