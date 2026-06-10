<?php

namespace App\Services;

use App\Domains\UnitEconomics\DTO\CalculationInput;
use App\Domains\UnitEconomics\UnitEconomicsOrchestrator;
use App\Models\OzonSupplyFixation;
use App\Models\OzonSkuDeliveryProfile;
use App\Models\Product;
use App\Models\UnitEconomics;
use Illuminate\Support\Facades\Cache;

class UnitEconomicsService
{
    private const WB_SMALL_VOLUME_TARIFFS = [
        ['max_volume' => 0.2, 'rate' => 23.0],
        ['max_volume' => 0.4, 'rate' => 26.0],
        ['max_volume' => 0.6, 'rate' => 29.0],
        ['max_volume' => 0.8, 'rate' => 30.0],
        ['max_volume' => 1.0, 'rate' => 32.0],
    ];

    private UnitEconomicsOrchestrator $orchestrator;

    public function __construct(?UnitEconomicsOrchestrator $orchestrator = null)
    {
        $this->orchestrator = $orchestrator ?? new UnitEconomicsOrchestrator();
    }

    public function calculate(string $marketplace, array $data): array
    {
        if ($marketplace === 'yandex') {
            $marketplace = 'yandex_market';
        }

        if ($marketplace === 'ozon') {
            $data = $this->enrichOzonInputWithProfile($data);
        }

        $price = $data['price'];
        $costPrice = $data['cost_price'];
        $salesCount = $data['sales_count'] ?? 1;

        $revenue = $price * $salesCount;

        $calculator = match ($marketplace) {
            'wildberries' => $this->calculateWB($data),
            'ozon' => $this->calculateOzon($data),
            'yandex_market' => $this->calculateYandex($data),
            default => throw new \InvalidArgumentException("Unknown marketplace: {$marketplace}"),
        };

        $drrPercent = (float) ($data['drr_percent'] ?? 0);
        $drrAmount = $revenue * ($drrPercent / 100);
        $ourSharePercent = (float) ($data['our_share_percent'] ?? 0);
        $ourShareAmount = $revenue * ($ourSharePercent / 100);
        $taxPercent = (float) ($data['tax_percent'] ?? 0);
        $taxAmount = $revenue * ($taxPercent / 100);
        $vatPercent = (float) ($data['vat_percent'] ?? 0);
        $vatAmount = $revenue * ($vatPercent / 100);
        $advertisingCost = (float) ($data['advertising_cost'] ?? 0);

        $totalCosts = $costPrice * $salesCount
            + $calculator['total_fees']
            + $drrAmount
            + $ourShareAmount
            + $taxAmount
            + $vatAmount
            + $advertisingCost;
        $grossProfit = $revenue - $totalCosts;
        $netProfit = $grossProfit;
        $marginPercent = $revenue > 0 ? ($netProfit / $revenue) * 100 : 0;
        $totalCostPrice = $costPrice * $salesCount;
        $markupPercent = $costPrice > 0 ? round($price / $costPrice, 2) : 0;
        $roiPercent = $totalCosts > 0 ? ($netProfit / $totalCosts) * 100 : 0;
        // «На РС» = деньги, которые перечисляет маркетплейс: выручка − удержания МП
        // − реклама (ДРР/advertising). Налог, НДС и «наша часть» — выплаты продавца,
        // маркетплейс их не удерживает, поэтому в «На РС» не входят.
        $toSettlementAccount = ($calculator['details']['to_settlement_account']
            ?? ($revenue - $calculator['total_fees']))
            - $drrAmount
            - $advertisingCost;

        return array_merge([
            'revenue' => round($revenue, 2),
            'total_costs' => round($totalCosts, 2),
            'gross_profit' => round($grossProfit, 2),
            'net_profit' => round($netProfit, 2),
            'margin_percent' => round($marginPercent, 2),
            'markup_percent' => round($markupPercent, 2),
            'markup_multiplier' => round($markupPercent, 2),
            'roi_percent' => round($roiPercent, 2),
            'drr_percent' => round($drrPercent, 2),
            'drr_amount' => round($drrAmount, 2),
            'our_share_percent' => round($ourSharePercent, 2),
            'our_share_amount' => round($ourShareAmount, 2),
            'tax_percent' => round($taxPercent, 2),
            'tax_amount' => round($taxAmount, 2),
            'vat_percent' => round($vatPercent, 2),
            'vat_amount' => round($vatAmount, 2),
            'advertising_cost' => round($advertisingCost, 2),
        ], $calculator['details'], [
            // «На РС» — последним, чтобы перекрыть значение из details (там без ДРР).
            'to_settlement_account' => round($toSettlementAccount, 2),
        ]);
    }

