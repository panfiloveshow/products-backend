<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Добавляем поле integration_id если ещё нет
            if (!Schema::hasColumn('products', 'integration_id')) {
                $table->unsignedBigInteger('integration_id')->nullable()->after('marketplace_id');
            }
        });
        
        // SQLite не поддерживает DROP INDEX в Schema Builder так же как MySQL
        // Пропускаем удаление индексов для SQLite (тесты)
        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::table('products', function (Blueprint $table) {
                // Удаляем старый уникальный индекс (только для MySQL/PostgreSQL)
                try {
                    $table->dropUnique('products_marketplace_marketplace_id_unique');
                } catch (\Exception $e) {
                    // Индекс может не существовать
                }
                try {
                    $table->dropIndex('products_marketplace_id_index');
                } catch (\Exception $e) {
                    // Индекс может не существовать
                }
            });
        }
        
        Schema::table('products', function (Blueprint $table) {
            // Создаём новые составные уникальные индексы с integration_id
            if (!$this->indexExists('products', 'products_marketplace_integration_unique')) {
                $table->unique(['marketplace', 'marketplace_id', 'integration_id'], 'products_marketplace_integration_unique');
            }
            
            // Индекс для быстрого поиска
            if (!$this->indexExists('products', 'products_marketplace_integration_index')) {
                $table->index(['marketplace', 'marketplace_id', 'integration_id'], 'products_marketplace_integration_index');
            }
            if (!$this->indexExists('products', 'products_integration_id_index')) {
                $table->index('integration_id', 'products_integration_id_index');
            }
        });
    }
    
    private function indexExists(string $table, string $indexName): bool
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
        
        // MySQL
        $indexes = DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$indexName]);
        return count($indexes) > 0;
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_marketplace_integration_unique');
            $table->dropIndex('products_marketplace_integration_index');
            $table->dropIndex('products_integration_id_index');
            
            // Восстанавливаем старые индексы
            $table->unique(['marketplace', 'marketplace_id'], 'products_marketplace_marketplace_id_unique');
            $table->index(['marketplace', 'marketplace_id'], 'products_marketplace_id_index');
        });
    }
};
