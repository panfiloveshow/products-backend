<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('marketplace', ['wildberries', 'ozon', 'yandex']);
            $table->enum('sync_type', ['products', 'inventory', 'sales', 'commissions']);
            $table->enum('status', ['pending', 'running', 'completed', 'failed']);
            $table->integer('items_synced')->default(0);
            $table->integer('items_failed')->default(0);
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['marketplace', 'sync_type']);
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
