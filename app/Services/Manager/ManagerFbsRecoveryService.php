<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\Integration;
use App\Models\ManagerFbsManualResolution;
use App\Models\Posting;
use App\Models\SellerWarehouseStock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class ManagerFbsRecoveryService
{
    public const CONFIRMATIONS = [
        'fbs_cabinet_checked',
        'fbs_no_pending_shipment_action',
        'fbs_late_effect_risk_accepted',
        'fbs_hold_release_understood',
    ];

    public function __construct(private ManagerStoreResolver $stores) {}

    public function unknown(
        ManagerIdentity $identity,
        int $integrationId,
        string $marketplace,
        ?string $cursor = null,
    ): array {
        $this->readPermission($identity);
        [, $store] = $this->resolveStore($identity, $integrationId, $marketplace);

        $all = $this->collectUnknownOperations($identity, $integrationId, $marketplace);
        $offset = $cursor !== null && ctype_digit($cursor) ? (int) $cursor : 0;
        $limit = 50;
        $slice = array_slice($all, $offset, $limit);
        $hasMore = ($offset + $limit) < count($all);

        return [
            'workspace_id' => (string) $identity->workspaceId,
            'integration_id' => (string) $integrationId,
            'integration_name' => $store->name,
            'marketplace' => $marketplace,
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
        if (! Schema::hasTable('manager_fbs_manual_resolutions')) {
            throw new ManagerDataException('Таблица ручных разборов FBS недоступна.', 503);
        }

        [, $store] = $this->resolveStore($identity, $integrationId, $marketplace);
        $target = $this->findUnknownTarget($identity, $integrationId, $marketplace, $operationId);
        if ($target === null) {
            throw new ManagerDataException('Удерживаемая операция FBS в статусе unknown не найдена.', 404);
        }

        $now = now()->utc();
        $row = new ManagerFbsManualResolution([
            'id' => (string) Str::uuid(),
            'workspace_id' => $identity->workspaceId,
            'user_id' => $identity->userId,
            'integration_id' => $integrationId,
            'integration_name' => $store->name,
            'marketplace' => $marketplace,
            'store_binding' => $store->binding,
            'operation_id' => $operationId,
            'operation_type' => $target['operation_type'],
            'source_table' => $target['source_table'],
            'external_id' => $target['external_id'],
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

        [, $store] = $this->resolveStore($identity, (int) $row->integration_id, $row->marketplace);
        if (! hash_equals($row->store_binding, $store->binding)) {
            throw new ManagerDataException('Ключи магазина изменились после подготовки разбора FBS.', 409);
        }

        DB::transaction(function () use ($row, $fingerprint, $confirmations) {
            $locked = ManagerFbsManualResolution::whereKey($row->id)->lockForUpdate()->firstOrFail();
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
                throw new ManagerDataException('Целевая операция FBS больше не находится в статусе unknown.', 409);
            }

            $updates = ['status' => 'resolved_manually', 'updated_at' => now()->utc()];
            if ($locked->source_table === 'manager_fbs_operations') {
                $updates['resource_key'] = null;
            }
            DB::table($locked->source_table)->where('id', $locked->operation_id)->update($updates);
            if (Schema::hasTable('manager_fbs_resource_holds')) {
                DB::table('manager_fbs_resource_holds')->where('operation_id', $locked->operation_id)->delete();
            }
            if ($locked->source_table === 'manager_ozon_packing_operations' && Schema::hasTable('manager_ozon_packing_outbox')) {
                DB::table('manager_ozon_packing_outbox')->where('operation_id', $locked->operation_id)->update([
                    'state' => 'cancelled',
                    'updated_at' => now()->utc(),
                ]);
            }

            $locked->update([
                'status' => 'resolved_manually',
                'confirmations' => $confirmations,
                'resolved_at' => now()->utc(),
            ]);
        });

        return $this->exposeResolution($row->fresh());
    }

    public function complexContext(
        ManagerIdentity $identity,
        int $integrationId,
        string $marketplace,
        array $postingIds,
    ): array {
        $this->readPermission($identity);
        [, $store] = $this->resolveStore($identity, $integrationId, $marketplace);

        $cleanIds = array_values(array_unique(array_map('trim', $postingIds)));
        $postings = Posting::with('items')
            ->where('integration_id', $integrationId)
            ->where('marketplace', $marketplace)
            ->whereIn('posting_number', $cleanIds)
            ->orderBy('posting_number')
            ->get();

        $requiredBySku = [];
        $namesBySku = [];
        $serializedPostings = [];

        foreach ($postings as $posting) {
            $meta = is_array($posting->meta) ? $posting->meta : [];
            $itemRows = [];
            foreach ($posting->items as $item) {
                $sku = (string) ($item->sku ?: $item->offer_id);
                $qty = max(0, (int) $item->quantity);
                if ($sku !== '') {
                    $requiredBySku[$sku] = ($requiredBySku[$sku] ?? 0) + $qty;
                    $namesBySku[$sku] = $item->name ?? $sku;
                }
                $itemMeta = is_array($item->meta) ? $item->meta : [];
                $itemRows[] = [
                    'sku' => $sku,
                    'offer_id' => $item->offer_id,
                    'name' => $item->name,
                    'quantity' => $qty,
                    'weight' => $item->weight !== null ? (string) $item->weight : null,
                    'exemplar_info_required' => (bool) ($itemMeta['exemplar_info_required'] ?? false),
                    'imei_required' => (bool) ($itemMeta['imei_required'] ?? false),
                    'gtd_required' => (bool) ($itemMeta['gtd_required'] ?? false),
                ];
            }

            $serializedPostings[] = [
                'posting_number' => (string) $posting->posting_number,
                'status' => (string) $posting->status,
                'warehouse_id' => $posting->warehouse_id !== null ? (string) $posting->warehouse_id : null,
                'warehouse_name' => $posting->warehouse_name,
                'is_multibox' => (bool) ($meta['is_multibox'] ?? false),
                'mandatory_mark_required' => (bool) ($meta['mandatory_mark_required'] ?? false),
                'cargo_type' => $meta['cargo_type'] ?? null,
                'items' => $itemRows,
            ];
        }

        ksort($requiredBySku, SORT_STRING);
        $stocks = SellerWarehouseStock::query()
            ->where('integration_id', $integrationId)
            ->whereIn('sku', array_keys($requiredBySku))
            ->get()
            ->keyBy('sku');

        $stockRows = [];
        $hasShortage = false;
        $latestObserved = null;

        foreach ($requiredBySku as $sku => $requiredQty) {
            $stock = $stocks->get($sku);
            $availableQty = $stock ? max(0, (int) $stock->quantity - (int) $stock->reserved) : 0;
            $shortageQty = max(0, $requiredQty - $availableQty);
            if ($shortageQty > 0) {
                $hasShortage = true;
            }
            if ($stock?->last_counted_at !== null) {
                $iso = Carbon::parse($stock->last_counted_at)->utc()->toIso8601String();
                if ($latestObserved === null || strcmp($iso, $latestObserved) > 0) {
                    $latestObserved = $iso;
                }
            }
            $stockRows[] = [
                'sku' => $sku,
                'product_name' => $stock?->product_name ?? ($namesBySku[$sku] ?? $sku),
                'required_qty' => $requiredQty,
                'available_qty' => $availableQty,
                'shortage_qty' => $shortageQty,
            ];
        }

        $fingerprint = hash('sha256', json_encode([
            'manager_fbs_complex_context_v1',
            (string) $identity->workspaceId,
            (string) $integrationId,
            $marketplace,
            $store->binding,
            $cleanIds,
            $serializedPostings,
            $stockRows,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return [
            'workspace_id' => (string) $identity->workspaceId,
            'integration_id' => (string) $integrationId,
            'integration_name' => $store->name,
            'marketplace' => $marketplace,
            'context_fingerprint' => $fingerprint,
            'observed_at' => now()->utc()->toIso8601String(),
            'postings' => $serializedPostings,
            'stock_cross_check' => [
                'stock_observed_at' => $latestObserved,
                'physical_stock_verified' => false,
                'has_shortage' => $hasShortage,
                'rows' => $stockRows,
            ],
        ];
    }

    private function collectUnknownOperations(ManagerIdentity $identity, int $integrationId, string $marketplace): array
    {
        $items = [];
        $heldIds = Schema::hasTable('manager_fbs_resource_holds')
            ? DB::table('manager_fbs_resource_holds')->pluck('operation_id')->flip()->all()
            : [];

        if (Schema::hasTable('manager_fbs_operations')) {
            $rows = DB::table('manager_fbs_operations')
                ->where('workspace_id', $identity->workspaceId)
                ->where('integration_id', $integrationId)
                ->where('marketplace', $marketplace)
                ->where('status', 'unknown')
                ->get();
            foreach ($rows as $r) {
                $items[] = [
                    'operation_id' => (string) $r->id,
                    'operation_type' => (string) $r->operation_type,
                    'source_table' => 'manager_fbs_operations',
                    'external_id' => (string) $r->external_id,
                    'created_at' => Carbon::parse($r->created_at)->utc()->toIso8601String(),
                    'hold_active' => $r->resource_key !== null || isset($heldIds[$r->id]),
                    'native_outcome_verified' => false,
                    'physical_status' => null,
                ];
            }
        }

        if ($marketplace === 'ozon' && Schema::hasTable('manager_ozon_carriage_operations')) {
            $rows = DB::table('manager_ozon_carriage_operations')
                ->where('workspace_id', $identity->workspaceId)
                ->where('integration_id', $integrationId)
                ->where('status', 'unknown')
                ->get();
            foreach ($rows as $r) {
                $items[] = [
                    'operation_id' => (string) $r->id,
                    'operation_type' => (string) $r->operation_type,
                    'source_table' => 'manager_ozon_carriage_operations',
                    'external_id' => (string) ($r->native_carriage_id ?? 'ozon_carriage'),
                    'created_at' => Carbon::parse($r->created_at)->utc()->toIso8601String(),
                    'hold_active' => isset($heldIds[$r->id]),
                    'native_outcome_verified' => false,
                    'physical_status' => null,
                ];
            }
        }

        if ($marketplace === 'wildberries' && Schema::hasTable('manager_wb_supply_operations')) {
            $rows = DB::table('manager_wb_supply_operations')
                ->where('workspace_id', $identity->workspaceId)
                ->where('integration_id', $integrationId)
                ->where('status', 'unknown')
                ->get();
            foreach ($rows as $r) {
                $items[] = [
                    'operation_id' => (string) $r->id,
                    'operation_type' => (string) $r->operation_type,
                    'source_table' => 'manager_wb_supply_operations',
                    'external_id' => (string) ($r->native_supply_id ?? 'wb_supply'),
                    'created_at' => Carbon::parse($r->created_at)->utc()->toIso8601String(),
                    'hold_active' => isset($heldIds[$r->id]),
                    'native_outcome_verified' => false,
                    'physical_status' => null,
                ];
            }
        }

        if ($marketplace === 'ozon' && Schema::hasTable('manager_ozon_packing_operations')) {
            $rows = DB::table('manager_ozon_packing_operations')
                ->where('workspace_id', $identity->workspaceId)
                ->where('integration_id', $integrationId)
                ->where('status', 'unknown')
                ->get();
            foreach ($rows as $r) {
                $items[] = [
                    'operation_id' => (string) $r->id,
                    'operation_type' => 'ozon_packing_batch',
                    'source_table' => 'manager_ozon_packing_operations',
                    'external_id' => 'ozon_packing_batch',
                    'created_at' => Carbon::parse($r->created_at)->utc()->toIso8601String(),
                    'hold_active' => isset($heldIds[$r->id]),
                    'native_outcome_verified' => false,
                    'physical_status' => null,
                ];
            }
        }

        usort($items, fn (array $a, array $b) => strcmp($b['created_at'], $a['created_at']));

        return array_map(function (array $item) {
            unset($item['source_table']);

            return $item;
        }, $items);
    }

    private function findUnknownTarget(ManagerIdentity $identity, int $integrationId, string $marketplace, string $operationId): ?array
    {
        $candidates = [
            ['table' => 'manager_fbs_operations', 'market' => $marketplace],
            ...($marketplace === 'ozon' ? [
                ['table' => 'manager_ozon_carriage_operations', 'market' => 'ozon'],
                ['table' => 'manager_ozon_packing_operations', 'market' => 'ozon'],
            ] : [
                ['table' => 'manager_wb_supply_operations', 'market' => 'wildberries'],
            ]),
        ];

        foreach ($candidates as $cand) {
            if (! Schema::hasTable($cand['table'])) {
                continue;
            }
            $query = DB::table($cand['table'])
                ->where('id', $operationId)
                ->where('workspace_id', $identity->workspaceId)
                ->where('integration_id', $integrationId)
                ->where('status', 'unknown');
            if ($cand['table'] === 'manager_fbs_operations') {
                $query->where('marketplace', $marketplace);
            }
            $row = $query->first();
            if ($row) {
                $external = match ($cand['table']) {
                    'manager_fbs_operations' => (string) $row->external_id,
                    'manager_ozon_carriage_operations' => (string) ($row->native_carriage_id ?? 'ozon_carriage'),
                    'manager_wb_supply_operations' => (string) ($row->native_supply_id ?? 'wb_supply'),
                    default => 'ozon_packing_batch',
                };

                return [
                    'source_table' => $cand['table'],
                    'operation_type' => $cand['table'] === 'manager_ozon_packing_operations' ? 'ozon_packing_batch' : (string) $row->operation_type,
                    'external_id' => $external,
                ];
            }
        }

        return null;
    }

    private function exposeResolution(ManagerFbsManualResolution $row): array
    {
        $holdActive = false;
        if ($row->status !== 'resolved_manually') {
            if ($row->source_table === 'manager_fbs_operations' && Schema::hasTable('manager_fbs_operations')) {
                $holdActive = DB::table('manager_fbs_operations')->where('id', $row->operation_id)->whereNotNull('resource_key')->exists();
            }
            if (! $holdActive && Schema::hasTable('manager_fbs_resource_holds')) {
                $holdActive = DB::table('manager_fbs_resource_holds')->where('operation_id', $row->operation_id)->exists();
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
            'marketplace' => $row->marketplace,
            'external_id' => $row->external_id,
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

    private function ownedResolution(ManagerIdentity $identity, string $operationId, string $resolutionId): ManagerFbsManualResolution
    {
        $row = ManagerFbsManualResolution::whereKey($resolutionId)
            ->where('operation_id', $operationId)
            ->where('workspace_id', $identity->workspaceId)
            ->where('user_id', $identity->userId)
            ->first();
        if (! $row) {
            throw new ManagerDataException('Предложение ручного разбора FBS не найдено.', 404);
        }

        return $row;
    }

    private function resolveStore(ManagerIdentity $identity, int $integrationId, string $marketplace): array
    {
        if (! in_array($marketplace, ['wildberries', 'ozon'], true)) {
            throw new ManagerDataException('FBS поддерживается для Wildberries и Ozon.', 422);
        }
        $integration = Integration::whereKey($integrationId)
            ->where('work_space_id', $identity->workspaceId)
            ->where('marketplace', $marketplace)
            ->first();
        if (! $integration) {
            throw new ManagerDataException('Магазин не найден в выбранном workspace.', 404);
        }

        return [$integration, $this->stores->resolve($identity, $integrationId, $marketplace)];
    }

    private function readPermission(ManagerIdentity $identity): void
    {
        foreach (['auto_supply.view', 'integrations.view'] as $perm) {
            if (! $identity->permits($perm)) {
                throw new ManagerDataException('Недостаточно прав для чтения операций FBS.', 403);
            }
        }
    }

    private function writePermission(ManagerIdentity $identity): void
    {
        if (! $identity->personalConfirmed) {
            throw new ManagerDataException('Ручной разбор FBS требует личной сессии сотрудника.', 403);
        }
        $this->readPermission($identity);
        if (! $identity->permits('auto_supply.create')) {
            throw new ManagerDataException('Недостаточно прав для ручного разбора операций FBS.', 403);
        }
    }

    private function validateConfirmations(array $confirmations): void
    {
        if (count($confirmations) !== count(self::CONFIRMATIONS)) {
            throw new ManagerDataException('Нужны четыре личные отметки ручного разбора FBS.', 422);
        }
        foreach (self::CONFIRMATIONS as $key) {
            if (($confirmations[$key] ?? null) !== true) {
                throw new ManagerDataException('Каждая из четырёх отметок ручного разбора FBS должна быть включена.', 422);
            }
        }
    }

    private function fingerprint(ManagerFbsManualResolution $row): string
    {
        return hash('sha256', json_encode([
            'manager_fbs_manual_resolution_v1',
            $row->id,
            (string) $row->workspace_id,
            (string) $row->user_id,
            (string) $row->integration_id,
            $row->marketplace,
            $row->store_binding,
            $row->operation_id,
            $row->operation_type,
            $row->external_id,
            $row->reason,
            $row->support_reference,
            $row->created_at->utc()->toIso8601String(),
            $row->expires_at->utc()->toIso8601String(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function integrity(ManagerFbsManualResolution $row, string $fingerprint): void
    {
        if (! hash_equals($row->fingerprint, $fingerprint) || ! hash_equals($row->fingerprint, $this->fingerprint($row))) {
            throw new ManagerDataException('Состав ручного разбора FBS изменился.', 409);
        }
    }
}
