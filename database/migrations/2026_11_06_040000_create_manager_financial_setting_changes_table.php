<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manager_financial_setting_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('integration_id');
            $table->unsignedBigInteger('created_by');
            $table->uuid('product_id');
            $table->text('product_sku');
            $table->text('product_name')->nullable();
            $table->text('integration_name');
            $table->string('marketplace', 20);
            $table->char('credential_binding', 64);
            $table->char('product_binding', 64);
            $table->char('fingerprint', 64);
            $table->json('baseline');
            $table->json('values');
            $table->string('status', 32);
            $table->string('message_code', 64)->nullable();
            $table->unsignedBigInteger('settings_id')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('applied_at')->nullable();
            $table->string('recalculation_status', 32)->default('unconfirmed');
            $table->json('events');
            $table->timestampsTz();
            $table->index(['workspace_id', 'created_by', 'status']);
            $table->index(['integration_id', 'product_id', 'status', 'applied_at'], 'manager_financial_input_lookup');
        });
    }

    public function down(): void { Schema::dropIfExists('manager_financial_setting_changes'); }
};
