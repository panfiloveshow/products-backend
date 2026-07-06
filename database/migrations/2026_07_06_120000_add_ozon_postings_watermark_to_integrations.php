<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            // Водяной знак инкрементального синка постингов Ozon: до какого момента
            // постинги уже подтянуты. Следующий синк тянет только since = знак − overlap,
            // а не всё окно. NULL → первый прогон делает полный бэкфилл.
            $table->timestamp('ozon_postings_synced_until')->nullable()->after('localization_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn('ozon_postings_synced_until');
        });
    }
};
