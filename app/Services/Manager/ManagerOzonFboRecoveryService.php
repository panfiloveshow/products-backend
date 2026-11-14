<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\AutoSupplyPlan;
use App\Models\Integration;
use App\Models\ManagerOzonFboManualResolution;
use App\Services\AutoSupplyPlanning\DataFreshnessRegistry;
use App\Services\AutoSupplyPlanning\PlanningSourceFingerprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class ManagerOzonFboRecoveryService
{
    public const CONFIRMATIONS = [
        'fbo_cabinet_checked',
        'fbo_no_pending_draft_or_supply',
        'fbo_late_effect_risk_accepted',
        'fbo_hold_release_understood',
    ];

    public const SCHEME_KINDS = [
        'ozon_crossdock',
        'ozon_dropoff_partner',
        'ozon_partial_availability_fallback',
    ];

    public function __construct(
        private ManagerStoreResolver $stores,
        private DataFreshnessRegistry $freshness,
        private PlanningSourceFingerprint $fingerprints,
    ) {}

    public function unknown(
        ManagerIdentity $identity,
        int $integrationId,
        string $marketplace,
        ?string $cursor = null,
    ): array {
        $this->readPermission($identity);
        [, $store] = $this->resolveOzonStore($identity, $integrationId, $marketplace);

        $all = $this->collectUnknownOperations($identity, $integrationId);
        $offset = $cursor !== null && ctype_digit($cursor) ? (int) $cursor : 0;
        $limit = 50;
        $slice = array_slice($all, $offset, $limit);
        $hasMore = ($offset + $limit) < count($all);

        return [
            'workspace_id' => (string) $identity->workspaceId,
            'integration_id' => (string) $integrationId,
            'integration_name' => $store->name,
            'marketplace' => 'ozon',
            'items' => $slice,
            'next_cursor' => $hasMore ? (string) ($offset + $limit) : null,
            'has_more' => $hasMore,
        ];
    }

    public function prepareResolution(
        ManagerIdentity $identity,
        string $operationId,
        int $integrationId,
        string $marketplace,
        string $reason,
        string $supportReference,
    ): array {
        $this->writePermission($identity);
        if (! Schema::hasTable('manager_ozon_fbo_manual_resolutions')) {
            throw new ManagerDataException('Таблица ручных разборов Ozon FBO недоступна.', 503);
        }

        [, $store] = $this->resolveOzonStore($identity, $integrationId, $marketplace);
        $target = $this->findUnknownTarget($identity, $integrationId, $operationId);
        if ($target === null) {
            throw new ManagerDataException('Удерживаемая операция Ozon FBO в статусе unknown не найдена.', 404);
        }

        $now = now()->utc();
        $row = new ManagerOzonFboManualResolution([
            'id' => (string) Str::uuid(),
            'workspace_id' => $identity->workspaceId,
            'user_id' => $identity->userId,
            'integration_id' => $integrationId,
            'integration_name' => $store->name,
            'marketplace' => 'ozon',
            'store_binding' => $store->binding,
            'operation_id' => $operationId,
            'operation_type' => $target['operation_type'],
            'source_table' => $target['source_table'],
            'reason' => trim($reason),
            'support_reference' => trim($supportReference),
            'status' => 'awaiting_confirmation',
            'expires_at' => $now->copy()->addMinutes(5),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $row->fingerprint = $this->fingerprint($row);
        $row->save();

        return $this->exposeResolution($row);
    }

    public function getResolution(ManagerIdentity $identity, string $operationId, string $resolutionId): array
    {
        $this->readPermission($identity);
        $row = $this->ownedResolution($identity, $operationId, $resolutionId);
        if ($row->status === 'awaiting_confirmation' && $row->expires_at->isPast()) {
            $row->update(['status' => 'expired']);
        }

        return $this->exposeResolution($row->fresh());
    }

    public function cancelResolution(
        ManagerIdentity $identity,
        string $operationId,
        string $resolutionId,
        string $fingerprint,
    ): array {
        $this->readPermission($identity);
        $row = $this->ownedResolution($identity, $operationId, $resolutionId);
        $this->integrity($row, $fingerprint);
        if ($row->status === 'awaiting_confirmation') {
            $row->update(['status' => 'cancelled']);
        }

        return $this->exposeResolution($row->fresh());
    }

    public function approveResolution(
        ManagerIdentity $identity,
        string $operationId,
        string $resolutionId,
        string $fingerprint,
        array $confirmations,
    ): array {
        $this->writePermission($identity);
        $this->validateConfirmations($confirmations);
        $row = $this->ownedResolution($identity, $operationId, $resolutionId);
        $this->integrity($row, $fingerprint);

        if ($row->status !== 'awaiting_confirmation') {
            return $this->exposeResolution($row);
        }

        [, $store] = $this->resolveOzonStore($identity, (int) $row->integration_id, 'ozon');
        if (! hash_equals($row->store_binding, $store->binding)) {
            throw new ManagerDataException('Ключи магазина Ozon изменились после подготовки разбора.', 409);
        }

        DB::transaction(function () use ($row, $fingerprint, $confirmations) {
            $locked = ManagerOzonFboManualResolution::whereKey($row->id)->lockForUpdate()->firstOrFail();
            $this->integrity($locked, $fingerprint);
            if ($locked->status !== 'awaiting_confirmation') {
                return;
            }
            if ($locked->expires_at->isPast()) {
                $locked->update(['status' => 'expired']);

                return;
            }

            $targetRow = DB::table($locked->source_table)->where('id', $locked->operation_id)->lockForUpdate()->first();
            if (! $targetRow || ! in_array($targetRow->status, ['unknown', 'resolved_manually'], true)) {
                throw new ManagerDataException('Целевая операция Ozon FBO больше не находится в статусе unknown.', 409);
            }

            DB::table($locked->source_table)->where('id', $locked->operation_id)->update([
                'status' => 'resolved_manually',
                'updated_at' => now()->utc(),
            ]);

            if (Schema::hasTable('manager_fbo_resource_holds')) {
                DB::table('manager_fbo_resource_holds')->where('operation_id', $locked->operation_id)->delete();
            }

            $locked->update([
                'status' => 'resolved_manually',
                'confirmations' => $confirmations,
                'resolved_at' => now()->utc(),
            ]);
        });

        return $this->exposeResolution($row->fresh());
    }

    public function schemeHandoffContext(
        ManagerIdentity $identity,
        int $integrationId,
        string $marketplace,
        string $planId,
        string $inputVersion,
        string $schemeKind,
        ?string $dropoffPointId = null,
        ?string $targetClusterId = null,
    ): array {
        $this->readPermission($identity);
        if (! in_array($schemeKind, self::SCHEME_KINDS, true)) {
            throw new ManagerDataException('Неизвестная схема поставки Ozon FBO.', 422);
        }

        [$integration, $store] = $this->resolveOzonStore($identity, $integrationId, $marketplace);
        $plan = AutoSupplyPlan::whereKey($planId)
            ->where('integration_id', $integrationId)
            ->where('marketplace', 'ozon')
            ->first();
        if (! $plan) {
            throw new ManagerDataException('План поставки Ozon FBO не найден.', 404);
        }
        if ($plan->status !== AutoSupplyPlan::STATUS_READY) {
            throw new ManagerDataException('Расчёт плана поставки ещё не завершён.', 409);
        }

        $health = $this->freshness->inspect($integration, $planId);
        $snapshotHash = $plan->snapshot?->summary_json['facts_hash'] ?? null;
        $bindingFresh = hash_equals($health['hash'], $inputVersion)
            && ($snapshotHash === null || ! is_string($snapshotHash) || hash_equals($snapshotHash, $health['hash']));

        $lines = $plan->lines()->where('qty_rounded', '>', 0)->where('is_excluded', false)->orderBy('id')->get();
        $skus = [];
        $totalUnits = 0;
        foreach ($lines as $line) {
            $skus[$line->sku] = true;
            $totalUnits += max(0, (int) $line->qty_rounded);
        }
        $skuList = array_keys($skus);
        sort($skuList, SORT_STRING);

        $staleStockRows = 0;
        $missingCostRows = 0;
        foreach ($skuList as $sku) {
            $inv = $this->fingerprints->observe(
                DB::table('inventory_warehouses')
                    ->where('integration_id', $integrationId)
                    ->where('marketplace', 'ozon')
                    ->where('sku', $sku)
                    ->orderBy('id'),
                'inventory',
                'last_updated',
            );
            $staleStockRows += (int) ($inv['stale_rows'] ?? 0);

            $hasCost = DB::table('unit_economics')
                ->where('integration_id', $integrationId)
                ->where('marketplace', 'ozon')
                ->where('sku', $sku)
                ->where('cost_price', '>', 0)
                ->exists();
            if (! $hasCost) {
                $missingCostRows++;
            }
        }

        $localFreshnessVerified = ($health['can_approve'] ?? false) === true && $staleStockRows === 0;
        $cleanDropoff = $dropoffPointId !== null && trim($dropoffPointId) !== '' ? trim($dropoffPointId) : null;
        $cleanCluster = $targetClusterId !== null && trim($targetClusterId) !== '' ? trim($targetClusterId) : null;

        $contextFingerprint = hash('sha256', json_encode([
            'manager_fbo_scheme_handoff_context_v1',
            (string) $identity->workspaceId,
            (string) $integrationId,
            $store->binding,
            $planId,
            $health['hash'],
            $schemeKind,
            $cleanDropoff,
            $cleanCluster,
            count($skuList),
            $totalUnits,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return [
            'workspace_id' => (string) $identity->workspaceId,
            'integration_id' => (string) $integrationId,
            'integration_name' => $store->name,
            'marketplace' => 'ozon',
            'plan_id' => $planId,
            'input_version' => $inputVersion,
            'input_binding_state' => $bindingFresh ? 'fresh' : 'stale',
            'scheme_kind' => $schemeKind,
            'dropoff_point_id' => $cleanDropoff,
            'target_cluster_id' => $cleanCluster,
            'total_skus' => count($skuList),
            'total_units' => $totalUnits,
            'context_fingerprint' => $contextFingerprint,
            'input_provenance' => [
                'local_freshness_verified' => $localFreshnessVerified,
                'native_origin_verified' => false,
                'demand_completeness_verified' => false,
                'financial_inputs_complete' => false,
                'stale_stock_rows_count' => $staleStockRows,
                'missing_cost_rows_count' => $missingCostRows,
            ],
            'observed_at' => now()->utc()->toIso8601String(),
        ];
    }

    private function collectUnknownOperations(ManagerIdentity $identity, int $integrationId): array
    {
        $items = [];
        $heldIds = Schema::hasTable('manager_fbo_resource_holds')
            ? DB::table('manager_fbo_resource_holds')->pluck('operation_id')->flip()->all()
            : [];

        if (Schema::hasTable('manager_ozon_fbo_operations')) {
            $rows = DB::table('manager_ozon_fbo_operations')
                ->where('workspace_id', $identity->workspaceId)
                ->where('integration_id', $integrationId)
                ->where('status', 'unknown')
                ->get();
            foreach ($rows as $r) {
                $plan = json_decode((string) $r->plan, true) ?: [];
                $opType = in_array($r->operation_type, ['fbo_ozon_draft_create', 'fbo_ozon_supply_create'], true)
                    ? $r->operation_type
                    : 'fbo_ozon_draft_create';
                $items[] = [
                    'operation_id' => (string) $r->id,
                    'operation_type' => $opType,
                    'plan_id' => isset($plan['plan_id']) && is_string($plan['plan_id']) ? $plan['plan_id'] : null,
                    'native_draft_id' => $r->native_draft_id !== null ? (string) $r->native_draft_id : null,
                    'native_order_id' => $r->native_order_id !== null ? (string) $r->native_order_id : null,
                    'created_at' => Carbon::parse($r->created_at)->utc()->toIso8601String(),
                    'hold_active' => isset($heldIds[$r->id]),
                    'native_outcome_verified' => false,
                    'physical_status' => null,
                ];
            }
        }

        if (Schema::hasTable('manager_supply_calculations')) {
            $rows = DB::table('manager_supply_calculations')
                ->where('workspace_id', $identity->workspaceId)
                ->where('integration_id', $integrationId)
                ->where('marketplace', 'ozon')
                ->where('status', 'unknown')
                ->get();
            foreach ($rows as $r) {
                $items[] = [
                    'operation_id' => (string) $r->id,
                    'operation_type' => 'supply_plan_calculation',
                    'plan_id' => (string) $r->plan_id,
                    'native_draft_id' => null,
                    'native_order_id' => null,
                    'created_at' => Carbon::parse($r->created_at)->utc()->toIso8601String(),
                    'hold_active' => true,
                    'native_outcome_verified' => false,
                    'physical_status' => null,
                ];
            }
        }

        usort($items, fn (array $a, array $b) => strcmp($b['created_at'], $a['created_at']));

        return $items;
    }

    private function findUnknownTarget(ManagerIdentity $identity, int $integrationId, string $operationId): ?array
    {
        if (Schema::hasTable('manager_ozon_fbo_operations')) {
            $row = DB::table('manager_ozon_fbo_operations')
                ->where('id', $operationId)
                ->where('workspace_id', $identity->workspaceId)
                ->where('integration_id', $integrationId)
                ->where('status', 'unknown')
                ->first();
            if ($row) {
                $opType = in_array($row->operation_type, ['fbo_ozon_draft_create', 'fbo_ozon_supply_create'], true)
                    ? $row->operation_type
                    : 'fbo_ozon_draft_create';

                return [
                    'source_table' => 'manager_ozon_fbo_operations',
                    'operation_type' => $opType,
                ];
            }
        }

        if (Schema::hasTable('manager_supply_calculations')) {
            $row = DB::table('manager_supply_calculations')
                ->where('id', $operationId)
                ->where('workspace_id', $identity->workspaceId)
                ->where('integration_id', $integrationId)
                ->where('marketplace', 'ozon')
                ->where('status', 'unknown')
                ->first();
            if ($row) {
                return [
                    'source_table' => 'manager_supply_calculations',
                    'operation_type' => 'supply_plan_calculation',
                ];
            }
        }

        return null;
    }

    private function exposeResolution(ManagerOzonFboManualResolution $row): array
    {
        $holdActive = false;
        if ($row->status !== 'resolved_manually') {
            if ($row->source_table === 'manager_supply_calculations') {
                $holdActive = DB::table('manager_supply_calculations')
                    ->where('id', $row->operation_id)
                    ->where('status', 'unknown')
                    ->exists();
            } elseif (Schema::hasTable('manager_fbo_resource_holds')) {
                $holdActive = DB::table('manager_fbo_resource_holds')
                    ->where('operation_id', $row->operation_id)
                    ->exists();
            }
        }

        return [
            'id' => $row->id,
            'fingerprint' => $row->fingerprint,
            'workspace_id' => (string) $row->workspace_id,
            'status' => $row->status,
            'operation_id' => $row->operation_id,
            'operation_type' => $row->operation_type,
            'integration_id' => (string) $row->integration_id,
            'integration_name' => $row->integration_name,
            'marketplace' => 'ozon',
            'reason' => $row->reason,
            'support_reference' => $row->support_reference,
            'resolved_at' => $row->resolved_at?->utc()->toIso8601String(),
            'hold_active' => $holdActive,
            'native_outcome_verified' => false,
            'physical_status' => null,
            'created_at' => $row->created_at->utc()->toIso8601String(),
            'expires_at' => $row->expires_at->utc()->toIso8601String(),
        ];
    }

    private function ownedResolution(ManagerIdentity $identity, string $operationId, string $resolutionId): ManagerOzonFboManualResolution
    {
        $row = ManagerOzonFboManualResolution::whereKey($resolutionId)
            ->where('operation_id', $operationId)
            ->where('workspace_id', $identity->workspaceId)
            ->where('user_id', $identity->userId)
            ->first();
        if (! $row) {
            throw new ManagerDataException('Предложение ручного разбора Ozon FBO не найдено.', 404);
        }

        return $row;
    }

    private function resolveOzonStore(ManagerIdentity $identity, int $integrationId, string $marketplace): array
    {
        if ($marketplace !== 'ozon') {
            throw new ManagerDataException('Операции FBO поддерживаются для Ozon.', 422);
        }
        $integration = Integration::whereKey($integrationId)
            ->where('work_space_id', $identity->workspaceId)
            ->where('marketplace', 'ozon')
            ->first();
        if (! $integration) {
            throw new ManagerDataException('Магазин Ozon не найден в выбранном workspace.', 404);
        }

        return [$integration, $this->stores->resolve($identity, $integrationId, 'ozon')];
    }

    private function readPermission(ManagerIdentity $identity): void
    {
        foreach (['auto_supply.view', 'integrations.view'] as $perm) {
            if (! $identity->permits($perm)) {
                throw new ManagerDataException('Недостаточно прав для чтения операций Ozon FBO.', 403);
            }
        }
    }

    private function writePermission(ManagerIdentity $identity): void
    {
        if (! $identity->personalConfirmed) {
            throw new ManagerDataException('Ручной разбор Ozon FBO требует личной сессии сотрудника.', 403);
        }
        $this->readPermission($identity);
        if (! $identity->permits('auto_supply.create')) {
            throw new ManagerDataException('Недостаточно прав для ручного разбора операций Ozon FBO.', 403);
        }
    }

    private function validateConfirmations(array $confirmations): void
    {
        if (count($confirmations) !== count(self::CONFIRMATIONS)) {
            throw new ManagerDataException('Нужны четыре личные отметки ручного разбора Ozon FBO.', 422);
        }
        foreach (self::CONFIRMATIONS as $key) {
            if (($confirmations[$key] ?? null) !== true) {
                throw new ManagerDataException('Каждая из четырёх отметок ручного разбора Ozon FBO должна быть включена.', 422);
            }
        }
    }

    private function fingerprint(ManagerOzonFboManualResolution $row): string
    {
        return hash('sha256', json_encode([
            'manager_ozon_fbo_manual_resolution_v1',
            $row->id,
            (string) $row->workspace_id,
            (string) $row->user_id,
            (string) $row->integration_id,
            $row->store_binding,
            $row->operation_id,
            $row->operation_type,
            $row->reason,
            $row->support_reference,
            $row->created_at->utc()->toIso8601String(),
            $row->expires_at->utc()->toIso8601String(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function integrity(ManagerOzonFboManualResolution $row, string $fingerprint): void
    {
        if (! hash_equals($row->fingerprint, $fingerprint) || ! hash_equals($row->fingerprint, $this->fingerprint($row))) {
            throw new ManagerDataException('Состав ручного разбора Ozon FBO изменился.', 409);
        }
    }
}