    private function calculateWB(array $data): array
    {
        $price = $data['price'];
        $salesCount = $data['sales_count'] ?? 1;
        $fulfillmentType = strtoupper((string) ($data['fulfillment_type'] ?? 'FBO'));

        $commissionPercent = $data['commission_percent'] ?? $data['wb_commission_percent'] ?? 15;
        $sppPercent = (float) ($data['spp_percent'] ?? 0);
        $customerPrice = $price * (1 - $sppPercent / 100);
        $commissionAmount = ($customerPrice * $commissionPercent / 100) * $salesCount;

        $volumeLiters = $data['volume_liters'] ?? 0;
        $storageTariff = $data['storage_tariff'] ?? 0.5;
        $storageDays = $data['storage_days'] ?? 30;
        $storageCost = $data['storage_cost'] ?? ($volumeLiters * $storageTariff * $storageDays * $salesCount);

        $warehouseCoefficient = (float) ($data['warehouse_coefficient'] ?? 1.0);
        $localizationIndex = (float) ($data['localization_index'] ?? 1.0);
        $deliveryBaseLiter = (float) ($data['delivery_base_liter'] ?? 46);
        $deliveryAdditionalLiter = (float) ($data['delivery_additional_liter'] ?? 14);
        $baseLogisticsPerUnit = $this->calculateWildberriesFallbackLogisticsBase(
            (float) $volumeLiters,
            $deliveryBaseLiter,
            $deliveryAdditionalLiter
        );
        $ownDeliveryCost = (float) ($data['own_delivery_cost'] ?? 0);
        $ownReturnCost = (float) ($data['own_return_cost'] ?? 0);
        // ИРП (индекс распределения продаж) — наценка за нелокальные продажи, в процентах от цены.
        // Формула WB: base × КС × ИЛ + Цена × ИРП%.
        $salesDistributionIndex = (float) ($data['sales_distribution_index'] ?? 0);
        $salesDistributionMarkupPerUnit = $price * ($salesDistributionIndex / 100);
        $logisticsPerUnit = in_array($fulfillmentType, ['DBS', 'EDBS'], true)
            ? $ownDeliveryCost
            : ($data['logistics_cost'] ?? (
                $baseLogisticsPerUnit * $warehouseCoefficient * $localizationIndex
                + $salesDistributionMarkupPerUnit
            ));
        $logisticsCost = $logisticsPerUnit * $salesCount;
        $acceptanceCost = $data['acceptance_cost'] ?? 0;
        $penaltyCost = $data['penalty_cost'] ?? 0;
        $returnLogisticsPerUnit = in_array($fulfillmentType, ['DBS', 'EDBS'], true)
            ? $ownReturnCost
            : (float) ($data['return_logistics_cost'] ?? $baseLogisticsPerUnit);
        $returnLogisticsCost = $returnLogisticsPerUnit * $salesCount;
        $redemptionRate = (float) ($data['redemption_rate'] ?? 80);
        $expectedReturnCost = $returnLogisticsCost * ((100 - $redemptionRate) / 100);

        $acquiringPercent = (float) ($data['acquiring_percent'] ?? 1.5);
        $acquiringAmount = ($price * $acquiringPercent / 100) * $salesCount;

        $sppRub = ($price * $sppPercent / 100) * $salesCount;

        $ksPercent = $data['ks_percent'] ?? 0;
        $ksRub = ($price * $ksPercent / 100) * $salesCount;

        $totalFees = $commissionAmount + $storageCost + $logisticsCost + 
                     $acceptanceCost + $penaltyCost + $expectedReturnCost + $acquiringAmount + $ksRub;

        $effectiveLogistics = $logisticsCost + $expectedReturnCost;
        $toSettlementAccount = ($customerPrice * $salesCount) - $totalFees;

        return [
            'total_fees' => $totalFees,
            'details' => [
                'fulfillment_type' => $fulfillmentType,
                'wb_commission_percent' => $commissionPercent,
                'commission_percent' => $commissionPercent,
                'commission_amount' => round($commissionAmount, 2),
                'volume_liters' => $volumeLiters,
                'storage_tariff' => $storageTariff,
                'storage_days' => $storageDays,
                'storage_cost' => round($storageCost, 2),
                'base_logistics_cost' => round($baseLogisticsPerUnit, 2),
                'logistics_cost' => round($logisticsCost, 2),
                'acceptance_cost' => round($acceptanceCost, 2),
                'penalty_cost' => round($penaltyCost, 2),
                'return_logistics_cost' => round($returnLogisticsPerUnit, 2),
                'expected_return_cost' => round($expectedReturnCost, 2),
                'effective_logistics' => round($effectiveLogistics, 2),
                'acquiring_percent' => round($acquiringPercent, 2),
                'acquiring_amount' => round($acquiringAmount, 2),
                'spp_percent' => $sppPercent,
                'spp_rub' => round($sppRub, 2),
                'ks_percent' => $ksPercent,
                'ks_rub' => round($ksRub, 2),
                'customer_price' => round($customerPrice, 2),
                'own_delivery_cost' => round($ownDeliveryCost, 2),
                'to_settlement_account' => round($toSettlementAccount, 2),
            ],
        ];
    }

    private function calculateWildberriesFallbackLogisticsBase(
        float $volumeLiters,
        float $firstLiterOverOne = 46.0,
        float $additionalLiterOverOne = 14.0
    ): float {
        $volumeLiters = max(0.001, $volumeLiters);

        if ($volumeLiters <= 1.0) {
            foreach (self::WB_SMALL_VOLUME_TARIFFS as $tier) {
                if ($volumeLiters <= $tier['max_volume']) {
                    return $tier['rate'];
                }
            }

            return 32.0;
        }

        return round($firstLiterOverOne + (($volumeLiters - 1.0) * $additionalLiterOverOne), 2);
    }

