<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\AutoSupplyPlan;
use App\Models\Integration;
use App\Services\AutoSupplyPlanning\DataFreshnessRegistry;
use App\Services\AutoSupplyPlanning\PlanningSnapshotEvidence;

/** An exact review package for a person, never permission to send a WB FBW shipment. */
final class ManagerSupplyHandoffContext
{
    public function __construct(private ManagerStoreResolver $stores, private DataFreshnessRegistry $freshness) {}

    public function read(ManagerIdentity $who, int $integrationId, string $planId): array
    {
        foreach (['auto_supply.view', 'integrations.view'] as $permission) {
            if (! $who->permits($permission)) {
                throw new ManagerDataException('Недостаточно прав на план и магазин.', 403);
            }
        }
        $integration = Integration::whereKey($integrationId)->where('work_space_id', $who->workspaceId)->where('marketplace', 'wildberries')->first();
        if (! $integration) {
            throw new ManagerDataException('Магазин WB не найден.', 404);
        }
        $store = $this->stores->resolve($who, $integrationId, 'wildberries');
        $plan = AutoSupplyPlan::whereKey($planId)->where('integration_id', $integrationId)->where('marketplace', 'wildberries')->first();
        if (! $plan) {
            throw new ManagerDataException('План не найден в выбранном магазине.', 404);
        }
        $health = $this->freshness->inspect($integration, $planId);
        $errors = PlanningSnapshotEvidence::errors($plan, $health);
        if ($plan->status !== AutoSupplyPlan::STATUS_READY) {
            $errors[] = ['code' => 'plan_not_ready', 'message' => 'Расчёт ещё не завершён.'];
        }
        if (($health['can_approve'] ?? false) !== true) {
            $errors[] = ['code' => 'local_inputs_not_ready', 'message' => 'Локальные исходные данные требуют обновления.'];
        }
        $rows = $plan->lines()->where('qty_rounded', '>', 0)->where('is_excluded', false)->orderBy('id')->limit(501)->get();
        if ($rows->count() > 500) {
            throw new ManagerDataException('В одной передаче поддержано до 500 строк. Подготовьте отдельный план для части направлений.', 422);
        }
        if ($rows->isEmpty()) {
            $errors[] = ['code' => 'empty_composition', 'message' => 'Нет положительных не исключённых строк плана.'];
        }
        $items = [];
        $units = 0;
        $missingBarcodes = 0;
        foreach ($rows as $row) {
            $qty = $row->qty_rounded;
            if (! is_int($qty) || $qty < 1 || $qty > 1000000000 || $units + $qty > 1000000000) {
                throw new ManagerDataException('Количество в плане не подтверждено.');
            }
            $barcode = $this->text($row->barcode, false);
            if ($barcode === null) {
                $missingBarcodes++;
            }
            $items[] = ['row_id' => (string) $row->id, 'sku' => $this->text($row->sku, true), 'offer_id' => $this->text($row->offer_id, false),
                'barcode' => $barcode, 'product_name' => $this->text($row->product_name, false), 'quantity' => $qty,
                'warehouse_id' => $this->text($row->warehouse_id, true), 'warehouse_name' => $this->text($row->warehouse_name, false),
                'barcode_source' => $barcode === null ? null : 'stored_plan_line', 'native_barcode_verified' => false];
            $units += $qty;
        }
        $snapshot = $plan->snapshot;
        $package = ['schema_version' => 1, 'workspace_id' => (string) $who->workspaceId, 'integration_id' => (string) $integrationId,
            'integration_name' => $store->name, 'marketplace' => 'wildberries', 'scope' => 'wb_fbw_personal_handoff', 'plan_id' => $planId,
            'plan_version' => hash('sha256', json_encode([$plan->getAttributes(), $rows->map(fn ($r) => $r->getAttributes())->all()], JSON_THROW_ON_ERROR)),
            'source_version' => $health['hash'], 'snapshot_version' => $snapshot ? PlanningSnapshotEvidence::hash($snapshot) : null,
            'can_prepare_task' => $errors === [], 'errors' => array_map(fn ($e) => ['code' => $e['code'], 'message' => $e['message']], $errors),
            'items' => $items, 'lines_count' => count($items), 'total_units' => $units, 'missing_barcode_count' => $missingBarcodes,
            'composition_complete' => true, 'native_barcode_verified' => false, 'acceptance_verified' => false, 'financial_inputs_complete' => false,
            'execution_allowed' => false, 'review_path' => '/products/shipments/auto-plans/'.$planId,
            'seller_portal_url' => 'https://seller.wildberries.ru/supplies-management/all-supplies',
            'required_manual_steps' => ['verify_barcodes_and_sizes', 'verify_physical_stock', 'check_destination_acceptance_date_and_fees', 'review_financial_inputs', 'create_and_book_in_seller_portal', 'record_native_supply_id_and_result']];
        $current = $this->freshness->inspect($integration, $planId);
        $freshStore = $this->stores->resolve($who, $integrationId, 'wildberries');
        $freshPlan = $plan->fresh();
        if (! hash_equals($health['hash'], $current['hash']) || $freshStore->binding !== $store->binding || $freshStore->name !== $store->name
            || $freshPlan === null || $freshPlan->getAttributes() !== $plan->getAttributes()
            || $plan->lines()->where('qty_rounded', '>', 0)->where('is_excluded', false)->orderBy('id')->get()->map(fn ($r) => $r->getAttributes())->all() !== $rows->map(fn ($r) => $r->getAttributes())->all()) {
            throw new ManagerDataException('План, его факты или магазин изменились во время чтения. Повторите подготовку.', 409);
        }
        $package['context_fingerprint'] = hash('sha256', json_encode([$package, $store->binding], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return [...$package, 'read_at' => now()->utc()->toIso8601String(), 'data_updated_at' => null];
    }

    private function text(mixed $value, bool $required): ?string
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw new ManagerDataException('В плане есть строка без идентификатора товара или склада.');
            }

            return null;
        }
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 200) {
            throw new ManagerDataException('Значение в плане не подтверждено.');
        }

        return $value;
    }
}
