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
            $table->boolean('is_premium')->nullable()->after('settings');
            $table->timestamp('premium_checked_at')->nullable()->after('is_premium');
            $table->decimal('manual_redemption_rate', 5, 2)->nullable()->after('premium_checked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn(['is_premium', 'premium_checked_at', 'manual_redemption_rate']);
        });
    }
};
