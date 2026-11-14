<?php

namespace App\Services\UnitEconomics;

use App\Models\UnitEconomicsCache;

/** Independent arithmetic checks. Missing formula inputs are explicitly unknown. */
class UnitEconomicsConsistencyInspector
{
    public function inspect(UnitEconomicsCache $row, float $tolerance = 0.02): array
    {
        $effective = (float) $row->logistics_cost + (float) $row->last_mile_cost
            + (float) $row->processing_cost + (float) $row->expected_return_cost;
        $expectedReturn = $this->expectedReturn($row);

        return ['effective_expected' => $effective,
            'effective_drift' => abs((float) $row->effective_logistics - $effective),
            'return_expected' => $expectedReturn,
            'return_checked' => $expectedReturn !== null,
            'return_drift' => $expectedReturn === null ? null : abs((float) $row->expected_return_cost - $expectedReturn),
            'has_drift' => abs((float) $row->effective_logistics - $effective) > $tolerance
                || ($expectedReturn !== null && abs((float) $row->expected_return_cost - $expectedReturn) > $tolerance)];
    }

    private function expectedReturn(UnitEconomicsCache $row): ?float
    {
        $audit = $row->marketplace_data['return_audit'] ?? null;
        if (! is_array($audit) || ($audit['model'] ?? null) !== 'per_sold_unit_v1' || $row->redemption_rate === null) {
            return null;
        }
        $lost = max(0.0, (100 - (float) $row->redemption_rate) / 100);
        if ($row->marketplace === 'wildberries') {
            $base = (float) $row->logistics_cost + (float) $row->return_logistics_cost;
        } elseif ($row->marketplace === 'ozon') {
            $ownDelivery = in_array(strtoupper($row->fulfillment_type), ['RFBS', 'EXPRESS'], true);
            $orders = $audit['orders_count'] ?? null;
            $returns = $audit['returns_count'] ?? null;
            $cancelled = max(0, (int) ($audit['cancelled_count'] ?? 0));
            if (! $ownDelivery && $row->redemption_source !== 'no_sales_28d'
                && $orders !== null && ($orders <= 0 || $cancelled >= $orders)) {
                $lost = 0.0;
            }
            $lost += $orders !== null && $orders > 0 && $returns !== null && $returns > 0 ? min(1.0, $returns / $orders) : 0.0;
            $base = $ownDelivery
                ? (float) $row->logistics_cost + (float) $row->return_logistics_cost
                : 2 * (float) $row->return_logistics_cost + (float) $row->return_processing_cost;
        } else {
            return null;
        }
        // Counts are per sold unit. Keep this independent of ReturnEconomics and
        // of both calculators so a shared implementation bug is detectable.
        $sold = 1 - $lost;
        $factor = $sold <= 0 ? 3.0 : min(3.0, $lost / $sold);

        return round($base * $factor, 2);
    }
}
