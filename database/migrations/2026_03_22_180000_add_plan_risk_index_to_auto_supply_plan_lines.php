<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_supply_plan_lines', function (Blueprint $table) {
            $table->index(['auto_supply_plan_id', 'risk_level'], 'aspl_plan_id_risk_level_index');
        });
    }

    public function down(): void
    {
        Schema::table('auto_supply_plan_lines', function (Blueprint $table) {
            $table->dropIndex('aspl_plan_id_risk_level_index');
        });
    }
};
