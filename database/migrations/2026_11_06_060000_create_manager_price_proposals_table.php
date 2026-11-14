<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manager_price_proposals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('integration_id');
            $table->uuid('product_id');
            $table->string('marketplace', 20);
            $table->string('fulfillment_type', 16);
            $table->text('product_sku');
            $table->text('integration_name');
            $table->decimal('seller_price', 12, 2);
            $table->json('constraints');
            $table->json('snapshot');
            $table->json('binding');
            $table->char('fingerprint', 64);
            $table->string('status', 32);
            $table->string('message_code', 64)->nullable();
            $table->json('events');
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->index(['workspace_id', 'created_by', 'status']);
            $table->index(['integration_id', 'product_id', 'status']);
        });
    }

    public function down(): void { Schema::dropIfExists('manager_price_proposals'); }
};
