<?php

namespace App\Domains\Ozon\Tariffs;

final class OzonRfbsTariffMatrix
{
    private array $defaults;

    public function __construct(?array $defaults = null)
    {
        $this->defaults = $defaults ?? (array) config('ozon_rfbs_tariffs', []);
    }

    public function configuration(?array $stored = null): array
    {
        $stored = is_array($stored) ? $stored : [];
        $priceBands = array_values((array) ($this->defaults['price_bands'] ?? []));
        $weightBands = array_values((array) ($this->defaults['weight_bands'] ?? []));
        $defaultRates = (array) ($this->defaults['rates'] ?? []);
        $storedRates = $stored['rates'] ?? null;
        $hasCustomRates = $this->hasExpectedShape($storedRates, count($priceBands), count($weightBands));

        return [
            'enabled' => array_key_exists('enabled', $stored)
                ? (bool) $stored['enabled']
                : (bool) ($this->defaults['enabled'] ?? true),
            'version' => (string) ($this->defaults['version'] ?? 'unknown'),
            'price_bands' => $priceBands,
            'weight_bands' => $weightBands,
            'rates' => $this->normalizeRates($hasCustomRates ? $storedRates : $defaultRates),
            'source' => $hasCustomRates ? 'custom' : 'default',
            'updated_at' => $stored['updated_at'] ?? null,
        ];
    }

    public function resolve(
        float $orderPrice,
        float $actualWeightKg,
        float $volumetricWeightKg,
        ?array $stored = null
    ): array {
        $configuration = $this->configuration($stored);
        $chargeableWeight = max(0.0, $actualWeightKg, $volumetricWeightKg);
        $priceIndex = $this->resolveBandIndex($orderPrice, $configuration['price_bands']);
        $weightIndex = $this->resolveBandIndex($chargeableWeight, $configuration['weight_bands']);
        $cost = $configuration['enabled']
            ? (float) ($configuration['rates'][$priceIndex][$weightIndex] ?? 0.0)
            : 0.0;

        return [
            'enabled' => $configuration['enabled'],
            'cost' => round($cost, 2),
            'source' => 'rfbs_'.$configuration['source'],
            'version' => $configuration['version'],
            'price_band_index' => $priceIndex,
            'price_band_label' => (string) ($configuration['price_bands'][$priceIndex]['label'] ?? ''),
            'weight_band_index' => $weightIndex,
            'weight_band_label' => (string) ($configuration['weight_bands'][$weightIndex]['label'] ?? ''),
            'actual_weight_kg' => round(max(0.0, $actualWeightKg), 4),
            'volumetric_weight_kg' => round(max(0.0, $volumetricWeightKg), 4),
            'chargeable_weight_kg' => round($chargeableWeight, 4),
        ];
    }

    private function resolveBandIndex(float $value, array $bands): int
    {
        foreach ($bands as $index => $band) {
            $max = $band['max'] ?? null;
            if ($max === null || $value <= (float) $max) {
                return (int) $index;
            }
        }

        return max(0, count($bands) - 1);
    }

    private function hasExpectedShape(mixed $rates, int $rows, int $columns): bool
    {
        if (! is_array($rates) || count($rates) !== $rows) {
            return false;
        }

        foreach ($rates as $row) {
            if (! is_array($row) || count($row) !== $columns) {
                return false;
            }
        }

        return true;
    }

    private function normalizeRates(array $rates): array
    {
        return array_map(
            static fn (array $row): array => array_map(
                static fn (mixed $value): float => round(max(0.0, (float) $value), 2),
                array_values($row)
            ),
            array_values($rates)
        );
    }
}
