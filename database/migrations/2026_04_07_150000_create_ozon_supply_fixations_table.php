<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ozon_supply_fixations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id');
            $table->unsignedBigInteger('supply_id');
            $table->string('sku');
            $table->string('offer_id')->nullable();
            $table->string('shipping_cluster_id')->nullable();
            $table->string('shipping_cluster_name')->nullable();
            $table->date('fixation_base_date');
            $table->date('fixed_until');
            $table->string('tariff_version');
            $table->string('markup_version');
            $table->date('announcement_effective_from')->nullable();
            $table->string('source')->default('supply_created');
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['integration_id', 'supply_id', 'sku'], 'ozon_supply_fixations_unique');
            $table->index(['integration_id', 'sku', 'is_active'], 'ozon_supply_fixations_sku_active_idx');
            $table->index(['integration_id', 'fixed_until'], 'ozon_supply_fixations_fixed_until_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ozon_supply_fixations');
    }
};
