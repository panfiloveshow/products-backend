<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manager_ozon_carriage_operations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('workspace_id'); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('integration_id');
            $t->string('store_name'); $t->string('store_binding', 64); $t->string('operation_type', 40); $t->string('fingerprint', 64);
            $t->string('status', 30); $t->json('plan'); $t->json('result')->nullable(); $t->json('confirmations')->nullable();
            $t->string('native_carriage_id', 19)->nullable(); $t->uuid('child_operation_id')->nullable(); $t->text('message')->nullable();
            $t->timestamp('expires_at'); $t->timestamp('execution_started_at')->nullable(); $t->timestamp('native_started_at')->nullable(); $t->timestamps();
            $t->index(['workspace_id', 'user_id', 'status']);
        });
        Schema::create('manager_fbs_resource_holds', function (Blueprint $t) {
            $t->string('resource_key', 64)->primary(); $t->uuid('operation_id'); $t->timestamp('created_at'); $t->index('operation_id');
        });
        Schema::create('manager_ozon_carriage_events', function (Blueprint $t) {
            $t->id(); $t->uuid('operation_id')->index(); $t->string('status', 40); $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        if (DB::table('manager_ozon_carriage_operations')->exists() || DB::table('manager_fbs_resource_holds')->exists()
            || DB::table('manager_ozon_carriage_events')->exists()) throw new RuntimeException('Carriage history/holds prevent downgrade');
        Schema::dropIfExists('manager_ozon_carriage_events'); Schema::dropIfExists('manager_fbs_resource_holds'); Schema::dropIfExists('manager_ozon_carriage_operations');
    }
};
