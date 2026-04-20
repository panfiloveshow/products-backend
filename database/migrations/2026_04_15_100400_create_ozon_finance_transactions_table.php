<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ozon_finance_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id');
            $table->string('operation_id', 64);
            $table->string('operation_type', 64)->nullable();
            $table->string('operation_type_name', 128)->nullable();
            $table->dateTime('operation_date');
            $table->string('posting_number', 64)->nullable();
            $table->string('sku', 100)->nullable();
            $table->string('offer_id', 100)->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->decimal('accruals_for_sale', 14, 2)->nullable();
            $table->decimal('sale_commission', 14, 2)->nullable();
            $table->string('warehouse_id', 64)->nullable();
            $table->string('warehouse_name', 120)->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['integration_id', 'operation_id'], 'ozon_finance_txn_unique');
            $table->index(['integration_id', 'operation_date'], 'ozon_finance_txn_date_idx');
            $table->index(['integration_id', 'posting_number'], 'ozon_finance_txn_posting_idx');
            $table->index(['integration_id', 'operation_type'], 'ozon_finance_txn_op_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ozon_finance_transactions');
    }
};
