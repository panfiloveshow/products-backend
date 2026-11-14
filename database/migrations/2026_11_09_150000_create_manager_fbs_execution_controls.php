<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manager_fbs_execution_controls', function (Blueprint $table) {
            $table->string('account_key', 96)->primary();
            $table->string('marketplace', 20);
            $table->string('mode', 20)->default('legacy');
            $table->unsignedBigInteger('version')->default(0);
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->unsignedBigInteger('integration_id')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
        });
        Schema::create('manager_fbs_execution_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('integration_id');
            $table->string('marketplace', 20);
            $table->string('store_name');
            $table->string('store_binding', 64);
            $table->string('account_key', 96);
            $table->string('fingerprint', 64);
            $table->json('plan');
            $table->json('result')->nullable();
            $table->string('status', 32);
            $table->text('message')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['workspace_id', 'user_id', 'created_at']);
        });
        Schema::create('manager_fbs_execution_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('operation_id');
            $table->string('status', 32);
            $table->timestamp('created_at');
            $table->foreign('operation_id')->references('id')->on('manager_fbs_execution_operations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('manager_fbs_execution_operations')->exists()
            || DB::table('manager_fbs_execution_controls')->where('version', '>', 0)->exists()) {
            throw new RuntimeException('Нельзя удалить действующие ограничения FBS или историю личных решений.');
        }
        Schema::dropIfExists('manager_fbs_execution_events');
        Schema::dropIfExists('manager_fbs_execution_operations');
        Schema::dropIfExists('manager_fbs_execution_controls');
    }
};
