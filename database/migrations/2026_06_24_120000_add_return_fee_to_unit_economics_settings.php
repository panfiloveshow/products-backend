<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Сбор за обработку возврата (Uzum FBO) — ручная ставка за 1 возврат.
 * Эффективная стоимость на проданную единицу = ставка × доля возвратов.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_economics_settings', function (Blueprint $table) {
            $table->decimal('return_fee', 12, 2)->nullable()->after('cost_price');
        });
    }

    public function down(): void
    {
        Schema::table('unit_economics_settings', function (Blueprint $table) {
            $table->dropColumn('return_fee');
        });
    }
};
