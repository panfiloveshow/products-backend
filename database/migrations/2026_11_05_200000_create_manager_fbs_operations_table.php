<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manager_fbs_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('integration_id');
            $table->string('marketplace', 20);
            $table->string('external_id', 100);
            $table->string('store_name');
            $table->string('store_binding', 64);
            $table->string('operation_type', 32);
            $table->string('resource_key', 64)->nullable()->unique();
            $table->string('fingerprint', 64);
            $table->string('status', 32)->index();
            $table->json('plan');
            $table->json('result')->nullable();
            $table->json('confirmations')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('execution_started_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'user_id', 'created_at']);
            $table->index(['marketplace', 'external_id']);
        });
        Schema::create('manager_fbs_operation_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_id')->index();
            $table->string('status', 32);
            $table->text('message')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_fbs_operation_events');
        Schema::dropIfExists('manager_fbs_operations');
    }
};
