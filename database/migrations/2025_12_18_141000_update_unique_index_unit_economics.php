<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Обновляем уникальный индекс для поддержки нескольких схем работы на один товар.
     * Старый индекс: sku + integration_id
     * Новый индекс: sku + integration_id + fulfillment_type
     */
    public function up(): void
    {
        // Безопасное удаление индексов
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS unit_economics_sku_integration_unique');
        } elseif ($driver === 'mysql') {
            try {
                Schema::table('unit_economics', function (Blueprint $table) {
                    $table->dropUnique('unit_economics_sku_integration_unique');
                });
            } catch (\Exception $e) {}
        }
        
        Schema::table('unit_economics', function (Blueprint $table) {
            // Создаём новый уникальный индекс с fulfillment_type
            if (!$this->indexExists('unit_economics', 'unit_economics_sku_integration_scheme_unique')) {
                $table->unique(['sku', 'integration_id', 'fulfillment_type'], 'unit_economics_sku_integration_scheme_unique');
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
        
        if ($driver === 'pgsql') {
            $indexes = DB::select("SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?", [$table, $indexName]);
            return count($indexes) > 0;
        }
        
        // MySQL
        $indexes = DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$indexName]);
        return count($indexes) > 0;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            $table->dropUnique('unit_economics_sku_integration_scheme_unique');
            $table->unique(['sku', 'integration_id'], 'unit_economics_sku_integration_unique');
        });
    }
};
