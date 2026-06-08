<?php

namespace App\Domains\Ozon\UnitEconomics;

use App\Domains\UnitEconomics\Contracts\UnitEconomicsCalculatorInterface;
use App\Domains\UnitEconomics\DTO\CalculationInput;
use App\Domains\UnitEconomics\DTO\UnitEconomicsResult;
use App\Domains\UnitEconomics\DTO\CostBreakdown;
use App\Domains\Ozon\Tariffs\OzonPricingMatrix;

/**
 * Калькулятор юнит-экономики для Ozon
 * 
 * Поддерживает 4 схемы: FBO, FBS, RFBS, EXPRESS
 */
class OzonUnitEconomicsCalculator implements UnitEconomicsCalculatorInterface
{
    private OzonPricingMatrix $pricing;

    public function __construct()
    {
        $this->pricing = new OzonPricingMatrix();
    }

    /**
     * Рассчитать юнит-экономику
     */
    public function calculate(CalculationInput $input): UnitEconomicsResult
    {
        $scheme = strtoupper($input->fulfillmentType);
        
        return match ($scheme) {
            'FBO' => $this->calculateFbo($input),
            'FBS' => $this->calculateFbs($input),
            'RFBS' => $this->calculateRfbs($input),
            'EXPRESS' => $this->calculateExpress($input),
            default => throw new \InvalidArgumentException("Unknown scheme: {$scheme}"),
        };
    }

    /**
     * Рассчитать FBO
     */
    private function calculateFbo(CalculationInput $input): UnitEconomicsResult
    {
        $price = $input->price;
        $volume = $input->getChargeableVolumeInLiters();
        $context = $this->resolveMarketplaceContext('FBO', $input, $volume);

        $returnCosts = $this->calculateExpectedReturnCosts('FBO', $context['base_logistics'], $input->redemptionRate, $input);

        return $this->buildResult(
            $input,
            commission: $context['commission'],
            commissionRate: $context['commission_rate'],
            acquiring: $context['acquiring'],
            acquiringRate: $context['acquiring_rate'],
            logistics: $context['logistics'],
            baseLogistics: $context['base_logistics'],
            lastMile: $context['last_mile'],
            processingFee: 0,
            expectedReturnCost: $returnCosts['expected'],
            routeKey: $context['route_key'],
            routeLabel: $context['route_label'],
            priceSegment: $context['price_segment'],
            tariffSource: $context['tariff_source'],
            tariffEffectiveFrom: $context['tariff_effective_from'],
            isLocalSale: $context['is_local_sale'],
            nonLocalMarkupPercent: $context['non_local_markup_percent'],
            salesFeePercent: $context['commission_rate'],
            agentFee: 0,
            integrationFee: 0,
            returnLogistics: $returnCosts['logistics'],
            returnProcessing: $returnCosts['processing'],
            routeResolutionStatus: $context['route_resolution_status'],
            localityResolutionStatus: $context['locality_resolution_status'],
            calculationConfidence: $context['calculation_confidence'],
            profileSource: $context['profile_source'],
            dominantClusterId: $context['dominant_cluster_id'],
            dominantClusterShare: $context['dominant_cluster_share'],
            expectedLocalityRate: $context['expected_locality_rate'],
            weightedNonLocalMarkupPercent: $context['weighted_non_local_markup_percent'],
            clustersSummary: $context['clusters_summary']
        );
    }

    /**
     * Рассчитать FBS
     */
    private function calculateFbs(CalculationInput $input): UnitEconomicsResult
    {
        $price = $input->price;
        $volume = $input->getChargeableVolumeInLiters();
        $context = $this->resolveMarketplaceContext('FBS', $input, $volume);
        $schemeCosts = $this->pricing->getSchemeCosts('FBS');
        $processingFee = (float) ($schemeCosts['processing_fee'] ?? 0);
        $returnCosts = $this->calculateExpectedReturnCosts('FBS', $context['base_logistics'], $input->redemptionRate, $input);

        return $this->buildResult(
            $input,
            commission: $context['commission'],
            commissionRate: $context['commission_rate'],
            acquiring: $context['acquiring'],
            acquiringRate: $context['acquiring_rate'],
            logistics: $context['logistics'],
            baseLogistics: $context['base_logistics'],
            lastMile: $context['last_mile'],
            processingFee: $processingFee,
            expectedReturnCost: $returnCosts['expected'],
            routeKey: $context['route_key'],
            routeLabel: $context['route_label'],
            priceSegment: $context['price_segment'],
            tariffSource: $context['tariff_source'],
            tariffEffectiveFrom: $context['tariff_effective_from'],
            isLocalSale: $context['is_local_sale'],
            nonLocalMarkupPercent: $context['non_local_markup_percent'],
            salesFeePercent: $context['commission_rate'],
            agentFee: 0,
            integrationFee: 0,
            returnLogistics: $returnCosts['logistics'],
            returnProcessing: $returnCosts['processing'],
            routeResolutionStatus: $context['route_resolution_status'],
            localityResolutionStatus: $context['locality_resolution_status'],
            calculationConfidence: $context['calculation_confidence'],
            profileSource: $context['profile_source'],
            dominantClusterId: $context['dominant_cluster_id'],
            dominantClusterShare: $context['dominant_cluster_share'],
            expectedLocalityRate: $context['expected_locality_rate'],
            weightedNonLocalMarkupPercent: $context['weighted_non_local_markup_percent'],
            clustersSummary: $context['clusters_summary']
        );
    }

