<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Выборка pending-команд и hourly-очистка фильтруют по (integration_id, status, expires_at) —
 * composite-индекс закрывает оба запроса без скана.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uzum_extension_commands', function (Blueprint $table) {
            $table->index(['integration_id', 'status', 'expires_at'], 'uzum_ext_cmd_pending_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('uzum_extension_commands', function (Blueprint $table) {
            $table->dropIndex('uzum_ext_cmd_pending_lookup');
        });
    }
};
