<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_economics', function (Blueprint $table): void {
            $table->timestampTz('dimensions_observed_at')->nullable();
            $table->timestampTz('redemption_observed_at')->nullable();
            $table->timestampTz('acquiring_observed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('unit_economics', function (Blueprint $table): void {
            $table->dropColumn([
                'dimensions_observed_at',
                'redemption_observed_at',
                'acquiring_observed_at',
            ]);
        });
    }
};