    private function calculateOzon(array $data): array
    {
        $data = $this->suppressOzonMarkupForExcludedOrderEconomics($data);

        $salesCount = max(1, (int) ($data['sales_count'] ?? 1));
        $volumeLiters = (float) ($data['volume_liters'] ?? 0);
        $sideCm = $volumeLiters > 0 ? pow($volumeLiters * 1000, 1 / 3) : 0;
        $categoryForCommission = $this->resolveOzonCategoryForCommission($data);

        $input = CalculationInput::fromArray([
            'sku' => $data['sku'] ?? 'preview',
            'integration_id' => (int) ($data['integration_id'] ?? 0),
            'marketplace' => 'ozon',
            'fulfillment_type' => strtoupper((string) ($data['fulfillment_type'] ?? 'FBO')),
            'price' => (float) ($data['price'] ?? 0),
            'cost_price' => (float) ($data['cost_price'] ?? 0),
            'length' => (float) ($data['length'] ?? $sideCm),
            'width' => (float) ($data['width'] ?? $sideCm),
            'height' => (float) ($data['height'] ?? $sideCm),
            'weight' => (float) ($data['weight'] ?? 0),
            'volume_weight' => isset($data['volume_weight']) ? (float) $data['volume_weight'] : null,
            'category_id' => $categoryForCommission,
            'commission_rate' => isset($data['commission_percent']) ? (float) $data['commission_percent'] : null,
            'redemption_rate' => isset($data['redemption_rate']) ? (float) $data['redemption_rate'] : null,
            'acquiring_percent' => isset($data['acquiring_percent']) ? (float) $data['acquiring_percent'] : 1.5,
            'storage_cost' => isset($data['storage_cost']) ? (float) $data['storage_cost'] : 0,
            'packaging_cost' => isset($data['packaging_cost']) ? (float) $data['packaging_cost'] : 0,
            'own_delivery_cost' => isset($data['own_delivery_cost']) ? (float) $data['own_delivery_cost'] : 0,
            'own_return_cost' => isset($data['own_return_cost']) ? (float) $data['own_return_cost'] : (isset($data['return_cost']) ? (float) $data['return_cost'] : 0),
            'marketplace_compensation' => isset($data['ozon_compensation']) ? (float) $data['ozon_compensation'] : (isset($data['marketplace_compensation']) ? (float) $data['marketplace_compensation'] : null),
            'sales_7_days' => isset($data['sales_7_days']) ? (int) $data['sales_7_days'] : null,
            'route_key' => $data['route_key'] ?? null,
            'route_label' => $data['route_label'] ?? null,
            'is_local_sale' => $data['is_local_sale'] ?? null,
            'non_local_markup_percent' => $data['non_local_markup_percent'] ?? null,
            'route_resolution_status' => $data['route_resolution_status'] ?? null,
            'locality_resolution_status' => $data['locality_resolution_status'] ?? null,
            'calculation_confidence' => $data['calculation_confidence'] ?? null,
            'profile_source' => $data['profile_source'] ?? null,
            'dominant_cluster_id' => $data['dominant_cluster_id'] ?? null,
            'dominant_cluster_share' => $data['dominant_cluster_share'] ?? null,
            'expected_locality_rate' => $data['expected_locality_rate'] ?? null,
            'weighted_non_local_markup_percent' => $data['weighted_non_local_markup_percent'] ?? null,
            'clusters_summary' => isset($data['clusters_summary']) && is_array($data['clusters_summary']) ? $data['clusters_summary'] : [],
            'stock_profile' => isset($data['stock_profile']) && is_array($data['stock_profile']) ? $data['stock_profile'] : [],
            'weighted_logistics_cost' => isset($data['weighted_logistics_cost']) ? (float) $data['weighted_logistics_cost'] : null,
            'order_date' => $data['order_date'] ?? null,
            'shipping_cluster_id' => $data['shipping_cluster_id'] ?? null,
            'shipping_cluster_name' => $data['shipping_cluster_name'] ?? null,
            'destination_cluster_id' => $data['destination_cluster_id'] ?? null,
            'destination_cluster_name' => $data['destination_cluster_name'] ?? null,
            'fixation_applied' => $data['fixation_applied'] ?? null,
            'fixation_id' => $data['fixation_id'] ?? null,
            'fixation_base_date' => $data['fixation_base_date'] ?? null,
            'fixed_until' => $data['fixed_until'] ?? null,
            'tariff_version_used' => $data['tariff_version_used'] ?? null,
            'markup_version_used' => $data['markup_version_used'] ?? null,
            'markup_applied' => $data['markup_applied'] ?? null,
            'markup_reason_code' => $data['markup_reason_code'] ?? null,
            'markup_reason_label' => $data['markup_reason_label'] ?? null,
            'markup_exception_status' => $data['markup_exception_status'] ?? null,
            'calculation_mode' => $data['calculation_mode'] ?? null,
            'redemption_source' => $data['redemption_source'] ?? null,
            'orders_count' => $data['orders_count'] ?? null,
            'delivered_count' => $data['delivered_count'] ?? null,
            'cancelled_count' => $data['cancelled_count'] ?? null,
            'not_redeemed_count' => $data['not_redeemed_count'] ?? null,
            'in_flight_count' => $data['in_flight_count'] ?? null,
            'returns_count' => $data['returns_count'] ?? null,
        ]);

        $result = $this->orchestrator->calculate($input)->toArray();
        $costs = $result['costs'] ?? [];
        $price = (float) ($data['price'] ?? 0);
        $commissionPercent = (float) ($result['sales_fee_percent'] ?? $result['commission_percent'] ?? 0);
        $acquiringPercent = (float) ($result['acquiring_percent'] ?? 0);
        $commissionAmount = $price * ($commissionPercent / 100) * $salesCount;
        $acquiringAmount = $price * ($acquiringPercent / 100) * $salesCount;
        $logisticsBasePerUnit = (float) ($result['base_logistics'] ?? $costs['logistics'] ?? 0);
        $nonLocalMarkupAmount = (float) ($result['non_local_markup_amount'] ?? 0);
        $logisticsCost = ($logisticsBasePerUnit + $nonLocalMarkupAmount) * $salesCount;
        $lastMileCost = (float) ($result['last_mile'] ?? $costs['last_mile'] ?? 0) * $salesCount;
        $processingCost = (float) ($result['processing_fee'] ?? $costs['processing_fee'] ?? 0) * $salesCount;
        $expectedReturnCost = (float) ($result['expected_return_cost'] ?? $costs['expected_return_cost'] ?? 0) * $salesCount;
        $storageCost = (float) ($result['storage_cost'] ?? $costs['storage_cost'] ?? ($data['storage_cost'] ?? 0)) * $salesCount;
        $packagingCost = (float) ($data['packaging_cost'] ?? 0) * $salesCount;
        $agentFee = (float) ($costs['agent_fee'] ?? 0) * $salesCount;
        $marketplaceCompensation = (float) ($result['marketplace_compensation'] ?? $data['ozon_compensation'] ?? $data['marketplace_compensation'] ?? 0) * $salesCount;
        $totalFees = $commissionAmount + $logisticsCost + $lastMileCost + $processingCost + $expectedReturnCost + $storageCost + $acquiringAmount + $packagingCost + $agentFee - $marketplaceCompensation;
        $toSettlementAccount = ($price * $salesCount) - $totalFees;

        return [
            'total_fees' => $totalFees,
            'details' => [
                'fulfillment_type' => $result['fulfillment_type'],
                'commission_percent' => round((float) ($result['commission_percent'] ?? 0), 2),
                'commission_amount' => round($commissionAmount, 2),
                'sales_fee_percent' => round($commissionPercent, 2),
                'price_segment' => $result['price_segment'] ?? null,
                'shipping_cluster_id' => $result['shipping_cluster_id'] ?? null,
                'shipping_cluster_name' => $result['shipping_cluster_name'] ?? null,
                'destination_cluster_id' => $result['destination_cluster_id'] ?? null,
                'destination_cluster_name' => $result['destination_cluster_name'] ?? null,
                'route_key' => $result['route_key'] ?? null,
                'route_label' => $result['route_label'] ?? null,
                'tariff_source' => $result['tariff_source'] ?? null,
                'tariff_effective_from' => $result['tariff_effective_from'] ?? null,
                'tariff_version' => $result['tariff_version'] ?? null,
                'markup_version_used' => $result['markup_version_used'] ?? null,
                'fixation_applied' => $result['fixation_applied'] ?? null,
                'fixation_id' => $result['fixation_id'] ?? null,
                'fixation_base_date' => $result['fixation_base_date'] ?? null,
                'fixed_until' => $result['fixed_until'] ?? null,
                'is_local_sale' => $result['is_local_sale'] ?? null,
                'non_local_markup_percent' => round((float) ($result['non_local_markup_percent'] ?? 0), 2),
                'markup_applied' => $result['markup_applied'] ?? null,
                'markup_reason_code' => $result['markup_reason_code'] ?? null,
                'markup_reason_label' => $result['markup_reason_label'] ?? null,
                'markup_exception_status' => $result['markup_exception_status'] ?? null,
                'calculation_mode' => $result['calculation_mode'] ?? null,
                'route_resolution_status' => $result['route_resolution_status'] ?? null,
                'locality_resolution_status' => $result['locality_resolution_status'] ?? null,
                'calculation_confidence' => $result['calculation_confidence'] ?? null,
                'profile_source' => $result['profile_source'] ?? null,
                'dominant_cluster_id' => $result['dominant_cluster_id'] ?? null,
                'dominant_cluster_share' => isset($result['dominant_cluster_share']) ? round((float) $result['dominant_cluster_share'], 2) : null,
                'expected_locality_rate' => isset($result['expected_locality_rate']) ? round((float) $result['expected_locality_rate'], 2) : null,
                'weighted_non_local_markup_percent' => isset($result['weighted_non_local_markup_percent']) ? round((float) $result['weighted_non_local_markup_percent'], 2) : null,
                'chargeable_volume_liters' => isset($result['chargeable_volume_liters']) ? round((float) $result['chargeable_volume_liters'], 4) : null,
                'profit_min' => isset($result['profit_min']) ? round((float) $result['profit_min'] * $salesCount, 2) : null,
                'profit_base' => isset($result['profit_base']) ? round((float) $result['profit_base'] * $salesCount, 2) : null,
                'profit_max' => isset($result['profit_max']) ? round((float) $result['profit_max'] * $salesCount, 2) : null,
                'clusters_summary' => $result['clusters_summary'] ?? [],
                'non_local_markup_amount' => round($nonLocalMarkupAmount * $salesCount, 2),
                'base_logistics_cost' => round($logisticsBasePerUnit * $salesCount, 2),
                'logistics_cost' => round($logisticsCost, 2),
                'last_mile_cost' => round($lastMileCost, 2),
                'processing_cost' => round($processingCost, 2),
                'return_cost' => round((float) (($data['own_return_cost'] ?? $data['return_cost'] ?? 0) * $salesCount), 2),
                'return_logistics_cost' => round((float) (($result['return_logistics'] ?? 0) * $salesCount), 2),
                'return_processing_cost' => round((float) (($result['return_processing'] ?? 0) * $salesCount), 2),
                'expected_return_cost' => round($expectedReturnCost, 2),
                'effective_logistics' => round($logisticsCost + $lastMileCost + $processingCost + $expectedReturnCost, 2),
                'storage_cost' => round($storageCost, 2),
                'acquiring_percent' => round($acquiringPercent, 2),
                'acquiring_amount' => round($acquiringAmount, 2),
                'packaging_cost' => round($packagingCost, 2),
                'own_delivery_cost' => round((float) ($data['own_delivery_cost'] ?? 0), 2),
                'ozon_compensation' => round($marketplaceCompensation, 2),
                'agent_fee' => round($agentFee, 2),
                'to_settlement_account' => round($toSettlementAccount, 2),
            ],
        ];
    }

