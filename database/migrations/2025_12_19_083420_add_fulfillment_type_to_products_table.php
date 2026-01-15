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
        Schema::table('products', function (Blueprint $table) {
            $table->string('fulfillment_type', 20)->nullable()->after('marketplace_id')
                ->comment('Схема работы: FBO, FBS, REALFBS, EXPRESS');
            $table->index(['marketplace', 'fulfillment_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['marketplace', 'fulfillment_type']);
            $table->dropColumn('fulfillment_type');
        });
    }
};
