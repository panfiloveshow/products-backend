<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 200);
            $table->enum('status', [
                'draft',
                'pending_logistics',
                'approved',
                'sent',
                'in_transit',
                'delivered',
                'rejected'
            ])->default('draft');
            $table->enum('marketplace', ['wildberries', 'ozon', 'yandex']);
            $table->enum('shipment_type', ['fbo', 'fbs', 'dbs']);
            $table->string('warehouse_name', 200)->nullable();
            $table->uuid('supplier_id');
            $table->string('supplier_name', 300)->nullable();
            $table->text('supplier_address')->nullable();
            $table->json('slot')->nullable();
            $table->json('marketplace_requirements')->nullable();
            $table->json('packaging')->nullable();
            $table->integer('total_items')->default(0);
            $table->integer('total_quantity')->default(0);
            $table->decimal('total_cost', 12, 2)->default(0);
            $table->decimal('total_volume', 10, 3)->default(0);
            $table->decimal('total_weight', 10, 3)->default(0);
            $table->string('truck_type', 50)->nullable();
            $table->decimal('truck_capacity', 10, 2)->nullable();
            $table->decimal('delivery_cost', 10, 2)->nullable();
            $table->decimal('delivery_cost_percent', 5, 2)->nullable();
            $table->decimal('utilization_percent', 5, 2)->nullable();
            $table->json('logistics_approval')->nullable();
            $table->uuid('created_by');
            $table->string('created_by_name', 200)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('marketplace');
            $table->index('created_at');
            $table->index('supplier_id');

            $table->foreign('supplier_id')->references('id')->on('suppliers')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
