<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * В коде интеграции и товары сохраняются как yandex_market, а изначальные enum
 * допускали только yandex — на MySQL это ломало вставку/обновление.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE products MODIFY marketplace VARCHAR(50) NOT NULL');
            DB::statement('ALTER TABLE inventory_warehouses MODIFY marketplace VARCHAR(50) NOT NULL');
            DB::statement('ALTER TABLE sync_logs MODIFY marketplace VARCHAR(50) NOT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE products ALTER COLUMN marketplace TYPE VARCHAR(50)');
            DB::statement('ALTER TABLE inventory_warehouses ALTER COLUMN marketplace TYPE VARCHAR(50)');
            DB::statement('ALTER TABLE sync_logs ALTER COLUMN marketplace TYPE VARCHAR(50)');
        }
    }

    public function down(): void
    {
        // Откат к enum опасен при наличии yandex_market в данных — оставляем пустым
    }
};
