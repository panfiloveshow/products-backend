<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Http\Controllers\Api\AutoSupplyPlanController;
use App\Jobs\RunManagerSupplyCalculation;
use App\Models\AutoSupplyPlan;
use App\Models\Integration;
use App\Models\ManagerSupplyCalculation;
use App\Models\PlanningFactSnapshot;
use App\Services\LimitsSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ManagerSupplyCalculationService
{
    public function __construct(private ManagerStoreResolver $stores, private AutoSupplyPlanController $plans,
        private ManagerSupplyPlanParameters $parameters, private LimitsSyncService $limits) {}

    public function prepare(ManagerIdentity $identity, int $integrationId, array $parameters): array
    {
        $this->permission($identity, true); $this->available();
        [$integration, $store] = $this->store($identity, $integrationId, true);
        $recipe = $this->parameters->normalize($store->marketplace, $parameters);
        $op = new ManagerSupplyCalculation(['id' => (string) Str::uuid(), 'workspace_id' => $identity->workspaceId,
            'created_by' => $identity->userId, 'integration_id' => $integrationId, 'integration_name' => $store->name,
            'marketplace' => $store->marketplace, 'credential_binding' => $store->binding, 'parameters' => $recipe,
            'plan_id' => (string) Str::uuid(), 'status' => 'awaiting_confirmation', 'expires_at' => now()->utc()->addMinutes(5), 'events' => []]);
        $op->fingerprint = $this->fingerprint($op);
        $this->event($op, 'prepared');

        return $this->expose($op);
    }

    public function get(ManagerIdentity $identity, string $id): array
    {
        $op = $this->owned($identity, $id);
        $this->store($identity, (int) $op->integration_id);
        if ($op->status === 'awaiting_confirmation' && $op->expires_at->isPast()) {
            DB::transaction(function () use ($op) {
                $locked = ManagerSupplyCalculation::whereKey($op->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === 'awaiting_confirmation' && $locked->expires_at->isPast()) $this->event($locked, 'expired', ['status' => 'expired']);
            });
        }
        // A matching saved value alone is insufficient. A worker must have
        // claimed this request, and the owner's own fact snapshot must be ready.
        if (in_array($op->status, ['verifying', 'unknown'], true) && $op->run_started_at !== null) $this->finish($op->id, false, true);

        return $this->expose($op->fresh());
    }

    public function approve(ManagerIdentity $identity, string $id, string $fingerprint): array
    {
        $this->permission($identity, true); $this->available();
        $op = $this->owned($identity, $id);
        [$integration, $store] = $this->store($identity, (int) $op->integration_id, true);
        DB::transaction(function () use ($identity, $op, $integration, $store, $fingerprint) {
            $op = ManagerSupplyCalculation::whereKey($op->id)->lockForUpdate()->firstOrFail();
            $this->integrity($op, $fingerprint);
            if ($op->status !== 'awaiting_confirmation') return;
            if ($op->expires_at->isPast()) { $this->event($op, 'expired', ['status' => 'expired']); return; }
            if (! hash_equals($op->credential_binding, $store->binding)) { $this->event($op, 'store_changed', ['status' => 'cancelled', 'message_code' => 'store_changed']); return; }
            $response = $this->plans->createPendingForManager($identity, $integration, $op->parameters, $op->id, $op->plan_id);
            if ($response->getStatusCode() !== 201) {
                $this->event($op, 'creation_rejected', ['status' => 'failed', 'message_code' => 'creation_rejected']);
                return;
            }
            $plan = AutoSupplyPlan::whereKey($op->plan_id)->where('integration_id', $op->integration_id)->firstOrFail();
            $this->assertPlan($op, $plan);
            $this->event($op, 'approved', ['approved_at' => now()->utc()]);
            $this->event($op, 'plan_created', ['status' => 'verifying', 'plan_created_at' => now()->utc(), 'message_code' => 'queue_pending']);
            DB::afterCommit(fn () => $this->dispatchApproved($op->id));
        });

        return $this->get($identity, $id);
    }

    public function cancel(ManagerIdentity $identity, string $id, string $fingerprint): array
    {
        $op = $this->owned($identity, $id); $this->store($identity, (int) $op->integration_id);
        DB::transaction(function () use ($op, $fingerprint) {
            $op = ManagerSupplyCalculation::whereKey($op->id)->lockForUpdate()->firstOrFail(); $this->integrity($op, $fingerprint);
            if (! in_array($op->status, ['awaiting_confirmation', 'verifying'], true)) return;
            if ($op->run_started_at !== null) throw new ManagerDataException('Расчёт уже начал работу. Отмена фонового процесса не подтверждена.', 409);
            $this->event($op, 'cancelled', ['status' => 'cancelled', 'message_code' => null]);
            if ($op->plan_created_at !== null) AutoSupplyPlan::whereKey($op->plan_id)->update(['status' => 'error', 'business_status' => 'cancelled']);
        });

        return $this->get($identity, $id);
    }

    /** Durable outbox, safe to retry: every queue copy carries the same guarded request. */
    public function dispatchApproved(string $id): bool
    {
        if (! config('services.manager.supply_calculations_enabled', false)) return false;
        $op = DB::transaction(function () use ($id) {
            $op = ManagerSupplyCalculation::whereKey($id)->lockForUpdate()->first();
            if (! $op || $op->status !== 'verifying' || $op->approved_at === null || $op->queued_at !== null || $op->run_started_at !== null
                || ($op->last_queue_attempt_at !== null && $op->last_queue_attempt_at->gt(now()->subSeconds(30)))) return null;
            $op->forceFill(['queue_attempts' => $op->queue_attempts + 1, 'last_queue_attempt_at' => now()->utc()])->save();

            return $op;
        });
        if ($op === null) return false;
        try {
            RunManagerSupplyCalculation::dispatch($id);
            DB::transaction(function () use ($id) {
                $op = ManagerSupplyCalculation::whereKey($id)->lockForUpdate()->firstOrFail();
                $this->event($op, 'queue_acknowledged', ['queued_at' => now()->utc(), 'message_code' => $op->status === 'verifying' ? null : $op->message_code]);
            });
            try { $this->limits->syncWorkspaceAutoplanningLimit((int) $op->workspace_id); } catch (\Throwable) { /* Next quota check reconciles the persisted count. */ }

            return true;
        } catch (\Throwable) {
            // The push may have succeeded before its receipt was lost. Outbox
            // retry does not recreate a plan; the worker has a one-use claim.
            return false;
        }
    }

    public function dispatchPending(int $limit = 50): int
    {
        if (! config('services.manager.supply_calculations_enabled', false)) return 0;
        $ids = ManagerSupplyCalculation::where('status', 'verifying')->whereNotNull('approved_at')->whereNull('queued_at')->whereNull('run_started_at')
            ->where(fn ($q) => $q->whereNull('last_queue_attempt_at')->orWhere('last_queue_attempt_at', '<=', now()->subSeconds(30)))
            ->orderBy('created_at')->limit(max(1, min(100, $limit)))->pluck('id');
        $count = 0;
        foreach ($ids as $id) if ($this->dispatchApproved($id)) $count++;

        return $count;
    }

    public function begin(string $id, ?string $claimId = null): ?string
    {
        $op = ManagerSupplyCalculation::find($id);
        if (! $op || $op->status !== 'verifying' || $op->approved_at === null || $op->run_started_at !== null) return null;
        $binding = null;
        try {
            if (config('services.manager.supply_calculations_enabled', false)) {
                [, $store] = $this->store(new ManagerIdentity((int) $op->workspace_id, (int) $op->created_by, []), (int) $op->integration_id, true);
                $binding = $store->binding;
            }
        } catch (\Throwable) { /* Never substitute an old local credential. */ }

        $claimId ??= $id;
        if (! Str::isUuid($claimId)) return null;
        return DB::transaction(function () use ($id, $binding, $claimId) {
            $op = ManagerSupplyCalculation::whereKey($id)->lockForUpdate()->first();
            if (! $op || $op->status !== 'verifying' || $op->approved_at === null || $op->run_started_at !== null) return null;
            if (! config('services.manager.supply_calculations_enabled', false) || $binding === null || ! hash_equals($op->credential_binding, $binding)) {
                $this->event($op, 'stopped_before_start', ['status' => 'cancelled', 'message_code' => 'stopped_before_start']);
                AutoSupplyPlan::whereKey($op->plan_id)->update(['status' => 'error', 'business_status' => 'cancelled']);
                return null;
            }
            try {
                $this->integrity($op, $op->fingerprint);
                $this->assertPlan($op, AutoSupplyPlan::findOrFail($op->plan_id));
            } catch (\Throwable) {
                $this->event($op, 'recipe_changed', ['status' => 'failed', 'message_code' => 'recipe_changed']);
                return null;
            }
            $this->event($op, 'worker_claimed', ['run_started_at' => now()->utc(), 'run_claim_id' => $claimId, 'message_code' => null]);

            return $op->plan_id;
        });
    }

    public function finish(string $id, bool $interrupted = false, bool $readOnlyRecovery = false, ?string $claimId = null): void
    {
        $candidate = ManagerSupplyCalculation::find($id);
        if ($claimId !== null && $candidate?->run_started_at !== null && $candidate->run_claim_id !== $claimId) return;
        $sourceConfirmed = null;
        if ($candidate && $candidate->run_started_at !== null && AutoSupplyPlan::whereKey($candidate->plan_id)->where('status', 'ready')->exists()) {
            try {
                [, $store] = $this->store(new ManagerIdentity((int) $candidate->workspace_id, (int) $candidate->created_by, []), (int) $candidate->integration_id, true);
                $sourceConfirmed = hash_equals($candidate->credential_binding, $store->binding);
            } catch (\Throwable) { /* A source outage is not proof of completion. */ }
        }
        DB::transaction(function () use ($id, $interrupted, $readOnlyRecovery, $sourceConfirmed, $claimId) {
            $op = ManagerSupplyCalculation::whereKey($id)->lockForUpdate()->first();
            if (! $op || ! in_array($op->status, ['verifying', 'unknown'], true)) return;
            if ($claimId !== null && $op->run_started_at !== null && $op->run_claim_id !== $claimId) return;
            if ($op->run_started_at === null) {
                if ($interrupted && ! $readOnlyRecovery) $this->event($op, 'queue_failed_before_start', ['queued_at' => null, 'last_queue_attempt_at' => now()->utc(), 'message_code' => 'queue_pending']);
                return;
            }
            $plan = AutoSupplyPlan::whereKey($op->plan_id)->where('integration_id', $op->integration_id)->first();
            $snapshot = $plan?->snapshot_id ? PlanningFactSnapshot::whereKey($plan->snapshot_id)->where('auto_supply_plan_id', $op->plan_id)->first() : null;
            if ($plan?->status === 'ready' && $snapshot?->status === 'ready') {
                try {
                    $this->integrity($op, $op->fingerprint);
                    $this->assertPlan($op, $plan);
                } catch (\Throwable) {
                    if ($op->status !== 'unknown' || $op->message_code !== 'result_recipe_changed') {
                        $this->event($op, 'result_recipe_changed', ['status' => 'unknown', 'message_code' => 'result_recipe_changed']);
                    }
                    return;
                }
            }
            if ($plan?->status === 'ready' && $snapshot?->status === 'ready' && $sourceConfirmed === true) {
                $this->event($op, 'calculation_verified', ['status' => 'succeeded', 'run_finished_at' => now()->utc(), 'snapshot_id' => $snapshot->id, 'message_code' => null]);
            } elseif ($plan?->status === 'ready' && $snapshot?->status === 'ready') {
                $code = $sourceConfirmed === false ? 'source_changed_during_calculation' : 'source_not_confirmed';
                if ($op->status !== 'unknown' || $op->message_code !== $code) $this->event($op, 'source_unconfirmed', ['status' => 'unknown', 'message_code' => $code]);
            } elseif ($plan?->status === 'error') {
                $this->event($op, 'calculation_failed', ['status' => 'failed', 'run_finished_at' => now()->utc(), 'message_code' => 'calculation_failed']);
            } elseif (! $readOnlyRecovery || $op->run_started_at->lte(now()->subSeconds(RunManagerSupplyCalculation::TIMEOUT_SECONDS + 30))) {
                $code = $interrupted ? 'worker_interrupted' : 'calculation_unconfirmed';
                if ($op->status !== 'unknown' || $op->message_code !== $code) $this->event($op, 'calculation_unconfirmed', ['status' => 'unknown', 'message_code' => $code]);
            }
        });
    }

    private function owned(ManagerIdentity $identity, string $id): ManagerSupplyCalculation
    {
        $this->permission($identity, false);
        $op = ManagerSupplyCalculation::whereKey($id)->where('workspace_id', $identity->workspaceId)->where('created_by', $identity->userId)->first();
        if (! $op) throw new ManagerDataException('Запрос расчёта не найден.', 404);

        return $op;
    }

    private function store(ManagerIdentity $identity, int $integrationId, bool $requireMirror = false): array
    {
        $integration = Integration::whereKey($integrationId)->where('work_space_id', $identity->workspaceId)->first();
        if (! $integration || ! in_array($integration->marketplace, ['wildberries', 'ozon'], true)) throw new ManagerDataException('Магазин не найден.', 404);

        $store = $this->stores->resolve($identity, $integrationId, $integration->marketplace);
        if ($requireMirror) {
            // The existing calculator instantiates API adapters from the local
            // integration. Matching only its logical ID would let an old key
            // select another source after reconnecting the CRM cabinet.
            $local = $integration->getDecryptedCredentials();
            $key = $local['api_key'] ?? null;
            $client = $local['client_id'] ?? null;
            if (! is_string($key) || ! hash_equals($store->apiKey, $key)
                || ($store->marketplace === 'ozon' && ((! is_string($client) && ! is_int($client)) || (string) $client !== $store->clientId
                    || ! empty($local['oauth_access_token']) || ! empty($local['access_token']) || ! empty(config('services.ozon.access_token'))))) {
                throw new ManagerDataException('Источник расчёта не совпадает с действующим подключением магазина. Обновите интеграцию перед подготовкой расчёта.', 409);
            }
        }

        return [$integration, $store];
    }

    private function assertPlan(ManagerSupplyCalculation $op, AutoSupplyPlan $plan): void
    {
        $p = $plan->params ?? [];
        if ((int) $plan->integration_id !== (int) $op->integration_id || $plan->marketplace !== $op->marketplace
            || ($p['manager_calculation_id'] ?? null) !== $op->id || ($p['manager_parameter_version'] ?? null) !== ManagerSupplyPlanParameters::VERSION) {
            throw new ManagerDataException('План не относится к согласованному запросу.', 409);
        }
        $recipe = $this->parameters->normalize($plan->marketplace, [
            'mode' => $plan->mode, 'analysis_period_days' => $p['analysis_period_days'] ?? null, 'horizon_days' => $plan->horizon_days,
            'min_cover_days' => $plan->min_cover_days, 'target_cover_days' => $plan->target_cover_days, 'max_cover_days' => $plan->max_cover_days,
            'safety_stock_days' => $p['safety_days'] ?? null, 'lead_time_days' => $p['lead_time_days'] ?? null, 'turnover_limit_days' => $plan->turnover_limit_days,
            'budget_limit' => $plan->budget_limit, 'include_in_transit' => $p['include_in_transit'] ?? null, 'skip_negative_profit' => $p['skip_negative_profit'] ?? null,
            'destination_ids' => $plan->marketplace === 'ozon' ? array_map(fn ($v) => 'cluster:'.$v, $p['cluster_ids'] ?? []) : ($p['warehouse_ids'] ?? []),
        ]);
        if ($recipe !== $this->parameters->normalize($op->marketplace, $op->parameters)) throw new ManagerDataException('Параметры плана изменились после подтверждения.', 409);
    }

    private function fingerprint(ManagerSupplyCalculation $op): string
    {
        return hash('sha256', json_encode(['manager_supply_calculation_v1', $op->id, (string) $op->workspace_id, (string) $op->created_by,
            (string) $op->integration_id, $op->marketplace, $op->credential_binding, $op->plan_id,
            $this->parameters->normalize($op->marketplace, $op->parameters)], JSON_THROW_ON_ERROR));
    }

    private function integrity(ManagerSupplyCalculation $op, string $fingerprint): void
    {
        if (! hash_equals($op->fingerprint, $fingerprint) || ! hash_equals($op->fingerprint, $this->fingerprint($op))) throw new ManagerDataException('Состав предложения не подтверждён.', 409);
    }

    private function event(ManagerSupplyCalculation $op, string $event, array $changes = []): void
    {
        $op->forceFill([...$changes, 'events' => [...($op->events ?? []), ['event' => $event, 'at' => now()->utc()->toIso8601String()]]])->save();
    }

    private function permission(ManagerIdentity $identity, bool $write): void
    {
        foreach (['auto_supply.view', 'integrations.view', ...($write ? ['auto_supply.create'] : [])] as $permission) {
            if (! $identity->permits($permission)) throw new ManagerDataException('Недостаточно прав на расчёт поставок.', 403);
        }
    }

    private function available(): void
    {
        if (! config('services.manager.supply_calculations_enabled', false)) throw new ManagerDataException('Запуск расчётов Сели остановлен.', 503);
    }

    private function expose(ManagerSupplyCalculation $op): array
    {
        $plan = $op->plan_created_at === null ? null : AutoSupplyPlan::whereKey($op->plan_id)->where('integration_id', $op->integration_id)->first();

        return ['schema_version' => 1, 'id' => $op->id, 'operation_type' => 'supply_plan_calculation', 'workspace_id' => (string) $op->workspace_id,
            'integration_id' => (string) $op->integration_id, 'integration_name' => $op->integration_name, 'marketplace' => $op->marketplace,
            'fingerprint' => $op->fingerprint, 'parameters' => $op->parameters, 'status' => $op->status, 'message_code' => $op->message_code,
            'result' => $op->plan_created_at === null ? null : ['plan_id' => $op->plan_id, 'calculation_status' => $op->status === 'succeeded' ? 'ready' : $plan?->status,
                'queued' => $op->queued_at !== null, 'started' => $op->run_started_at !== null, 'calculation_verified' => $op->status === 'succeeded',
                'snapshot_id' => $op->snapshot_id, 'review_path' => $plan ? '/products/shipments/auto-plans/'.$plan->id : null],
            'shipment_execution_allowed' => false, 'created_at' => $op->created_at->utc()->toIso8601String(), 'expires_at' => $op->expires_at->toIso8601String()];
    }
}