    /**
     * Рассчитать RFBS (realFBS Standard)
     */
    private function calculateRfbs(CalculationInput $input): UnitEconomicsResult
    {
        $price = $input->price;
        $context = $this->resolveMarketplaceContext('RFBS', $input, $input->getChargeableVolumeInLiters());
        $schemeCosts = $this->pricing->getSchemeCosts('RFBS');
        $agentFee = (float) ($schemeCosts['agent_fee'] ?? 0);

        // Своя доставка (из настроек пользователя)
        $ownDeliveryCost = $input->ownDeliveryCost ?? 0;
        $ownReturnCost = ($input->ownReturnCost ?? 0) > 0
            ? (float) $input->ownReturnCost
            : (float) $ownDeliveryCost;

        // Не доехавшие (100 − % выкупа) + пост-доставочные возвраты (/v1/returns/*),
        // которые постинги не видят. Без второго слагаемого при выкупе 100% возвраты терялись.
        $returnRate = $input->redemptionRate !== null ? max(0.0, (100 - $input->redemptionRate) / 100) : 0.0;
        $returnRate = min(1.0, $returnRate + $this->postDeliveryReturnsFraction($input));
        $expectedReturnCost = $ownReturnCost * $returnRate;

        return $this->buildResult(
            $input,
            commission: $context['commission'],
            commissionRate: $context['commission_rate'],
            acquiring: $context['acquiring'],
            acquiringRate: $context['acquiring_rate'],
            logistics: $ownDeliveryCost,
            baseLogistics: $ownDeliveryCost,
            lastMile: 0,
            processingFee: 0,
            expectedReturnCost: $expectedReturnCost,
            routeKey: $context['route_key'],
            routeLabel: $context['route_label'],
            priceSegment: $context['price_segment'],
            tariffSource: $context['tariff_source'],
            tariffEffectiveFrom: $context['tariff_effective_from'],
            isLocalSale: $context['is_local_sale'],
            nonLocalMarkupPercent: 0,
            salesFeePercent: $context['commission_rate'],
            agentFee: $agentFee,
            integrationFee: 0,
            returnLogistics: $ownReturnCost,
            returnProcessing: 0,
            routeResolutionStatus: $context['route_resolution_status'],
            localityResolutionStatus: $context['locality_resolution_status'],
            calculationConfidence: $context['calculation_confidence'],
            profileSource: $context['profile_source'],
            dominantClusterId: $context['dominant_cluster_id'],
            dominantClusterShare: $context['dominant_cluster_share'],
            expectedLocalityRate: $context['expected_locality_rate'],
            weightedNonLocalMarkupPercent: $context['weighted_non_local_markup_percent'],
            clustersSummary: $context['clusters_summary']
        );
    }

    /**
     * Рассчитать EXPRESS (realFBS Express)
     */
    private function calculateExpress(CalculationInput $input): UnitEconomicsResult
    {
        $price = $input->price;
        $context = $this->resolveMarketplaceContext('EXPRESS', $input, $input->getChargeableVolumeInLiters());
        $schemeCosts = $this->pricing->getSchemeCosts('EXPRESS');
        $agentFee = (float) ($schemeCosts['agent_fee'] ?? 0);
        $expressCompensation = $input->marketplaceCompensation ?? (float) ($schemeCosts['express_compensation'] ?? 0);

        // Своя доставка
        $ownDeliveryCost = $input->ownDeliveryCost ?? 0;
        $ownReturnCost = ($input->ownReturnCost ?? 0) > 0
            ? (float) $input->ownReturnCost
            : (float) $ownDeliveryCost;

        // Не доехавшие (100 − % выкупа) + пост-доставочные возвраты (/v1/returns/*),
        // которые постинги не видят. Без второго слагаемого при выкупе 100% возвраты терялись.
        $returnRate = $input->redemptionRate !== null ? max(0.0, (100 - $input->redemptionRate) / 100) : 0.0;
        $returnRate = min(1.0, $returnRate + $this->postDeliveryReturnsFraction($input));
        $expectedReturnCost = $ownReturnCost * $returnRate;

        return $this->buildResult(
            $input,
            commission: $context['commission'],
            commissionRate: $context['commission_rate'],
            acquiring: $context['acquiring'],
            acquiringRate: $context['acquiring_rate'],
            logistics: $ownDeliveryCost,
            baseLogistics: $ownDeliveryCost,
            lastMile: 0,
            processingFee: 0,
            expectedReturnCost: $expectedReturnCost,
            routeKey: $context['route_key'],
            routeLabel: $context['route_label'],
            priceSegment: $context['price_segment'],
            tariffSource: $context['tariff_source'],
            tariffEffectiveFrom: $context['tariff_effective_from'],
            isLocalSale: true,
            nonLocalMarkupPercent: 0,
            salesFeePercent: $context['commission_rate'],
            agentFee: $agentFee,
            integrationFee: -$expressCompensation,
            returnLogistics: $ownReturnCost,
            returnProcessing: 0,
            routeResolutionStatus: $context['route_resolution_status'],
            localityResolutionStatus: $context['locality_resolution_status'],
            calculationConfidence: $context['calculation_confidence'],
            profileSource: $context['profile_source'],
            dominantClusterId: $context['dominant_cluster_id'],
            dominantClusterShare: $context['dominant_cluster_share'],
            expectedLocalityRate: $context['expected_locality_rate'],
            weightedNonLocalMarkupPercent: $context['weighted_non_local_markup_percent'],
            clustersSummary: $context['clusters_summary']
        );
    }

