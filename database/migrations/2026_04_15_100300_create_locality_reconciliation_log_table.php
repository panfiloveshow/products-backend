<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locality_reconciliation_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id');
            $table->date('period_from');
            $table->date('period_to');
            $table->timestamp('run_at');
            $table->string('source', 50)->default('finance_transaction_list');

            $table->decimal('expected_base_logistics', 14, 2)->default(0);
            $table->decimal('expected_non_local_markup', 14, 2)->default(0);
            $table->decimal('actual_base_logistics', 14, 2)->default(0);
            $table->decimal('actual_non_local_markup', 14, 2)->default(0);

            $table->decimal('base_logistics_diff', 14, 2)->default(0);
            $table->decimal('markup_diff', 14, 2)->default(0);
            $table->decimal('base_logistics_diff_percent', 6, 2)->nullable();
            $table->decimal('markup_diff_percent', 6, 2)->nullable();

            $table->string('verdict', 16)->default('match');
            $table->unsignedInteger('operations_count')->default(0);
            $table->unsignedInteger('postings_matched')->default(0);
            $table->unsignedInteger('postings_missing')->default(0);
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index(['integration_id', 'period_to'], 'locality_recon_period_idx');
            $table->index(['integration_id', 'verdict'], 'locality_recon_verdict_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locality_reconciliation_log');
    }
};
