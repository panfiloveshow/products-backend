<?php

namespace Database\Migrations\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait SafeIndexMigration
{
    protected function safeDropIndex(string $table, string $indexName): void
    {
        $driver = DB::connection()->getDriverName();
        
        if ($driver === 'pgsql') {
            DB::statement("DROP INDEX IF EXISTS {$indexName}");
        } elseif ($driver === 'mysql') {
            try {
                Schema::table($table, function ($t) use ($indexName) {
                    $t->dropIndex($indexName);
                });
            } catch (\Exception $e) {}
        }
    }
    
    protected function safeDropUnique(string $table, string $indexName): void
    {
        $driver = DB::connection()->getDriverName();
        
        if ($driver === 'pgsql') {
            DB::statement("DROP INDEX IF EXISTS {$indexName}");
        } elseif ($driver === 'mysql') {
            try {
                Schema::table($table, function ($t) use ($indexName) {
                    $t->dropUnique($indexName);
                });
            } catch (\Exception $e) {}
        }
    }
    
    protected function indexExists(string $table, string $indexName): bool
    {
        $driver = DB::connection()->getDriverName();
        
        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list({$table})");
            foreach ($indexes as $index) {
                if ($index->name === $indexName) {
                    return true;
                }
            }
            return false;
        }
        
        if ($driver === 'pgsql') {
            $indexes = DB::select("SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?", [$table, $indexName]);
            return count($indexes) > 0;
        }
        
        // MySQL
        $indexes = DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$indexName]);
        return count($indexes) > 0;
    }
}
