<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel enum() на PostgreSQL создаёт CHECK вида {table}_{column}_check.
 * После ALTER TYPE VARCHAR ограничение остаётся и по-прежнему запрещает yandex_market.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['sync_logs', 'products', 'inventory_warehouses'] as $table) {
            $constraint = "{$table}_marketplace_check";
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
        }
    }

    public function down(): void
    {
        // Восстанавливать старый ENUM-check небезопасно при наличии yandex_market в данных
    }
};
