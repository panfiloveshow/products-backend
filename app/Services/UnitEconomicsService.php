<?php

namespace App\Services;

use App\Models\UnitEconomics;
use Illuminate\Support\Facades\Cache;

class UnitEconomicsService
{
    public function calculate(string $marketplace, array $data): array
    {
        if ($marketplace === 'yandex') {
            $marketplace = 'yandex_market';
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
        $markupPercent = $costPrice > 0 ? ($netProfit / ($costPrice * $salesCount)) * 100 : 0;
        $roiPercent = $totalCosts > 0 ? ($netProfit / $totalCosts) * 100 : 0;
        $toSettlementAccount = $calculator['details']['to_settlement_account']
            ?? ($revenue - $calculator['total_fees']);

        return array_merge([
            'revenue' => round($revenue, 2),
            'total_costs' => round($totalCosts, 2),
            'gross_profit' => round($grossProfit, 2),
            'net_profit' => round($netProfit, 2),
            'margin_percent' => round($marginPercent, 2),
            'markup_percent' => round($markupPercent, 2),
            'roi_percent' => round($roiPercent, 2),
            'to_settlement_account' => round($toSettlementAccount, 2),
            'drr_percent' => round($drrPercent, 2),
            'drr_amount' => round($drrAmount, 2),
            'our_share_percent' => round($ourSharePercent, 2),
            'our_share_amount' => round($ourShareAmount, 2),
            'tax_percent' => round($taxPercent, 2),
            'tax_amount' => round($taxAmount, 2),
            'vat_percent' => round($vatPercent, 2),
            'vat_amount' => round($vatAmount, 2),
            'advertising_cost' => round($advertisingCost, 2),
        ], $calculator['details']);
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
        $baseLogisticsPerUnit = $volumeLiters <= 1
            ? $deliveryBaseLiter
            : $deliveryBaseLiter + max(0, $volumeLiters - 1) * $deliveryAdditionalLiter;
        $ownDeliveryCost = (float) ($data['own_delivery_cost'] ?? 0);
        $ownReturnCost = (float) ($data['own_return_cost'] ?? 0);
        $logisticsPerUnit = in_array($fulfillmentType, ['DBS', 'EDBS'], true)
            ? $ownDeliveryCost
            : ($data['logistics_cost'] ?? ($baseLogisticsPerUnit * $warehouseCoefficient * $localizationIndex));
        $logisticsCost = $logisticsPerUnit * $salesCount;
        $acceptanceCost = $data['acceptance_cost'] ?? 0;
        $penaltyCost = $data['penalty_cost'] ?? 0;
        $returnLogisticsPerUnit = in_array($fulfillmentType, ['DBS', 'EDBS'], true)
            ? $ownReturnCost
            : (float) ($data['return_logistics_cost'] ?? $baseLogisticsPerUnit);
        $returnLogisticsCost = $returnLogisticsPerUnit * $salesCount;
        $redemptionRate = (float) ($data['redemption_rate'] ?? 80);
        $expectedReturnCost = $returnLogisticsCost * ((100 - $redemptionRate) / 100);

        $sppRub = ($price * $sppPercent / 100) * $salesCount;

        $ksPercent = $data['ks_percent'] ?? 0;
        $ksRub = ($price * $ksPercent / 100) * $salesCount;

        $totalFees = $commissionAmount + $storageCost + $logisticsCost + 
                     $acceptanceCost + $penaltyCost + $expectedReturnCost + $ksRub;

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

    private function calculateOzon(array $data): array
    {
        $price = $data['price'];
        $salesCount = $data['sales_count'] ?? 1;
        $fulfillmentType = strtoupper((string) ($data['fulfillment_type'] ?? 'FBO'));

        $commissionPercent = $data['commission_percent'] ?? match ($fulfillmentType) {
            'FBO' => ($data['fbo_commission_percent'] ?? 15),
            default => ($data['fbs_commission_percent'] ?? 12),
        };
        $commissionAmount = ($price * $commissionPercent / 100) * $salesCount;

        $volumeLiters = (float) ($data['volume_liters'] ?? 0);
        $storageCost = (float) ($data['storage_cost'] ?? 0);
        $ownDeliveryCost = (float) ($data['own_delivery_cost'] ?? 0);
        $ozonCompensation = (float) ($data['ozon_compensation'] ?? 0);

        $avgDeliveryTimeHours = (int) ($data['avg_delivery_time_hours'] ?? 29);
        $logisticsCoefficient = $fulfillmentType === 'FBO'
            ? (float) ($data['localization_index'] ?? $data['logistics_coefficient'] ?? $this->getOzonDeliveryCoefficient($avgDeliveryTimeHours))
            : 1.0;
        $additionalCommissionPercent = $fulfillmentType === 'FBO'
            ? (float) ($data['localization_additional_percent'] ?? $data['additional_commission_percent'] ?? $this->getOzonAdditionalPercent($avgDeliveryTimeHours))
            : 0.0;

        $baseLogisticsPerUnit = $this->getOzonBaseLogistics($fulfillmentType, $volumeLiters);
        $logisticsPerUnit = match ($fulfillmentType) {
            'RFBS', 'EXPRESS' => $ownDeliveryCost,
            'FBO' => ($baseLogisticsPerUnit * $logisticsCoefficient) + ($price * $additionalCommissionPercent / 100),
            default => $data['logistics_cost'] ?? $baseLogisticsPerUnit,
        };
        $logisticsCost = $logisticsPerUnit * $salesCount;

        $lastMilePerUnit = in_array($fulfillmentType, ['FBO', 'FBS'], true)
            ? (float) ($data['last_mile_cost'] ?? 40)
            : 0.0;
        $lastMileCost = $lastMilePerUnit * $salesCount;

        $processingPerUnit = $fulfillmentType === 'FBS'
            ? (float) ($data['processing_cost'] ?? 20)
            : 0.0;
        $processingCost = $processingPerUnit * $salesCount;

        $returnPerUnit = in_array($fulfillmentType, ['RFBS', 'EXPRESS'], true)
            ? (float) ($data['own_return_cost'] ?? 0)
            : (float) ($data['return_cost'] ?? $data['return_logistics_cost'] ?? 0);
        $redemptionRate = (float) ($data['redemption_rate'] ?? 100);
        $expectedReturnCost = ($returnPerUnit * $salesCount) * ((100 - $redemptionRate) / 100);

        $acquiringPercent = $data['acquiring_percent'] ?? 1.5;
        $acquiringAmount = ($price * $acquiringPercent / 100) * $salesCount;

        $packagingCost = ($data['packaging_cost'] ?? 5) * $salesCount;

        $agentFee = in_array($fulfillmentType, ['RFBS', 'EXPRESS'], true) ? (float) ($data['agent_fee'] ?? 20 * $salesCount) : 0;
        $additionalCommissionAmount = ($price * $additionalCommissionPercent / 100) * $salesCount;

        $totalFees = $commissionAmount
            + $logisticsCost
            + $lastMileCost
            + $processingCost
            + $expectedReturnCost
            + $storageCost
            + $acquiringAmount
            + $packagingCost
            + $agentFee
            - $ozonCompensation;

        $toSettlementAccount = $revenue = ($price * $salesCount) - $totalFees;

        return [
            'total_fees' => $totalFees,
            'details' => [
                'fulfillment_type' => $fulfillmentType,
                'commission_percent' => round($commissionPercent, 2),
                'fbo_commission_percent' => $data['fbo_commission_percent'] ?? 15,
                'fbs_commission_percent' => $data['fbs_commission_percent'] ?? 12,
                'commission_amount' => round($commissionAmount, 2),
                'avg_delivery_time_hours' => $avgDeliveryTimeHours,
                'logistics_coefficient' => round($logisticsCoefficient, 2),
                'additional_commission_percent' => round($additionalCommissionPercent, 2),
                'additional_commission_amount' => round($additionalCommissionAmount, 2),
                'base_logistics_cost' => round($baseLogisticsPerUnit, 2),
                'logistics_cost' => round($logisticsCost, 2),
                'last_mile_cost' => round($lastMileCost, 2),
                'processing_cost' => round($processingCost, 2),
                'return_cost' => round($returnPerUnit * $salesCount, 2),
                'expected_return_cost' => round($expectedReturnCost, 2),
                'effective_logistics' => round($logisticsCost + $lastMileCost + $processingCost + $expectedReturnCost, 2),
                'storage_cost' => round($storageCost, 2),
                'acquiring_percent' => $acquiringPercent,
                'acquiring_amount' => round($acquiringAmount, 2),
                'packaging_cost' => round($packagingCost, 2),
                'own_delivery_cost' => round($ownDeliveryCost, 2),
                'ozon_compensation' => round($ozonCompensation, 2),
                'agent_fee' => round($agentFee, 2),
                'to_settlement_account' => round($toSettlementAccount, 2),
            ],
        ];
    }

    private function calculateYandex(array $data): array
    {
        $price = $data['price'];
        $salesCount = $data['sales_count'] ?? 1;
        $fulfillmentType = strtoupper((string) ($data['fulfillment_type'] ?? 'FBY'));
        $tariffBreakdown = $this->normalizeYandexTariffBreakdown($data['tariff_breakdown'] ?? []);

        $referralFeePercent = $data['referral_fee_percent'] ?? 5;
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
        $totalFees = $referralFeeAmount + $acquiringAmount + $logisticsTotal + $storageCost;
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
                'to_settlement_account' => round($toSettlementAccount, 2),
            ],
        ];
    }

