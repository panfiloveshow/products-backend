<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_supply_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('auto_supply_plans', 'result_json')) {
                $table->json('result_json')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('auto_supply_plans', function (Blueprint $table) {
            if (Schema::hasColumn('auto_supply_plans', 'result_json')) {
                $table->dropColumn('result_json');
            }
        });
    }
};