    /**
     * Построить результат
     */
    private function buildResult(
        CalculationInput $input,
        float $commission,
        float $commissionRate,
        float $acquiring,
        float $acquiringRate,
        float $logistics,
        ?float $baseLogistics = null,
        float $lastMile = 0,
        float $processingFee = 0,
        float $expectedReturnCost = 0,
        ?string $routeKey = null,
        ?string $routeLabel = null,
        ?string $priceSegment = null,
        ?string $tariffSource = null,
        ?string $tariffEffectiveFrom = null,
        ?bool $isLocalSale = null,
        float $nonLocalMarkupPercent = 0,
        ?float $salesFeePercent = null,
        float $agentFee = 0,
        float $integrationFee = 0,
        float $returnLogistics = 0,
        float $returnProcessing = 0,
        ?string $routeResolutionStatus = null,
        ?string $localityResolutionStatus = null,
        ?string $calculationConfidence = null,
        ?string $profileSource = null,
        ?string $dominantClusterId = null,
        ?float $dominantClusterShare = null,
        ?float $expectedLocalityRate = null,
        ?float $weightedNonLocalMarkupPercent = null,
        array $clustersSummary = []
    ): UnitEconomicsResult {
        $price = $input->price;
        
        // Себестоимость
        $costPrice = $input->costPrice ?? 0;
        $packagingCost = $input->packagingCost ?? 0;
        $additionalCosts = $input->additionalCosts ?? 0;
        $storageCost = $input->storageCost ?? 0;
        $acceptanceCost = $input->acceptanceCost ?? 0;
        $penaltyCost = $input->penaltyCost ?? 0;

        // Стоимость доставки
        $deliveryCost = $logistics + $lastMile + $processingFee;

        // Разбивка расходов
        $costs = new CostBreakdown(
            commission: $commission,
            acquiring: $acquiring,
            logistics: $logistics,
            lastMile: $lastMile,
            processingFee: $processingFee,
            deliveryCost: $deliveryCost,
            storageCost: $storageCost,
            returnLogistics: $returnLogistics,
            returnProcessing: $returnProcessing,
            expectedReturnCost: $expectedReturnCost,
            costPrice: $costPrice,
            packagingCost: $packagingCost,
            additionalCosts: $additionalCosts,
            agentFee: $agentFee,
            integrationFee: $integrationFee,
            acceptanceCost: $acceptanceCost,
            penaltyCost: $penaltyCost,
            deliveryCoefficient: null,
            additionalPercent: null,
        );

        // Финансовые метрики
        $totalCosts = $costs->getTotalCosts();
        $netProfit = $price - $totalCosts;
        $marginPercent = $price > 0 ? ($netProfit / $price) * 100 : 0;
        $toSettlementAccount = $price - $costs->getMarketplaceCosts();
        $scenarioRange = $this->calculateScenarioRange($input, [
            'commission' => $commission,
            'acquiring' => $acquiring,
            'last_mile' => $lastMile,
            'processing_fee' => $processingFee,
            'storage_cost' => $storageCost,
            'cost_price' => $costPrice,
            'packaging_cost' => $packagingCost,
            'additional_costs' => $additionalCosts,
            'agent_fee' => $agentFee,
            'integration_fee' => $integrationFee,
            'acceptance_cost' => $acceptanceCost,
            'penalty_cost' => $penaltyCost,
        ]);

        $result = new UnitEconomicsResult(
            sku: $input->sku,
            marketplace: $this->getMarketplace(),
            fulfillmentType: $input->fulfillmentType,
            price: $price,
            costs: $costs,
            revenue: $price,
            totalCosts: $totalCosts,
            netProfit: $netProfit,
            marginPercent: $marginPercent,
            marginAbsolute: $netProfit,
            commissionPercent: $commissionRate,
            acquiringPercent: $acquiringRate,
            isProfitable: $netProfit > 0,
            hasCostPrice: $costPrice > 0,
            oldPrice: $input->oldPrice,
            isActualScheme: false,
            productName: $input->productName,
            calculatedAt: now()->toIso8601String(),
        );

        $result->metadata = [
            'base_logistics' => round($baseLogistics ?? $logistics, 2),
            'last_mile' => round($lastMile, 2),
            'processing_fee' => round($processingFee, 2),
            'storage_cost' => round($storageCost, 2),
            'return_logistics' => round($returnLogistics, 2),
            'return_processing' => round($returnProcessing, 2),
            'expected_return_cost' => round($expectedReturnCost, 2),
            'effective_logistics' => round($deliveryCost + $expectedReturnCost, 2),
            'acceptance_cost' => round($acceptanceCost, 2),
            'penalty_cost' => round($penaltyCost, 2),
            'to_settlement_account' => round($toSettlementAccount, 2),
            'own_delivery_cost' => round($input->ownDeliveryCost ?? 0, 2),
            'marketplace_compensation' => round($input->marketplaceCompensation ?? 0, 2),
            'route_key' => $routeKey,
            'route_label' => $routeLabel,
            'price_segment' => $priceSegment,
            'tariff_source' => $tariffSource,
            'tariff_effective_from' => $tariffEffectiveFrom,
            'tariff_version' => $input->tariffVersionUsed ?? $this->pricing->getVersion(),
            'markup_version_used' => $input->markupVersionUsed ?? $this->pricing->getVersion(),
            'is_local_sale' => $isLocalSale,
            'non_local_markup_percent' => round($nonLocalMarkupPercent, 2),
            'non_local_markup_amount' => round($input->price * ($nonLocalMarkupPercent / 100), 2),
            'sales_fee_percent' => round((float) ($salesFeePercent ?? $commissionRate), 2),
            'shipping_cluster_id' => $input->shippingClusterId,
            'shipping_cluster_name' => $input->shippingClusterName,
            'destination_cluster_id' => $input->destinationClusterId,
            'destination_cluster_name' => $input->destinationClusterName,
            'fixation_applied' => $input->fixationApplied,
            'fixation_id' => $input->fixationId,
            'fixation_base_date' => $input->fixationBaseDate,
            'fixed_until' => $input->fixedUntil,
            // Non-local markup exemptions (6 total):
            // 1. [IMPLEMENTED] local_cluster — shipping and destination clusters match (local sale)
            // 2. [IMPLEMENTED] cancelled_order — order was cancelled
            // 3. [IMPLEMENTED] not_redeemed — order was not picked up (STATUS_NOT_ACCEPTED)
            // 4. [IMPLEMENTED] fbo_lt_50_orders_7d — seller has fewer than 50 FBO orders in 7 days
            // 5. [NOT IMPLEMENTABLE] unavailable_ozon_reroute — Ozon shipped from non-local cluster
            //    when local stock was available (requires Ozon internal routing data, not available via API)
            // 6. [NOT IMPLEMENTABLE] unavailable_cluster_blocked — product cannot be placed in buyer's
            //    cluster warehouse (requires warehouse restriction data, not available via API)
            // 7. [NOT IMPLEMENTABLE] unavailable_select_only — product sold only on Select platform
            //    (requires Select platform detection, not available via current integration)
            'markup_applied' => $input->markupApplied,
            'markup_reason_code' => $input->markupReasonCode,
            'markup_reason_label' => $input->markupReasonLabel,
            'markup_exception_status' => $input->markupExceptionStatus,
            'calculation_mode' => $input->calculationMode,
            'route_resolution_status' => $routeResolutionStatus,
            'locality_resolution_status' => $localityResolutionStatus,
            'calculation_confidence' => $calculationConfidence,
            'profile_source' => $profileSource,
            'dominant_cluster_id' => $dominantClusterId,
            'dominant_cluster_share' => $dominantClusterShare !== null ? round($dominantClusterShare, 2) : null,
            'expected_locality_rate' => $expectedLocalityRate !== null ? round($expectedLocalityRate, 2) : null,
            'weighted_non_local_markup_percent' => $weightedNonLocalMarkupPercent !== null ? round($weightedNonLocalMarkupPercent, 2) : null,
            'chargeable_volume_liters' => round($input->getChargeableVolumeInLiters(), 4),
            'volume_weight' => $input->volumeWeight !== null ? round($input->volumeWeight, 4) : null,
            'profit_min' => round($scenarioRange['profit_min'], 2),
            'profit_base' => round($netProfit, 2),
            'profit_max' => round($scenarioRange['profit_max'], 2),
            'clusters_summary' => $clustersSummary,
        ];

        return $result;
     }

