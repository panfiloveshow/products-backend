<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wildberries_tariff_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id')->nullable()->index();
            $table->string('marketplace', 50)->default('wildberries');
            $table->string('tariff_type', 50);
            $table->date('effective_date')->nullable();
            $table->string('warehouse_id', 100)->default('');
            $table->string('warehouse_name')->nullable();
            $table->string('subject_id', 100)->default('');
            $table->string('subject_name')->nullable();
            $table->string('scheme', 50)->default('');
            $table->json('payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['integration_id', 'tariff_type', 'effective_date', 'warehouse_id', 'subject_id', 'scheme'],
                'wb_tariff_snapshots_unique'
            );
            $table->index(['integration_id', 'tariff_type', 'effective_date'], 'wb_tariff_snapshots_lookup_idx');
            $table->index(['integration_id', 'warehouse_name'], 'wb_tariff_snapshots_warehouse_idx');
            $table->index(['integration_id', 'subject_id'], 'wb_tariff_snapshots_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wildberries_tariff_snapshots');
    }
};
