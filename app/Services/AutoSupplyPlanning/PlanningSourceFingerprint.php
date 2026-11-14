<?php

declare(strict_types=1);

namespace App\Services\AutoSupplyPlanning;

use App\Models\Integration;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Local input versions. A row update time never becomes proof of Native provenance. */
final class PlanningSourceFingerprint
{
    public const VERSION = 'planning-local-inputs-1';

    public const IN_TRANSIT_STATUSES = ['draft_ozon', 'slot_pending', 'slot_booked', 'preparing', 'ready_to_ship', 'shipped', 'in_transit', 'at_warehouse'];

    public function capture(Integration $integration, ?string $planId = null): array
    {
        $id = (int) $integration->id;
        if ($id < 1 || ! in_array($integration->marketplace, ['ozon', 'wildberries', 'yandex', 'yandex_market'], true)) {
            throw new \RuntimeException('Planning input scope is not confirmed');
        }
        $marketplace = (string) $integration->marketplace;
        if ($planId !== null && ! DB::table('auto_supply_plans')->where('id', $planId)->where('integration_id', $id)->exists()) {
            throw new \RuntimeException('Planning source plan scope is not confirmed');
        }
        $specs = [
            'products' => ['products', ['id', 'integration_id', 'marketplace', 'sku', 'marketplace_id', 'barcode', 'name', 'price', 'cost_price', 'ozon_data', 'wb_data', 'updated_at'], 'updated_at'],
            'inventory' => ['inventory_warehouses', ['id', 'integration_id', 'marketplace', 'sku', 'warehouse_id', 'warehouse_name', 'warehouse_coefficient', 'fulfillment_type', 'quantity', 'reserved', 'in_transit', 'average_daily_sales', 'effective_daily_sales', 'sales_7_days', 'sales_14_days', 'sales_30_days', 'days_in_stock_30', 'real_avg_daily_sales', 'real_sales_period_days', 'turnover_days', 'storage_cost_per_day', 'storage_cost_per_month', 'storage_fee_total', 'paid_storage_fee', 'last_updated'], 'last_updated'],
            'postings' => ['postings', ['id', 'integration_id', 'marketplace', 'posting_number', 'status', 'delivery_type', 'warehouse_id', 'in_process_at', 'delivered_at', 'cancelled_at', 'items_count', 'total_quantity', 'synced_at', 'updated_at'], 'synced_at'],
            'economics' => ['unit_economics', ['id', 'integration_id', 'marketplace', 'sku', 'price', 'cost_price', 'purchase_price', 'commission_percent', 'logistics_cost', 'storage_cost', 'net_profit', 'margin_percent', 'roi_percent', 'marketplace_data', 'updated_at'], 'updated_at'],
            'constraints' => ['marketplace_constraint_snapshots', ['id', 'integration_id', 'marketplace', 'sync_status', 'synced_at', 'summary_json', 'payload_json', 'warehouses_json', 'cluster_constraints_json', 'updated_at'], 'synced_at'],
            'supplies' => ['supplies', ['id', 'integration_id', 'auto_supply_plan_id', 'supply_type', 'supply_method', 'cluster_id', 'warehouse_id', 'status', 'ozon_draft_id', 'ozon_order_id', 'planned_date', 'actual_date', 'updated_at'], 'updated_at'],
            'seller_stock' => ['seller_warehouse_stocks', ['id', 'integration_id', 'sku', 'barcode', 'quantity', 'reserved', 'cost_price', 'last_counted_at', 'updated_at'], 'last_counted_at'],
            'settings' => ['supply_settings', null, 'updated_at'],
            'barcode_costs' => ['wb_barcode_costs', null, 'updated_at'],
        ];
        $sources = [];
        if ($marketplace !== 'wildberries') {
            unset($specs['barcode_costs']);
        }
        foreach ($specs as $name => [$table, $fields, $time]) {
            $columns = Schema::getColumnListing($table);
            if (! in_array('integration_id', $columns, true) || ! in_array('id', $columns, true)) {
                throw new \RuntimeException('Planning source schema is not confirmed: '.$table);
            }
            $query = DB::table($table)->where('integration_id', $id);
            if ($name === 'supplies') {
                $query->whereIn('status', self::IN_TRANSIT_STATUSES);
                if ($planId !== null) {
                    $query->where(fn ($q) => $q->whereNull('auto_supply_plan_id')->orWhere('auto_supply_plan_id', '!=', $planId));
                }
            }
            if (in_array('marketplace', $columns, true)) {
                $query->where('marketplace', $marketplace);
            }
            $fields = $name === 'postings' ? array_values(array_intersect($fields, $columns)) : $columns;
            $sources[$name] = $this->observe($query->select($fields)->orderBy('id'), $name, $time);
        }
        // Child rows are always joined through the same seller scope, never through a global SKU.
        foreach (['posting_items' => ['postings', 'posting_id'], 'supply_items' => ['supplies', 'supply_id']] as $table => [$parent, $foreign]) {
            $query = DB::table($table.' as child')->join($parent.' as parent', 'parent.id', '=', 'child.'.$foreign)
                ->where('parent.integration_id', $id)->select('child.*')->orderBy('child.id');
            if ($parent === 'postings') {
                $query->where('parent.marketplace', $marketplace);
            } else {
                $query->whereIn('parent.status', self::IN_TRANSIT_STATUSES);
                if ($planId !== null) {
                    $query->where(fn ($q) => $q->whereNull('parent.auto_supply_plan_id')->orWhere('parent.auto_supply_plan_id', '!=', $planId));
                }
            }
            $sources[$table] = $this->observe($query, $table, 'updated_at');
        }
        $digest = hash('sha256', json_encode([self::VERSION, $id, $marketplace, $planId,
            array_map(fn ($source) => ['hash' => $source['hash'], 'rows' => $source['rows']], $sources)], JSON_THROW_ON_ERROR));

        return ['version' => self::VERSION, 'integration_id' => (string) $id, 'marketplace' => $marketplace, 'hash' => $digest,
            'scope' => 'local_planning_input_rows', 'scope_plan_id' => $planId, 'native_origin_verified' => false, 'sources' => $sources];
    }