    /**
     * Рассчитать ожидаемые расходы на возвраты
     */
    private function calculateExpectedReturnCosts(string $scheme, float $baseLogistics, ?float $redemptionRate, ?CalculationInput $input = null): array
    {
        $schemeCosts = $this->pricing->getSchemeCosts($scheme);
        $returnLogistics = $baseLogistics;
        $returnProcessing = (float) ($schemeCosts['return_processing'] ?? 0);

        // Две независимые доли заказов, которым нужна обратная логистика:
        //
        // 1) «Не доехавшие» (отмены + невыкупы) — уже зашиты в % выкупа, который
        //    меряем по постингам (виджет Ozon «Выкупы»). Их доля = (100 − выкуп).
        //    hasReturnRisk отсекает мусорные SKU (например, единственный
        //    отменённый заказ без реальных продаж).
        // 2) Пост-доставочные возвраты (/v1/returns/*) — заказ дошёл (delivered),
        //    выкуп их НЕ видит (см. OzonPostingsBuyoutCalculator), поэтому в % выкупа
        //    их нет. Складываем отдельно по returns_count / orders_count. Без этого
        //    при выкупе 100% реальные возвраты не попадали в эффективную логистику
        //    вообще.
        $nonRedeemedFraction = 0.0;
        if ($redemptionRate !== null && $redemptionRate < 100 && $this->hasReturnRisk($input)) {
            $nonRedeemedFraction = (100 - $redemptionRate) / 100;
        }

        $returnsFraction = $this->postDeliveryReturnsFraction($input);

        $totalReturnFraction = min(1.0, $nonRedeemedFraction + $returnsFraction);

        if ($totalReturnFraction <= 0.0) {
            return [
                'expected' => 0.0,
                'logistics' => 0.0,
                'processing' => 0.0,
            ];
        }

        return [
            'expected' => ($returnLogistics + $returnProcessing) * $totalReturnFraction,
            'logistics' => $returnLogistics,
            'processing' => $returnProcessing,
        ];
    }

