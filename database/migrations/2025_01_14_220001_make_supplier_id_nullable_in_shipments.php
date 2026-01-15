<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite не поддерживает ALTER COLUMN, поэтому пересоздаём таблицу
        if (config('database.default') === 'sqlite') {
            // Для SQLite просто игнорируем - constraint уже nullable в новых записях
            // или используем raw SQL
            DB::statement('PRAGMA foreign_keys=off;');
            
            // Создаём временную таблицу
            Schema::create('shipments_temp', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('supply_plan_id')->nullable();
                $table->unsignedBigInteger('integration_id')->nullable();
                $table->string('warehouse_id')->nullable();
                $table->string('external_supply_id')->nullable();
                $table->string('external_status')->nullable();
                $table->string('name');
                $table->string('status')->default('draft');
                $table->string('marketplace');
                $table->string('shipment_type')->default('fbo');
                $table->string('warehouse_name')->nullable();
                $table->text('description')->nullable();
                $table->json('meta')->nullable();
                $table->date('planned_date')->nullable();
                $table->uuid('supplier_id')->nullable(); // Теперь nullable
                $table->string('supplier_name')->nullable();
                $table->string('supplier_address')->nullable();
                $table->json('slot')->nullable();
                $table->json('marketplace_requirements')->nullable();
                $table->json('packaging')->nullable();
                $table->integer('total_items')->default(0);
                $table->integer('total_quantity')->default(0);
                $table->decimal('total_cost', 12, 2)->default(0);
                $table->decimal('total_volume', 10, 4)->default(0);
                $table->decimal('total_weight', 10, 4)->default(0);
                $table->string('truck_type')->nullable();
                $table->decimal('truck_capacity', 10, 2)->nullable();
                $table->decimal('delivery_cost', 12, 2)->nullable();
                $table->decimal('delivery_cost_percent', 5, 2)->nullable();
                $table->decimal('utilization_percent', 5, 2)->nullable();
                $table->json('logistics_approval')->nullable();
                $table->uuid('created_by')->nullable();
                $table->string('created_by_name')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();
            });
            
            // Копируем данные
            DB::statement('INSERT INTO shipments_temp SELECT * FROM shipments;');
            
            // Удаляем старую таблицу
            Schema::drop('shipments');
            
            // Переименовываем
            Schema::rename('shipments_temp', 'shipments');
            
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
