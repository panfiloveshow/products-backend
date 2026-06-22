<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'uzum_data')) {
                $table->json('uzum_data')->nullable()->after('yandex_data');
            }
        });

        // Защитно снимаем enum-CHECK на marketplace (для pgsql), если где-то остался —
        // чтобы значение 'uzum' принималось. Основные дропы уже сделаны в 2026_03_25.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            foreach (['products', 'inventory_warehouses', 'sync_logs'] as $t) {
                DB::statement("ALTER TABLE {$t} DROP CONSTRAINT IF EXISTS {$t}_marketplace_check");
            }
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'uzum_data')) {
                $table->dropColumn('uzum_data');
            }
        });
    }
};
