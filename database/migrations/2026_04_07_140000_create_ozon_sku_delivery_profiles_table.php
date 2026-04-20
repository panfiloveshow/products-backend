<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ozon_sku_delivery_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id')->index();
            $table->string('sku', 255)->index();
            $table->string('offer_id', 255)->nullable()->index();
            $table->string('ozon_sku', 255)->nullable()->index();
            $table->string('scheme', 20)->default('ALL')->index();

            $table->json('stock_profile')->nullable();
            $table->json('sales_profile')->nullable();
            $table->json('cluster_profile')->nullable();

            $table->string('dominant_stock_cluster_id', 50)->nullable()->index();
            $table->decimal('dominant_stock_cluster_share', 6, 2)->nullable();
            $table->string('dominant_sales_cluster_id', 50)->nullable()->index();
            $table->decimal('dominant_sales_cluster_share', 6, 2)->nullable();
            $table->string('dominant_demand_cluster_id', 50)->nullable()->index();
            $table->decimal('dominant_demand_cluster_share', 6, 2)->nullable();

            $table->decimal('expected_locality_rate', 6, 2)->nullable();
            $table->decimal('weighted_non_local_markup_percent', 6, 2)->nullable();
            $table->decimal('weighted_logistics_cost', 12, 2)->nullable();

            $table->string('profile_source', 50)->nullable();
            $table->string('route_resolution_status', 20)->nullable();
            $table->string('locality_resolution_status', 20)->nullable();
            $table->string('calculation_confidence', 20)->nullable();
            $table->timestamp('calculated_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['integration_id', 'sku', 'scheme'], 'ozon_delivery_profiles_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ozon_sku_delivery_profiles');
    }
};
