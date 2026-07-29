<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_supply_plans', function (Blueprint $table) {
            $table->json('requested_params_json')->nullable()->after('params');
            $table->json('effective_params_json')->nullable()->after('requested_params_json');
            $table->json('validation_json')->nullable()->after('data_quality_json');
            $table->timestamp('validated_at')->nullable()->after('validation_json');
            $table->string('validation_fingerprint', 64)->nullable()->after('validated_at');
            $table->string('calculation_engine', 30)->default('legacy')->after('algorithm_version');
            $table->string('forecast_version', 40)->nullable()->after('calculation_engine');
            $table->string('allocation_version', 40)->nullable()->after('forecast_version');
            $table->string('adapter_version', 40)->nullable()->after('allocation_version');
            $table->string('code_commit', 64)->nullable()->after('adapter_version');
            $table->timestamp('execution_started_at')->nullable()->after('materialized_at');
            $table->timestamp('execution_completed_at')->nullable()->after('execution_started_at');
            $table->text('last_execution_error')->nullable()->after('execution_completed_at');
        });

        Schema::table('auto_supply_plan_lines', function (Blueprint $table) {
            $table->unsignedInteger('original_qty_rounded')->nullable()->after('qty_rounded');
            $table->boolean('is_excluded')->default(false)->after('original_qty_rounded')->index();
            $table->text('manual_comment')->nullable()->after('is_excluded');
            $table->json('manual_override_json')->nullable()->after('manual_comment');
            $table->unsignedBigInteger('manual_updated_by')->nullable()->after('manual_override_json');
            $table->timestamp('manual_updated_at')->nullable()->after('manual_updated_by');
            $table->string('source_hash', 64)->nullable()->after('manual_updated_at');
        });

        Schema::table('integrations', function (Blueprint $table) {
            $table->string('credential_type', 20)->default('api_key')->after('credentials');
            $table->timestamp('credentials_expires_at')->nullable()->after('credential_type');
            $table->string('credential_health', 20)->default('unknown')->after('credentials_expires_at');
            $table->json('credential_scopes')->nullable()->after('credential_health');
            $table->timestamp('credential_last_checked_at')->nullable()->after('credential_scopes');
        });

        Schema::create('auto_supply_plan_adjustments', function (Blueprint $table) {
            $table->id();
            $table->uuid('auto_supply_plan_id');
            $table->unsignedBigInteger('auto_supply_plan_line_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 40);
            $table->json('old_values_json')->nullable();
            $table->json('new_values_json')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->foreign('auto_supply_plan_id')
                ->references('id')
                ->on('auto_supply_plans')
                ->cascadeOnDelete();
            $table->foreign('auto_supply_plan_line_id')
                ->references('id')
                ->on('auto_supply_plan_lines')
                ->nullOnDelete();
            $table->index(['auto_supply_plan_id', 'created_at'], 'aspa_plan_created_index');
        });

        Schema::create('auto_supply_plan_executions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('auto_supply_plan_id');
            $table->unsignedBigInteger('integration_id');
            $table->string('idempotency_key', 120);
            $table->string('status', 30)->default('pending')->index();
            $table->string('plan_fingerprint', 64);
            $table->json('request_json')->nullable();
            $table->json('result_json')->nullable();
            $table->json('error_json')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedBigInteger('initiated_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('auto_supply_plan_id')
                ->references('id')
                ->on('auto_supply_plans')
                ->cascadeOnDelete();
            $table->foreign('integration_id')
                ->references('id')
                ->on('integrations')
                ->cascadeOnDelete();
            $table->unique(
                ['auto_supply_plan_id', 'idempotency_key'],
                'aspe_plan_idempotency_unique'
            );
        });

        Schema::table('supplies', function (Blueprint $table) {
            $table->string('ozon_order_id')->nullable()->after('ozon_supply_id')->index();
            $table->uuid('auto_supply_plan_execution_id')->nullable()->after('auto_supply_plan_id');
            $table->string('execution_step', 30)->nullable()->after('auto_supply_plan_execution_id');
            $table->text('execution_error')->nullable()->after('execution_step');
            $table->timestamp('external_last_synced_at')->nullable()->after('execution_error');

            $table->foreign('auto_supply_plan_execution_id', 'supplies_aspe_fk')
                ->references('id')
                ->on('auto_supply_plan_executions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            $table->dropForeign('supplies_aspe_fk');
            $table->dropIndex(['ozon_order_id']);
            $table->dropColumn([
                'ozon_order_id',
                'auto_supply_plan_execution_id',
                'execution_step',
                'execution_error',
                'external_last_synced_at',
            ]);
        });

        Schema::dropIfExists('auto_supply_plan_executions');
        Schema::dropIfExists('auto_supply_plan_adjustments');

        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn([
                'credential_type',
                'credentials_expires_at',
                'credential_health',
                'credential_scopes',
                'credential_last_checked_at',
            ]);
        });

        Schema::table('auto_supply_plan_lines', function (Blueprint $table) {
            $table->dropIndex(['is_excluded']);
            $table->dropColumn([
                'original_qty_rounded',
                'is_excluded',
                'manual_comment',
                'manual_override_json',
                'manual_updated_by',
                'manual_updated_at',
                'source_hash',
            ]);
        });

        Schema::table('auto_supply_plans', function (Blueprint $table) {
            $table->dropColumn([
                'requested_params_json',
                'effective_params_json',
                'validation_json',
                'validated_at',
                'validation_fingerprint',
                'calculation_engine',
                'forecast_version',
                'allocation_version',
                'adapter_version',
                'code_commit',
                'execution_started_at',
                'execution_completed_at',
                'last_execution_error',
            ]);
        });
    }
};
