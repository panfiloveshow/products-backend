<?php

namespace App\Services\AutoSupplyPlanning;

use App\Services\AutoSupplyPlanService;

/**
 * Собирает неизменяемую матрицу фактов расчёта и безопасный shadow-отчёт.
 *
 * В payload не попадают токены, заголовки API или сырые ответы маркетплейса.
 */
class OzonPlanningFactsBuilder
{
    /**
     * @param list<array<string, mixed>> $lines
     * @return array{
     *   demand:list<array<string, mixed>>,
     *   stock:list<array<string, mixed>>,
     *   supply:list<array<string, mixed>>,
     *   economics:list<array<string, mixed>>
     * }
     */
    public function buildLineFacts(array $lines): array
    {
        $facts = ['demand' => [], 'stock' => [], 'supply' => [], 'economics' => []];

        foreach ($lines as $line) {
            $explain = $this->decodeExplain($line['explain_json'] ?? null);
            $inputs = is_array($explain['inputs'] ?? null) ? $explain['inputs'] : [];
            $math = is_array($explain['math'] ?? null) ? $explain['math'] : [];
            $confidence = is_array($explain['confidence'] ?? null) ? $explain['confidence'] : [];
            $identity = [
                'sku' => (string) ($line['sku'] ?? ''),
                'offer_id' => $line['offer_id'] ?? null,
                'cluster_id' => isset($line['cluster_id']) ? (string) $line['cluster_id'] : null,
                'warehouse_id' => isset($line['warehouse_id']) ? (string) $line['warehouse_id'] : null,
                'source_hash' => $line['source_hash'] ?? null,
            ];

            $facts['demand'][] = $identity + [
                'sales_7_days' => (int) ($line['sales_7_days'] ?? 0),
                'sales_14_days' => (int) ($line['sales_14_days'] ?? 0),
                'sales_30_days' => (int) ($line['sales_30_days'] ?? 0),
                'daily_demand' => round((float) ($line['demand_daily'] ?? 0), 4),
                'demand_source' => $inputs['demand_source'] ?? null,
                'analysis_period_days' => $inputs['analysis_period_days'] ?? null,
                'target_cover_days' => $inputs['target_cover_days'] ?? null,
                'lead_time_days' => $inputs['lead_time_days'] ?? null,
                'safety_stock' => $math['safety_stock'] ?? null,
                'confidence_level' => $confidence['confidence_level'] ?? null,
                'confidence_reasons' => $confidence['confidence_reasons'] ?? [],
            ];
            $facts['stock'][] = $identity + [
                'current_stock' => (int) ($line['current_stock'] ?? 0),
                'in_transit' => (int) ($line['in_transit'] ?? 0),
                'own_stock' => isset($line['own_stock']) ? (int) $line['own_stock'] : null,
                'own_stock_reserved' => isset($line['own_stock_reserved'])
                    ? (int) $line['own_stock_reserved']
                    : null,
                'seller_stock_deficit' => isset($line['deficit']) ? (int) $line['deficit'] : null,
                'stock_scope' => $inputs['stock_scope'] ?? null,
                'stock_source' => $confidence['sources']['stock'] ?? null,
            ];
            $facts['supply'][] = $identity + [
                'qty_recommended' => round((float) ($line['qty_recommended'] ?? 0), 2),
                'qty_rounded' => (int) ($line['qty_rounded'] ?? 0),
                'original_qty_rounded' => isset($line['original_qty_rounded'])
                    ? (int) $line['original_qty_rounded']
                    : null,
                'is_excluded' => (bool) ($line['is_excluded'] ?? false),
                'pack_multiple' => max(1, (int) ($inputs['pack_multiple'] ?? 1)),
                'needed_before_caps' => $math['needed_before_caps'] ?? null,
                'needed_after_caps' => $math['needed_after_caps'] ?? null,
                'caps_applied' => $math['caps_applied'] ?? [],
                'planning_reason' => $explain['reason'] ?? null,
                'not_recommended_reason' => $explain['not_recommended_reason']
                    ?? $explain['optimizer_rejection']['reason']
                    ?? null,
            ];
            $facts['economics'][] = $identity + [
                'price' => isset($line['price']) ? round((float) $line['price'], 2) : null,
                'cost_price' => isset($line['cost_price']) ? round((float) $line['cost_price'], 2) : null,
                'supply_cost_estimate' => isset($line['supply_cost_estimate'])
                    ? round((float) $line['supply_cost_estimate'], 2)
                    : null,
                'expected_revenue' => isset($line['expected_revenue'])
                    ? round((float) $line['expected_revenue'], 2)
                    : null,
                'expected_profit' => isset($line['expected_profit'])
                    ? round((float) $line['expected_profit'], 2)
                    : null,
                'roi_percent' => isset($line['roi_percent']) ? round((float) $line['roi_percent'], 2) : null,
                'economics_source' => $confidence['sources']['economics'] ?? null,
            ];
        }

        return $facts;
    }

