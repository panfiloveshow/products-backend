<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locality_metrics_daily', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id');
            $table->string('sku', 100);
            $table->date('snapshot_date');
            $table->unsignedSmallInteger('period_days')->default(28);

            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedInteger('local_orders_count')->default(0);
            $table->unsignedInteger('non_local_orders_count')->default(0);
            $table->decimal('local_share_percent', 6, 2)->nullable();

            $table->decimal('revenue_total', 14, 2)->default(0);
            $table->decimal('base_logistics_total', 14, 2)->default(0);
            $table->decimal('non_local_markup_total', 14, 2)->default(0);
            $table->decimal('overpayment_amount', 14, 2)->default(0);
            $table->decimal('lost_margin_amount', 14, 2)->default(0);

            $table->decimal('avg_base_tariff', 12, 2)->nullable();
            $table->decimal('avg_markup_percent', 6, 2)->nullable();

            $table->unsignedInteger('factual_orders_count')->default(0);
            $table->unsignedInteger('estimate_orders_count')->default(0);
            $table->string('calculation_confidence', 16)->default('low');
            $table->string('tariff_version_used', 32)->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(
                ['integration_id', 'sku', 'snapshot_date', 'period_days'],
                'locality_metrics_daily_unique'
            );
            $table->index(['integration_id', 'snapshot_date'], 'locality_metrics_daily_date_idx');
            $table->index(['integration_id', 'overpayment_amount'], 'locality_metrics_daily_overpay_idx');
            $table->index(['integration_id', 'local_share_percent'], 'locality_metrics_daily_share_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locality_metrics_daily');
    }
};
