<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Персистентное хранилище per-SKU рекламной статистики Ozon (CPC) по периоду.
 *
 * Раньше результат жил только в Cache с TTL 30 мин и сбрасывался после обновления страницы —
 * пользователю приходилось заново ждать генерацию async-отчётов. Теперь сохраняем итог в БД
 * вместе с fetched_at, отдаём мгновенно при открытии юнит-экономики и показываем актуальность.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ozon_ad_stats', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('integration_id');
            $table->string('date_from', 10);
            $table->string('date_to', 10);
            $table->string('status', 20)->default('ready'); // ready | partial
            $table->longText('payload'); // JSON: rows/totals/derived/source/...
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['integration_id', 'date_from', 'date_to'], 'ozon_ad_stats_int_period_uq');
            $table->index('integration_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ozon_ad_stats');
    }
};