    /**
     * Доля пост-доставочных возвратов: returns_count / orders_count.
     *
     * Постинги такие возвраты не видят — заказ остаётся в статусе delivered и
     * считается выкупленным, поэтому в % выкупа они отсутствуют. Возвраты
     * приходят отдельным API (/v1/returns/*) и складываются с долей
     * «не доехавших» (100 − % выкупа).
     */
    private function postDeliveryReturnsFraction(?CalculationInput $input): float
    {
        if ($input === null) {
            return 0.0;
        }

        $orders = $input->ordersCount;
        $returns = $input->returnsCount;

        if ($orders === null || $orders <= 0 || $returns === null || $returns <= 0) {
            return 0.0;
        }

        return min(1.0, $returns / $orders);
    }

    private function hasReturnRisk(?CalculationInput $input): bool
    {
        if ($input === null) {
            return true;
        }

        if ($input->redemptionSource === 'no_sales_28d') {
            return true;
        }

        if ($input->ordersCount !== null && $input->ordersCount <= 0) {
            return false;
        }

        $cancelled = max(0, (int) ($input->cancelledCount ?? 0));
        if ($input->ordersCount !== null && $cancelled >= $input->ordersCount) {
            return false;
        }

        return true;
    }

    private function hasRealizedSaleForMarkup(CalculationInput $input): bool
    {
        if ($input->redemptionSource === 'no_sales_28d') {
            return false;
        }

        if ($input->ordersCount !== null && $input->ordersCount <= 0) {
            return false;
        }

        $delivered = $input->deliveredCount;
        $inFlight = $input->inFlightCount;
        if ($delivered !== null || $inFlight !== null) {
            return max(0, (int) $delivered) + max(0, (int) $inFlight) > 0;
        }

        $cancelled = max(0, (int) ($input->cancelledCount ?? 0));
        if ($input->ordersCount !== null && $cancelled >= $input->ordersCount) {
            return false;
        }

        return true;
    }

