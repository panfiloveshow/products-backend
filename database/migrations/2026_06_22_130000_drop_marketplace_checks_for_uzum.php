<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel enum() на PostgreSQL создаёт CHECK {table}_{column}_check, который не пускает 'uzum'.
 * Часть таблиц уже расчищена ранее (2026_03_25), но unit_economics / unit_economics_cache
 * на проде всё ещё имели CHECK на marketplace — из-за чего расчёт юнит-экономики Uzum падал
 * на "unit_economics_marketplace_check". Снимаем оставшиеся (идемпотентно, IF EXISTS).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $tables = [
            'products',
            'inventory_warehouses',
            'sync_logs',
            'unit_economics',
            'unit_economics_cache',
        ];

        foreach ($tables as $t) {
            DB::statement("ALTER TABLE {$t} DROP CONSTRAINT IF EXISTS {$t}_marketplace_check");
        }
    }

    public function down(): void
    {
        // Восстанавливать ENUM-check небезопасно: в данных уже есть uzum/yandex_market.
    }
};
