<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planning_fact_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('auto_supply_plan_id')->index();
            $table->unsignedBigInteger('integration_id')->index();
            $table->string('marketplace', 50)->index();
            $table->string('status', 32)->default('building')->index();
            $table->timestamp('captured_at')->nullable();
            $table->json('params_json')->nullable();
            $table->json('facts_freshness_json')->nullable();
            $table->json('planning_sources_json')->nullable();
            $table->json('demand_facts_json')->nullable();
            $table->json('stock_facts_json')->nullable();
            $table->json('supply_facts_json')->nullable();
            $table->json('economics_facts_json')->nullable();
            $table->json('constraints_facts_json')->nullable();
            $table->json('summary_json')->nullable();
            $table->timestamps();
        });

        Schema::table('auto_supply_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('auto_supply_plans', 'snapshot_id')) {
                $table->uuid('snapshot_id')->nullable()->after('id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('auto_supply_plans', function (Blueprint $table) {
            if (Schema::hasColumn('auto_supply_plans', 'snapshot_id')) {
                $table->dropColumn('snapshot_id');
            }
        });

        Schema::dropIfExists('planning_fact_snapshots');
    }
};
