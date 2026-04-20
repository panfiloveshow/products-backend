<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ozon_order_unit_economics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id');
            $table->uuid('posting_id');
            $table->uuid('posting_item_id');
            $table->string('posting_number')->nullable();
            $table->string('sku');
            $table->string('offer_id')->nullable();
            $table->dateTime('order_date')->nullable();
            $table->decimal('sale_price', 12, 2)->default(0);
            $table->decimal('volume_liters', 12, 4)->nullable();
            $table->string('price_bucket')->nullable();
            $table->string('shipping_cluster_id')->nullable();
            $table->string('shipping_cluster_name')->nullable();
            $table->string('destination_cluster_id')->nullable();
            $table->string('destination_cluster_name')->nullable();
            $table->boolean('fixation_applied')->default(false);
            $table->unsignedBigInteger('fixation_id')->nullable();
            $table->date('fixation_base_date')->nullable();
            $table->date('fixed_until')->nullable();
            $table->string('tariff_version_used')->nullable();
            $table->string('markup_version_used')->nullable();
            $table->decimal('base_logistics_tariff', 12, 2)->default(0);
            $table->decimal('non_local_markup_percent', 8, 2)->default(0);
            $table->decimal('non_local_markup_amount', 12, 2)->default(0);
            $table->boolean('markup_applied')->default(false);
            $table->string('markup_reason_code')->nullable();
            $table->string('markup_reason_label')->nullable();
            $table->string('markup_exception_code')->nullable();
            $table->string('markup_exception_label')->nullable();
            $table->string('markup_exception_status')->nullable();
            $table->string('calculation_mode')->default('estimate');
            $table->string('calculation_confidence')->default('low');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['posting_item_id'], 'ozon_order_unit_economics_posting_item_unique');
            $table->index(['integration_id', 'sku'], 'ozon_order_unit_economics_sku_idx');
            $table->index(['integration_id', 'order_date'], 'ozon_order_unit_economics_order_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ozon_order_unit_economics');
    }
};
