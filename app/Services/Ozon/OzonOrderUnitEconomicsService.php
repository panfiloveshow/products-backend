<?php

namespace App\Services\Ozon;

use App\Domains\Ozon\Tariffs\OzonPricingMatrix;
use App\Models\OzonOrderUnitEconomics;
use App\Models\OzonSkuDeliveryProfile;
use App\Models\OzonWarehouseCluster;
use App\Models\Posting;
use App\Models\PostingItem;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class OzonOrderUnitEconomicsService
{
    public function __construct(
        private readonly OzonPricingMatrix $pricing = new OzonPricingMatrix(),
        private readonly OzonSupplyFixationService $fixationService = new OzonSupplyFixationService(),
    ) {
    }

    public function syncForIntegration(int $integrationId): Collection
    {
        $rows = collect();

        Posting::query()
            ->with(['items', 'items.product'])
            ->where('integration_id', $integrationId)
            ->where('marketplace', 'ozon')
            ->chunk(100, function ($postings) use (&$rows): void {
                foreach ($postings as $posting) {
                    foreach ($posting->items as $item) {
                        $row = $this->upsertForPostingItem($posting, $item);
                        if ($row !== null) {
                            $rows->push($row);
                        }
                    }
                }
            });

        return $rows;
    }

    public function summarizeForPreview(int $integrationId): array
    {
        $rows = OzonOrderUnitEconomics::query()
            ->where('integration_id', $integrationId)
            ->whereNotNull('sku')
            ->get()
            ->groupBy('sku');

        $summary = [];

        foreach ($rows as $sku => $items) {
            $count = max(1, $items->count());
            $summary[$sku] = [
                'order_economics_summary' => [
                    'orders_count' => $items->count(),
                    'avg_base_logistics_tariff' => round((float) $items->avg('base_logistics_tariff'), 2),
                    'avg_non_local_markup_percent' => round((float) $items->avg('non_local_markup_percent'), 2),
                    'avg_non_local_markup_amount' => round((float) $items->avg('non_local_markup_amount'), 2),
                    'factual_orders_count' => $items->where('calculation_mode', 'factual')->count(),
                    'estimate_orders_count' => $items->where('calculation_mode', 'estimate')->count(),
                    'shipping_clusters' => $items->groupBy('shipping_cluster_name')->map->count()->toArray(),
                    'destination_clusters' => $items->groupBy('destination_cluster_name')->map->count()->toArray(),
                    'markup_reason_codes' => $items->pluck('markup_reason_code')->filter()->countBy()->toArray(),
                ],
            ];
        }

        return $summary;
    }

    public function upsertForPostingItem(Posting $posting, PostingItem $item): ?OzonOrderUnitEconomics
    {
        $sku = (string) ($item->sku ?: $item->offer_id ?: '');
        if ($sku === '') {
            return null;
        }

        $product = $item->product ?: Product::query()
            ->where('integration_id', $posting->integration_id)
            ->where('sku', $sku)
            ->first();

        $volumeLiters = $this->resolveVolumeLiters($item, $product);
        $orderDate = $this->resolveOrderDate($posting);
        $shippingClusterName = $this->resolveShippingClusterName($posting, $product);
        $destinationClusterName = $this->resolveDestinationClusterName($posting, $sku);
        $fixation = $orderDate ? $this->fixationService->matchForOrder($posting->integration_id, $sku, $orderDate, $shippingClusterName) : null;
        $fixationApplied = $fixation !== null;

        $effectiveShippingClusterName = $fixation?->shipping_cluster_name ?? $shippingClusterName;
        $effectiveShippingClusterRow = $effectiveShippingClusterName ? OzonWarehouseCluster::findByWarehouseName($effectiveShippingClusterName) : null;
        $effectiveShippingClusterId = $fixation?->shipping_cluster_id
            ?? ($effectiveShippingClusterRow?->cluster_id ? (string) $effectiveShippingClusterRow->cluster_id : null);

        $clusterLogistics = $this->pricing->resolveClusterLogistics(
            'FBO',
            $volumeLiters,
            (float) ($item->price ?? 0),
            $effectiveShippingClusterName,
            $destinationClusterName
        );

        [$markupApplied, $markupReasonCode, $markupReasonLabel, $markupExceptionStatus] = $this->resolveMarkupDecision(
            $posting,
            $sku,
            $effectiveShippingClusterName,
            $destinationClusterName,
            (float) ($item->price ?? 0)
        );

        $markupPercent = $markupApplied ? (float) $clusterLogistics['non_local_markup_percent'] : 0.0;
        $markupAmount = round((float) ($item->price ?? 0) * ($markupPercent / 100), 2);

        // Last mile: partners pay up to 25₽, Ozon charges exactly 25₽
        // Not charged if order was cancelled before delivery to pickup point
        $lastMileCost = 25.0;
        if ($posting->status === Posting::STATUS_CANCELLED) {
            $deliveredToPickup = $posting->delivered_at !== null || $posting->in_process_at !== null;
            if (!$deliveredToPickup) {
                $lastMileCost = 0.0;
            }
        }

        $mode = ($destinationClusterName !== null && $effectiveShippingClusterName !== null && $fixationApplied)
            ? 'factual'
            : 'estimate';
        $confidence = match (true) {
            $mode === 'factual' => 'high',
            $destinationClusterName !== null || $effectiveShippingClusterName !== null => 'medium',
            default => 'low',
        };

        return OzonOrderUnitEconomics::updateOrCreate(
            ['posting_item_id' => $item->id],
            [
                'integration_id' => $posting->integration_id,
                'posting_id' => $posting->id,
                'posting_number' => $posting->posting_number,
                'sku' => $sku,
                'offer_id' => $item->offer_id,
                'order_date' => $orderDate?->toDateTimeString(),
                'sale_price' => (float) ($item->price ?? 0),
                'volume_liters' => $volumeLiters,
                'price_bucket' => (float) ($item->price ?? 0) <= 300 ? '<=300' : '>300',
                'shipping_cluster_id' => $effectiveShippingClusterId,
                'shipping_cluster_name' => $effectiveShippingClusterName,
                'destination_cluster_id' => $this->resolveClusterIdByName($destinationClusterName),
                'destination_cluster_name' => $destinationClusterName,
                'fixation_applied' => $fixationApplied,
                'fixation_id' => $fixation?->id,
                'fixation_base_date' => optional($fixation?->fixation_base_date)?->toDateString(),
                'fixed_until' => optional($fixation?->fixed_until)?->toDateString(),
                'tariff_version_used' => $fixation?->tariff_version ?? $this->pricing->getVersionForDate($orderDate),
                'markup_version_used' => $fixation?->markup_version ?? $this->pricing->getVersionForDate($orderDate),
                'base_logistics_tariff' => (float) ($clusterLogistics['base_cost'] ?? 0),
                'non_local_markup_percent' => $markupPercent,
                'non_local_markup_amount' => $markupAmount,
                'markup_applied' => $markupApplied,
                'markup_reason_code' => $markupReasonCode,
                'markup_reason_label' => $markupReasonLabel,
                'markup_exception_code' => in_array($markupReasonCode, ['unavailable_ozon_reroute', 'unavailable_cluster_blocked', 'unavailable_select_only'], true) ? $markupReasonCode : null,
                'markup_exception_label' => in_array($markupReasonCode, ['unavailable_ozon_reroute', 'unavailable_cluster_blocked', 'unavailable_select_only'], true) ? $markupReasonLabel : null,
                'markup_exception_status' => $markupExceptionStatus,
                'calculation_mode' => $mode,
                'calculation_confidence' => $confidence,
                'meta' => [
                    'posting_status' => $posting->status,
                    'posting_substatus' => $posting->substatus,
                    'financial_data' => $posting->financial_data,
                    'analytics_data' => $posting->analytics_data,
                    'used_universal_tariff' => $clusterLogistics['used_universal_tariff'] ?? false,
                    'tariff_source' => $clusterLogistics['tariff_source'] ?? null,
                    'last_mile_cost' => $lastMileCost,
                    'last_mile_waived' => $lastMileCost === 0.0,
                ],
            ]
        );
    }

    private function resolveOrderDate(Posting $posting): ?Carbon
    {
        foreach ([$posting->delivered_at, $posting->shipped_at, $posting->shipment_date, $posting->created_at] as $candidate) {
            if ($candidate !== null) {
                return Carbon::parse($candidate);
            }
        }

        return null;
    }

    private function resolveVolumeLiters(PostingItem $item, ?Product $product): float
    {
        $itemVolume = (float) ($item->volume ?? 0);
        if ($itemVolume > 0) {
            return $itemVolume;
        }

        $lengthMm = (float) ($product?->ozon_data['length_mm'] ?? $product?->depth ?? 0);
        $widthMm = (float) ($product?->ozon_data['width_mm'] ?? $product?->width ?? 0);
        $heightMm = (float) ($product?->ozon_data['height_mm'] ?? $product?->height ?? 0);

        return $lengthMm > 0 && $widthMm > 0 && $heightMm > 0
            ? round(($lengthMm * $widthMm * $heightMm) / 1000000, 4)
            : 0.0;
    }

    private function resolveShippingClusterName(Posting $posting, ?Product $product): ?string
    {
        $warehouseName = $posting->warehouse_name
            ?? ($posting->analytics_data['warehouse_name'] ?? null)
            ?? ($product?->ozon_data['active_fixation']['shipping_cluster_name'] ?? null);

        if ($warehouseName === null) {
            return null;
        }

        $cluster = OzonWarehouseCluster::findByWarehouseName((string) $warehouseName);

        return $cluster?->cluster_name ?? (string) $warehouseName;
    }

    private function resolveDestinationClusterName(Posting $posting, string $sku): ?string
    {
        $analytics = is_array($posting->analytics_data ?? null) ? $posting->analytics_data : [];
        foreach (['delivery_cluster', 'cluster_name', 'region', 'delivery_region', 'delivery_cluster_name'] as $key) {
            $value = $analytics[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $this->pricing->resolveClusterName($value);
            }
        }

        $profile = OzonSkuDeliveryProfile::findForProduct($posting->integration_id, $sku, 'ALL');
        $clusterProfile = is_array($profile?->cluster_profile ?? null) ? $profile->cluster_profile : [];
        $dominant = $clusterProfile['clusters_summary'][0]['cluster_name'] ?? null;

        return $dominant ? $this->pricing->resolveClusterName((string) $dominant) : null;
    }

    private function resolveClusterIdByName(?string $clusterName): ?string
    {
        if ($clusterName === null) {
            return null;
        }

        $row = \App\Models\OzonWarehouseCluster::query()
            ->where('cluster_name', $clusterName)
            ->first();

        return $row?->cluster_id ? (string) $row->cluster_id : null;
    }

    private function resolveMarkupDecision(
        Posting $posting,
        string $sku,
        ?string $shippingClusterName,
        ?string $destinationClusterName,
        float $price
    ): array {
        if ($destinationClusterName !== null && $shippingClusterName !== null && $destinationClusterName === $shippingClusterName) {
            return [false, 'local_cluster', 'Надбавка не применяется: продажа локальная', 'confirmed'];
        }

        if ($posting->status === Posting::STATUS_CANCELLED || $posting->cancelled_at !== null) {
            return [false, 'cancelled_order', 'Надбавка не применяется: заказ отменён', 'confirmed'];
        }

        if (in_array($posting->status, [Posting::STATUS_NOT_ACCEPTED], true)) {
            return [false, 'not_redeemed', 'Надбавка не применяется: заказ не выкуплен', 'confirmed'];
        }

        // Seller-level total FBO sales in 7 days (Ozon rule applies per-seller, not per-SKU)
        $sellerFboSales7Days = (int) \App\Models\InventoryWarehouse::where('integration_id', $posting->integration_id)
            ->where('marketplace', 'ozon')
            ->where('fulfillment_type', 'FBO')
            ->sum('sales_7_days');

        if ($sellerFboSales7Days < 50) {
            return [false, 'fbo_lt_50_orders_7d', 'Надбавка не применяется: за 7 дней по FBO меньше 50 заказов', 'confirmed'];
        }

        $markupPercent = $this->pricing->resolveDestinationMarkupPercent($destinationClusterName);
        if ($markupPercent <= 0) {
            return [false, 'zero_markup_cluster', 'Надбавка не применяется: для кластера назначения ставка 0%', 'confirmed'];
        }

        // NOT IMPLEMENTED: Ozon shipped from non-local cluster when local stock was available
        // (requires Ozon's internal routing decision data, not available via API)

        // NOT IMPLEMENTED: Product cannot be placed in buyer's cluster warehouse
        // (requires warehouse restriction data, not available via API)

        // NOT IMPLEMENTED: Product sold only on Select platform
        // (requires Select platform detection, not available via current integration)

        return [true, 'non_local_markup_applied', 'Надбавка применяется по кластеру назначения', 'confirmed'];
    }
}
