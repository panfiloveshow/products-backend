<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\Product;
use App\Models\UnitEconomicsSettings;
use App\Services\UnitEconomics\OzonCommissionObservation;
use App\Services\UnitEconomics\FinancialInputUsage;
use Carbon\CarbonImmutable;

/** Describes the inputs actually used by a forecast; never grants execution. */
final class ManagerPriceFinancialEvidence
{
    private const FIELDS = FinancialInputUsage::FIELDS;

    public function describe(Product $product, string $scheme, array $preview, ?UnitEconomicsSettings $settings, ?string $credentialBinding = null): array
    {
        $inputs = is_array($preview['inputs'] ?? null) ? $preview['inputs'] : [];
        $result = is_array($preview['after'] ?? null) ? $preview['after'] : [];
        $checkedAt = CarbonImmutable::instance(now()->utc());
        $inputIntegration = $inputs['integration_id'] ?? null;
        $bound = ($inputs['sku'] ?? null) === (string) $product->sku
            && (is_string($inputIntegration) || is_int($inputIntegration)) && (string) $inputIntegration === (string) $product->integration_id
            && ($inputs['marketplace'] ?? null) === $product->marketplace
            && ($inputs['fulfillment_type'] ?? null) === $scheme
            && ($result['sku'] ?? null) === (string) $product->sku
            && ($result['marketplace'] ?? null) === $product->marketplace
            && ($result['fulfillment_type'] ?? null) === $scheme
            && $this->number($inputs['price'] ?? null) !== null
            && $this->number($inputs['price']) === $this->number($result['price'] ?? null);
        $settingsBound = $settings !== null && (string) $settings->integration_id === (string) $product->integration_id
            && (string) $settings->sku === (string) $product->sku;
        $items = [];
        foreach (self::FIELDS as $field => $unit) {
            $value = $bound ? $this->number($inputs[$field] ?? null) : null;
            if ($unit === 'percent' && ($value === null || $value < 0 || $value > 100)) {
                $value = null;
            }
            $status = $value === null ? 'missing' : 'unverified';
            $source = null;
            if ($value !== null && $settingsBound && in_array($field, ['cost_price', 'tax_percent', 'vat_percent', 'drr_percent', 'our_share_percent', 'acceptance_cost'], true)
                && $this->number($settings->$field) === $value) {
                // getOrCreate writes defaults too. Equality is useful context,
                // but not evidence that a human supplied or confirmed a value.
                $status = 'configured_unconfirmed';
                $source = 'unit_economics_settings';
            }
            $items[$field] = ['value' => $value, 'unit' => $unit, 'status' => $status, 'source' => $source, 'observed_at' => null];
        }
        if ($bound && $product->marketplace === 'ozon') {
            $items['commission_rate'] = $this->ozonCommission($product, $scheme, $inputs, $result, $items['commission_rate'], $checkedAt);
        }
        if ($bound) {
            foreach (app(ManagerFinancialSettingsProof::class)->forInputs($product, $settings, $inputs, $credentialBinding, $checkedAt) as $field => $item) {
                if (isset($items[$field]) && $items[$field]['value'] === $item['value']) $items[$field] = $item;
            }
        }
        $unverified = array_keys(array_filter($items, fn (array $item): bool => ! in_array($item['status'], ['observed', 'user_supplied'], true)));
        $usage = $bound ? FinancialInputUsage::fromPreview($result['financial_input_usage'] ?? null, $product->marketplace, $scheme)
            : FinancialInputUsage::unknown($product->marketplace, $scheme);
        $unverifiedApplied = array_values(array_filter($unverified, fn ($field) => $usage['items'][$field] !== 'not_applied'));

        return ['version' => 'price_financial_evidence_v1', 'scope' => 'selected_scheme_forecast',
            'integration_id' => (string) $product->integration_id, 'product_id' => (string) $product->id,
            'marketplace' => $product->marketplace, 'fulfillment_type' => $scheme,
            'seller_price' => $this->price($result['price'] ?? null),
            'checked_at' => $checkedAt->format('Y-m-d\TH:i:s.uP'), 'status' => $bound ? 'incomplete' : 'unavailable',
            'items' => $items, 'unverified_inputs' => $unverified,
            'input_usage' => $usage, 'unverified_applied_inputs' => $unverifiedApplied,
            'reasons' => $bound ? ['financial_input_provenance_incomplete'] : ['financial_inputs_not_bound_to_forecast'],
            'execution_allowed' => false];
    }

    private function ozonCommission(Product $product, string $scheme, array $inputs, array $result, array $item, CarbonImmutable $checkedAt): array
    {
        if ($item['value'] === null) {
            return $item;
        }
        $source = $result['commission_source'] ?? null;
        if (in_array($source, ['ozon_category_table', 'repo_fallback', 'scheme_default'], true)
            || ($source === null && ($inputs['commission_rate_source'] ?? null) === 'model_default')) {
            // When a category tariff overrides an unverified input, report the
            // selected base rate, not the discarded input as an applied fact.
            $selected = $this->number($result['commission_rate_base'] ?? null);
            if ($selected !== null && $selected > 100) $selected = null;

            return [...$item, 'value' => $selected, 'status' => $selected === null ? 'missing' : 'model_assumption', 'source' => 'financial_model'];
        }
        $observation = app(OzonCommissionObservation::class)->forScheme($product, $scheme);
        if ($source !== 'ozon_prices_v5' || ($inputs['commission_rate_source'] ?? null) !== 'ozon_prices_v5'
            || ($result['commission_input_source'] ?? null) !== 'ozon_prices_v5' || $observation === null
            || $item['value'] !== $this->number($observation['percent'])
            || ($inputs['commission_observed_at'] ?? null) !== $observation['observed_at']
            || ($result['commission_observed_at'] ?? null) !== $observation['observed_at']) {
            return $item;
        }
        $at = CarbonImmutable::parse($observation['observed_at']);

        // The JSON consumer uses millisecond timestamps. Compare at that same
        // precision so a boundary observation cannot change status in transit.
        return [...$item, 'status' => (int) $at->format('Uv') < (int) $checkedAt->subHours(72)->format('Uv') ? 'stale' : 'observed',
            'source' => 'ozon_prices_v5', 'observed_at' => $observation['observed_at']];
    }

    private function number(mixed $value): ?float
    {
        if ((! is_int($value) && ! is_float($value) && ! is_string($value)) || ! is_numeric($value)) {
            return null;
        }
        $number = (float) $value;

        return is_finite($number) && $number >= 0 && $number <= 1_000_000_000 ? $number : null;
    }

    private function price(mixed $value): ?string
    {
        if ($this->number($value) === null) {
            return null;
        }
        try {
            return DecimalMoney::format(DecimalMoney::minor($value));
        } catch (ManagerDataException) {
            return null;
        }
    }
}
