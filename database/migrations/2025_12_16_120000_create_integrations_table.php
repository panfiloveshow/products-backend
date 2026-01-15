<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Название магазина/интеграции
            $table->string('marketplace'); // wildberries, ozon, yandex
            $table->json('credentials'); // API ключи (зашифрованы)
            $table->boolean('is_active')->default(true);
            $table->boolean('auto_sync_enabled')->default(true);
            $table->integer('sync_interval_hours')->default(6); // Интервал автосинхронизации
            $table->timestamp('last_sync_at')->nullable();
            $table->string('last_sync_status')->nullable(); // completed, failed
            $table->text('last_sync_error')->nullable();
            $table->json('settings')->nullable(); // Дополнительные настройки
            $table->timestamps();
            
            $table->index('marketplace');
            $table->index('is_active');
            $table->index(['marketplace', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
