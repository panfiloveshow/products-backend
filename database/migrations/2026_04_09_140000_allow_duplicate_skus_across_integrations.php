<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_sku_marketplace_unique');
            DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_marketplace_sku_integration_unique');
            DB::statement('DROP INDEX IF EXISTS products_sku_marketplace_unique');
            DB::statement('DROP INDEX IF EXISTS products_marketplace_sku_integration_unique');
        } elseif ($driver === 'mysql') {
            try {
                Schema::table('products', function (Blueprint $table) {
                    $table->dropUnique('products_sku_marketplace_unique');
                });
            } catch (\Throwable $e) {
            }

            try {
                Schema::table('products', function (Blueprint $table) {
                    $table->dropUnique('products_marketplace_sku_integration_unique');
                });
            } catch (\Throwable $e) {
            }
        }

        Schema::table('products', function (Blueprint $table) {
            $table->unique(
                ['marketplace', 'sku', 'integration_id'],
                'products_marketplace_sku_integration_unique'
            );
        });
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS products_marketplace_sku_integration_unique');
        } elseif ($driver === 'mysql') {
            try {
                Schema::table('products', function (Blueprint $table) {
                    $table->dropUnique('products_marketplace_sku_integration_unique');
                });
            } catch (\Throwable $e) {
            }
        }

        Schema::table('products', function (Blueprint $table) {
            $table->unique(['sku', 'marketplace'], 'products_sku_marketplace_unique');
        });
    }
};
