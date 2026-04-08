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
            if (Schema::hasColumn('unit_economics', 'non_local_markup_percent')) {
                DB::statement('ALTER TABLE unit_economics ALTER COLUMN non_local_markup_percent TYPE NUMERIC(8,2)');
            }
            if (Schema::hasColumn('unit_economics_cache', 'non_local_markup_percent')) {
                DB::statement('ALTER TABLE unit_economics_cache ALTER COLUMN non_local_markup_percent TYPE NUMERIC(8,2)');
            }
        } elseif (DB::getDriverName() === 'mysql') {
            if (Schema::hasColumn('unit_economics', 'non_local_markup_percent')) {
                Schema::table('unit_economics', function (Blueprint $table) {
                    $table->decimal('non_local_markup_percent', 8, 2)->nullable()->change();
                });
            }
            if (Schema::hasColumn('unit_economics_cache', 'non_local_markup_percent')) {
                Schema::table('unit_economics_cache', function (Blueprint $table) {
                    $table->decimal('non_local_markup_percent', 8, 2)->nullable()->change();
                });
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            if (Schema::hasColumn('unit_economics', 'non_local_markup_percent')) {
                DB::statement('ALTER TABLE unit_economics ALTER COLUMN non_local_markup_percent TYPE NUMERIC(5,2)');
            }
            if (Schema::hasColumn('unit_economics_cache', 'non_local_markup_percent')) {
                DB::statement('ALTER TABLE unit_economics_cache ALTER COLUMN non_local_markup_percent TYPE NUMERIC(5,2)');
            }
        } elseif (DB::getDriverName() === 'mysql') {
            if (Schema::hasColumn('unit_economics', 'non_local_markup_percent')) {
                Schema::table('unit_economics', function (Blueprint $table) {
                    $table->decimal('non_local_markup_percent', 5, 2)->nullable()->change();
                });
            }
            if (Schema::hasColumn('unit_economics_cache', 'non_local_markup_percent')) {
                Schema::table('unit_economics_cache', function (Blueprint $table) {
                    $table->decimal('non_local_markup_percent', 5, 2)->nullable()->change();
                });
            }
        }
    }
};
