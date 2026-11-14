<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Http\Controllers\Api\AutoSupplyPlanController;
use App\Models\Integration;
use App\Models\InventoryWarehouse;
use App\Models\SupplySettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Local selection context; neither an availability check nor a new calculation. */
final class ManagerSupplyPlanningContext
{
    public function __construct(private ManagerStoreResolver $stores, private AutoSupplyPlanController $plans) {}

    public function read(ManagerIdentity $identity, Request $request, int $integrationId, int $page, int $limit): array
    {
        $integration = Integration::whereKey($integrationId)->where('work_space_id', $identity->workspaceId)->first();
        if (! $integration || ! in_array($integration->marketplace, ['wildberries', 'ozon'], true)) throw new ManagerDataException('Магазин не найден.', 404);
        $store = $this->stores->resolve($identity, $integrationId, $integration->marketplace);
        // Preserve the existing section's mapping, without exposing its cost/sales aggregates.
        $response = $this->plans->warehouses($request->duplicate(['integration_id' => $integrationId]));
        if ($response->getStatusCode() !== 200) throw new ManagerDataException('Направления расчёта недоступны.', $response->getStatusCode());
        $rows = $response->getData(true)['data'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 10000) throw new ManagerDataException('Источник не подтвердил список направлений.');
        $destinations = []; $unmapped = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) throw new ManagerDataException('Источник не подтвердил направление.');
            $id = (string) ($row['warehouse_id'] ?? '');
            if (! preg_match($store->marketplace === 'ozon' ? '/^cluster:[1-9][0-9]{0,8}$/D' : '/^[1-9][0-9]{0,17}$/D', $id)) { $unmapped++; continue; }
            $name = $this->text($row['warehouse_name'] ?? null);
            if (isset($destinations[$id])) {
                if ($destinations[$id]['name'] !== $name) { $destinations[$id]['name'] = null; $destinations[$id]['name_conflict'] = true; }
                continue;
            }
            $destinations[$id] = ['id' => $id, 'name' => $name, 'name_conflict' => false,
                'type' => $store->marketplace === 'ozon' ? 'cluster' : 'warehouse', 'marketplace_available' => null];
        }
        ksort($destinations, SORT_STRING);
        $total = count($destinations); $offset = ($page - 1) * $limit;
        $items = array_slice(array_values($destinations), $offset, $limit);
        $more = $offset + count($items) < $total;

        // Do not call SupplySettings::getOrCreate: reading must not choose defaults for the seller.
        $settings = SupplySettings::where('integration_id', $integrationId)->first();
        $window = $settings?->default_sales_window;
        $inventory = InventoryWarehouse::where('integration_id', $integrationId)->where('marketplace', $store->marketplace);
        $dates = (clone $inventory)->selectRaw('COUNT(*) as total_rows, MIN(last_updated) as oldest_row_at, MAX(last_updated) as newest_row_at, COUNT(last_updated) as dated_rows')->first();

        return ['schema_version' => 1, 'workspace_id' => (string) $identity->workspaceId, 'integration_id' => (string) $integrationId,
            'integration_name' => $store->name, 'marketplace' => $store->marketplace, 'scope' => 'observed_supply_destinations',
            'read_at' => now()->utc()->toIso8601String(), 'data_updated_at' => null, 'source_freshness_verified' => false,
            'partial' => true, 'execution_allowed' => false, 'items' => $items, 'page' => $page, 'limit' => $limit,
            'total' => $total, 'has_more' => $more, 'next_page' => $more ? $page + 1 : null, 'unmapped_warehouse_count' => $unmapped,
            'settings' => ['present' => $settings !== null, 'active' => $settings?->is_active,
                'last_changed_at' => $this->date($settings?->updated_at),
                'default_sales_window' => is_string($window) && preg_match('/^[0-9]{1,3}d$/D', $window) ? $window : null,
                'target_days_a' => $this->days($settings?->target_days_a, 365), 'target_days_b' => $this->days($settings?->target_days_b, 365),
                'target_days_c' => $this->days($settings?->target_days_c, 365), 'safety_stock_days' => $this->days($settings?->safety_stock_days, 30),
                'lead_time_days' => $this->days($settings?->default_lead_time_days, 30)],
            'inventory_observation' => ['date_basis' => 'inventory_warehouses.last_updated', 'rows' => (int) $dates->total_rows,
                'missing_row_dates' => (int) $dates->total_rows - (int) $dates->dated_rows,
                'oldest_row_at' => $this->date($dates->oldest_row_at), 'newest_row_at' => $this->date($dates->newest_row_at)]];
    }

    private function days(mixed $value, int $max): ?int
    {
        return is_int($value) && $value >= 0 && $value <= $max ? $value : null;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && mb_strlen($value) <= 500 ? $value : null;
    }

    private function date(mixed $value): ?string
    {
        if ($value === null) return null;
        try { $date = CarbonImmutable::parse($value)->utc(); return $date->gt(now()->addMinutes(5)) ? null : $date->toIso8601String(); }
        catch (\Throwable) { return null; }
    }
}