    private function suppressOzonMarkupForExcludedOrderEconomics(array $data): array
    {
        $summary = is_array($data['order_economics_summary'] ?? null)
            ? $data['order_economics_summary']
            : [];
        if ($summary === []) {
            return $data;
        }

        $ordersCount = (int) ($summary['orders_count'] ?? 0);
        $markupAmount = (float) ($summary['avg_non_local_markup_amount'] ?? 0);
        $reasonCodes = is_array($summary['markup_reason_codes'] ?? null)
            ? $summary['markup_reason_codes']
            : [];
        if ($ordersCount <= 0 || abs($markupAmount) > 0.0001 || $reasonCodes === []) {
            return $data;
        }

        $excludedReasons = [
            'cancelled_order',
            'not_redeemed',
            'local_cluster',
            'fbo_lt_50_orders_7d',
            'zero_markup_cluster',
        ];
        $excludedCount = 0;
        foreach ($reasonCodes as $reason => $count) {
            if (in_array((string) $reason, $excludedReasons, true)) {
                $excludedCount += (int) $count;
            }
        }

        if ($excludedCount < $ordersCount) {
            return $data;
        }

        $data['non_local_markup_percent'] = 0.0;
        $data['weighted_non_local_markup_percent'] = 0.0;
        if (isset($data['clusters_summary']) && is_array($data['clusters_summary'])) {
            $firstReason = (string) array_key_first($reasonCodes);
            $data['clusters_summary'] = array_map(static function (array $cluster) use ($firstReason): array {
                $cluster['effective_markup_percent'] = 0.0;
                $cluster['markup_reason'] = $firstReason;

                return $cluster;
            }, $data['clusters_summary']);
        }

        return $data;
    }

