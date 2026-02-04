<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supply_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supply_id')->constrained('supplies')->onDelete('cascade');
            
            $table->string('package_type', 20)->default('box'); // box, pallet, mono_pallet
            $table->integer('sequence_number')->default(1);
            $table->string('barcode', 50)->unique();
            $table->string('ozon_package_id', 50)->nullable();
            
            $table->decimal('weight', 10, 3)->nullable(); // кг
            $table->decimal('length', 8, 2)->nullable();  // см
            $table->decimal('width', 8, 2)->nullable();   // см
            $table->decimal('height', 8, 2)->nullable();  // см
            
            $table->integer('items_count')->default(0);
            $table->integer('total_quantity')->default(0);
            
            $table->string('status', 20)->default('draft');
            
            $table->timestamp('packed_at')->nullable();
            $table->foreignId('packed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('label_printed_at')->nullable();
            $table->integer('label_print_count')->default(0);
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            
            $table->integer('accepted_quantity')->nullable();
            $table->integer('rejected_quantity')->nullable();
            $table->text('rejection_reason')->nullable();
            
            $table->json('meta')->nullable();
            $table->timestamps();
            
            $table->index(['supply_id', 'status']);
            $table->index('barcode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_packages');
    }
};
