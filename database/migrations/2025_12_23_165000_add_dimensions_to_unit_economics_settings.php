<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Добавить поля габаритов в настройки юнит-экономики
     * Позволяет пользователю вводить габариты вручную, если WB API их не возвращает
     */
    public function up(): void
    {
        Schema::table('unit_economics_settings', function (Blueprint $table) {
            // Габариты (мм и г) — редактируемые пользователем
            $table->decimal('length_mm', 10, 2)->nullable()->after('redemption_rate_override');
            $table->decimal('width_mm', 10, 2)->nullable()->after('length_mm');
            $table->decimal('height_mm', 10, 2)->nullable()->after('width_mm');
            $table->decimal('weight_g', 10, 2)->nullable()->after('height_mm');
            
            // WB-специфичные поля
            $table->decimal('spp_percent', 5, 2)->nullable()->after('weight_g'); // СПП, %
        });
    }

    public function down(): void
    {
        Schema::table('unit_economics_settings', function (Blueprint $table) {
            $table->dropColumn(['length_mm', 'width_mm', 'height_mm', 'weight_g', 'spp_percent']);
        });
    }
};