    public function enrichOzonInputWithProfile(array $data): array
    {
        $integrationId = (int) ($data['integration_id'] ?? 0);
        $sku = (string) ($data['sku'] ?? '');
        if ($integrationId <= 0 || $sku === '') {
            return $data;
        }

        if (empty($data['shipping_cluster_name']) && empty($data['fixed_until'])) {
            $fixations = OzonSupplyFixation::query()
                ->where('integration_id', $integrationId)
                ->where('sku', $sku)
                ->activeWindow()
                ->orderByDesc('fixation_base_date')
                ->get();

            if ($fixations->count() === 1) {
                $fixation = $fixations->first();
                $data['shipping_cluster_id'] ??= $fixation?->shipping_cluster_id;
                $data['shipping_cluster_name'] ??= $fixation?->shipping_cluster_name;
                $data['fixation_applied'] ??= true;
                $data['fixation_id'] ??= $fixation?->id;
                $data['fixation_base_date'] ??= optional($fixation?->fixation_base_date)?->toDateString();
                $data['fixed_until'] ??= optional($fixation?->fixed_until)?->toDateString();
                $data['tariff_version_used'] ??= $fixation?->tariff_version;
                $data['markup_version_used'] ??= $fixation?->markup_version;
                $data['calculation_mode'] ??= 'preview';
            } elseif ($fixations->count() > 1) {
                $data['calculation_mode'] ??= 'estimate';
                $data['calculation_confidence'] ??= 'medium';
            }
        }

        $scheme = strtoupper((string) ($data['fulfillment_type'] ?? 'FBO'));
        $profile = OzonSkuDeliveryProfile::findForProduct($integrationId, $sku, $scheme)
            ?? OzonSkuDeliveryProfile::findForProduct($integrationId, $sku, 'ALL');

        if (! $profile) {
            return $data;
        }

        $stockProfile = is_array($profile->stock_profile ?? null) ? $profile->stock_profile : [];
        $clusterProfile = is_array($profile->cluster_profile ?? null) ? $profile->cluster_profile : [];
        $clustersSummary = is_array($clusterProfile['clusters_summary'] ?? null)
            ? $clusterProfile['clusters_summary']
            : [];

        $data['route_key'] ??= $stockProfile['route_key'] ?? null;
        $data['route_label'] ??= $stockProfile['route_label'] ?? null;
        if (! array_key_exists('is_local_sale', $data) && array_key_exists('is_local_sale', $stockProfile)) {
            $data['is_local_sale'] = $stockProfile['is_local_sale'];
        }
        $data['route_resolution_status'] ??= $profile->route_resolution_status;
        $data['locality_resolution_status'] ??= $profile->locality_resolution_status;
        $data['calculation_confidence'] ??= $profile->calculation_confidence;
        $data['profile_source'] ??= $profile->profile_source;
        $data['dominant_cluster_id'] ??= $profile->dominant_demand_cluster_id ?? ($clusterProfile['dominant_cluster_id'] ?? null);
        $data['dominant_cluster_share'] ??= $profile->dominant_demand_cluster_share ?? ($clusterProfile['dominant_cluster_share'] ?? null);
        $data['expected_locality_rate'] ??= $profile->expected_locality_rate;
        $data['weighted_non_local_markup_percent'] ??= $profile->weighted_non_local_markup_percent;
        $data['weighted_logistics_cost'] ??= $profile->weighted_logistics_cost;
        $data['shipping_cluster_name'] ??= $stockProfile['route_label'] ?? null;
        if (empty($data['stock_profile'])) {
            $data['stock_profile'] = $stockProfile['clusters'] ?? [];
        }
        if (empty($data['clusters_summary'])) {
            $data['clusters_summary'] = $clustersSummary;
        }

        return $data;
    }

