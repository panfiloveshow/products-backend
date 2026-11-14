<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\Product;
use App\Models\UnitEconomicsCache;
use App\Models\UnitEconomicsSettings;
use App\Services\UnitEconomics\CalculationFingerprint;

/** Main money plus conservative source diagnostics from the existing reader. */
final class ManagerPriceCalculationSnapshot
{
    public function read(Product $product, string $scheme, ?array $legacy): array
    {
        $rows = UnitEconomicsCache::query()->where('integration_id', $product->integration_id)->where('product_id', $product->id)
            ->where('sku', $product->sku)->where('marketplace', $product->marketplace)->where('fulfillment_type', $scheme)->get();
        $row = $rows->count() === 1 ? $rows->first() : null;
        $source = $product->marketplace === 'wildberries' ? $product->wb_data : $product->ozon_data;
        $version = $source['price_version'] ?? null;
        $receipt = $row?->marketplace_data['calculation_identity'] ?? null;
        if (! $row || $row->data_version !== UnitEconomicsCache::CURRENT_DATA_VERSION
            || ! $row->calculated_at || $row->calculated_at->lt(now()->subHours(72)) || $row->calculated_at->gt(now()->addMinutes(5))
            || ! is_int($version) || $version <= 0 || (int) $row->source_version !== $version
            || ! CalculationFingerprint::valid($receipt, $product) || ($row->marketplace_data['calculation_error_code'] ?? null) !== null) {
            return ['economics' => null, 'reasons' => ['missing_current_calculation_snapshot'], 'identity' => null];
        }
        $money = [];
        foreach (['price', 'cost_price', 'total_costs', 'net_profit', 'margin_percent', 'to_settlement_account',
            'commission_percent', 'commission_amount', 'effective_logistics', 'acquiring_percent', 'acquiring_amount',
            'redemption_rate', 'drr_percent', 'drr_amount', 'tax_percent', 'tax_amount', 'vat_percent', 'vat_amount',
            'our_share_percent', 'our_share_amount'] as $field) {
            if ($row->$field === null || ! is_numeric($row->$field) || ! is_finite((float) $row->$field)) {
                return ['economics' => null, 'reasons' => ['invalid_calculation_snapshot'], 'identity' => null];
            }
            $money[$field] = (float) $row->$field;
        }
        $settings = UnitEconomicsSettings::query()->where('integration_id', $product->integration_id)->where('sku', $product->sku)->first();
        if ($money['price'] <= 0 || $money['cost_price'] < 0 || $money['total_costs'] < 0
            || ($money['cost_price'] === 0.0 && (! $settings?->hasExplicitCostPrice() || (float) $settings->cost_price !== 0.0))
            || abs($money['price'] - $money['total_costs'] - $money['net_profit']) > 0.021) {
            return ['economics' => null, 'reasons' => ['invalid_calculation_snapshot'], 'identity' => null];
        }
        // These describe obsolete legacy money. Keep all source/input reasons,
        // including unknown future reasons, until their own owner verifies them.
        $obsolete = ['stale_calculation', 'unprofitable', 'missing_price', 'missing_cost_price', 'missing_profit', 'missing_max_allowed_drr', 'cost_price_not_recalculated'];
        $reasons = $legacy === null ? ['missing_economics'] : array_values(array_diff($legacy['readiness_reasons'] ?? [], $obsolete));
        $economics = [...$money, 'source' => 'unit_economics_cache', 'calculated_at' => $row->calculated_at->toRfc3339String(),
            'legacy_input_sources' => $legacy['sources'] ?? [], 'legacy_input_observed_at' => $legacy['source_observed_at'] ?? [],
            'readiness_reasons' => $reasons, 'ready' => false, 'financial_inputs_verified' => false, 'execution_allowed' => false];

        return ['economics' => $economics, 'reasons' => $reasons, 'identity' => $receipt];
    }
}
