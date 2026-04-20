<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Настройки Locality-интеграции per integration (по умолчанию включено для Ozon FBO).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supply_settings', function (Blueprint $table) {
            $table->boolean('locality_split_default')->default(true)->after('custom_rules')
                ->comment('Автоматически разбивать новые планы по кластерам для Ozon');
            $table->string('locality_min_confidence_default', 16)->default('medium')->after('locality_split_default')
                ->comment('Минимальная уверенность рекомендаций для включения в split');
            $table->unsignedSmallInteger('locality_max_split_clusters')->default(5)->after('locality_min_confidence_default')
                ->comment('Максимум target-кластеров на SKU при split');
        });
    }

    public function down(): void
    {
        Schema::table('supply_settings', function (Blueprint $table) {
            $table->dropColumn([
                'locality_split_default',
                'locality_min_confidence_default',
                'locality_max_split_clusters',
            ]);
        });
    }
};
