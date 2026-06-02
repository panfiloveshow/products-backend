<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_supply_constraint_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id')->index();
            $table->string('marketplace', 50)->index();
            $table->string('file_name');
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->string('file_hash', 128)->nullable()->index();
            $table->string('parser_version', 80)->nullable();
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('constraints_count')->default(0);
            $table->unsignedInteger('warnings_count')->default(0);
            $table->json('cluster_constraints_json')->nullable();
            $table->json('warehouse_constraints_json')->nullable();
            $table->json('summary_json')->nullable();
            $table->json('warnings_json')->nullable();
            $table->timestamp('parsed_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['integration_id', 'marketplace', 'created_at'], 'asp_constraint_files_lookup_idx');
            $table->unique(['integration_id', 'file_hash'], 'asp_constraint_files_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_supply_constraint_files');
    }
};
