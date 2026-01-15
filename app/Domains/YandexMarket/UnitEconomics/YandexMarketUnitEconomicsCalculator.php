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

        // Комиссия
        $commissionRate = $input->commissionRate 
            ?? $this->commissions->getCommissionRate($input->categoryId ?? 'default');
        $commission = $price * ($commissionRate / 100);

        // Эквайринг
        $acquiringRate = $this->commissions->getAcquiringRate();
        $acquiring = $price * ($acquiringRate / 100);

        // Логистика
        $logistics = $this->tariffs->calculateLogisticsCost(
            $input->fulfillmentType,
            $volume,
            $weight,
            ['own_delivery_cost' => 0]
        );

        // Обработка (только FBS)
        $processingFee = strtoupper($input->fulfillmentType) === 'FBS' ? 25.0 : 0;

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
            storageCost: 0,
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

        return new UnitEconomicsResult(
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
