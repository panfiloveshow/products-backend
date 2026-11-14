<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manager_promotion_proposals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('workspace_id');
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('integration_id');
            $t->string('integration_name');
            $t->string('marketplace', 20);
            $t->string('promotion_id', 80);
            $t->string('promotion_title');
            $t->uuid('product_id');
            $t->string('product_sku', 200);
            $t->string('fulfillment_type', 20);
            $t->string('action', 16);
            $t->string('target_promo_price', 24);
            $t->char('context_fingerprint', 64);
            $t->json('constraints');
            $t->json('scenario');
            $t->json('binding');
            $t->json('approval')->nullable();
            $t->json('executor_receipt')->nullable();
            $t->char('fingerprint', 64);
            $t->string('status', 32)->index();
            $t->string('message_code', 64)->nullable();
            $t->json('events');
            $t->timestamp('created_at');
            $t->timestamp('expires_at');
            $t->timestamp('updated_at')->nullable();
            $t->index(['workspace_id', 'created_by', 'status']);
            $t->index(['integration_id', 'promotion_id', 'product_id']);
        });

        Schema::create('manager_promotion_holds', function (Blueprint $t) {
            $t->char('resource_key', 64)->primary();
            $t->uuid('proposal_id')->index();
            $t->timestamp('created_at');
        });

        Schema::create('manager_fbs_manual_resolutions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('workspace_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('integration_id');
            $t->string('integration_name');
            $t->string('marketplace', 20);
            $t->char('store_binding', 64);
            $t->uuid('operation_id')->index();
            $t->string('operation_type', 48);
            $t->string('source_table', 64);
            $t->string('external_id', 120);
            $t->text('reason');
            $t->string('support_reference', 256);
            $t->json('confirmations')->nullable();
            $t->char('fingerprint', 64);
            $t->string('status', 32)->index();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->index(['workspace_id', 'user_id', 'status']);
        });

        Schema::create('manager_ozon_fbo_manual_resolutions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('workspace_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('integration_id');
            $t->string('integration_name');
            $t->string('marketplace', 20);
            $t->char('store_binding', 64);
            $t->uuid('operation_id')->index();
            $t->string('operation_type', 48);
            $t->string('source_table', 64);
            $t->text('reason');
            $t->string('support_reference', 256);
            $t->json('confirmations')->nullable();
            $t->char('fingerprint', 64);
            $t->string('status', 32)->index();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->index(['workspace_id', 'user_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach ([
            'manager_promotion_proposals',
            'manager_promotion_holds',
            'manager_fbs_manual_resolutions',
            'manager_ozon_fbo_manual_resolutions',
        ] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Manager Option 2 history in {$table} prevents downgrade");
            }
        }
        Schema::dropIfExists('manager_ozon_fbo_manual_resolutions');
        Schema::dropIfExists('manager_fbs_manual_resolutions');
        Schema::dropIfExists('manager_promotion_holds');
        Schema::dropIfExists('manager_promotion_proposals');
    }
};
