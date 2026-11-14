<?php

declare(strict_types=1);

namespace App\Services\UnitEconomics;

use App\Domains\UnitEconomics\DTO\CalculationInput;
use App\Models\Product;

/** Coherence of a calculation, never proof of input provenance or authority. */
final class CalculationFingerprint
{
    public const VERSION = 'main_calculation_inputs_v1';

    public static function productBinding(Product $product): string
    {
        $external = $product->marketplace === 'ozon' ? ($product->ozon_data['product_id'] ?? null) : ($product->wb_data['nmID'] ?? $product->wb_data['nmId'] ?? null);
        $external = (is_int($external) || is_string($external)) && preg_match('/^[1-9][0-9]{0,18}$/D', (string) $external) ? (string) $external : null;

        return hash('sha256', json_encode([(string) $product->id, (string) $product->integration_id, $product->marketplace, $product->sku, $external], JSON_THROW_ON_ERROR));
    }

    public static function forInputs(Product $product, array $data): array
    {
        $adjustments = [];
        foreach (['drr_percent', 'tax_percent', 'vat_percent', 'our_share_percent'] as $field) {
            $adjustments[$field] = (float) ($data['_extra'][$field] ?? 0);
        }
        $recipe = self::canonical(['input' => get_object_vars(CalculationInput::fromArray($data)), 'seller_adjustments' => $adjustments]);

        return ['version' => self::VERSION, 'product_binding' => self::productBinding($product),
            'input_hash' => hash('sha256', json_encode($recipe, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION))];
    }

    public static function valid(mixed $receipt, Product $product): bool
    {
        return is_array($receipt) && count($receipt) === 3 && ($receipt['version'] ?? null) === self::VERSION
            && is_string($receipt['product_binding'] ?? null) && hash_equals(self::productBinding($product), $receipt['product_binding'])
            && is_string($receipt['input_hash'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $receipt['input_hash']) === 1;
    }

    public static function matches(mixed $left, mixed $right, Product $product): bool
    {
        return self::valid($left, $product) && self::valid($right, $product)
            && hash_equals($left['input_hash'], $right['input_hash']);
    }

    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) $value[$key] = self::canonical($item);
        }

        return $value;
    }
}
