<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite не поддерживает ALTER COLUMN, поэтому пересоздаём таблицу.
        // Схему берём из sqlite_master (как есть на момент миграции), иначе SELECT *
        // не совпадает с жёстко заданным списком колонок (колонки из будущих миграций).
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=off;');

            $createRow = DB::selectOne(
                "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'shipments'"
            );
            if ($createRow === null || empty($createRow->sql)) {
                throw new \RuntimeException('SQLite: DDL для shipments не найден в sqlite_master.');
            }

            $indexSqls = array_column(
                DB::select(
                    "SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'shipments' AND sql IS NOT NULL"
                ),
                'sql'
            );

            $ddl = str_replace(
                ['CREATE TABLE IF NOT EXISTS "shipments"', 'CREATE TABLE "shipments"'],
                'CREATE TABLE "shipments_temp"',
                $createRow->sql,
            );
            $ddl = preg_replace(
                '/"supplier_id"\s+varchar\s+not\s+null/i',
                '"supplier_id" varchar',
                $ddl,
                1
            );

            DB::statement($ddl);
            DB::statement('INSERT INTO shipments_temp SELECT * FROM shipments;');

            Schema::drop('shipments');
            Schema::rename('shipments_temp', 'shipments');

            foreach ($indexSqls as $indexSql) {
                DB::statement($indexSql);
            }

            DB::statement('PRAGMA foreign_keys=on;');
        } else {
            Schema::table('shipments', function (Blueprint $table) {
                $table->uuid('supplier_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Не откатываем - это безопасное изменение
    }
};
