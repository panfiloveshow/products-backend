<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wildberries_spp_sync_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id')->unique();
            $table->string('status', 20)->default('idle');
            $table->unsignedInteger('attempt')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('preserved_count')->default(0);
            $table->string('source', 32)->nullable();
            $table->json('source_counts')->nullable();
            $table->text('message')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('retry_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('integration_id')
                ->references('id')->on('integrations')
                ->cascadeOnDelete();
        });

        Schema::create('wildberries_spp_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id');
            $table->string('sku', 100);
            $table->string('nm_id', 64)->nullable();
            $table->decimal('spp_percent', 7, 2);
            $table->decimal('seller_price', 14, 2)->nullable();
            $table->decimal('customer_price', 14, 2)->nullable();
            $table->string('source', 32);
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->unique(['integration_id', 'sku'], 'wb_spp_snapshot_integration_sku_uq');
            $table->index(['integration_id', 'nm_id'], 'wb_spp_snapshot_integration_nm_idx');
            $table->foreign('integration_id')
                ->references('id')->on('integrations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wildberries_spp_snapshots');
        Schema::dropIfExists('wildberries_spp_sync_states');
    }
};
