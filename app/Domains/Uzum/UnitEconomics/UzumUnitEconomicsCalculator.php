<?php

namespace App\Domains\Uzum\UnitEconomics;

use App\Domains\UnitEconomics\Contracts\UnitEconomicsCalculatorInterface;
use App\Domains\UnitEconomics\DTO\CalculationInput;
use App\Domains\UnitEconomics\DTO\CostBreakdown;
use App\Domains\UnitEconomics\DTO\UnitEconomicsResult;

/**
 * Калькулятор юнит-экономики Uzum Market.
 *
 * Phase 1: комиссия, себестоимость, хранение и %выкупа приходят готовыми из
 * Uzum API (SkuForTable) и кладутся в CalculationInput. Логистика и эквайринг —
 * настраиваемые константы (config services.uzum), пока их нет per-SKU в API.
 *
 * Схемы: FBS, FBO, DBS. Хранение учитываем только для FBO.
 */
class UzumUnitEconomicsCalculator implements UnitEconomicsCalculatorInterface
{
    public function getMarketplace(): string
    {
        return 'uzum';
    }

    public function getSupportedSchemes(): array
    {
        return ['FBS', 'FBO', 'DBS'];
    }

    public function calculate(CalculationInput $input): UnitEconomicsResult
    {
        $price = $input->price;
        $scheme = strtoupper($input->fulfillmentType ?: 'FBS');

        $commissionRate = $input->commissionRate ?? 0.0;
        $commission = $price * ($commissionRate / 100);

        $acquiringRate = $input->acquiringPercent ?? (float) config('services.uzum.acquiring_percent', 0);
        $acquiring = $price * ($acquiringRate / 100);

        // Логистика — Phase-1 константа из конфига по схеме.
        // ponytail: апгрейд-путь — реальная логистика из /v1/finance/expenses (Phase 2).
        $logistics = $input->ownDeliveryCost ?? $this->logisticsForScheme($scheme);

        // Хранение учитываем только для FBO (склад Uzum); для FBS/DBS хранит продавец.
        $storageCost = $scheme === 'FBO' ? (float) ($input->storageCost ?? 0) : 0.0;

        // Возвраты: %выкупа из API (100 − returnedPercentage).
        $redemptionRate = $input->redemptionRate ?? 100;
        $returnRate = $redemptionRate >= 100 ? 0.0 : (100 - $redemptionRate) / 100;
        $expectedReturnCost = $logistics * $returnRate;

        $costPrice = $input->costPrice ?? 0.0;
        $packagingCost = $input->packagingCost ?? 0.0;
        $additionalCosts = $input->additionalCosts ?? 0.0;

        $deliveryCost = $logistics;

        $costs = new CostBreakdown(
            commission: $commission,
            acquiring: $acquiring,
            logistics: $logistics,
            lastMile: 0,
            processingFee: 0,
            deliveryCost: $deliveryCost,
            storageCost: $storageCost,
            returnLogistics: $logistics,
            returnProcessing: 0,
            expectedReturnCost: $expectedReturnCost,
            costPrice: $costPrice,
            packagingCost: $packagingCost,
            additionalCosts: $additionalCosts,
        );

        $totalCosts = $costs->getTotalCosts();
        $netProfit = $price - $totalCosts;
        $marginPercent = $price > 0 ? ($netProfit / $price) * 100 : 0;
        $toSettlementAccount = $price - $costs->getMarketplaceCosts();

        $result = new UnitEconomicsResult(
            sku: $input->sku,
            marketplace: $this->getMarketplace(),
            fulfillmentType: $scheme,
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
            'commission' => round($commission, 2),
            'acquiring' => round($acquiring, 2),
            'logistics_cost' => round($logistics, 2),
            'storage_cost' => round($storageCost, 2),
            'redemption_rate' => round($redemptionRate, 2),
            'return_logistics_cost' => round($logistics, 2),
            'expected_return_cost' => round($expectedReturnCost, 2),
            'effective_logistics' => round($deliveryCost + $expectedReturnCost, 2),
            'to_settlement_account' => round($toSettlementAccount, 2),
        ];

        return $result;
    }

    private function logisticsForScheme(string $scheme): float
    {
        return (float) match ($scheme) {
            'FBO' => config('services.uzum.logistics_fbo', 0),
            'DBS' => config('services.uzum.logistics_dbs', 0),
            default => config('services.uzum.logistics_fbs', 0),
        };
    }
}
