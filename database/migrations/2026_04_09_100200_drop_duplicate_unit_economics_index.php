<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The index `ue_actual_scheme_idx` on `unit_economics` was accidentally created
        // in the cache performance indexes migration, duplicating `idx_ue_actual_scheme`.
        Schema::table('unit_economics', function ($table) {
            // Only drop if it exists to avoid errors
            try {
                $table->dropIndex('ue_actual_scheme_idx');
            } catch (\Exception $e) {
                // Index may not exist in all environments
            }
        });
    }

    public function down(): void
    {
        // Not restoring duplicate index
    }
};
