<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->timestamp('last_validation_at')->nullable()->after('updated_at')
                ->comment('Время последней проверки валидности интеграции');
            
            $table->string('last_validation_status', 50)->nullable()->after('last_validation_at')
                ->comment('Статус проверки: valid, invalid_token, workspace_mismatch, connection_error, no_token');
            
            $table->text('last_validation_error')->nullable()->after('last_validation_status')
                ->comment('Текст ошибки проверки (если есть)');
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn([
                'last_validation_at',
                'last_validation_status',
                'last_validation_error',
            ]);
        });
    }
};
