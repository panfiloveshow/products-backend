<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            $table->unsignedBigInteger('integration_id')->nullable()->after('product_id');
            $table->index('integration_id');
        });
    }

    public function down(): void
    {
        Schema::table('unit_economics', function (Blueprint $table) {
            $table->dropIndex(['integration_id']);
            $table->dropColumn('integration_id');
        });
    }
};
