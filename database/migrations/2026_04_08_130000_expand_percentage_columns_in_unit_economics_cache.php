<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE unit_economics_cache ALTER COLUMN margin_percent TYPE NUMERIC(12,2)');
            DB::statement('ALTER TABLE unit_economics_cache ALTER COLUMN markup_percent TYPE NUMERIC(12,2)');
            DB::statement('ALTER TABLE unit_economics_cache ALTER COLUMN roi_percent TYPE NUMERIC(12,2)');
        } elseif (DB::getDriverName() === 'mysql') {
            Schema::table('unit_economics_cache', function (Blueprint $table) {
                $table->decimal('margin_percent', 12, 2)->default(0)->change();
                $table->decimal('markup_percent', 12, 2)->default(0)->change();
                $table->decimal('roi_percent', 12, 2)->default(0)->change();
            });
        }
        // SQLite: no-op, handled by table recreation in prior migration
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE unit_economics_cache ALTER COLUMN margin_percent TYPE NUMERIC(5,2)');
            DB::statement('ALTER TABLE unit_economics_cache ALTER COLUMN markup_percent TYPE NUMERIC(5,2)');
            DB::statement('ALTER TABLE unit_economics_cache ALTER COLUMN roi_percent TYPE NUMERIC(8,2)');
        } elseif (DB::getDriverName() === 'mysql') {
            Schema::table('unit_economics_cache', function (Blueprint $table) {
                $table->decimal('margin_percent', 5, 2)->default(0)->change();
                $table->decimal('markup_percent', 5, 2)->default(0)->change();
                $table->decimal('roi_percent', 8, 2)->default(0)->change();
            });
        }
    }
};
