<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Сбор за обработку возврата FBO (ручная ставка) — для отображения в таблице юнит-экономики. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_economics_cache', function (Blueprint $table) {
            $table->decimal('return_fee', 12, 2)->nullable()->after('cost_price');
        });
    }

    public function down(): void
    {
        Schema::table('unit_economics_cache', function (Blueprint $table) {
            $table->dropColumn('return_fee');
        });
    }
};
