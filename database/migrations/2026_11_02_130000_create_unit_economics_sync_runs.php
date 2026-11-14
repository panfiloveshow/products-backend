<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_economics_sync_states', function (Blueprint $table): void {
            $table->foreignId('integration_id')->primary()->constrained('integrations')->cascadeOnDelete();
            $table->unsignedBigInteger('requested_version')->default(0);
            $table->unsignedBigInteger('observed_version')->default(0);
            $table->unsignedBigInteger('published_version')->default(0);
            $table->unsignedBigInteger('publication_revision')->default(0);
            $table->uuid('latest_run_id')->nullable();
            $table->timestamps();
        });
        Schema::create('unit_economics_sync_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('integration_id')->constrained('integrations')->cascadeOnDelete();
            $table->unsignedBigInteger('version');
            $table->unsignedBigInteger('requested_version');
            $table->unsignedBigInteger('published_version')->default(0);
            $table->string('purpose', 24);
            $table->string('status', 24)->default('queued');
            $table->json('required_stages');
            $table->json('stages');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('deadline_at');
            $table->timestamps();
            $table->unique(['integration_id', 'version']);
            $table->index(['integration_id', 'created_at']);
        });
        Schema::table('unit_economics_cache', function (Blueprint $table): void {
            $table->unsignedBigInteger('source_version')->default(0);
            $table->timestamp('price_observed_at')->nullable();
            $table->string('price_source', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('unit_economics_cache', function (Blueprint $table): void {
            $table->dropColumn(['source_version', 'price_observed_at', 'price_source']);
        });
        Schema::dropIfExists('unit_economics_sync_runs');
        Schema::dropIfExists('unit_economics_sync_states');
    }
};
