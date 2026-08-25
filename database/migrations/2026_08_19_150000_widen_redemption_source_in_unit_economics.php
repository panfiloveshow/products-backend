<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * redemption_source varchar(20) мал: значение «fallback_orders_returns» (23 символа)
 * роняло сохранение UE-строки (SQLSTATE 22001) — товар молча оставался со старыми
 * данными выкупа.
 */
return new class extends Migration
{
    public function up(): void
    {
        // sqlite (CI) не знает ALTER COLUMN ... TYPE и не enforce-ит длину varchar —
        // там миграция не нужна и валила бы весь тестовый прогон.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE unit_economics ALTER COLUMN redemption_source TYPE varchar(40)');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE unit_economics ALTER COLUMN redemption_source TYPE varchar(20)');
        }
    }
};
