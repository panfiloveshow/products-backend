<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_supply_plans', function (Blueprint $table) {
            $table->string('business_status', 30)
                ->default('draft')
                ->after('status')
                ->index();
            $table->timestamp('approved_at')->nullable()->after('business_status');
            $table->unsignedBigInteger('approved_by')->nullable()->after('approved_at');
            $table->string('approval_fingerprint', 64)->nullable()->after('approved_by');
            $table->timestamp('materialized_at')->nullable()->after('approval_fingerprint');
        });

        DB::table('auto_supply_plans')
            ->where('status', 'ready')
            ->update(['business_status' => 'review_required']);

        Schema::table('supplies', function (Blueprint $table) {
            $table->uuid('auto_supply_plan_id')
                ->nullable()
                ->after('supply_plan_id');
            $table->foreign('auto_supply_plan_id')
                ->references('id')
                ->on('auto_supply_plans')
                ->nullOnDelete();
            $table->unique(
                ['auto_supply_plan_id', 'cluster_id'],
                'supplies_auto_plan_cluster_unique'
            );
        });

        Schema::table('supply_items', function (Blueprint $table) {
            $table->unsignedBigInteger('auto_supply_plan_line_id')
                ->nullable()
                ->after('supply_id');
            $table->foreign('auto_supply_plan_line_id')
                ->references('id')
                ->on('auto_supply_plan_lines')
                ->nullOnDelete();
            $table->unique(
                ['supply_id', 'auto_supply_plan_line_id'],
                'supply_items_supply_auto_plan_line_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('supply_items', function (Blueprint $table) {
            $table->dropUnique('supply_items_supply_auto_plan_line_unique');
            $table->dropForeign(['auto_supply_plan_line_id']);
            $table->dropColumn('auto_supply_plan_line_id');
        });

        Schema::table('supplies', function (Blueprint $table) {
            $table->dropUnique('supplies_auto_plan_cluster_unique');
            $table->dropForeign(['auto_supply_plan_id']);
            $table->dropColumn('auto_supply_plan_id');
        });

        Schema::table('auto_supply_plans', function (Blueprint $table) {
            $table->dropIndex(['business_status']);
            $table->dropColumn([
                'business_status',
                'approved_at',
                'approved_by',
                'approval_fingerprint',
                'materialized_at',
            ]);
        });
    }
};
