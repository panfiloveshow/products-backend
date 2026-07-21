<?php

namespace App\Services\UnitEconomics;

use App\Models\Product;
use App\Models\UnitEconomics;

/**
 * Единственная точка выбора действующей цены для юнит-экономики.
 *
 * Синхронизация Product и UnitEconomics идёт разными путями, поэтому для Ozon
 * сравниваем время наблюдения цены и предпочитаем более свежий источник. Для
 * остальных маркетплейсов сохраняем исторический приоритет данных Product.
 */
class MarketplacePriceResolver
{
    /**
     * @return array{price: float, source: string, observed_at: ?string, observed_timestamp: ?int}
     */
    public function resolveWithMetadata(
        Product $product,
        array $marketplaceData,
        array $commissions,
        ?UnitEconomics $existingUnitEconomics
    ): array {
        $unitEconomicsData = is_array($existingUnitEconomics?->marketplace_data ?? null)
            ? $existingUnitEconomics->marketplace_data
            : [];

        $productObservedAt = $this->observationTimestamp($marketplaceData);
        $unitEconomicsObservedAt = $this->observationTimestamp($unitEconomicsData);
        $productUpdatedAt = $product->updated_at?->toIso8601String();
        $unitEconomicsUpdatedAt = $existingUnitEconomics?->updated_at?->toIso8601String();

        $productCandidates = [
            $this->candidate('product_actual_price', $marketplaceData['actual_price'] ?? null, $marketplaceData, $productUpdatedAt),
            $this->candidate('product_marketing_seller_price', $marketplaceData['marketing_seller_price'] ?? null, $marketplaceData, $productUpdatedAt),
            $this->candidate('product_commission_actual_price', $commissions['actual_price'] ?? null, $marketplaceData, $productUpdatedAt),
        ];
        $unitEconomicsCandidates = [
            $this->candidate('unit_economics_actual_price', $unitEconomicsData['actual_price'] ?? null, $unitEconomicsData, $unitEconomicsUpdatedAt),
            $this->candidate('unit_economics_marketing_seller_price', $unitEconomicsData['marketing_seller_price'] ?? null, $unitEconomicsData, $unitEconomicsUpdatedAt),
            $this->candidate(
                'unit_economics_price',
                $existingUnitEconomics?->price,
                $unitEconomicsData,
                $unitEconomicsUpdatedAt
            ),
        ];
        $productFallback = $this->candidate(
            'product_price',
            $product->price,
            $marketplaceData,
            $productUpdatedAt
        );

        if ($product->marketplace === 'ozon') {
            $productPriceIsNewer = $productObservedAt !== null
                && ($unitEconomicsObservedAt === null || $productObservedAt > $unitEconomicsObservedAt);

            $candidates = [
                ...($productPriceIsNewer ? $productCandidates : $unitEconomicsCandidates),
                ...($productPriceIsNewer ? $unitEconomicsCandidates : $productCandidates),
                $productFallback,
            ];
        } else {
            $candidates = [
                ...$productCandidates,
                $productFallback,
                ...$unitEconomicsCandidates,
            ];
        }

        $resolved = $this->firstPositive($candidates);

        // Сохраняем действующее правило калькулятора: витринная/акционная цена
        // из текущего Product-синка является нижней фактической ценой продажи.
        $marketingPrice = $marketplaceData['marketing_seller_price'] ?? null;
        if (
            is_numeric($marketingPrice)
            && (float) $marketingPrice > 0
            && (float) $marketingPrice < $resolved['price']
        ) {
            return $this->candidate(
                'product_marketing_seller_price',
                $marketingPrice,
                $marketplaceData,
                $productUpdatedAt
            );
        }

        return $resolved;
    }

    public function resolve(
        Product $product,
        array $marketplaceData,
        array $commissions,
        ?UnitEconomics $existingUnitEconomics
    ): float {
        return $this->resolveWithMetadata(
            $product,
            $marketplaceData,
            $commissions,
            $existingUnitEconomics
        )['price'];
    }

    public function observationTimestamp(array $marketplaceData): ?int
    {
        $observedAt = $marketplaceData['price_observed_at'] ?? null;
        if (! is_string($observedAt) || trim($observedAt) === '') {
            return null;
        }

        $timestamp = strtotime($observedAt);

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * @param  array<int, array{price: float, source: string, observed_at: ?string, observed_timestamp: ?int}>  $candidates
     * @return array{price: float, source: string, observed_at: ?string, observed_timestamp: ?int}
     */
    private function firstPositive(array $candidates): array
    {
        foreach ($candidates as $candidate) {
            if ($candidate['price'] > 0) {
                return $candidate;
            }
        }

        return [
            'price' => 0.0,
            'source' => 'none',
            'observed_at' => null,
            'observed_timestamp' => null,
        ];
    }

    /**
     * @return array{price: float, source: string, observed_at: ?string, observed_timestamp: ?int}
     */
    private function candidate(
        string $source,
        mixed $price,
        array $marketplaceData,
        ?string $fallbackObservedAt = null
    ): array {
        $observedAt = $marketplaceData['price_observed_at'] ?? $fallbackObservedAt;
        $observedAt = is_string($observedAt) && trim($observedAt) !== '' ? $observedAt : null;

        return [
            'price' => is_numeric($price) ? (float) $price : 0.0,
            'source' => $source,
            'observed_at' => $observedAt,
            'observed_timestamp' => $observedAt !== null
                ? $this->observationTimestamp(['price_observed_at' => $observedAt])
                : null,
        ];
    }
}
