<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locality_metrics_cluster_daily', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id');
            $table->string('destination_cluster_id', 50)->nullable();
            $table->string('destination_cluster_name', 120);
            $table->date('snapshot_date');
            $table->unsignedSmallInteger('period_days')->default(28);

            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedInteger('local_orders_count')->default(0);
            $table->decimal('local_share_percent', 6, 2)->nullable();

            $table->decimal('total_revenue', 14, 2)->default(0);
            $table->decimal('total_overpayment', 14, 2)->default(0);
            $table->decimal('lost_margin_amount', 14, 2)->default(0);

            $table->unsignedInteger('distinct_skus_affected')->default(0);
            $table->json('top_skus_by_loss')->nullable();
            $table->json('shipping_cluster_breakdown')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(
                ['integration_id', 'destination_cluster_name', 'snapshot_date', 'period_days'],
                'locality_metrics_cluster_daily_unique'
            );
            $table->index(['integration_id', 'snapshot_date'], 'locality_metrics_cluster_daily_date_idx');
            $table->index(['integration_id', 'total_overpayment'], 'locality_metrics_cluster_daily_overpay_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locality_metrics_cluster_daily');
    }
};