    private function resolveMarketplaceContext(string $scheme, CalculationInput $input, float $volume): array
    {
        $hasNoSales = $input->redemptionSource === 'no_sales_28d'
            || ($input->ordersCount !== null && $input->ordersCount <= 0);
        $commissionData = $this->pricing->resolveCommission($scheme, $input->categoryId, $input->price);
        $pricingDate = $input->orderDate ?? $input->tariffEffectiveFrom ?? $this->pricing->getEffectiveFrom();
        $hasExactClusters = ! $hasNoSales && ($input->shippingClusterName !== null || $input->destinationClusterName !== null);
        $clusterLogisticsData = $hasExactClusters
            ? $this->pricing->resolveClusterLogistics(
                $scheme,
                $volume,
                $input->price,
                $input->shippingClusterName,
                $input->destinationClusterName,
                $pricingDate
            )
            : null;
        $logisticsData = $clusterLogisticsData
            ?? $this->pricing->resolveLogistics(
                $scheme,
                $volume,
                $hasNoSales ? null : $input->routeKey,
                $hasNoSales ? null : $input->routeLabel
            );
        $schemeCosts = $this->pricing->getSchemeCosts($scheme);
        $commissionRate = $input->commissionRate ?? (float) $commissionData['sales_fee_percent'];
        $acquiringRate = $input->acquiringPercent ?? 1.5;
        $dominantClusterId = $input->dominantClusterId;
        $dominantClusterShare = $input->dominantClusterShare;
        $dominantClusterName = null;
        if (($dominantClusterId === null || $dominantClusterShare === null) && !empty($input->clustersSummary)) {
            $dominant = $this->resolveDominantCluster($input->clustersSummary);
            $dominantClusterId ??= $dominant['cluster_id'];
            $dominantClusterShare ??= $dominant['cluster_share'];
            $dominantClusterName = $dominant['cluster_name'];
        }

        $routeWasProvided = ! $hasNoSales && (!empty($input->routeKey) || !empty($input->routeLabel) || !empty($input->shippingClusterName) || !empty($input->destinationClusterName));
        $hasProfile = ! $hasNoSales && (!empty($input->clustersSummary) || !empty($dominantClusterId));
        $routeResolutionStatus = $hasNoSales ? 'unknown' : ($input->routeResolutionStatus ?? ($routeWasProvided ? 'resolved' : ($hasProfile ? 'estimated' : 'unknown')));
        $localityResolutionStatus = $hasNoSales ? 'unknown' : ($input->localityResolutionStatus ?? (($input->isLocalSale !== null || $input->expectedLocalityRate !== null) ? 'resolved' : ($hasProfile ? 'estimated' : 'unknown')));
        $calculationConfidence = $input->calculationConfidence ?? $this->resolveConfidence($dominantClusterShare, $routeResolutionStatus);
        $profileMetrics = $hasNoSales ? null : $this->resolveWeightedProfileMetrics($scheme, $input, $volume, $pricingDate);
        $expectedLocalityRate = $profileMetrics['expected_locality_rate'] ?? $input->expectedLocalityRate ?? null;
        if ($expectedLocalityRate === null && $input->isLocalSale !== null && $localityResolutionStatus === 'resolved') {
            $expectedLocalityRate = $input->isLocalSale ? 100.0 : 0.0;
        }

        // Локальная продажа определяется ТОЛЬКО по expected_locality_rate из weighted profile metrics.
        // НЕ используем $input->isLocalSale — он может содержать stale значение из stock_profile.
        $isFullyLocalByProfile = $expectedLocalityRate !== null && $expectedLocalityRate >= 99.99;
        // Приоритет: expected_locality_rate из weighted profile → route-based (без profile данных)
        if ($expectedLocalityRate !== null) {
            $isLocalSale = $isFullyLocalByProfile;
        } elseif ($profileMetrics === null) {
            // Нет profile данных — используем input или route config
            $isLocalSale = $input->isLocalSale ?? (bool) $logisticsData['is_local_sale'];
        } else {
            // Profile есть, но expected_locality не рассчитан — не локальная
            $isLocalSale = false;
        }
        // Источник истины — Σ(share × effective_markup_percent) из clusters_summary (вариант A).
        // Без профиля — используем явный override из input (например, repo_fallback / user),
        // иначе остаётся null и финальная наценка упадёт на configMarkupPercent/0 ниже.
        $weightedNonLocalMarkupPercent = $hasNoSales ? 0.0 : ($profileMetrics['weighted_markup_percent'] ?? $input->weightedNonLocalMarkupPercent ?? null);
        if ($input->markupApplied === false) {
            $weightedNonLocalMarkupPercent = 0.0;
        }
        $configMarkupPercent = (float) $logisticsData['non_local_markup_percent'];
        $nonLocalMarkupPercent = $isLocalSale === true
            ? 0.0
            : ($weightedNonLocalMarkupPercent ?? $input->nonLocalMarkupPercent ?? $configMarkupPercent);
        if ($input->markupApplied === false || ! $this->hasRealizedSaleForMarkup($input)) {
            $nonLocalMarkupPercent = 0.0;
        }
        $baseLogistics = $hasNoSales
            ? (float) $logisticsData['base_cost']
            : ($profileMetrics['base_logistics']
                ?? $input->weightedLogisticsCost
                ?? (float) $logisticsData['base_cost']);
        $nonLocalMarkupAmount = $input->price * ($nonLocalMarkupPercent / 100);
        $displayRouteKey = $routeResolutionStatus === 'resolved' ? ($logisticsData['route_key'] ?? null) : null;
        $displayRouteLabel = match ($routeResolutionStatus) {
            'resolved' => $input->shippingClusterName ?? $input->routeLabel ?? $profileMetrics['dominant_source_cluster'] ?? ($logisticsData['route_label'] ?? null),
            'estimated' => $dominantClusterName ?? ($dominantClusterId ? "Кластер {$dominantClusterId} (оценка)" : 'Оценка по истории'),
            default => null,
        };

        return [
            'commission_rate' => $commissionRate,
            'commission' => $input->price * ($commissionRate / 100),
            'acquiring_rate' => $acquiringRate,
            'acquiring' => $input->price * ($acquiringRate / 100),
            'base_logistics' => $baseLogistics,
            'logistics' => round($baseLogistics + $nonLocalMarkupAmount, 2),
            'last_mile' => (float) ($schemeCosts['last_mile'] ?? 0),
            'route_key' => $displayRouteKey,
            'route_label' => $displayRouteLabel,
            'price_segment' => $input->priceSegment ?? $commissionData['price_segment'],
            'tariff_source' => $input->tariffSource ?? (($logisticsData['tariff_source'] ?? null) === 'repo_fallback' || $commissionData['tariff_source'] === 'repo_fallback'
                ? 'repo_fallback'
                : (($clusterLogisticsData['tariff_source'] ?? null) === 'universal' ? 'universal' : 'official')),
            'tariff_effective_from' => $input->tariffEffectiveFrom ?? $this->pricing->getEffectiveFrom(),
            'is_local_sale' => $isLocalSale,
            'non_local_markup_percent' => $nonLocalMarkupPercent,
            'route_resolution_status' => $routeResolutionStatus,
            'locality_resolution_status' => $localityResolutionStatus,
            'calculation_confidence' => $calculationConfidence,
            'profile_source' => $input->profileSource ?? ($hasProfile ? 'delivery_analytics' : 'repo_fallback'),
            'dominant_cluster_id' => $dominantClusterId,
            'dominant_cluster_share' => $dominantClusterShare,
            'expected_locality_rate' => $expectedLocalityRate,
            'weighted_non_local_markup_percent' => $weightedNonLocalMarkupPercent ?? $nonLocalMarkupPercent,
            'clusters_summary' => $input->clustersSummary,
        ];
    }

