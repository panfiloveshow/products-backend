<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $t = 'auto_supply_plan_lines';
        $cols = [
            'price'              => fn (Blueprint $tb) => $tb->decimal('price', 10, 2)->nullable(),
            'cost_price'         => fn (Blueprint $tb) => $tb->decimal('cost_price', 10, 2)->nullable(),
            'sales_trend'        => fn (Blueprint $tb) => $tb->string('sales_trend', 20)->nullable(),
            'sales_trend_percent'=> fn (Blueprint $tb) => $tb->decimal('sales_trend_percent', 8, 2)->nullable(),
            'destination_id'     => fn (Blueprint $tb) => $tb->string('destination_id')->nullable(),
            'destination_type'   => fn (Blueprint $tb) => $tb->string('destination_type', 20)->nullable(),
            'qty_recommended'    => fn (Blueprint $tb) => $tb->decimal('qty_recommended', 10, 2)->default(0),
            'demand_daily'       => fn (Blueprint $tb) => $tb->decimal('demand_daily', 10, 4)->default(0),
            'cover_days_before'  => fn (Blueprint $tb) => $tb->decimal('cover_days_before', 8, 2)->nullable(),
            'cover_days_after'   => fn (Blueprint $tb) => $tb->decimal('cover_days_after', 8, 2)->nullable(),
            'oos_date'           => fn (Blueprint $tb) => $tb->string('oos_date', 20)->nullable(),
            'surplus_days'       => fn (Blueprint $tb) => $tb->unsignedInteger('surplus_days')->nullable(),
            'storage_cost_daily' => fn (Blueprint $tb) => $tb->decimal('storage_cost_daily', 10, 2)->nullable(),
            'storage_cost_monthly'=> fn (Blueprint $tb) => $tb->decimal('storage_cost_monthly', 10, 2)->nullable(),
            'lost_revenue_daily' => fn (Blueprint $tb) => $tb->decimal('lost_revenue_daily', 10, 2)->nullable(),
            'supply_cost_estimate'=> fn (Blueprint $tb) => $tb->decimal('supply_cost_estimate', 10, 2)->nullable(),
            'expected_revenue'   => fn (Blueprint $tb) => $tb->decimal('expected_revenue', 10, 2)->nullable(),
            'expected_profit'    => fn (Blueprint $tb) => $tb->decimal('expected_profit', 10, 2)->nullable(),
            'roi_percent'        => fn (Blueprint $tb) => $tb->decimal('roi_percent', 8, 2)->nullable(),
            'priority_score'     => fn (Blueprint $tb) => $tb->decimal('priority_score', 8, 2)->nullable(),
            'priority'           => fn (Blueprint $tb) => $tb->string('priority', 20)->nullable(),
            'turnover_days'      => fn (Blueprint $tb) => $tb->decimal('turnover_days', 8, 1)->nullable(),
            'tenant_id'          => fn (Blueprint $tb) => $tb->uuid('tenant_id')->nullable(),
        ];

        foreach ($cols as $col => $defn) {
            if (!Schema::hasColumn($t, $col)) {
                Schema::table($t, function (Blueprint $table) use ($defn) {
                    $defn($table);
                });
            }
        }

        // Индексы (безопасно через try/catch)
        Schema::table($t, function (Blueprint $table) use ($t) {
            try { $table->index('priority'); } catch (\Throwable $e) {}
            try { $table->index('sales_trend'); } catch (\Throwable $e) {}
            try { $table->index('tenant_id'); } catch (\Throwable $e) {}
        });
    }

    public function down(): void
    {
        Schema::table('auto_supply_plan_lines', function (Blueprint $table) {
            $table->dropIndex(['priority']);
            $table->dropIndex(['sales_trend']);
            $table->dropIndex(['tenant_id']);

            $table->dropColumn([
                'price', 'cost_price',
                'sales_trend', 'sales_trend_percent',
                'destination_id', 'destination_type', 'qty_recommended', 'demand_daily',
                'cover_days_before', 'cover_days_after', 'oos_date', 'surplus_days',
                'storage_cost_daily', 'storage_cost_monthly',
                'lost_revenue_daily', 'supply_cost_estimate', 'expected_revenue', 'expected_profit', 'roi_percent',
                'priority_score', 'priority', 'turnover_days',
                'tenant_id',
            ]);
        });
    }
};
