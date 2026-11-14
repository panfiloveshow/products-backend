<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\AutoSupplyPlan;
use App\Models\Integration;
use App\Services\AutoSupplyPlanning\DataFreshnessRegistry;
use App\Services\AutoSupplyPlanning\PlanningSnapshotEvidence;
use App\Services\AutoSupplyPlanning\PlanningSourceFingerprint;
use Illuminate\Support\Facades\DB;

/** Bounded inspection of local observations; no refresh, calculation or marketplace command. */
final class ManagerSupplyInputHealthService
{
    public function __construct(private ManagerStoreResolver $stores, private DataFreshnessRegistry $registry, private PlanningSourceFingerprint $fingerprints) {}

    public function read(ManagerIdentity $who, int $integrationId, ?string $planId, int $page, int $limit): array
    {
        foreach (['auto_supply.view', 'integrations.view'] as $permission) {
            if (! $who->permits($permission)) {
                throw new ManagerDataException('Недостаточно прав на исходные данные поставок.', 403);
            }
        }
        $integration = Integration::whereKey($integrationId)->where('work_space_id', $who->workspaceId)->first();
        if (! $integration || ! in_array($integration->marketplace, ['ozon', 'wildberries'], true)) {
            throw new ManagerDataException('Магазин не найден.', 404);
        }
        $store = $this->stores->resolve($who, $integrationId, $integration->marketplace);
        $plan = $planId === null ? null : AutoSupplyPlan::whereKey($planId)->where('integration_id', $integrationId)->where('marketplace', $integration->marketplace)->first();
        if ($planId !== null && ! $plan) {
            throw new ManagerDataException('План не найден в выбранном магазине.', 404);
        }
        $health = $this->registry->inspect($integration, $planId);
        $query = $plan ? $plan->lines()->where('qty_rounded', '>', 0)->where('is_excluded', false)->select('sku')->distinct()->orderBy('sku')
            : DB::table('inventory_warehouses')->where('integration_id', $integrationId)->where('marketplace', $integration->marketplace)->select('sku')->distinct()->orderBy('sku');
        $total = (clone $query)->count('sku');
        $skus = (clone $query)->offset(($page - 1) * $limit)->limit($limit)->pluck('sku')->all();
        $items = [];
        foreach ($skus as $sku) {
            if (! is_string($sku) || trim($sku) === '' || mb_strlen($sku) > 200) {
                throw new ManagerDataException('В источнике есть неподтверждённый идентификатор товара.', 503);
            }
            $inventory = $this->fingerprints->observe(DB::table('inventory_warehouses')->where('integration_id', $integrationId)
                ->where('marketplace', $integration->marketplace)->where('sku', $sku)->orderBy('id'), 'inventory', 'last_updated');
            $economics = $this->fingerprints->observe(DB::table('unit_economics')->where('integration_id', $integrationId)
                ->where('marketplace', $integration->marketplace)->where('sku', $sku)->orderBy('id'), 'economics', 'updated_at');
            $items[] = ['sku' => $sku, 'inventory' => $this->projection($inventory), 'economics' => $this->projection($economics),
                'demand_origin_verified' => false, 'financial_inputs_complete' => false];
        }
        $errors = $plan ? PlanningSnapshotEvidence::errors($plan, $health) : [];
        $snapshot = $plan?->snapshot;
        $savedHash = $snapshot?->summary_json['facts_hash'] ?? null;
        $healthAfter = $this->registry->inspect($integration, $planId);
        if (! hash_equals($health['hash'], $healthAfter['hash'])) {
            throw new ManagerDataException('Исходные данные изменились во время чтения. Повторите проверку.', 409);
        }
        $current = $this->stores->resolve($who, $integrationId, $integration->marketplace);
        if ($current->binding !== $store->binding || $current->name !== $store->name) {
            throw new ManagerDataException('Магазин или ключи изменились во время чтения.', 409);
        }
        $more = $page * $limit < $total;

        return ['schema_version' => 1, 'workspace_id' => (string) $who->workspaceId, 'integration_id' => (string) $integrationId, 'integration_name' => $store->name,
            'marketplace' => $integration->marketplace, 'plan_id' => $planId, 'scope' => $plan ? 'selected_plan_skus' : 'observed_inventory_skus',
            'source' => 'Products local planning inputs', 'read_at' => now()->utc()->toIso8601String(), 'data_updated_at' => null,
            'input_version' => $health['hash'], 'content_version' => $health['input_evidence']['hash'], 'local_registry_ready' => $health['can_approve'] === true,
            'native_origin_verified' => false, 'financial_inputs_complete' => false, 'execution_allowed' => false,
            'registry_sources' => array_map(fn ($key, $card) => ['key' => $key, 'status' => $card['status'], 'blocking' => $card['blocking'],
                'observed_at' => $card['fetched_at'], 'timestamp_basis' => $card['timestamp_basis'], 'native_origin_verified' => false], array_keys($health['sources']), array_values($health['sources'])),
            'plan_binding' => $plan === null ? null : ['snapshot_present' => $snapshot !== null, 'snapshot_status' => $snapshot?->status,
                'unchanged_since_calculation' => PlanningSnapshotEvidence::digest($savedHash) ? hash_equals($savedHash, $health['hash']) : null,
                'errors' => array_map(fn ($e) => ['code' => $e['code'], 'message' => $e['message']], $errors),
                'review_path' => '/products/shipments/auto-plans/'.$plan->id],
            'items' => $items, 'page' => $page, 'limit' => $limit, 'total' => $total, 'has_more' => $more, 'next_page' => $more ? $page + 1 : null,
            'warnings' => ['Dates describe local records or reported observations, not independently verified Native origin.', 'Demand coverage, complete financial inputs and physical stock require separate verification.']];
    }

    private function projection(array $value): array
    {
        $invalid = $value['missing_row_dates'] + $value['invalid_row_dates'] + $value['future_row_dates'];
        $status = $value['rows'] === 0 || $invalid > 0 ? 'missing' : ($value['stale_rows'] > 0 ? 'stale' : 'ready');

        return ['status' => $status, 'rows' => $value['rows'], 'missing_row_dates' => $value['missing_row_dates'], 'invalid_row_dates' => $value['invalid_row_dates'],
            'future_row_dates' => $value['future_row_dates'], 'stale_rows' => $value['stale_rows'], 'oldest_row_at' => $value['oldest_row_at'], 'newest_row_at' => $value['newest_row_at'],
            'timestamp_basis' => $value['timestamp_basis'], 'local_freshness_verified' => $status === 'ready', 'native_origin_verified' => false];
    }
}
