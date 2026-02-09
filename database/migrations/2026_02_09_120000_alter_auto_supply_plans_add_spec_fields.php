<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_supply_plans', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            $table->unsignedBigInteger('mp_account_id')->nullable()->after('integration_id');
            $table->string('mode', 20)->default('balanced')->after('status'); // anti_oos|balanced|cash_safe
            $table->unsignedSmallInteger('horizon_days')->default(28)->after('mode'); // 7|14|28|56
            $table->unsignedSmallInteger('min_cover_days')->default(7)->after('horizon_days');
            $table->unsignedSmallInteger('target_cover_days')->default(21)->after('min_cover_days');
            $table->unsignedSmallInteger('max_cover_days')->default(42)->after('target_cover_days');
            $table->unsignedSmallInteger('safety_stock_days')->default(5)->after('max_cover_days');
            $table->unsignedSmallInteger('turnover_limit_days')->nullable()->after('safety_stock_days');
            $table->decimal('budget_limit', 12, 2)->nullable()->after('turnover_limit_days');
            $table->string('forecast_model', 50)->default('EWMA_0.35')->after('budget_limit');
            $table->string('algorithm_version', 20)->default('asp-1.0.0')->after('forecast_model');
            $table->json('data_quality_json')->nullable()->after('data_quality_score');

            $table->index(['tenant_id', 'marketplace', 'mp_account_id', 'created_at'], 'asp_tenant_mp_account_created');
            $table->index(['tenant_id', 'status'], 'asp_tenant_status');
        });

        Schema::table('auto_supply_plan_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            $table->string('destination_id')->nullable()->after('destination');
            $table->string('destination_type', 20)->default('all')->after('destination_id'); // cluster|warehouse|all
            $table->decimal('demand_daily', 10, 4)->default(0)->after('ewma_daily_sales');
            $table->decimal('cover_days_before', 8, 2)->nullable()->after('demand_daily');
            $table->decimal('cover_days_after', 8, 2)->nullable()->after('cover_days_before');
            $table->date('oos_date')->nullable()->after('cover_days_after');
            $table->unsignedSmallInteger('surplus_days')->nullable()->after('oos_date');

            // Переименовать qty_raw → qty_recommended
            $table->renameColumn('qty_raw', 'qty_recommended');

            $table->index(['auto_supply_plan_id', 'offer_id', 'destination_id'], 'aspl_plan_offer_dest');
        });
    }

    public function down(): void
    {
        Schema::table('auto_supply_plan_lines', function (Blueprint $table) {
            $table->renameColumn('qty_recommended', 'qty_raw');
            $table->dropIndex('aspl_plan_offer_dest');
            $table->dropColumn([
                'tenant_id', 'destination_id', 'destination_type',
                'demand_daily', 'cover_days_before', 'cover_days_after',
                'oos_date', 'surplus_days',
            ]);
        });

        Schema::table('auto_supply_plans', function (Blueprint $table) {
            $table->dropIndex('asp_tenant_mp_account_created');
            $table->dropIndex('asp_tenant_status');
            $table->dropColumn([
                'tenant_id', 'mp_account_id', 'mode', 'horizon_days',
                'min_cover_days', 'target_cover_days', 'max_cover_days',
                'safety_stock_days', 'turnover_limit_days', 'budget_limit',
                'forecast_model', 'algorithm_version', 'data_quality_json',
            ]);
        });
    }
};
