<?php

namespace App\Services\UnitEconomics;

use App\Models\Integration;
use App\Models\Product;
use App\Models\UnitEconomicsCache;
use App\Services\Wildberries\WildberriesPriceRefreshService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Writes only confirmed external observations, including an unchanged price. */
class MarketplacePriceObservation
{
    public const PRICE_FIELDS = ['actual_price', 'old_price', 'price_source', 'price_observed_at',
        'price_changed_at', 'price_version', 'price_synced_at', 'prices_by_size',
        'marketing_seller_price', 'seller_price', 'is_in_promotion', 'promotion_discount',
        'price_sync_error_code', 'price_sync_error_at', 'price_sync_requested_version'];

    public function apply(Integration $integration, array $prices, int $version): array
    {
        $stats = ['total' => 0, 'matched' => 0, 'updated' => 0, 'unchanged' => 0, 'missing' => 0, 'stale' => 0];
        Product::where('integration_id', $integration->id)->where('marketplace', $integration->marketplace)
            ->chunkById(100, function ($products) use ($integration, $prices, $version, &$stats): void {
                foreach ($products as $candidate) {
                    $outcome = DB::transaction(function () use ($candidate, $integration, $prices, $version): string {
                        $product = Product::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                        $field = $integration->marketplace === 'wildberries' ? 'wb_data' : 'ozon_data';
                        $data = is_array($product->{$field}) ? $product->{$field} : [];
                        if ($field === 'wb_data') {
                            [$row, $source] = app(WildberriesPriceRefreshService::class)->resolvePriceData($prices, $product, $data);
                        } else {
                            $row = $prices[$product->sku] ?? null;
                            $source = $row['price_source'] ?? 'seller_price';
                        }
                        $price = (float) ($row['final_price'] ?? $row['actual_price'] ?? 0);
                        $observedAt = $row['price_observed_at'] ?? null;
                        $currentVersion = (int) ($data['price_version'] ?? 0);
                        $legacyFallback = $version === 0 && ! app(UnitEconomicsSyncCoordinator::class)->enabled((int) $integration->id);
                        if ($currentVersion > $version && ! $legacyFallback) {
                            return 'stale';
                        }
                        if ($price <= 0 || ! is_string($observedAt) || strtotime($observedAt) === false) {
                            $error = ['price_sync_error_code' => 'price_not_returned',
                                'price_sync_error_at' => now()->utc()->toIso8601String(),
                                'price_sync_requested_version' => max($currentVersion, $version)];
                            $product->forceFill([$field => array_merge($data, $error)])->save();
                            if (Schema::hasTable('unit_economics_cache')) {
                                foreach (UnitEconomicsCache::where('integration_id', $integration->id)->where('sku', $product->sku)->lockForUpdate()->get() as $cache) {
                                    $cache->forceFill(['marketplace_data' => array_merge($cache->marketplace_data ?? [], $error)])->save();
                                }
                            }

                            return 'missing';
                        }
                        if (($currentVersion > $version && ! $legacyFallback)
                            || strtotime($data['price_observed_at'] ?? '1970-01-01') > strtotime($observedAt)) {
                            return 'stale';
                        }
                        $base = (float) ($row['price'] ?? 0);
                        $old = $field === 'wb_data' ? ($base > $price ? $base : null) : ((float) ($row['old_price'] ?? 0) ?: null);
                        $changed = abs((float) $product->price - $price) > 0.009
                            || ($product->old_price === null) !== ($old === null)
                            || abs((float) $product->old_price - (float) $old) > 0.009;
                        $next = ['actual_price' => $price, 'old_price' => $old, 'seller_price' => $base,
                            'price_source' => $source, 'price_observed_at' => $observedAt,
                            'price_synced_at' => $observedAt, 'price_version' => max($currentVersion, $version),
                            'price_sync_error_code' => null, 'price_sync_error_at' => null, 'price_sync_requested_version' => max($currentVersion, $version),
                            'is_in_promotion' => $price < $base,
                            'promotion_discount' => $base > $price && $base > 0 ? round((1 - $price / $base) * 100, 1) : 0];
                        if ($changed) {
                            $next['price_changed_at'] = $observedAt;
                        }
                        if ($field === 'wb_data') {
                            $next['prices_by_size'] = $row['sizes'] ?? ($data['prices_by_size'] ?? []);
                        } else {
                            $next['marketing_seller_price'] = $row['marketing_seller_price'] ?? 0;
                            $data = app(OzonCommissionObservation::class)->merge($product, $data, $row);
                        }
                        $product->forceFill(['price' => $price, 'old_price' => $old,
                            $field => array_merge($data, $next)])->save();

                        return $changed ? 'updated' : 'unchanged';
                    });
                    $stats['total']++;
                    $stats[$outcome]++;
                    if (in_array($outcome, ['updated', 'unchanged'], true)) {
                        $stats['matched']++;
                    }
                }
            });

        return $stats;
    }

    /** A slow catalog response must preserve the independently confirmed price. */
    public function preserveConfirmedPrice(Product $product, array $incoming): array
    {
        $field = $product->marketplace === 'wildberries' ? 'wb_data' : 'ozon_data';
        $current = is_array($product->{$field}) ? $product->{$field} : [];
        if ((int) ($current['price_version'] ?? 0) <= 0 || ! app(UnitEconomicsSyncCoordinator::class)->enabled((int) $product->integration_id)) {
            return $incoming;
        }
        $incoming['price'] = $product->price;
        $incoming['old_price'] = $product->old_price;
        $incoming[$field] = array_merge($current, is_array($incoming[$field] ?? null) ? $incoming[$field] : [],
            array_intersect_key($current, array_flip(self::PRICE_FIELDS)));

        return $incoming;
    }
}
