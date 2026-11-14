<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manager_supply_calculations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('integration_id')->index();
            $table->unsignedBigInteger('created_by');
            $table->string('marketplace', 20);
            $table->text('integration_name');
            $table->char('credential_binding', 64);
            $table->char('fingerprint', 64);
            $table->json('parameters');
            $table->uuid('plan_id')->unique();
            $table->string('status', 32)->index();
            $table->timestampTz('expires_at');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('plan_created_at')->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('run_started_at')->nullable();
            $table->uuid('run_claim_id')->nullable();
            $table->timestampTz('run_finished_at')->nullable();
            $table->uuid('snapshot_id')->nullable();
            $table->unsignedInteger('queue_attempts')->default(0);
            $table->timestampTz('last_queue_attempt_at')->nullable();
            $table->string('message_code', 64)->nullable();
            $table->json('events');
            $table->timestampsTz();
            $table->index(['workspace_id', 'created_by', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_supply_calculations');
    }
};
