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
        Schema::table('integrations', function (Blueprint $table) {
            // Индекс локализации (ИЛ) — ручной ввод из ЛК WB
            // Влияет на стоимость логистики: логистика × ИЛ
            // Значение 1.0 = без изменений, < 1.0 = скидка, > 1.0 = наценка
            $table->decimal('localization_index', 4, 2)->nullable()->after('manual_redemption_rate')
                ->comment('Индекс локализации WB (ручной ввод из ЛК)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn('localization_index');
        });
    }
};
