<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->timestamp('localization_checked_at')->nullable()->after('premium_checked_at');
            $table->timestamp('redemption_checked_at')->nullable()->after('localization_checked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn(['localization_checked_at', 'redemption_checked_at']);
        });
    }
};