    private function resolveWeightedProfileMetrics(string $scheme, CalculationInput $input, float $volume, string $pricingDate): ?array
    {
        // Используем наиболее полный источник данных о спросе.
        // clusters_summary (Delivery Analytics API) может содержать неполные данные,
        // а sales_profile (реальные отгрузки за 30 дней) отражает фактическое распределение.
        // Берём тот источник, где БОЛЬШЕ заказов — он статистически надёжнее.
        $demandClusters = $input->clustersSummary;
        if (!empty($input->salesProfile)) {
            $analyticsTotal = array_sum(array_column($demandClusters, 'orders_count'));
            $salesTotal = array_sum(array_column($input->salesProfile, 'sales_30_days'));
            if ($salesTotal > $analyticsTotal) {
                $demandClusters = array_map(fn(array $sp) => [
                    'cluster_id' => $sp['cluster_id'] ?? null,
                    'cluster_name' => $sp['cluster_name'] ?? null,
                    'orders_count' => (int) ($sp['sales_30_days'] ?? 0),
                    'orders_percent' => (float) ($sp['sales_share_percent'] ?? 0),
                ], $input->salesProfile);
            }
        }

        if (empty($demandClusters)) {
            return null;
        }

        $stockClusters = $this->normalizeStockClusters($input->stockProfile);
        $stockClusterNames = array_column($stockClusters, 'cluster_name');
        $fixedShippingCluster = $this->pricing->resolveClusterName($input->shippingClusterName);
        $dominantSourceCluster = $fixedShippingCluster
            ?? $stockClusters[0]['cluster_name']
            ?? $this->pricing->resolveClusterName($input->routeLabel);
        // Локальная продажа без фиксации допустима только когда весь доступный сток
        // сосредоточен в одном кластере. Иначе не считаем продажу полностью локальной.
        $singleStockCluster = count($stockClusterNames) === 1 ? $stockClusterNames[0] : null;
        $markupAllowed = $scheme === 'FBO'
            && ($input->sales7Days === null || $input->sales7Days >= 50);

        $weightedBaseLogistics = 0.0;
        $weightedMarkupPercent = 0.0;
        $localityShare = 0.0;
        $hasDemand = false;

        foreach ($demandClusters as $cluster) {
            $share = (float) ($cluster['orders_percent'] ?? 0);
            if ($share <= 0) {
                continue;
            }

            $hasDemand = true;
            $destinationCluster = $this->pricing->resolveClusterName(
                $cluster['cluster_name']
                ?? $cluster['route_label']
                ?? null
            );

            // Локальная продажа: если fixation задаёт кластер отгрузки — сравниваем с ним,
            // иначе — destination совпадает с любым кластером остатков
            $isLocalCluster = $destinationCluster !== null && (
                $fixedShippingCluster !== null
                    ? $destinationCluster === $fixedShippingCluster
                    : ($singleStockCluster !== null && $destinationCluster === $singleStockCluster)
            );

            if ($isLocalCluster) {
                $localityShare += $share;
            }

            $sourceCluster = $fixedShippingCluster
                ?? ($isLocalCluster ? $destinationCluster : ($dominantSourceCluster ?? $destinationCluster));

            $clusterLogistics = $this->pricing->resolveClusterLogistics(
                $scheme,
                $volume,
                $input->price,
                $sourceCluster,
                $destinationCluster,
                $pricingDate
            );

            $weightedBaseLogistics += ($share / 100) * (float) $clusterLogistics['base_cost'];

            // Источник истины — effective_markup_percent из обогащённого clusters_summary.
            // Если его нет (preview / legacy input) — вычисляем тем же правилом, что и Service.
            // ВАЖНО: даже если в кэше лежит ненулевой effective_markup_percent (с момента когда sales7Days был ≥50),
            // при текущем markupAllowed=false (sales упали ниже порога) принудительно обнуляем —
            // правило Ozon применяется на момент отгрузки, не на момент обогащения кэша.
            if ($isLocalCluster || ! $markupAllowed) {
                $clusterMarkup = 0.0;
            } else {
                $clusterMarkup = $this->pricing->resolveDestinationMarkupPercent($destinationCluster, $pricingDate);
            }

            $weightedMarkupPercent += ($share / 100) * $clusterMarkup;
        }

        if (! $hasDemand) {
            return null;
        }

        return [
            'base_logistics' => round($weightedBaseLogistics, 2),
            'weighted_markup_percent' => round($weightedMarkupPercent, 2),
            'expected_locality_rate' => round(min(100.0, $localityShare), 2),
            'dominant_source_cluster' => $dominantSourceCluster,
        ];
    }

