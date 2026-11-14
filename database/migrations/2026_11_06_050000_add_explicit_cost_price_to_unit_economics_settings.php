<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_economics_settings', function (Blueprint $table) {
            // NULL preserves the legacy zero-as-missing convention. This is
            // a value choice, not evidence of source, freshness or permission.
            $table->decimal('explicit_cost_price', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('unit_economics_settings', fn (Blueprint $table) => $table->dropColumn('explicit_cost_price'));
    }
};
