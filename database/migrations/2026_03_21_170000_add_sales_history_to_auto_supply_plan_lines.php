<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_supply_plan_lines', function (Blueprint $table) {
            if (!Schema::hasColumn('auto_supply_plan_lines', 'sales_7_days')) {
                $table->unsignedInteger('sales_7_days')->default(0)->after('in_transit');
            }
            if (!Schema::hasColumn('auto_supply_plan_lines', 'sales_14_days')) {
                $table->unsignedInteger('sales_14_days')->default(0)->after('sales_7_days');
            }
            if (!Schema::hasColumn('auto_supply_plan_lines', 'sales_30_days')) {
                $table->unsignedInteger('sales_30_days')->default(0)->after('sales_14_days');
            }
        });
    }

    public function down(): void
    {
        Schema::table('auto_supply_plan_lines', function (Blueprint $table) {
            $drop = [];

            foreach (['sales_7_days', 'sales_14_days', 'sales_30_days'] as $column) {
                if (Schema::hasColumn('auto_supply_plan_lines', $column)) {
                    $drop[] = $column;
                }
            }

            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
