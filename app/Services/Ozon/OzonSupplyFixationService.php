<?php

namespace App\Services\Ozon;

use App\Domains\Ozon\Tariffs\OzonPricingMatrix;
use App\Models\OzonSupplyFixation;
use App\Models\Product;
use App\Models\Supply;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class OzonSupplyFixationService
{
    public function __construct(
        private readonly OzonPricingMatrix $pricing = new OzonPricingMatrix()
    ) {
    }

    public function syncForIntegration(int $integrationId): Collection
    {
        $supplies = Supply::query()
            ->with('items')
            ->where('integration_id', $integrationId)
            ->where('supply_type', Supply::TYPE_FBO)
            ->where('status', '!=', Supply::STATUS_CANCELLED)
            ->get();

        $rows = collect();

        foreach ($supplies as $supply) {
            $createdAt = $supply->created_in_ozon_at ?? $supply->created_at;
            if ($createdAt === null) {
                continue;
            }

            // Use the tariff version that was active when the supply was created
            $supplyCreated = Carbon::parse($createdAt);
            $tariffVersion = $this->pricing->getVersionForDate($supplyCreated->toDateString());
            $announcementDate = Carbon::parse($this->pricing->getAnnouncementDateForVersion($tariffVersion));
            $fixationBaseDate = $announcementDate->lte($supplyCreated) ? $announcementDate->copy() : $supplyCreated->copy();
            $fixedUntil = $fixationBaseDate->copy()->addDays(60);
            $isActive = $fixedUntil->isFuture() || $fixedUntil->isToday();

            foreach ($supply->items as $item) {
                if (blank($item->sku)) {
                    continue;
                }

                $rows->push(OzonSupplyFixation::updateOrCreate(
                    [
                        'integration_id' => $integrationId,
                        'supply_id' => $supply->id,
                        'sku' => (string) $item->sku,
                    ],
                    [
                        'offer_id' => $item->sku,
                        'shipping_cluster_id' => $supply->cluster_id ? (string) $supply->cluster_id : null,
                        'shipping_cluster_name' => $supply->cluster_name,
                        'fixation_base_date' => $fixationBaseDate->toDateString(),
                        'fixed_until' => $fixedUntil->toDateString(),
                        'tariff_version' => $tariffVersion,
                        'markup_version' => $tariffVersion,
                        'announcement_effective_from' => $announcementDate->toDateString(),
                        'source' => $announcementDate->lte($supplyCreated) ? 'announcement' : 'supply_created',
                        'is_active' => $isActive,
                        'meta' => [
                            'supply_status' => $supply->status,
                            'warehouse_id' => $supply->warehouse_id,
                            'warehouse_name' => $supply->warehouse_name,
                            'created_in_ozon_at' => optional($supply->created_in_ozon_at)?->toIso8601String(),
                        ],
                    ]
                ));
            }
        }

        return $rows;
    }

    public function getPreviewFixationMap(int $integrationId, array $stockProfiles = []): array
    {
        $rows = OzonSupplyFixation::query()
            ->where('integration_id', $integrationId)
            ->activeWindow()
            ->orderBy('fixation_base_date')
            ->get()
            ->groupBy('sku');

        $map = [];

        foreach ($rows as $sku => $items) {
            $selected = $this->selectPreviewFixation($items, $stockProfiles[$sku] ?? []);
            if ($selected === null) {
                continue;
            }

            $map[$sku] = $this->toPreviewArray($selected, $items->count() > 1);
        }

        return $map;
    }

    public function matchForOrder(
        int $integrationId,
        string $sku,
        Carbon $orderDate,
        ?string $shippingClusterName = null
    ): ?OzonSupplyFixation {
        $items = OzonSupplyFixation::query()
            ->where('integration_id', $integrationId)
            ->where('sku', $sku)
            ->whereDate('fixation_base_date', '<=', $orderDate->toDateString())
            ->whereDate('fixed_until', '>=', $orderDate->toDateString())
            ->get();

        if ($items->isEmpty()) {
            return null;
        }

        if ($shippingClusterName !== null) {
            $matched = $items->first(function (OzonSupplyFixation $fixation) use ($shippingClusterName): bool {
                return mb_strtolower((string) $fixation->shipping_cluster_name) === mb_strtolower($shippingClusterName);
            });

            if ($matched) {
                return $matched;
            }

            return null;
        }

        return $items->count() === 1 ? $items->first() : null;
    }

    public function appendPreviewFixationToProduct(Product $product, ?array $previewFixation): void
    {
        if ($previewFixation === null) {
            return;
        }

        $current = is_array($product->ozon_data ?? null) ? $product->ozon_data : [];
        $current['active_fixation'] = $previewFixation;
        $product->forceFill(['ozon_data' => $current])->saveQuietly();
        $product->setAttribute('ozon_data', $current);
    }

    private function selectPreviewFixation(Collection $items, array $stockProfile): ?OzonSupplyFixation
    {
        if ($items->isEmpty()) {
            return null;
        }

        $dominantStockCluster = (string) ($stockProfile['dominant_cluster_id'] ?? '');
        if ($dominantStockCluster !== '') {
            $matched = $items->first(fn (OzonSupplyFixation $fixation): bool => (string) $fixation->shipping_cluster_id === $dominantStockCluster);
            if ($matched) {
                return $matched;
            }
        }

        return $items->sortByDesc('fixation_base_date')->first();
    }

    private function toPreviewArray(OzonSupplyFixation $fixation, bool $mixed): array
    {
        return [
            'fixation_applied' => true,
            'fixation_id' => $fixation->id,
            'fixation_base_date' => optional($fixation->fixation_base_date)?->toDateString(),
            'fixed_until' => optional($fixation->fixed_until)?->toDateString(),
            'tariff_version_used' => $fixation->tariff_version,
            'markup_version_used' => $fixation->markup_version,
            'shipping_cluster_id' => $fixation->shipping_cluster_id,
            'shipping_cluster_name' => $fixation->shipping_cluster_name,
            'calculation_mode' => $mixed ? 'estimate' : 'preview',
            'calculation_confidence' => $mixed ? 'medium' : 'high',
            'fixation_source' => $fixation->source,
        ];
    }
}
