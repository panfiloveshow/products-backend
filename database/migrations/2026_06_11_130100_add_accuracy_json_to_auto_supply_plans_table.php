<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_supply_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('auto_supply_plans', 'accuracy_json')) {
                // Агрегат точности план-факта: mape, bias, lines_evaluated, acceptance_rate, evaluated_at.
                $table->json('accuracy_json')->nullable()->after('result_json');
            }
        });
    }

    public function down(): void
    {
        Schema::table('auto_supply_plans', function (Blueprint $table) {
            if (Schema::hasColumn('auto_supply_plans', 'accuracy_json')) {
                $table->dropColumn('accuracy_json');
            }
        });
    }
};
