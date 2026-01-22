<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Обновление таблицы supply_recommendations и создание новых таблиц модуля поставок
 */
return new class extends Migration
{
    public function up(): void
    {
        // Обновляем supply_recommendations если нужно
        if (Schema::hasTable('supply_recommendations') && !Schema::hasColumn('supply_recommendations', 'avg_sales_7d')) {
            Schema::table('supply_recommendations', function (Blueprint $table) {
                // Добавляем новые поля если их нет
                if (!Schema::hasColumn('supply_recommendations', 'product_id')) {
                    $table->foreignId('product_id')->nullable()->after('integration_id')->constrained()->onDelete('cascade');
                }
                if (!Schema::hasColumn('supply_recommendations', 'ozon_product_id')) {
                    $table->string('ozon_product_id', 50)->nullable()->after('sku');
                }
                if (!Schema::hasColumn('supply_recommendations', 'cluster_id')) {
                    $table->string('cluster_id', 50)->nullable()->after('warehouse_name')->index();
                }
                if (!Schema::hasColumn('supply_recommendations', 'cluster_name')) {
                    $table->string('cluster_name', 200)->nullable()->after('cluster_id');
                }
                if (!Schema::hasColumn('supply_recommendations', 'avg_sales_7d')) {
                    $table->decimal('avg_sales_7d', 12, 4)->default(0)->after('cluster_name');
                }
                if (!Schema::hasColumn('supply_recommendations', 'avg_sales_14d')) {
                    $table->decimal('avg_sales_14d', 12, 4)->default(0)->after('avg_sales_7d');
                }
                if (!Schema::hasColumn('supply_recommendations', 'avg_sales_28d')) {
                    $table->decimal('avg_sales_28d', 12, 4)->default(0)->after('avg_sales_14d');
                }
                if (!Schema::hasColumn('supply_recommendations', 'state')) {
                    $table->string('state', 20)->default('new')->after('lead_time_days')->index();
                }
                if (!Schema::hasColumn('supply_recommendations', 'oos_risk')) {
                    $table->boolean('oos_risk')->default(false)->after('days_of_stock');
                }
                if (!Schema::hasColumn('supply_recommendations', 'overstock_risk')) {
                    $table->boolean('overstock_risk')->default(false)->after('oos_risk');
                }
                if (!Schema::hasColumn('supply_recommendations', 'supply_id')) {
                    $table->unsignedBigInteger('supply_id')->nullable()->after('supply_plan_id')->index();
                }
            });
        }

        // Создаём таблицу supplies если не существует
        if (!Schema::hasTable('supplies')) {
            Schema::create('supplies', function (Blueprint $table) {
                $table->id();
                $table->foreignId('integration_id')->constrained()->onDelete('cascade');
                $table->string('crm_number', 50)->unique();
                $table->string('ozon_supply_id', 50)->nullable()->index();
                $table->string('ozon_draft_id', 50)->nullable()->index();
                $table->enum('supply_type', ['fbo', 'fbs', 'realfbs'])->default('fbo');
                $table->enum('supply_method', ['direct', 'crossdock', 'multi_cluster'])->default('direct');
                $table->enum('delivery_scheme', ['drop_off', 'pick_up'])->nullable();
                $table->string('cluster_id', 50)->nullable()->index();
                $table->string('cluster_name', 200)->nullable();
                $table->string('warehouse_id', 50)->nullable()->index();
                $table->string('warehouse_name', 200)->nullable();
                $table->string('drop_off_point_id', 50)->nullable();
                $table->string('drop_off_point_type', 50)->nullable();
                $table->string('seller_warehouse_id', 50)->nullable();
                $table->string('timeslot_id', 50)->nullable();
                $table->timestamp('timeslot_from')->nullable();
                $table->timestamp('timeslot_to')->nullable();
                $table->date('planned_delivery_date')->nullable()->index();
                $table->integer('items_count')->default(0);
                $table->integer('total_quantity')->default(0);
                $table->integer('total_boxes')->default(0);
                $table->decimal('total_weight', 12, 3)->default(0);
                $table->decimal('total_volume', 12, 6)->default(0);
                $table->string('status', 30)->default('draft')->index();
                $table->string('ozon_status', 100)->nullable();
                $table->string('ozon_status_description', 500)->nullable();
                $table->timestamp('created_in_ozon_at')->nullable();
                $table->timestamp('slot_booked_at')->nullable();
                $table->timestamp('preparing_started_at')->nullable();
                $table->timestamp('ready_to_ship_at')->nullable();
                $table->timestamp('shipped_at')->nullable();
                $table->timestamp('arrived_at')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->integer('accepted_quantity')->nullable();
                $table->integer('rejected_quantity')->nullable();
                $table->json('acceptance_discrepancies')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('supply_plan_id')->nullable()->constrained('supply_plans')->nullOnDelete();
                $table->text('comment')->nullable();
                $table->json('meta')->nullable();
                $table->json('ozon_response')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['integration_id', 'status']);
                $table->index(['integration_id', 'created_at']);
            });
        }

        // Создаём таблицу supply_items если не существует
        if (!Schema::hasTable('supply_items')) {
            Schema::create('supply_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supply_id')->constrained('supplies')->onDelete('cascade');
                $table->foreignId('product_id')->nullable()->constrained()->onDelete('set null');
                $table->string('sku', 100)->index();
                $table->string('ozon_product_id', 50)->nullable();
                $table->string('barcode', 100)->nullable();
                $table->string('product_name', 500)->nullable();
                $table->integer('planned_qty')->default(0);
                $table->integer('packed_qty')->default(0);
                $table->integer('shipped_qty')->default(0);
                $table->integer('accepted_qty')->nullable();
                $table->integer('rejected_qty')->nullable();
                $table->integer('pack_multiple')->default(1);
                $table->integer('boxes_count')->default(0);
                $table->decimal('weight', 10, 3)->nullable();
                $table->decimal('length', 10, 2)->nullable();
                $table->decimal('width', 10, 2)->nullable();
                $table->decimal('height', 10, 2)->nullable();
                $table->string('status', 20)->default('pending');
                $table->string('rejection_reason', 500)->nullable();
                $table->unsignedBigInteger('recommendation_id')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->index(['supply_id', 'sku']);
                $table->index(['supply_id', 'status']);
            });
        }

        // Создаём таблицу supply_events если не существует
        if (!Schema::hasTable('supply_events')) {
            Schema::create('supply_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supply_id')->constrained('supplies')->onDelete('cascade');
                $table->string('event_type', 30)->index();
                $table->string('title', 200)->nullable();
                $table->text('description')->nullable();
                $table->string('old_value', 500)->nullable();
                $table->string('new_value', 500)->nullable();
                $table->json('changes')->nullable();
                $table->string('api_method', 100)->nullable();
                $table->string('api_endpoint', 200)->nullable();
                $table->json('api_request_body')->nullable();
                $table->json('api_response_body')->nullable();
                $table->integer('api_response_code')->nullable();
                $table->integer('api_duration_ms')->nullable();
                $table->string('error_code', 50)->nullable();
                $table->text('error_message')->nullable();
                $table->json('error_context')->nullable();
                $table->boolean('is_critical')->default(false);
                $table->boolean('is_resolved')->default(false);
                $table->timestamp('resolved_at')->nullable();
                $table->string('initiated_by', 20)->default('system');
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['supply_id', 'event_type']);
                $table->index(['supply_id', 'created_at']);
            });
        }

        // Создаём таблицу timeslots_cache если не существует
        if (!Schema::hasTable('timeslots_cache')) {
            Schema::create('timeslots_cache', function (Blueprint $table) {
                $table->id();
                $table->foreignId('integration_id')->constrained()->onDelete('cascade');
                $table->string('cluster_id', 50)->nullable()->index();
                $table->string('cluster_name', 200)->nullable();
                $table->string('warehouse_id', 50)->index();
                $table->string('warehouse_name', 200)->nullable();
                $table->string('draft_id', 50)->nullable()->index();
                $table->string('timeslot_id', 50)->index();
                $table->date('slot_date')->index();
                $table->time('time_from');
                $table->time('time_to');
                $table->timestamp('datetime_from');
                $table->timestamp('datetime_to');
                $table->boolean('is_available')->default(true);
                $table->integer('capacity')->nullable();
                $table->integer('booked_count')->default(0);
                $table->integer('remaining_capacity')->nullable();
                $table->json('restrictions')->nullable();
                $table->timestamp('fetched_at')->useCurrent();
                $table->timestamp('expires_at')->index();
                $table->timestamps();
                $table->index(['integration_id', 'warehouse_id', 'slot_date']);
            });
        }

        // Создаём таблицу supply_settings если не существует
        if (!Schema::hasTable('supply_settings')) {
            Schema::create('supply_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('integration_id')->unique()->constrained()->onDelete('cascade');
                $table->string('default_sales_window', 5)->default('14d');
                $table->integer('target_days_a')->default(21);
                $table->integer('target_days_b')->default(14);
                $table->integer('target_days_c')->default(7);
                $table->integer('safety_stock_days')->default(3);
                $table->decimal('safety_stock_percent', 5, 2)->default(10);
                $table->string('safety_stock_mode', 10)->default('days');
                $table->integer('default_lead_time_days')->default(3);
                $table->integer('min_order_qty')->default(1);
                $table->integer('default_pack_multiple')->default(1);
                $table->integer('oos_risk_days')->default(3);
                $table->integer('overstock_days')->default(60);
                $table->json('preferred_weekdays')->nullable();
                $table->time('preferred_time_from')->nullable();
                $table->time('preferred_time_to')->nullable();
                $table->integer('max_supplies_per_day')->default(3);
                $table->integer('max_items_per_supply')->default(100);
                $table->integer('max_qty_per_supply')->default(10000);
                $table->boolean('auto_book_slot')->default(false);
                $table->integer('auto_book_oos_threshold_days')->default(2);
                $table->boolean('notify_no_slots')->default(true);
                $table->integer('notify_no_slots_days')->default(7);
                $table->boolean('notify_oos_risk')->default(true);
                $table->boolean('notify_stuck_supply')->default(true);
                $table->integer('notify_stuck_hours')->default(24);
                $table->boolean('notify_api_errors')->default(true);
                $table->boolean('notify_acceptance_issues')->default(true);
                $table->json('notification_channels')->nullable();
                $table->json('notification_recipients')->nullable();
                $table->json('excluded_skus')->nullable();
                $table->json('excluded_categories')->nullable();
                $table->json('restricted_skus')->nullable();
                $table->boolean('is_active')->default(true);
                $table->json('custom_rules')->nullable();
                $table->timestamps();
            });
        }

        // Создаём таблицу supply_analytics если не существует
        if (!Schema::hasTable('supply_analytics')) {
            Schema::create('supply_analytics', function (Blueprint $table) {
                $table->id();
                $table->foreignId('integration_id')->constrained()->onDelete('cascade');
                $table->date('date')->index();
                $table->string('period_type', 10)->default('daily');
                $table->string('cluster_id', 50)->nullable()->index();
                $table->string('warehouse_id', 50)->nullable()->index();
                $table->string('sku', 100)->nullable()->index();
                $table->integer('recommendations_generated')->default(0);
                $table->integer('recommendations_accepted')->default(0);
                $table->integer('recommendations_rejected')->default(0);
                $table->integer('recommendations_expired')->default(0);
                $table->decimal('fill_rate', 5, 2)->nullable();
                $table->integer('oos_skus_count')->default(0);
                $table->decimal('oos_rate', 5, 2)->nullable();
                $table->decimal('oos_revenue_lost', 15, 2)->nullable();
                $table->integer('overstock_skus_count')->default(0);
                $table->decimal('overstock_rate', 5, 2)->nullable();
                $table->decimal('overstock_value', 15, 2)->nullable();
                $table->decimal('forecast_accuracy', 5, 2)->nullable();
                $table->decimal('demand_vs_actual', 8, 2)->nullable();
                $table->integer('supplies_created')->default(0);
                $table->integer('supplies_completed')->default(0);
                $table->integer('supplies_cancelled')->default(0);
                $table->integer('supplies_with_errors')->default(0);
                $table->integer('slots_booked')->default(0);
                $table->integer('slots_changed')->default(0);
                $table->integer('slots_missed')->default(0);
                $table->decimal('avg_lead_time_days', 5, 2)->nullable();
                $table->decimal('planned_vs_actual_lead_time', 5, 2)->nullable();
                $table->integer('items_shipped')->default(0);
                $table->integer('items_accepted')->default(0);
                $table->integer('items_rejected')->default(0);
                $table->decimal('acceptance_rate', 5, 2)->nullable();
                $table->integer('discrepancies_count')->default(0);
                $table->decimal('sla_on_time_rate', 5, 2)->nullable();
                $table->integer('sla_violations')->default(0);
                $table->decimal('total_supplied_value', 15, 2)->nullable();
                $table->decimal('logistics_cost', 15, 2)->nullable();
                $table->timestamps();
                $table->index(['integration_id', 'date', 'period_type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_analytics');
        Schema::dropIfExists('supply_settings');
        Schema::dropIfExists('timeslots_cache');
        Schema::dropIfExists('supply_events');
        Schema::dropIfExists('supply_items');
        Schema::dropIfExists('supplies');
    }
};
