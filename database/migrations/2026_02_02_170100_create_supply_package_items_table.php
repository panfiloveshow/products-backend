<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supply_package_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('supply_packages')->onDelete('cascade');
            $table->foreignId('supply_item_id')->nullable()->constrained('supply_items')->nullOnDelete();
            $table->uuid('product_id')->nullable();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            
            $table->string('sku', 100);
            $table->string('barcode', 100)->nullable();
            $table->string('product_name', 500)->nullable();
            
            $table->integer('quantity')->default(1);
            $table->decimal('weight', 10, 3)->nullable(); // вес единицы товара
            $table->date('expiry_date')->nullable()->comment('Срок годности (Годен до)');
            
            $table->timestamp('scanned_at')->nullable();
            $table->foreignId('scanned_by')->nullable()->constrained('users')->nullOnDelete();
            
            $table->json('meta')->nullable();
            $table->timestamps();
            
            $table->index(['package_id', 'sku']);
            $table->index('supply_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_package_items');
    }
};
