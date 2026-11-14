<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manager_ozon_packing_operations', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->unsignedBigInteger('workspace_id'); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('integration_id');
            $t->string('store_name'); $t->string('store_binding', 64); $t->string('fingerprint', 64); $t->json('plan'); $t->json('confirmations')->nullable();
            $t->string('status', 30); $t->string('message_code', 60)->nullable(); $t->timestamp('expires_at'); $t->timestamp('approved_at')->nullable(); $t->timestamp('heartbeat_at')->nullable(); $t->timestamps();
            $t->index(['workspace_id', 'user_id', 'status']);
        });
        Schema::create('manager_ozon_packing_items', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('operation_id')->index(); $t->unsignedSmallInteger('position'); $t->string('source_posting_number', 100);
            $t->json('plan'); $t->string('status', 30); $t->string('message_code', 60)->nullable(); $t->timestamp('native_started_at')->nullable();
            $t->json('receipt')->nullable(); $t->json('result')->nullable(); $t->timestamps();
            $t->unique(['operation_id', 'position']); $t->unique(['operation_id', 'source_posting_number']);
        });
        Schema::create('manager_ozon_packing_outbox', function (Blueprint $t) {
            $t->uuid('operation_id')->primary(); $t->text('payload_cipher')->nullable(); $t->string('token_hash', 64); $t->string('state', 20);
            $t->unsignedInteger('queue_attempts')->default(0); $t->timestamp('last_queue_attempt_at')->nullable(); $t->timestamp('queued_at')->nullable();
            $t->timestamp('worker_claimed_at')->nullable(); $t->uuid('worker_claim_id')->nullable(); $t->timestamps();
        });
        Schema::create('manager_ozon_packing_events', function (Blueprint $t) {
            $t->id(); $t->uuid('operation_id')->index(); $t->uuid('item_id')->nullable(); $t->string('status', 60); $t->timestamp('created_at');
        });
    }
    public function down(): void
    {
        foreach (['manager_ozon_packing_operations', 'manager_ozon_packing_items', 'manager_ozon_packing_outbox', 'manager_ozon_packing_events'] as $table)
            if (DB::table($table)->exists()) throw new RuntimeException('Ozon packing history prevents downgrade');
        foreach (['manager_ozon_packing_events', 'manager_ozon_packing_outbox', 'manager_ozon_packing_items', 'manager_ozon_packing_operations'] as $table) Schema::dropIfExists($table);
    }
};
