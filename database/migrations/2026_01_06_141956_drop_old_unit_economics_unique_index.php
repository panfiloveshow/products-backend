<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Удаляем старый уникальный индекс unit_economics_unique,
     * который не включает integration_id и вызывает конфликты
     * при синхронизации товаров из разных интеграций.
     * 
     * Правильный индекс: unit_economics_sku_integration_scheme_unique
     * (sku, integration_id, fulfillment_type)
     */
    public function up(): void
    {
        // Для SQLite используем raw SQL
        if (DB::getDriverName() === 'sqlite') {
            try {
                DB::statement('DROP INDEX IF EXISTS unit_economics_unique');
            } catch (\Exception $e) {
                // Индекс может не существовать
            }
        } else {
            // Для MySQL/PostgreSQL
            Schema::table('unit_economics', function (Blueprint $table) {
                try {
                    $table->dropUnique('unit_economics_unique');
                } catch (\Exception $e) {
                    // Индекс может не существовать
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Не восстанавливаем старый индекс - он вызывает проблемы
    }
};
