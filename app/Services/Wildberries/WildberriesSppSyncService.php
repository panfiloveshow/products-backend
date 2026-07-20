<?php

namespace App\Services\Wildberries;

use App\Domains\Wildberries\UnitEconomics\WildberriesSppResolver;
use App\Models\Integration;
use App\Models\Product;
use App\Models\UnitEconomics;
use App\Models\UnitEconomicsCache;
use App\Models\UnitEconomicsSettings;
use App\Models\WildberriesSppSnapshot;
use Illuminate\Support\Facades\DB;

class WildberriesSppSyncService
{
    public function __construct(
        private readonly WildberriesSppDataProvider $provider,
    ) {}

    /**
     * Synchronize only SPP-derived fields. No commissions, logistics, sales or
     * other unit-economics inputs are recalculated here.
     *
     * @return array{updated:int,total:int,preserved:int,source:string,source_counts:array<string,int>,report_available:bool}
     */
    public function sync(Integration $integration, int $attempt = 1): array
    {
        $products = Product::query()
            ->where('integration_id', $integration->id)
            ->where('marketplace', 'wildberries')
            ->get();

        $data = $this->provider->fetch($integration, $products, $attempt);
        $snapshots = WildberriesSppSnapshot::query()
            ->where('integration_id', $integration->id)
            ->get()
            ->keyBy('sku');
        $unitEconomics = UnitEconomics::query()
            ->where('integration_id', $integration->id)
            ->where('marketplace', 'wildberries')
            ->get()
            ->groupBy('sku');
        $cacheRows = UnitEconomicsCache::query()
            ->where('integration_id', $integration->id)
            ->where('marketplace', 'wildberries')
            ->get()
            ->groupBy('sku');

        $updated = 0;
        $preserved = 0;
        $sources = [];
        $freshSettings = [];
        $observedAt = now();

        DB::transaction(function () use (
            $products,
            $data,
            $snapshots,
            $unitEconomics,
            $cacheRows,
            $integration,
            $observedAt,
            &$updated,
            &$preserved,
            &$sources,
            &$freshSettings,
        ): void {
            foreach ($products as $product) {
                $wbData = is_array($product->wb_data) ? $product->wb_data : [];
                $nmId = $this->nmId($wbData);
                $snapshot = $snapshots->get($product->sku);
                $existingUe = $unitEconomics->get($product->sku)?->first();
                $existingSpp = $snapshot !== null
                    ? (float) $snapshot->spp_percent
                    : ($existingUe !== null ? (float) $existingUe->spp_percent : null);
                $embeddedSpp = isset($wbData['spp_percent'])
                    ? (float) $wbData['spp_percent']
                    : null;

                $hasExactReport = array_key_exists((string) $product->sku, $data['spp_by_sku']);
                $reportSpp = $hasExactReport
                    ? (float) $data['spp_by_sku'][(string) $product->sku]
                    : ($nmId !== null && array_key_exists($nmId, $data['spp_by_nm_id'])
                        ? (float) $data['spp_by_nm_id'][$nmId]
                        : null);
                $cardSpp = $nmId !== null && array_key_exists($nmId, $data['card_spp_by_nm_id'])
                    ? (float) $data['card_spp_by_nm_id'][$nmId]
                    : null;

                $resolved = WildberriesSppResolver::resolveWithSource(
                    $reportSpp,
                    $cardSpp,
                    $existingSpp,
                    $embeddedSpp,
                    $data['report_source'] ?? 'report',
                    $hasExactReport,
                );
                $spp = $resolved['value'];
                $source = $resolved['source'];
                $fresh = $resolved['fresh'];
                if (! $fresh && $snapshot !== null) {
                    $source = (string) $snapshot->source;
                }

                $price = max(0, (float) ($product->price ?? 0));
                $customerPrice = round($price * (1 - $spp / 100), 2);

                $wbData['spp_percent'] = $spp;
                $wbData['customer_price'] = $customerPrice;
                $wbData['spp_source'] = $source;
                $wbData['spp_stale'] = ! $fresh;
                if ($fresh) {
                    $wbData['spp_synced_at'] = $observedAt->toIso8601String();
                }
                $product->forceFill(['wb_data' => $wbData])->saveQuietly();

                foreach ($unitEconomics->get($product->sku, collect()) as $row) {
                    $rowPrice = (float) $row->price;
                    $rowMarketplaceData = is_array($row->marketplace_data) ? $row->marketplace_data : [];
                    $rowMarketplaceData['spp_percent'] = $spp;
                    $rowMarketplaceData['customer_price'] = round($rowPrice * (1 - $spp / 100), 2);
                    $rowMarketplaceData['spp_source'] = $source;
                    $rowMarketplaceData['spp_stale'] = ! $fresh;
                    if ($fresh) {
                        $rowMarketplaceData['spp_synced_at'] = $observedAt->toIso8601String();
                    }
                    $row->forceFill([
                        'spp_percent' => $spp,
                        'spp_amount' => round($rowPrice * $spp / 100, 2),
                        'customer_price' => $rowMarketplaceData['customer_price'],
                        'marketplace_data' => $rowMarketplaceData,
                    ])->saveQuietly();
                }

                // Targeted read-model projection: keep the cached listing in sync
                // without invoking the expensive full unit-economics rebuild.
                foreach ($cacheRows->get($product->sku, collect()) as $cacheRow) {
                    $cachePrice = (float) $cacheRow->price;
                    $cacheData = is_array($cacheRow->marketplace_data) ? $cacheRow->marketplace_data : [];
                    $cacheData['spp_percent'] = $spp;
                    $cacheData['customer_price'] = round($cachePrice * (1 - $spp / 100), 2);
                    $cacheData['spp_source'] = $source;
                    $cacheData['spp_stale'] = ! $fresh;
                    if ($fresh) {
                        $cacheData['spp_synced_at'] = $observedAt->toIso8601String();
                    }
                    $cacheRow->forceFill(['marketplace_data' => $cacheData])->saveQuietly();
                }

                if ($fresh) {
                    // Кнопка «Обновить СПП» является явным запросом получить данные WB.
                    // Сохраняем результат и в settings, потому что listing/full rebuild
                    // исторически читают это поле первым. Иначе старые 0/ручные значения
                    // перекрывают уже успешно полученный СПП из заказов.
                    $freshSettings[] = [
                        'integration_id' => $integration->id,
                        'sku' => (string) $product->sku,
                        'spp_percent' => $spp,
                        'created_at' => $observedAt,
                        'updated_at' => $observedAt,
                    ];

                    WildberriesSppSnapshot::query()->updateOrCreate(
                        ['integration_id' => $integration->id, 'sku' => $product->sku],
                        [
                            'nm_id' => $nmId,
                            'spp_percent' => $spp,
                            'seller_price' => $price > 0 ? $price : null,
                            'customer_price' => $price > 0 ? $customerPrice : null,
                            'source' => $source,
                            'observed_at' => $observedAt,
                        ],
                    );
                    $updated++;
                } else {
                    $preserved++;
                }

                $sources[$source] = ($sources[$source] ?? 0) + 1;
            }

            if ($freshSettings !== []) {
                UnitEconomicsSettings::query()->upsert(
                    $freshSettings,
                    ['integration_id', 'sku'],
                    ['spp_percent', 'updated_at'],
                );
            }
        });

        return [
            'updated' => $updated,
            'total' => $products->count(),
            'preserved' => $preserved,
            'source' => count($sources) > 1 ? 'mixed' : (array_key_first($sources) ?? 'none'),
            'source_counts' => $sources,
            'report_available' => (bool) $data['report_available'],
        ];
    }

    private function nmId(array $wbData): ?string
    {
        $value = $wbData['nmID'] ?? $wbData['nmId'] ?? null;

        return $value !== null && $value !== '' ? (string) $value : null;
    }
}
