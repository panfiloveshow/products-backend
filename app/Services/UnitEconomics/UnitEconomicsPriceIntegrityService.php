<?php

namespace App\Services\UnitEconomics;

use App\Models\Integration;
use App\Models\Product;
use App\Models\UnitEconomics;
use App\Models\UnitEconomicsCache;

class UnitEconomicsPriceIntegrityService
{
    public function __construct(private readonly MarketplacePriceResolver $priceResolver) {}

    /**
     * @return array{
     *     integration_id:int,
     *     marketplace:string,
     *     products:int,
     *     expected_cache_rows:int,
     *     cache_rows:int,
     *     issues:array<int, array<string, mixed>>,
     *     issue_counts:array<string, int>,
     *     repairable:bool,
     *     healthy:bool
     * }
     */
    public function inspectIntegration(
        Integration $integration,
        float $tolerance = 0.01,
        int $maxAgeMinutes = 2880
    ): array {
        $products = Product::query()
            ->where('integration_id', $integration->id)
            ->where('marketplace', $integration->marketplace)
            ->get()
            ->keyBy(fn (Product $product) => (string) $product->sku);

        $unitEconomics = UnitEconomics::query()
            ->where('integration_id', $integration->id)
            ->where('marketplace', $this->normalizeMarketplace($integration->marketplace))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        $unitEconomicsByScheme = [];
        foreach ($unitEconomics as $row) {
            $key = $this->rowKey((string) $row->sku, (string) $row->fulfillment_type);
            $unitEconomicsByScheme[$key] ??= $row;
        }

        $cacheRows = UnitEconomicsCache::query()
            ->where('integration_id', $integration->id)
            ->where('marketplace', $this->normalizeMarketplace($integration->marketplace))
            ->get();
        $cacheByScheme = $cacheRows->keyBy(
            fn (UnitEconomicsCache $row) => $this->rowKey((string) $row->sku, (string) $row->fulfillment_type)
        );

        $issues = [];
        $staleSkus = [];
        $schemes = $this->schemesForMarketplace($integration->marketplace);

        foreach ($products as $product) {
            $marketplaceData = $this->marketplaceData($product);
            $commissions = is_array($marketplaceData['commissions_by_scheme'] ?? null)
                ? $marketplaceData['commissions_by_scheme']
                : (is_array($marketplaceData['commissions'] ?? null) ? $marketplaceData['commissions'] : []);
            $pricesByScheme = [];

            foreach ($schemes as $scheme) {
                $key = $this->rowKey((string) $product->sku, $scheme);
                $existingUnitEconomics = $unitEconomicsByScheme[$key] ?? null;
                $resolved = $this->priceResolver->resolveWithMetadata(
                    $product,
                    $marketplaceData,
                    $commissions,
                    $existingUnitEconomics
                );
                $pricesByScheme[$scheme] = $resolved['price'];
                $cache = $cacheByScheme->get($key);

                if ($resolved['price'] <= 0) {
                    $issues[] = $this->issue(
                        'missing_price_source',
                        $product,
                        $scheme,
                        0.0,
                        $cache?->price,
                        $resolved,
                        false
                    );
                } elseif ($cache === null) {
                    $issues[] = $this->issue(
                        'missing_cache',
                        $product,
                        $scheme,
                        $resolved['price'],
                        null,
                        $resolved,
                        true
                    );
                } elseif (abs((float) $cache->price - $resolved['price']) > $tolerance) {
                    $issues[] = $this->issue(
                        'price_drift',
                        $product,
                        $scheme,
                        $resolved['price'],
                        $cache->price,
                        $resolved,
                        true
                    );
                }

                if (
                    ! isset($staleSkus[$product->sku])
                    && $this->isStale($resolved['observed_timestamp'], $maxAgeMinutes)
                ) {
                    $staleSkus[$product->sku] = true;
                    $issues[] = $this->issue(
                        'stale_price_source',
                        $product,
                        $scheme,
                        $resolved['price'],
                        $cache?->price,
                        $resolved,
                        false
                    );
                }
            }

            $positivePrices = array_values(array_filter($pricesByScheme, fn (float $price) => $price > 0));
            if (
                count($positivePrices) > 1
                && max($positivePrices) - min($positivePrices) > $tolerance
            ) {
                $issues[] = [
                    'type' => 'scheme_price_divergence',
                    'sku' => (string) $product->sku,
                    'scheme' => '*',
                    'expected' => null,
                    'actual' => null,
                    'source' => 'unit_economics_by_scheme',
                    'observed_at' => null,
                    'repairable' => false,
                    'details' => $pricesByScheme,
                ];
            }
        }

        foreach ($cacheRows as $cache) {
            if (! $products->has((string) $cache->sku)) {
                $issues[] = [
                    'type' => 'orphan_cache',
                    'sku' => (string) $cache->sku,
                    'scheme' => (string) $cache->fulfillment_type,
                    'expected' => null,
                    'actual' => (float) $cache->price,
                    'source' => 'unit_economics_cache',
                    'observed_at' => null,
                    'repairable' => true,
                ];
            }
        }

        $issueCounts = [];
        foreach ($issues as $issue) {
            $issueCounts[$issue['type']] = ($issueCounts[$issue['type']] ?? 0) + 1;
        }

        return [
            'integration_id' => (int) $integration->id,
            'marketplace' => (string) $integration->marketplace,
            'products' => $products->count(),
            'expected_cache_rows' => $products->count() * count($schemes),
            'cache_rows' => $cacheRows->count(),
            'issues' => $issues,
            'issue_counts' => $issueCounts,
            'repairable' => collect($issues)->contains(fn (array $issue) => $issue['repairable'] === true),
            'healthy' => $issues === [],
        ];
    }

