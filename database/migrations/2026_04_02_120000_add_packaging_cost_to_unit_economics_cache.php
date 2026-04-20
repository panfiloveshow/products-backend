<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_economics_cache', function (Blueprint $table) {
            $table->decimal('packaging_cost', 12, 2)->nullable()->after('storage_cost');
        });
    }

    public function down(): void
    {
        Schema::table('unit_economics_cache', function (Blueprint $table) {
            $table->dropColumn('packaging_cost');
        });
    }
};
