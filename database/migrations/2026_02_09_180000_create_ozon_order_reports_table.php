<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Загруженные отчёты
        Schema::create('ozon_order_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('integration_id')->index();
            $table->string('filename');
            $table->string('period_label')->nullable(); // "14 дней", "3 месяца" и т.д.
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->integer('total_orders')->default(0);
            $table->integer('total_items')->default(0);
            $table->integer('unique_skus')->default(0);
            $table->integer('unique_warehouses')->default(0);
            $table->string('status')->default('processing'); // processing, ready, error
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        // Агрегированные продажи по SKU + склад (из отчёта)
        Schema::create('ozon_warehouse_sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('report_id')->index();
            $table->unsignedBigInteger('integration_id')->index();
            $table->string('sku', 100)->index();
            $table->string('article', 100)->nullable()->index(); // Артикул продавца
            $table->string('product_name')->nullable();
            $table->string('warehouse_name', 200)->index();
            $table->string('shipment_cluster', 200)->nullable(); // Кластер отгрузки
            $table->string('delivery_cluster', 200)->nullable(); // Кластер доставки
            $table->integer('orders_count')->default(0); // Кол-во заказов
            $table->integer('items_sold')->default(0); // Кол-во проданных штук
            $table->decimal('revenue', 12, 2)->default(0); // Выручка
            $table->decimal('avg_daily_sales', 8, 2)->default(0); // Среднедневные продажи
            $table->integer('period_days')->default(14); // Период отчёта в днях
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->timestamps();

            $table->unique(['report_id', 'sku', 'warehouse_name'], 'uq_report_sku_wh');
            $table->foreign('report_id')->references('id')->on('ozon_order_reports')->onDelete('cascade');
        });

        // Добавляем поля реальных продаж по складу в inventory_warehouses
        Schema::table('inventory_warehouses', function (Blueprint $table) {
            $table->decimal('real_avg_daily_sales', 8, 2)->nullable()->after('effective_daily_sales');
            $table->integer('real_sales_period_days')->nullable()->after('real_avg_daily_sales');
            $table->decimal('real_turnover_days', 8, 1)->nullable()->after('real_sales_period_days');
            $table->integer('real_days_of_stock')->nullable()->after('real_turnover_days');
            $table->uuid('sales_report_id')->nullable()->after('real_days_of_stock');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_warehouses', function (Blueprint $table) {
            $table->dropColumn(['real_avg_daily_sales', 'real_sales_period_days', 'real_turnover_days', 'real_days_of_stock', 'sales_report_id']);
        });
        Schema::dropIfExists('ozon_warehouse_sales');
        Schema::dropIfExists('ozon_order_reports');
    }
};
