<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supply_recommendations', function (Blueprint $table) {
            // Добавляем недостающие колонки для новой модели
            if (!Schema::hasColumn('supply_recommendations', 'sku')) {
                $table->string('sku', 100)->nullable()->after('product_id');
            }
            if (!Schema::hasColumn('supply_recommendations', 'product_name')) {
                $table->string('product_name', 500)->nullable()->after('ozon_product_id');
            }
            if (!Schema::hasColumn('supply_recommendations', 'avg_sales_used')) {
                $table->decimal('avg_sales_used', 12, 4)->default(0)->after('avg_sales_28d');
            }
            if (!Schema::hasColumn('supply_recommendations', 'sales_window')) {
                $table->string('sales_window', 10)->default('14d')->after('avg_sales_used');
            }
            if (!Schema::hasColumn('supply_recommendations', 'current_stock')) {
                $table->integer('current_stock')->default(0)->after('sales_window');
            }
            if (!Schema::hasColumn('supply_recommendations', 'in_transit')) {
                $table->integer('in_transit')->default(0)->after('current_stock');
            }
            if (!Schema::hasColumn('supply_recommendations', 'safety_stock')) {
                $table->integer('safety_stock')->default(0)->after('in_transit');
            }
            if (!Schema::hasColumn('supply_recommendations', 'target_days')) {
                $table->integer('target_days')->default(14)->after('safety_stock');
            }
            if (!Schema::hasColumn('supply_recommendations', 'demand')) {
                $table->integer('demand')->default(0)->after('target_days');
            }
            if (!Schema::hasColumn('supply_recommendations', 'need_raw')) {
                $table->integer('need_raw')->default(0)->after('demand');
            }
            if (!Schema::hasColumn('supply_recommendations', 'recommended_qty')) {
                $table->integer('recommended_qty')->default(0)->after('need_raw');
            }
            if (!Schema::hasColumn('supply_recommendations', 'pack_multiple')) {
                $table->integer('pack_multiple')->default(1)->after('recommended_qty');
            }
            if (!Schema::hasColumn('supply_recommendations', 'min_order_qty')) {
                $table->integer('min_order_qty')->default(1)->after('pack_multiple');
            }
            if (!Schema::hasColumn('supply_recommendations', 'priority_score')) {
                $table->decimal('priority_score', 8, 2)->default(0)->after('priority');
            }
            if (!Schema::hasColumn('supply_recommendations', 'days_of_stock')) {
                $table->integer('days_of_stock')->default(0)->after('priority_score');
            }
            if (!Schema::hasColumn('supply_recommendations', 'reasons')) {
                $table->json('reasons')->nullable()->after('overstock_risk');
            }
            if (!Schema::hasColumn('supply_recommendations', 'warnings')) {
                $table->json('warnings')->nullable()->after('reasons');
            }
            if (!Schema::hasColumn('supply_recommendations', 'restrictions')) {
                $table->json('restrictions')->nullable()->after('warnings');
            }
            if (!Schema::hasColumn('supply_recommendations', 'recommended_create_date')) {
                $table->date('recommended_create_date')->nullable()->after('restrictions');
            }
            if (!Schema::hasColumn('supply_recommendations', 'recommended_delivery_date')) {
                $table->date('recommended_delivery_date')->nullable()->after('recommended_create_date');
            }
            if (!Schema::hasColumn('supply_recommendations', 'lead_time_days')) {
                $table->integer('lead_time_days')->default(3)->after('recommended_delivery_date');
            }
            if (!Schema::hasColumn('supply_recommendations', 'user_qty')) {
                $table->integer('user_qty')->nullable()->after('state');
            }
            if (!Schema::hasColumn('supply_recommendations', 'user_comment')) {
                $table->text('user_comment')->nullable()->after('user_qty');
            }
            if (!Schema::hasColumn('supply_recommendations', 'processed_by')) {
                $table->unsignedBigInteger('processed_by')->nullable()->after('user_comment');
            }
            if (!Schema::hasColumn('supply_recommendations', 'processed_at')) {
                $table->timestamp('processed_at')->nullable()->after('processed_by');
            }
            if (!Schema::hasColumn('supply_recommendations', 'supply_plan_id')) {
                $table->unsignedBigInteger('supply_plan_id')->nullable()->after('processed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('supply_recommendations', function (Blueprint $table) {
            $columns = [
                'sku', 'product_name', 'avg_sales_used', 'sales_window',
                'current_stock', 'in_transit', 'safety_stock', 'target_days',
                'demand', 'need_raw', 'recommended_qty', 'pack_multiple',
                'min_order_qty', 'priority_score', 'days_of_stock',
                'reasons', 'warnings', 'restrictions',
                'recommended_create_date', 'recommended_delivery_date', 'lead_time_days',
                'user_qty', 'user_comment', 'processed_by', 'processed_at', 'supply_plan_id'
            ];
            
            foreach ($columns as $column) {
                if (Schema::hasColumn('supply_recommendations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