    private function normalizeStockClusters(array $stockProfile): array
    {
        $normalized = [];

        foreach ($stockProfile as $cluster) {
            if (! is_array($cluster)) {
                continue;
            }

            $clusterName = $this->pricing->resolveClusterName($cluster['cluster_name'] ?? null);
            if ($clusterName === null) {
                continue;
            }

            $normalized[] = [
                'cluster_name' => $clusterName,
                'share_percent' => (float) ($cluster['share_percent'] ?? 0),
            ];
        }

        usort($normalized, static fn (array $left, array $right): int => ($right['share_percent'] <=> $left['share_percent']));

        return $normalized;
    }

    private function resolveDominantCluster(array $clustersSummary): array
    {
        $dominantClusterId = null;
        $dominantClusterName = null;
        $dominantClusterShare = null;

        foreach ($clustersSummary as $cluster) {
            $share = (float) ($cluster['orders_percent'] ?? $cluster['sales_percent'] ?? 0);
            if ($dominantClusterShare === null || $share > $dominantClusterShare) {
                $dominantClusterShare = $share;
                $dominantClusterId = (string) ($cluster['cluster_id'] ?? $cluster['delivery_cluster_id'] ?? '');
                $dominantClusterName = $cluster['cluster_name'] ?? $cluster['delivery_cluster_name'] ?? null;
            }
        }

        return [
            'cluster_id' => $dominantClusterId ?: null,
            'cluster_name' => $dominantClusterName,
            'cluster_share' => $dominantClusterShare,
        ];
    }

    private function resolveConfidence(?float $dominantClusterShare, string $routeResolutionStatus): string
    {
        if ($routeResolutionStatus === 'resolved') {
            return 'high';
        }

        if ($dominantClusterShare === null) {
            return 'low';
        }

        return match (true) {
            $dominantClusterShare >= 70 => 'high',
            $dominantClusterShare >= 45 => 'medium',
            default => 'low',
        };
    }

    private function calculateScenarioRange(CalculationInput $input, array $fixedCosts): array
    {
        $volume = $input->getChargeableVolumeInLiters();
        $scheme = strtoupper($input->fulfillmentType);
        $routes = $this->pricing->getConfig()['routes'] ?? [];
        $profits = [];

        foreach (array_keys($routes) as $routeKey) {
            $logisticsData = $this->pricing->resolveLogistics($scheme, $volume, $routeKey, null);
            $schemeCosts = $this->pricing->getSchemeCosts($scheme);
            $markupPercent = (float) ($logisticsData['is_local_sale'] ? 0 : ($logisticsData['non_local_markup_percent'] ?? 0));
            $logistics = (float) $logisticsData['base_cost'] + $input->price * ($markupPercent / 100);
            $returnCosts = $this->calculateExpectedReturnCosts($scheme, (float) $logisticsData['base_cost'], $input->redemptionRate, $input);

            $totalCosts = $fixedCosts['commission']
                + $fixedCosts['acquiring']
                + $logistics
                + (float) ($schemeCosts['last_mile'] ?? $fixedCosts['last_mile'] ?? 0)
                + ($fixedCosts['processing_fee'] ?? 0)
                + ($fixedCosts['storage_cost'] ?? 0)
                + ($returnCosts['expected'] ?? 0)
                + ($fixedCosts['cost_price'] ?? 0)
                + ($fixedCosts['packaging_cost'] ?? 0)
                + ($fixedCosts['additional_costs'] ?? 0)
                + ($fixedCosts['agent_fee'] ?? 0)
                + ($fixedCosts['acceptance_cost'] ?? 0)
                + ($fixedCosts['penalty_cost'] ?? 0)
                + ($fixedCosts['integration_fee'] ?? 0);

            $profits[] = $input->price - $totalCosts;
        }

        if (empty($profits)) {
            return [
                'profit_min' => $input->price - array_sum($fixedCosts),
                'profit_max' => $input->price - array_sum($fixedCosts),
            ];
        }

        return [
            'profit_min' => min($profits),
            'profit_max' => max($profits),
        ];
    }

    /**
     * Получить код маркетплейса
     */
    public function getMarketplace(): string
    {
        return 'ozon';
    }

    /**
     * Получить поддерживаемые схемы
     */
    public function getSupportedSchemes(): array
    {
        return ['FBO', 'FBS', 'RFBS', 'EXPRESS'];
    }
}
