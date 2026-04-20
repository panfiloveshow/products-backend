<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_economics_cache', function (Blueprint $table) {
            if (! Schema::hasColumn('unit_economics_cache', 'marketplace_data')) {
                $table->json('marketplace_data')->nullable()->after('fulfillment_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('unit_economics_cache', function (Blueprint $table) {
            if (Schema::hasColumn('unit_economics_cache', 'marketplace_data')) {
                $table->dropColumn('marketplace_data');
            }
        });
    }
};
