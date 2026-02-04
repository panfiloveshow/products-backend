<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supply_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supply_id')->constrained('supplies')->onDelete('cascade');
            $table->foreignId('package_id')->nullable()->constrained('supply_packages')->nullOnDelete();
            
            $table->string('document_type', 30); // package_label, pallet_label, supply_act, waybill, etc.
            $table->string('document_name', 255);
            $table->text('description')->nullable();
            
            $table->string('format', 10)->default('pdf'); // pdf, png, zpl, html, xlsx
            $table->string('source', 20)->default('system'); // ozon, system, upload
            
            $table->string('file_path', 500)->nullable();
            $table->string('file_url', 1000)->nullable();
            $table->bigInteger('file_size')->nullable();
            $table->string('file_hash', 64)->nullable();
            
            $table->text('barcode_data')->nullable();
            $table->string('barcode_type', 20)->nullable(); // EAN13, Code128, QR, DataMatrix
            
            $table->string('ozon_document_id', 100)->nullable();
            
            $table->string('status', 20)->default('pending'); // pending, generating, ready, error, expired
            $table->text('error_message')->nullable();
            
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            
            $table->integer('downloaded_count')->default(0);
            $table->timestamp('last_downloaded_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            
            $table->json('meta')->nullable();
            $table->timestamps();
            
            $table->index(['supply_id', 'document_type']);
            $table->index(['package_id', 'document_type']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_documents');
    }
};
