<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_line_evaluations', function (Blueprint $table) {
            $table->id();
            $table->uuid('auto_supply_plan_id')->index();
            $table->unsignedBigInteger('auto_supply_plan_line_id')->index();
            $table->unsignedBigInteger('integration_id')->index();
            $table->string('marketplace', 50);
            $table->string('sku')->index();
            $table->string('cluster_id')->nullable();
            $table->string('warehouse_id')->nullable();

            // Окно оценки
            $table->timestamp('plan_created_at');
            $table->unsignedInteger('horizon_days');
            $table->timestamp('evaluated_at');

            // A: прогноз спроса vs факт
            $table->decimal('forecast_demand_qty', 12, 2)->nullable();
            $table->decimal('actual_sales_qty', 12, 2)->nullable();
            $table->decimal('abs_pct_error', 8, 2)->nullable();   // APE, %
            $table->decimal('bias_pct', 8, 2)->nullable();        // signed
            $table->string('demand_fact_source', 40)->nullable(); // postings_fbo | ozon_order_report

            // B: исполнение поставки
            $table->integer('planned_qty')->nullable();
            $table->integer('accepted_qty')->nullable();
            $table->integer('rejected_qty')->nullable();
            $table->decimal('acceptance_rate', 6, 2)->nullable();

            $table->string('status', 20)->default('ok'); // ok | insufficient_data | error
            $table->json('details_json')->nullable();
            $table->timestamps();

            $table->unique(['auto_supply_plan_line_id'], 'plan_line_eval_unique');
            $table->index(['integration_id', 'sku', 'evaluated_at'], 'plan_line_eval_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_line_evaluations');
    }
};
