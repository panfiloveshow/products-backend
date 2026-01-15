<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Для SQLite нужно пересоздать таблицу, так как ALTER COLUMN не поддерживается
        // Временное решение: изменяем тип колонки на string
        if (DB::getDriverName() === 'sqlite') {
            // SQLite не поддерживает изменение типа колонки напрямую
            // Создаём временную таблицу, копируем данные, удаляем старую, переименовываем
            DB::statement('PRAGMA foreign_keys=off;');
            
            DB::statement('
                CREATE TABLE unit_economics_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    product_id INTEGER,
                    integration_id INTEGER,
                    product_name VARCHAR(500),
                    sku VARCHAR(100) NOT NULL,
                    marketplace VARCHAR(50) NOT NULL,
                    price DECIMAL(12,2),
                    cost_price DECIMAL(12,2),
                    sales_count INTEGER,
                    revenue DECIMAL(14,2),
                    total_costs DECIMAL(14,2),
                    gross_profit DECIMAL(14,2),
                    net_profit DECIMAL(14,2),
                    margin_percent DECIMAL(6,2),
                    roi_percent DECIMAL(6,2),
                    period_start DATE,
                    period_end DATE,
                    marketplace_data TEXT,
                    created_at DATETIME,
                    updated_at DATETIME
                )
            ');
            
            DB::statement('
                INSERT INTO unit_economics_new 
                SELECT id, product_id, integration_id, product_name, sku, marketplace, price, cost_price, 
                       sales_count, revenue, total_costs, gross_profit, net_profit, margin_percent, 
                       roi_percent, period_start, period_end, marketplace_data, created_at, updated_at
                FROM unit_economics
            ');
            
            DB::statement('DROP TABLE unit_economics');
            DB::statement('ALTER TABLE unit_economics_new RENAME TO unit_economics');
            
            // Восстанавливаем индексы
            DB::statement('CREATE UNIQUE INDEX unit_economics_unique ON unit_economics (sku, marketplace, period_start, period_end)');
            DB::statement('CREATE INDEX unit_economics_sku_index ON unit_economics (sku)');
            DB::statement('CREATE INDEX unit_economics_marketplace_index ON unit_economics (marketplace)');
            DB::statement('CREATE INDEX unit_economics_period_index ON unit_economics (period_start, period_end)');
            DB::statement('CREATE INDEX unit_economics_integration_id_index ON unit_economics (integration_id)');
            
            DB::statement('PRAGMA foreign_keys=on;');
        } else {
            // Для MySQL/PostgreSQL
            Schema::table('unit_economics', function (Blueprint $table) {
                $table->string('marketplace', 50)->change();
            });
        }
    }

    public function down(): void
    {
        // Откат не требуется
    }
};
