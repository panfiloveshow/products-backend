<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wb_barcode_costs')) {
            return;
        }
        Schema::create('wb_barcode_costs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id')->index();
            $table->string('barcode', 255);
            $table->string('nm_id', 255);
            $table->string('chrt_id', 255)->nullable();
            $table->string('size_name', 255)->nullable();
            $table->decimal('cost_price', 14, 2)->nullable();
            $table->timestamps();
            $table->unique(['integration_id', 'barcode']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('wb_barcode_costs') && DB::table('wb_barcode_costs')->exists()) {
            throw new RuntimeException('Barcode cost data prevents downgrade');
        }
        Schema::dropIfExists('wb_barcode_costs');
    }
};