    public function observe(Builder $query, string $source, string $dateColumn): array
    {
        $digest = hash_init('sha256');
        hash_update($digest, self::VERSION.':'.$source."\n");
        $rows = 0;
        $missing = 0;
        $invalid = 0;
        $future = 0;
        $stale = 0;
        $oldest = null;
        $newest = null;
        $examples = [];
        $missingExamples = [];
        $sla = max(1, (int) config('autoplanning.freshness_sla_hours.'.$source, 24));
        $at = CarbonImmutable::now();
        foreach ($query->cursor() as $row) {
            $data = (array) $row;
            ksort($data, SORT_STRING);
            $rows++;
            hash_update($digest, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n");
            $raw = $data[$dateColumn] ?? null;
            $date = self::timestamp($raw);
            if ($raw === null || $raw === '') {
                $missing++;
                $this->example($missingExamples, $data['sku'] ?? null);

                continue;
            }
            if ($date === null) {
                $invalid++;
                $this->example($missingExamples, $data['sku'] ?? null);

                continue;
            }
            if ($date->gt($at->addMinutes(5))) {
                $future++;
                $this->example($missingExamples, $data['sku'] ?? null);

                continue;
            }
            if ($oldest === null || $date->lt($oldest)) {
                $oldest = $date;
            }
            if ($newest === null || $date->gt($newest)) {
                $newest = $date;
            }
            if ($date->lt($at->subHours($sla))) {
                $stale++;
                $this->example($examples, $data['sku'] ?? null);
            }
        }

        return ['hash' => hash_final($digest), 'rows' => $rows, 'missing_row_dates' => $missing, 'invalid_row_dates' => $invalid,
            'future_row_dates' => $future, 'stale_rows' => $stale, 'stale_sku_examples' => array_values($examples), 'missing_sku_examples' => array_values($missingExamples),
            'oldest_row_at' => $oldest?->utc()->toIso8601String(), 'newest_row_at' => $newest?->utc()->toIso8601String(), 'date_basis' => $dateColumn,
            'timestamp_basis' => in_array($dateColumn, ['last_updated', 'synced_at', 'last_counted_at'], true) ? 'reported_observation_time' : 'local_record_update',
            'native_origin_verified' => false];
    }

    public static function timestamp(mixed $raw): ?CarbonImmutable
    {
        if ($raw instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($raw);
        }
        if (! is_string($raw) || ! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})?$/D', $raw)) {
            return null;
        }
        try {
            $value = new \DateTimeImmutable($raw, new \DateTimeZone((string) config('app.timezone', 'UTC')));
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) {
                return null;
            }

            return CarbonImmutable::instance($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function example(array &$values, mixed $sku): void
    {
        if (count($values) >= 10 || ! is_string($sku) || $sku === '' || mb_strlen($sku) > 200) {
            return;
        }
        $values['sku:'.$sku] = $sku;
    }
}
