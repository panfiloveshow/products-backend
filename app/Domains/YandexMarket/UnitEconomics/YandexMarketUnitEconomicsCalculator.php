<?php

namespace App\Domains\YandexMarket\UnitEconomics;

use App\Domains\UnitEconomics\Contracts\UnitEconomicsCalculatorInterface;
use App\Domains\UnitEconomics\DTO\CalculationInput;
use App\Domains\UnitEconomics\DTO\UnitEconomicsResult;
use App\Domains\UnitEconomics\DTO\CostBreakdown;
use App\Domains\YandexMarket\Tariffs\YandexMarketTariffs;
use App\Domains\YandexMarket\Tariffs\CommissionCalculator;

/**
 * Калькулятор юнит-экономики для Yandex Market
 * 
 * Схемы: FBY, FBS, DBS, EXPRESS
 */
class YandexMarketUnitEconomicsCalculator implements UnitEconomicsCalculatorInterface
{
    private YandexMarketTariffs $tariffs;
    private CommissionCalculator $commissions;

    public function __construct()
    {
        $this->tariffs = new YandexMarketTariffs();
        $this->commissions = new CommissionCalculator();
    }

    /**
     * Рассчитать юнит-экономику
     */
    public function calculate(CalculationInput $input): UnitEconomicsResult
    {
        $price = $input->price;
        $volume = $input->getVolumeInLiters();
        $weight = $input->weight;

        $tariffBreakdown = $this->normalizeTariffBreakdown($input->tariffBreakdown);

        $commissionRate = $input->commissionRate 
            ?? $this->commissions->getCommissionRate($input->categoryId ?? 'default');
        $commission = $tariffBreakdown['FEE'] ?? ($price * ($commissionRate / 100));
        if (isset($tariffBreakdown['FEE']) && $price > 0) {
            $commissionRate = ($commission / $price) * 100;
        }

        $acquiring = ($tariffBreakdown['AGENCY_COMMISSION'] ?? 0) + ($tariffBreakdown['PAYMENT_TRANSFER'] ?? 0);
        $acquiringRate = $price > 0
            ? (($acquiring / $price) * 100)
            : ($input->acquiringPercent ?? $this->commissions->getAcquiringRate());
        if ($acquiring <= 0) {
            $acquiringRate = $input->acquiringPercent ?? $this->commissions->getAcquiringRate();
            $acquiring = $price * ($acquiringRate / 100);
        }

        $logistics = ($tariffBreakdown['DELIVERY_TO_CUSTOMER'] ?? 0)
            + ($tariffBreakdown['CROSSREGIONAL_DELIVERY'] ?? 0)
            + ($tariffBreakdown['MIDDLE_MILE'] ?? 0)
            + ($tariffBreakdown['EXPRESS_DELIVERY'] ?? 0);
        if ($logistics <= 0) {
            $logistics = $this->tariffs->calculateLogisticsCost(
                $input->fulfillmentType,
                $volume,
                $weight,
                ['own_delivery_cost' => $input->ownDeliveryCost ?? 0]
            );
        }

        $processingFee = $tariffBreakdown['SORTING'] ?? 0;
        if ($processingFee <= 0) {
            $processingFee = strtoupper($input->fulfillmentType) === 'FBS' ? 25.0 : 0;
        }

        // Возвраты
        $expectedReturnCost = $this->calculateExpectedReturnCost(
            $input->fulfillmentType,
            $weight,
            $input->redemptionRate
        );

        // Себестоимость
        $costPrice = $input->costPrice ?? 0;
        $packagingCost = $input->packagingCost ?? 0;
        $additionalCosts = $input->additionalCosts ?? 0;
        $storageCost = $input->storageCost ?? 0;

        // Стоимость доставки
        $deliveryCost = $logistics + $processingFee;

        // Разбивка расходов
        $costs = new CostBreakdown(
            commission: $commission,
            acquiring: $acquiring,
            logistics: $logistics,
            lastMile: 0,
            processingFee: $processingFee,
            deliveryCost: $deliveryCost,
            storageCost: $storageCost,
            returnLogistics: 0,
            returnProcessing: 0,
            expectedReturnCost: $expectedReturnCost,
            costPrice: $costPrice,
            packagingCost: $packagingCost,
            additionalCosts: $additionalCosts,
        );

        // Финансовые метрики
        $totalCosts = $costs->getTotalCosts();
        $netProfit = $price - $totalCosts;
        $marginPercent = $price > 0 ? ($netProfit / $price) * 100 : 0;
        $toSettlementAccount = $price - $costs->getMarketplaceCosts();

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
            'fee' => round($commission, 2),
            'agency_commission' => round($tariffBreakdown['AGENCY_COMMISSION'] ?? 0, 2),
            'payment_transfer' => round($tariffBreakdown['PAYMENT_TRANSFER'] ?? 0, 2),
            'delivery_to_customer' => round($tariffBreakdown['DELIVERY_TO_CUSTOMER'] ?? 0, 2),
            'crossregional_delivery' => round($tariffBreakdown['CROSSREGIONAL_DELIVERY'] ?? 0, 2),
            'middle_mile' => round($tariffBreakdown['MIDDLE_MILE'] ?? 0, 2),
            'express_delivery' => round($tariffBreakdown['EXPRESS_DELIVERY'] ?? 0, 2),
            'sorting' => round($processingFee, 2),
            'storage_cost' => round($storageCost, 2),
            'to_settlement_account' => round($toSettlementAccount, 2),
            'own_delivery_cost' => round($input->ownDeliveryCost ?? 0, 2),
        ];

        return $result;
    }

    /**
     * Рассчитать ожидаемые расходы на возвраты
     */
    private function calculateExpectedReturnCost(string $scheme, float $weight, ?float $redemptionRate): float
    {
        if ($redemptionRate === null || $redemptionRate >= 100) {
            return 0;
        }

        $returnLogistics = $this->tariffs->calculateReturnLogisticsCost($scheme, $weight);
        $returnRate = (100 - $redemptionRate) / 100;
        
        return $returnLogistics * $returnRate;
    }

    private function normalizeTariffBreakdown(array $tariffBreakdown): array
    {
        $normalized = [];

        foreach ($tariffBreakdown as $item) {
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

    /**
     * Получить код маркетплейса
     */
    public function getMarketplace(): string
    {
        return 'yandex_market';
    }

    /**
     * Получить поддерживаемые схемы
     */
    public function getSupportedSchemes(): array
    {
        return ['FBY', 'FBS', 'DBS', 'EXPRESS'];
    }
}
