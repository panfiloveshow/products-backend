<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_logs', function (Blueprint $table) {
            // Добавляем поле для хранения credentials (зашифрованные API-ключи)
            $table->text('credentials')->nullable()->after('metadata');
            
            // Добавляем integration_id для связи с конкретной интеграцией из Sellico
            $table->unsignedBigInteger('integration_id')->nullable()->after('marketplace');
        });
    }

    public function down(): void
    {
        Schema::table('sync_logs', function (Blueprint $table) {
            $table->dropColumn(['credentials', 'integration_id']);
        });
    }
};
