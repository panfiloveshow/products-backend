<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locality_recommendations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id');
            $table->string('sku', 100);
            $table->string('offer_id', 100)->nullable();
            $table->unsignedBigInteger('product_id')->nullable();

            $table->string('target_cluster_id', 50)->nullable();
            $table->string('target_cluster_name', 120);

            $table->unsignedInteger('recommended_qty_units')->default(0);
            $table->unsignedSmallInteger('pack_multiple')->default(1);
            $table->unsignedInteger('current_stock_cluster')->default(0);
            $table->unsignedInteger('in_transit_cluster')->default(0);
            $table->decimal('daily_demand_cluster', 10, 3)->default(0);
            $table->decimal('volatility_cluster', 6, 4)->nullable();
            $table->unsignedInteger('gap_units')->default(0);

            $table->decimal('expected_savings_rub', 12, 2)->default(0);
            $table->decimal('expected_monthly_savings_rub', 12, 2)->default(0);
            $table->decimal('expected_local_share_uplift_pp', 6, 2)->default(0);
            $table->decimal('expected_days_of_cover', 8, 2)->nullable();
            $table->decimal('avg_markup_amount_rub', 10, 2)->default(0);
            $table->decimal('avg_base_logistics_rub', 10, 2)->default(0);

            $table->string('confidence', 16)->default('low');
            $table->decimal('confidence_score', 5, 2)->default(0);
            $table->decimal('rank_score', 14, 2)->default(0);
            $table->unsignedInteger('rank_position')->nullable();

            $table->text('reasoning_text')->nullable();
            $table->json('warnings')->nullable();
            $table->json('constraints_checked')->nullable();

            $table->string('state', 32)->default('new');
            $table->timestamp('dismissed_at')->nullable();
            $table->unsignedBigInteger('dismissed_by')->nullable();
            $table->string('dismiss_reason', 100)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->unsignedBigInteger('applied_by')->nullable();
            $table->unsignedBigInteger('linked_supply_order_id')->nullable();
            $table->string('linked_draft_id', 50)->nullable();

            $table->uuid('cohort_id');
            $table->timestamp('computed_at');
            $table->date('period_from');
            $table->date('period_to');
            $table->date('basis_snapshot_date');
            $table->unsignedSmallInteger('lead_time_days')->default(14);
            $table->date('expires_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['integration_id', 'sku', 'target_cluster_id', 'cohort_id'],
                'locality_recs_sku_cluster_cohort_unique'
            );
            $table->index(['integration_id', 'state', 'rank_score'], 'locality_recs_rank_idx');
            $table->index(['integration_id', 'target_cluster_id', 'state'], 'locality_recs_cluster_state_idx');
            $table->index(['integration_id', 'sku'], 'locality_recs_sku_idx');
            $table->index('cohort_id', 'locality_recs_cohort_idx');
            $table->index('linked_supply_order_id', 'locality_recs_supply_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locality_recommendations');
    }
};
