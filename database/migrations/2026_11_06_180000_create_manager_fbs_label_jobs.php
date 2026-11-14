<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manager_fbs_label_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('integration_id');
            $table->string('external_id', 100);
            $table->string('store_binding', 64);
            $table->string('status', 20)->default('prepared');
            $table->string('task_id', 19)->nullable();
            $table->string('task_type', 20)->nullable();
            $table->json('tasks')->nullable();
            $table->timestamp('native_started_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['workspace_id', 'user_id', 'integration_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('manager_fbs_label_jobs')->exists()) throw new RuntimeException('FBS label job history prevents downgrade');
        Schema::dropIfExists('manager_fbs_label_jobs');
    }
};