    private function getOzonDeliveryCoefficient(int $avgDeliveryTimeHours): float
    {
        return match (true) {
            $avgDeliveryTimeHours >= 38 => 1.44,
            $avgDeliveryTimeHours >= 34 => 1.34,
            $avgDeliveryTimeHours >= 30 => 1.24,
            default => 1.00,
        };
    }

    private function getOzonAdditionalPercent(int $avgDeliveryTimeHours): float
    {
        return match (true) {
            $avgDeliveryTimeHours >= 38 => 2.2,
            $avgDeliveryTimeHours >= 34 => 1.4,
            $avgDeliveryTimeHours >= 30 => 0.7,
            default => 0.0,
        };
    }

    private function getOzonBaseLogistics(string $fulfillmentType, float $volumeLiters): float
    {
        $volumeLiters = max(0.0, $volumeLiters);

        return match (strtoupper($fulfillmentType)) {
            'FBO' => $volumeLiters <= 1
                ? 46.77
                : 46.77 + (max(0, ceil($volumeLiters) - 1) * 10.17),
            'FBS' => match (true) {
                $volumeLiters <= 1 => 81.34,
                $volumeLiters <= 2 => 99.64,
                $volumeLiters <= 3 => 117.94,
                default => 117.94 + (max(0, $volumeLiters - 3) * 23.39),
            },
            default => 0.0,
        };
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
        $calculated = $this->calculate($data['marketplace'], $data);

        return UnitEconomics::updateOrCreate(
            [
                'sku' => $data['sku'],
                'marketplace' => $data['marketplace'],
                'period_start' => $data['period_start'] ?? now()->startOfMonth()->toDateString(),
                'period_end' => $data['period_end'] ?? now()->endOfMonth()->toDateString(),
            ],
            [
                'integration_id' => $data['integration_id'] ?? null,
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
                    'last_mile' => ['base' => 40],
                    'storage' => ['per_liter_per_day' => 0.4],
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
        ]);

        return [
            'synced' => $exitCode === 0 ? 1 : 0,
            'errors' => $exitCode !== 0 ? 1 : 0,
        ];
    }
}
