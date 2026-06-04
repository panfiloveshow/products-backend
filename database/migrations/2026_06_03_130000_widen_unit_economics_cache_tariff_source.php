<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * tariff_source был varchar(32), но WB-фолбэк "wildberries_tariff_snapshots_fallback" — 37 символов.
 * Из-за этого вставка в unit_economics_cache падала (SQLSTATE 22001) для каждого товара новых WB-магазинов
 * без тарифных данных, и кэш юнит-экономики не строился → товары не появлялись на странице.
 * Расширяем поля источника/версии тарифа.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE unit_economics_cache ALTER COLUMN tariff_source TYPE varchar(64)');
            DB::statement('ALTER TABLE unit_economics_cache ALTER COLUMN tariff_version TYPE varchar(64)');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE unit_economics_cache MODIFY tariff_source varchar(64)');
            DB::statement('ALTER TABLE unit_economics_cache MODIFY tariff_version varchar(64)');
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE unit_economics_cache ALTER COLUMN tariff_source TYPE varchar(32)');
            DB::statement('ALTER TABLE unit_economics_cache ALTER COLUMN tariff_version TYPE varchar(32)');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE unit_economics_cache MODIFY tariff_source varchar(32)');
            DB::statement('ALTER TABLE unit_economics_cache MODIFY tariff_version varchar(32)');
        }
    }
};
