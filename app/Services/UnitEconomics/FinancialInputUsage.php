<?php

declare(strict_types=1);

namespace App\Services\UnitEconomics;

use App\Domains\UnitEconomics\DTO\UnitEconomicsResult;

/** Describes owner-selected branches; never infers non-use from a zero amount. */
final class FinancialInputUsage
{
    public const FIELDS = [
        'cost_price' => 'RUB', 'tax_percent' => 'percent', 'vat_percent' => 'percent',
        'drr_percent' => 'percent', 'our_share_percent' => 'percent',
        'commission_rate' => 'percent', 'acquiring_percent' => 'percent', 'redemption_rate' => 'percent',
        'packaging_cost' => 'RUB', 'additional_costs' => 'RUB', 'acceptance_cost' => 'RUB',
        'storage_cost' => 'RUB', 'own_delivery_cost' => 'RUB', 'own_return_cost' => 'RUB',
        'marketplace_compensation' => 'RUB', 'penalty_cost' => 'RUB',
        'length' => 'cm', 'width' => 'cm', 'height' => 'cm', 'weight' => 'kg',
    ];

    public static function unknown(string $marketplace, string $scheme): array
    {
        return ['version' => 'price_input_usage_v1', 'source' => null, 'marketplace' => $marketplace,
            'fulfillment_type' => $scheme, 'items' => array_fill_keys(array_keys(self::FIELDS), 'unknown')];
    }

    public static function fromResult(UnitEconomicsResult $result): array
    {
        $usage = self::unknown($result->marketplace, $result->fulfillmentType);
        if (! in_array($result->marketplace, ['wildberries', 'ozon'], true)) return $usage;
        $domain = $result->metadata['financial_input_usage'] ?? null;
        if (! is_array($domain) || ($domain['version'] ?? null) !== 'domain_financial_input_usage_v1'
            || ! is_array($domain['items'] ?? null) || array_diff(array_keys($domain['items']), array_keys(self::FIELDS)) !== []
            || array_filter($domain['items'], fn ($value) => ! is_bool($value)) !== []) return $usage;
        foreach ($domain['items'] as $field => $applied) $usage['items'][$field] = $applied ? 'applied' : 'not_applied';
        // These adjustments are finalized by the main owner after the domain
        // calculation. Do not derive applicability from the domain total alone.
        foreach (['tax_percent', 'vat_percent', 'drr_percent', 'our_share_percent'] as $field) $usage['items'][$field] = 'applied';
        $usage['source'] = 'products_main_financials_v1';

        return $usage;
    }

    public static function fromPreview(mixed $raw, string $marketplace, string $scheme): array
    {
        $unknown = self::unknown($marketplace, $scheme);
        if (! is_array($raw) || count($raw) !== 5 || ($raw['version'] ?? null) !== $unknown['version']
            || ($raw['source'] ?? null) !== 'products_main_financials_v1' || ($raw['marketplace'] ?? null) !== $marketplace
            || ($raw['fulfillment_type'] ?? null) !== $scheme || ! is_array($raw['items'] ?? null)
            || count($raw['items']) !== count(self::FIELDS) || array_diff(array_keys($raw['items']), array_keys(self::FIELDS)) !== []
            || array_filter($raw['items'], fn ($value) => ! in_array($value, ['applied', 'not_applied', 'unknown'], true)) !== []) return $unknown;
        foreach (['tax_percent', 'vat_percent', 'drr_percent', 'our_share_percent'] as $field) {
            if ($raw['items'][$field] !== 'applied') return $unknown;
        }

        return $raw;
    }
}