    /**
     * Сравнивает v2 с реально используемой старой EWMA-функцией на тех же фактах.
     * Результат никогда не влияет на итоговое количество плана.
     *
     * @param list<array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    public function buildLegacyV2ShadowReport(array $lines, AutoSupplyPlanService $service): array
    {
        $rows = [];
        $legacyQtyTotal = 0;
        $v2QtyTotal = 0;
        $demandAbsDelta = 0.0;
        $comparableDemandRows = 0;
        $qtyDirection = ['legacy_higher' => 0, 'v2_higher' => 0, 'same' => 0];

        foreach ($lines as $line) {
            $explain = $this->decodeExplain($line['explain_json'] ?? null);
            $inputs = is_array($explain['inputs'] ?? null) ? $explain['inputs'] : [];
            $legacy = $service->calculateDailyDemand(
                0.35,
                (float) ($line['sales_7_days'] ?? 0),
                (float) ($line['sales_14_days'] ?? 0),
                (float) ($line['sales_30_days'] ?? 0)
            );
            $legacyDaily = (float) $legacy['daily_demand'];
            $v2Daily = (float) ($line['demand_daily'] ?? 0);
            $targetDays = max(1, (int) ($inputs['target_cover_days'] ?? 21));
            $pack = max(1, (int) ($inputs['pack_multiple'] ?? 1));
            $legacyNeed = $service->calculateNeededBeforeCaps(
                $legacyDaily,
                $targetDays,
                (int) ($line['current_stock'] ?? 0),
                (int) ($line['in_transit'] ?? 0)
            );
            $legacyQty = $service->roundToPackMultiple(
                (float) ($legacyNeed['needed_before_caps'] ?? 0),
                $pack
            );
            $v2Qty = empty($line['is_excluded']) ? (int) ($line['qty_rounded'] ?? 0) : 0;
            $direction = $legacyQty > $v2Qty
                ? 'legacy_higher'
                : ($v2Qty > $legacyQty ? 'v2_higher' : 'same');
            $qtyDirection[$direction]++;
            $legacyQtyTotal += $legacyQty;
            $v2QtyTotal += $v2Qty;
            if ($legacyDaily > 0 || $v2Daily > 0) {
                $demandAbsDelta += abs($v2Daily - $legacyDaily);
                $comparableDemandRows++;
            }
            $rows[] = [
                'sku' => (string) ($line['sku'] ?? ''),
                'cluster_id' => isset($line['cluster_id']) ? (string) $line['cluster_id'] : null,
                'legacy_daily_demand' => round($legacyDaily, 4),
                'v2_daily_demand' => round($v2Daily, 4),
                'legacy_qty' => $legacyQty,
                'v2_qty' => $v2Qty,
                'qty_delta' => $v2Qty - $legacyQty,
                'direction' => $direction,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => abs($b['qty_delta']) <=> abs($a['qty_delta']));

        return [
            'version' => 'legacy-ewma-vs-v2-shadow-1',
            'affects_plan' => false,
            'legacy_formula' => 'EWMA alpha=0.35 по окнам 7/30 без v2 safety/optimizer',
            'lines_compared' => count($rows),
            'legacy_qty_total' => $legacyQtyTotal,
            'v2_qty_total' => $v2QtyTotal,
            'qty_total_delta' => $v2QtyTotal - $legacyQtyTotal,
            'qty_direction' => $qtyDirection,
            'mean_abs_daily_demand_delta' => $comparableDemandRows > 0
                ? round($demandAbsDelta / $comparableDemandRows, 4)
                : null,
            'largest_differences' => array_slice($rows, 0, 100),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeExplain(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
