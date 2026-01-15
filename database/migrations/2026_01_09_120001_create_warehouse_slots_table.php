<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_slots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            $table->string('marketplace', 20);
            $table->string('warehouse_id', 100);
            $table->string('warehouse_name')->nullable();
            
            $table->string('external_slot_id')->nullable();
            
            $table->date('date');
            $table->time('time_from');
            $table->time('time_to');
            
            $table->decimal('coefficient', 5, 3)->nullable();
            
            $table->boolean('is_available')->default(true);
            $table->integer('capacity')->nullable();
            $table->integer('capacity_used')->default(0);
            $table->integer('boxes_limit')->nullable();
            $table->integer('pallets_limit')->nullable();
            
            $table->uuid('booked_by_shipment_id')->nullable();
            $table->timestamp('booked_at')->nullable();
            
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            
            $table->index(['marketplace', 'warehouse_id', 'date']);
            $table->index(['is_available', 'date']);
            $table->unique(['marketplace', 'warehouse_id', 'external_slot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_slots');
    }
};