    private function resolveOzonCategoryForCommission(array $data): string
    {
        foreach (['category_name', 'category', 'category_id'] as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && trim($value) !== '' && ! is_numeric(trim($value))) {
                return trim($value);
            }
        }

        $integrationId = (int) ($data['integration_id'] ?? 0);
        $sku = (string) ($data['sku'] ?? '');
        if ($integrationId <= 0 || $sku === '') {
            return 'default';
        }

        try {
            $product = Product::query()
                ->where('integration_id', $integrationId)
                ->where('sku', $sku)
                ->first();

            $productCategory = $product?->category;
            if (is_string($productCategory) && trim($productCategory) !== '' && ! is_numeric(trim($productCategory))) {
                return trim($productCategory);
            }
        } catch (\Throwable) {
            // Plain PHPUnit/unit contexts may not have a configured DB connection.
        }

        return 'default';
    }

    private function calculateYandex(array $data): array
    {
        $price = $data['price'];
        $salesCount = $data['sales_count'] ?? 1;
        $fulfillmentType = strtoupper((string) ($data['fulfillment_type'] ?? 'FBY'));
        $tariffBreakdown = $this->normalizeYandexTariffBreakdown($data['tariff_breakdown'] ?? []);

        $referralFeePercent = $data['referral_fee_percent'] ?? 12;
        $referralFeePerUnit = $tariffBreakdown['FEE'] ?? ($price * $referralFeePercent / 100);
        if (isset($tariffBreakdown['FEE']) && $price > 0) {
            $referralFeePercent = ($referralFeePerUnit / $price) * 100;
        }
        $referralFeeAmount = $referralFeePerUnit * $salesCount;

        $agencyCommissionPerUnit = $tariffBreakdown['AGENCY_COMMISSION'] ?? 0;
        $paymentTransferPerUnit = $tariffBreakdown['PAYMENT_TRANSFER'] ?? 0;
        $acquiringPerUnit = $agencyCommissionPerUnit + $paymentTransferPerUnit;
        $acquiringAmount = $acquiringPerUnit * $salesCount;
        $acquiringPercent = $price > 0
            ? ($acquiringPerUnit / $price) * 100
            : ($data['acquiring_percent'] ?? 0);

        $deliveryToCustomerPerUnit = $tariffBreakdown['DELIVERY_TO_CUSTOMER'] ?? 0;
        $crossregionalDeliveryPerUnit = $tariffBreakdown['CROSSREGIONAL_DELIVERY'] ?? 0;
        $middleMilePerUnit = $tariffBreakdown['MIDDLE_MILE'] ?? 0;
        $expressDeliveryPerUnit = $tariffBreakdown['EXPRESS_DELIVERY'] ?? 0;
        $sortingPerUnit = $tariffBreakdown['SORTING'] ?? 0;

        $defaultFbyDelivery = $data['fby_delivery'] ?? 50;
        $defaultFbsDelivery = $data['fbs_delivery'] ?? 40;
        $deliveryPerUnit = $deliveryToCustomerPerUnit + $crossregionalDeliveryPerUnit + $middleMilePerUnit + $expressDeliveryPerUnit;
        if ($deliveryPerUnit <= 0) {
            $deliveryPerUnit = match ($fulfillmentType) {
                'FBS', 'DBS', 'EXPRESS' => $defaultFbsDelivery,
                default => $defaultFbyDelivery,
            };
        }

        $fbyPlacement = ($data['fby_placement'] ?? 0) * $salesCount;
        $fbyPickupTransfer = ($data['fby_pickup_transfer'] ?? 0) * $salesCount;
        $fbyDelivery = ($fulfillmentType === 'FBY' ? $deliveryPerUnit : $defaultFbyDelivery) * $salesCount;
        $fbyMiddleMile = ($fulfillmentType === 'FBY' ? $middleMilePerUnit : ($data['fby_middle_mile'] ?? 0)) * $salesCount;
        $fbyTotal = $fbyPlacement + $fbyPickupTransfer + $fbyDelivery + $fbyMiddleMile;

        $fbsPlacement = ($data['fbs_placement'] ?? 0) * $salesCount;
        $fbsPickupTransfer = ($data['fbs_pickup_transfer'] ?? 0) * $salesCount;
        $fbsDelivery = ($fulfillmentType !== 'FBY' ? $deliveryPerUnit : $defaultFbsDelivery) * $salesCount;
        $fbsMiddleMile = ($fulfillmentType !== 'FBY' ? $middleMilePerUnit : ($data['fbs_middle_mile'] ?? 0)) * $salesCount;
        $fbsSorting = $sortingPerUnit * $salesCount;
        $fbsTotal = $fbsPlacement + $fbsPickupTransfer + $fbsDelivery + $fbsMiddleMile + $fbsSorting;

        $logisticsTotal = ($deliveryPerUnit + $sortingPerUnit) * $salesCount;
        $storageCost = (float) ($data['storage_cost'] ?? 0);

        // Хранение: рассчитываем по тарифу если не задано
        if ($storageCost <= 0 && $fulfillmentType === 'FBY') {
            $volumeLiters = (float) ($data['volume_liters'] ?? 0);
            $turnoverDays = (int) ($data['turnover_days'] ?? 30);
            if ($volumeLiters > 0) {
                $tariffs = new \App\Domains\YandexMarket\Tariffs\YandexMarketTariffs();
                $storageCost = $tariffs->calculateStorageCost($volumeLiters, $turnoverDays);
            }
        }

        // Возвраты
        $weightKg = (float) ($data['weight_g'] ?? 0) / 1000;
        $tariffs = $tariffs ?? new \App\Domains\YandexMarket\Tariffs\YandexMarketTariffs();
        $returnLogisticsCost = $tariffs->calculateReturnLogisticsCost($fulfillmentType, $weightKg);
        $returnProcessingCost = $tariffs->getReturnProcessingFee();
        $redemptionRate = (float) ($data['redemption_rate'] ?? 95);
        $returnRate = ($redemptionRate >= 100) ? 0 : (100 - $redemptionRate) / 100;
        $expectedReturnCost = ($returnLogisticsCost + $returnProcessingCost) * $returnRate;

        $totalFees = $referralFeeAmount + $acquiringAmount + $logisticsTotal + $storageCost + $expectedReturnCost;
        $toSettlementAccount = ($price * $salesCount) - $totalFees;

        return [
            'total_fees' => $totalFees,
            'details' => [
                'fulfillment_type' => $fulfillmentType,
                'referral_fee_percent' => $referralFeePercent,
                'commission_percent' => round($referralFeePercent, 2),
                'commission_amount' => round($referralFeeAmount, 2),
                'referral_fee_amount' => round($referralFeeAmount, 2),
                'agency_commission' => round($agencyCommissionPerUnit * $salesCount, 2),
                'payment_transfer' => round($paymentTransferPerUnit * $salesCount, 2),
                'acquiring_percent' => round($acquiringPercent, 2),
                'acquiring_amount' => round($acquiringAmount, 2),
                'delivery_to_customer' => round($deliveryToCustomerPerUnit * $salesCount, 2),
                'crossregional_delivery' => round($crossregionalDeliveryPerUnit * $salesCount, 2),
                'middle_mile' => round($middleMilePerUnit * $salesCount, 2),
                'express_delivery' => round($expressDeliveryPerUnit * $salesCount, 2),
                'sorting' => round($fbsSorting, 2),
                'fby_placement' => round($fbyPlacement, 2),
                'fby_pickup_transfer' => round($fbyPickupTransfer, 2),
                'fby_delivery' => round($fbyDelivery, 2),
                'fby_middle_mile' => round($fbyMiddleMile, 2),
                'fby_total' => round($fbyTotal, 2),
                'fbs_placement' => round($fbsPlacement, 2),
                'fbs_pickup_transfer' => round($fbsPickupTransfer, 2),
                'fbs_delivery' => round($fbsDelivery, 2),
                'fbs_middle_mile' => round($fbsMiddleMile, 2),
                'fbs_total' => round($fbsTotal, 2),
                'logistics_cost' => round($logisticsTotal, 2),
                'delivery_cost' => round($logisticsTotal, 2),
                'storage_cost' => round($storageCost, 2),
                'return_logistics_cost' => round($returnLogisticsCost, 2),
                'return_processing_cost' => round($returnProcessingCost, 2),
                'expected_return_cost' => round($expectedReturnCost, 2),
                'effective_logistics' => round($logisticsTotal + $expectedReturnCost, 2),
                'packaging_cost' => round((float) ($data['packaging_cost'] ?? 0), 2),
                'to_settlement_account' => round($toSettlementAccount, 2),
            ],
        ];
    }

    private function normalizeYandexTariffBreakdown(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = strtoupper((string) ($item['type'] ?? $item['tariffType'] ?? $item['serviceType'] ?? $item['code'] ?? ''));
            if ($type === '') {
                continue;
            }

            $amount = $item['amount'] ?? $item['price'] ?? $item['total'] ?? $item['value'] ?? 0;
            if (is_array($amount)) {
                $amount = $amount['value'] ?? 0;
            }

            $normalized[$type] = (float) $amount;
        }

        return $normalized;
    }

    public function createOrUpdate(array $data): UnitEconomics
    {
        $marketplace = $data['marketplace'] === 'yandex' ? 'yandex_market' : $data['marketplace'];
        $data['marketplace'] = $marketplace;
        $calculated = $this->calculate($marketplace, $data);
        $fulfillmentType = strtoupper((string) ($data['fulfillment_type'] ?? $calculated['fulfillment_type'] ?? 'FBO'));

        return UnitEconomics::updateOrCreate(
            [
                'sku' => $data['sku'],
                'marketplace' => $marketplace,
                'integration_id' => $data['integration_id'] ?? null,
                'fulfillment_type' => $fulfillmentType,
                'period_start' => $data['period_start'] ?? now()->startOfMonth()->toDateString(),
                'period_end' => $data['period_end'] ?? now()->endOfMonth()->toDateString(),
            ],
            [
                'product_name' => $data['product_name'] ?? null,
                'price' => $data['price'],
                'cost_price' => $data['cost_price'],
                'sales_count' => $data['sales_count'] ?? 0,
                'revenue' => $calculated['revenue'],
                'total_costs' => $calculated['total_costs'],
                'gross_profit' => $calculated['gross_profit'],
                'net_profit' => $calculated['net_profit'],
                'margin_percent' => $calculated['margin_percent'],
                'roi_percent' => $calculated['roi_percent'],
                'marketplace_data' => $calculated,
            ]
        );
    }

    public function getStats(array $filters = []): array
    {
        $query = UnitEconomics::query();

        if (!empty($filters['marketplace'])) {
            $query->marketplace($filters['marketplace']);
        }

        return [
            'total_revenue' => round($query->sum('revenue'), 2),
            'total_costs' => round($query->sum('total_costs'), 2),
            'total_profit' => round($query->sum('net_profit'), 2),
            'average_margin' => round($query->avg('margin_percent'), 2),
            'average_roi' => round($query->avg('roi_percent'), 2),
            'total_sales' => $query->sum('sales_count'),
            'profitable_products' => (clone $query)->profitable()->count(),
            'unprofitable_products' => (clone $query)->unprofitable()->count(),
        ];
    }

    public function getStatsByMarketplace(string $marketplace): array
    {
        $query = UnitEconomics::marketplace($marketplace);

        return [
            'total_revenue' => round($query->sum('revenue'), 2),
            'total_costs' => round($query->sum('total_costs'), 2),
            'total_profit' => round($query->sum('net_profit'), 2),
            'average_margin' => round($query->avg('margin_percent'), 2),
            'average_roi' => round($query->avg('roi_percent'), 2),
            'total_sales' => $query->sum('sales_count'),
            'profitable_products' => (clone $query)->profitable()->count(),
            'unprofitable_products' => (clone $query)->unprofitable()->count(),
        ];
    }

    public function getOverallStats(): array
    {
        $stats = [];

        foreach (['wildberries', 'ozon', 'yandex_market'] as $marketplace) {
            $stats[$marketplace] = $this->getStatsByMarketplace($marketplace);
        }

        $stats['total'] = [
            'total_revenue' => array_sum(array_column($stats, 'total_revenue')),
            'total_profit' => array_sum(array_column($stats, 'total_profit')),
            'total_sales' => array_sum(array_column($stats, 'total_sales')),
        ];

        return $stats;
    }

    public function getMarketplaceComparison(): array
    {
        $comparison = [];

        foreach (['wildberries', 'ozon', 'yandex_market'] as $marketplace) {
            $stats = $this->getStatsByMarketplace($marketplace);
            $comparison[$marketplace] = [
                'revenue' => $stats['total_revenue'],
                'profit' => $stats['total_profit'],
                'margin' => $stats['average_margin'],
                'roi' => $stats['average_roi'],
                'products' => $stats['profitable_products'] + $stats['unprofitable_products'],
            ];
        }

        return $comparison;
    }

    public function getCommissions(string $marketplace): array
    {
        return Cache::remember("commissions_{$marketplace}", 3600, function () use ($marketplace) {
            return match ($marketplace) {
                'wildberries' => [
                    ['category' => 'Одежда', 'commission' => 15],
                    ['category' => 'Обувь', 'commission' => 15],
                    ['category' => 'Электроника', 'commission' => 10],
                    ['category' => 'Красота', 'commission' => 18],
                    ['category' => 'Дом и сад', 'commission' => 15],
                ],
                'ozon' => [
                    ['category' => 'Одежда', 'fbo' => 15, 'fbs' => 12],
                    ['category' => 'Обувь', 'fbo' => 15, 'fbs' => 12],
                    ['category' => 'Электроника', 'fbo' => 8, 'fbs' => 6],
                    ['category' => 'Красота', 'fbo' => 18, 'fbs' => 15],
                ],
                'yandex_market' => [
                    ['category' => 'Одежда', 'referral_fee' => 6],
                    ['category' => 'Электроника', 'referral_fee' => 4],
                    ['category' => 'Красота', 'referral_fee' => 8],
                ],
                default => [],
            };
        });
    }

    public function getTariffs(string $marketplace): array
    {
        return Cache::remember("tariffs_{$marketplace}", 3600, function () use ($marketplace) {
            return match ($marketplace) {
                'wildberries' => [
                    'storage' => ['per_liter_per_day' => 0.5],
                    'logistics' => ['base' => 50, 'per_kg' => 5],
                    'acceptance' => ['per_item' => 2],
                ],
                'ozon' => [
                    'routing' => ['mode' => 'route_matrix'],
                    'last_mile' => ['base' => 25],
                    'sales_fee' => ['price_segments' => config('ozon_unit_economics.price_segments', [])],
                    'routes' => config('ozon_unit_economics.routes', []),
                    'acquiring' => ['percent' => 1.5],
                ],
                'yandex_market' => [
                    'fby_delivery' => ['base' => 50],
                    'fbs_delivery' => ['base' => 40],
                    'storage' => ['per_liter_per_day' => 0.3],
                ],
                default => [],
            };
        });
    }

    public function bulkSave(array $items): array
    {
        $synced = 0;
        $errors = 0;

        foreach ($items as $item) {
            try {
                $this->createOrUpdate($item);
                $synced++;
            } catch (\Exception $e) {
                $errors++;
            }
        }

        return ['synced' => $synced, 'errors' => $errors];
    }

    public function getProductComparison(?int $integrationId = null): array
    {
        $query = UnitEconomics::query();
        if ($integrationId) {
            $query->where('integration_id', $integrationId);
        }

        return $query->select('sku', 'marketplace', 'price', 'cost_price', 'margin_percent', 'roi_percent', 'net_profit')
            ->orderByDesc('roi_percent')
            ->limit(100)
            ->get()
            ->toArray();
    }

    public function syncFromRealData(
        \App\Models\Integration $integration,
        ?string $periodStart = null,
        ?string $periodEnd = null,
        ?array $localizationIndex = null
    ): array {
        $exitCode = \Illuminate\Support\Facades\Artisan::call('unit-economics:sync', [
            '--integration' => $integration->id,
            '--skip-cache-dispatch' => true,
        ]);

        return [
            'synced' => $exitCode === 0 ? 1 : 0,
            'errors' => $exitCode !== 0 ? 1 : 0,
        ];
    }
}
