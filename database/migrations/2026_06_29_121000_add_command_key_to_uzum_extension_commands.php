<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uzum_extension_commands', function (Blueprint $table) {
            $table->string('command_key', 80)->nullable()->after('integration_id')->index();
            $table->timestamp('expires_at')->nullable()->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('uzum_extension_commands', function (Blueprint $table) {
            $table->dropColumn(['command_key', 'expires_at']);
        });
    }
};