    /**
     * @param  array{price:float,source:string,observed_at:?string,observed_timestamp:?int}  $resolved
     * @return array<string, mixed>
     */
    private function issue(
        string $type,
        Product $product,
        string $scheme,
        float $expected,
        mixed $actual,
        array $resolved,
        bool $repairable
    ): array {
        return [
            'type' => $type,
            'sku' => (string) $product->sku,
            'scheme' => strtoupper($scheme),
            'expected' => round($expected, 2),
            'actual' => is_numeric($actual) ? round((float) $actual, 2) : null,
            'source' => $resolved['source'],
            'observed_at' => $resolved['observed_at'],
            'repairable' => $repairable,
        ];
    }

    private function isStale(?int $observedTimestamp, int $maxAgeMinutes): bool
    {
        if ($maxAgeMinutes <= 0) {
            return false;
        }

        return $observedTimestamp === null
            || $observedTimestamp < now()->subMinutes($maxAgeMinutes)->getTimestamp();
    }

    private function marketplaceData(Product $product): array
    {
        $data = match ($product->marketplace) {
            'wildberries' => $product->wb_data,
            'yandex', 'yandex_market' => $product->yandex_data,
            'uzum' => $product->uzum_data,
            default => $product->ozon_data,
        };

        return is_array($data) ? $data : [];
    }

    /** @return array<int, string> */
    private function schemesForMarketplace(string $marketplace): array
    {
        return match ($marketplace) {
            'ozon' => ['FBO', 'FBS', 'RFBS', 'EXPRESS'],
            'wildberries' => ['FBO', 'FBS', 'DBS', 'EDBS', 'DBW'],
            'yandex', 'yandex_market' => ['FBY', 'FBS', 'DBS', 'EXPRESS'],
            'uzum' => ['FBS', 'FBO', 'DBS'],
            default => ['FBO'],
        };
    }

    private function normalizeMarketplace(string $marketplace): string
    {
        return $marketplace === 'yandex' ? 'yandex_market' : $marketplace;
    }

    private function rowKey(string $sku, string $scheme): string
    {
        return $sku.'|'.strtoupper($scheme);
    }
}
