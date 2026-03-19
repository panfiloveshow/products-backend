<?php

namespace App\Domains\Ozon\UnitEconomics;

use App\Domains\UnitEconomics\Contracts\UnitEconomicsCalculatorInterface;
use App\Domains\UnitEconomics\DTO\CalculationInput;
use App\Domains\UnitEconomics\DTO\UnitEconomicsResult;
use App\Domains\UnitEconomics\DTO\CostBreakdown;
use App\Domains\Ozon\Tariffs\OzonTariffs;
use App\Domains\Ozon\Tariffs\CommissionCalculator;

/**
 * Калькулятор юнит-экономики для Ozon
 * 
 * Поддерживает 4 схемы: FBO, FBS, RFBS, EXPRESS
 */
class OzonUnitEconomicsCalculator implements UnitEconomicsCalculatorInterface
{
    private OzonTariffs $tariffs;
    private CommissionCalculator $commissions;

    public function __construct()
    {
        $this->tariffs = new OzonTariffs();
        $this->commissions = new CommissionCalculator();
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
        $volume = $input->getVolumeInLiters();

        // Комиссия
        $commissionRate = $input->commissionRate 
            ?? $this->commissions->getCommissionRate($input->categoryId ?? 'default');
        $commission = $price * ($commissionRate / 100);

        // Эквайринг
        $acquiringRate = $input->acquiringPercent ?? $this->commissions->getAcquiringRate();
        $acquiring = $price * ($acquiringRate / 100);

        // Логистика FBO
        $deliveryCoefficient = $input->deliveryCoefficient ?? 1.0;
        $additionalPercent = $input->additionalCommissionPercent ?? $this->getAdditionalPercent($price, $deliveryCoefficient);
        
        $logistics = $this->tariffs->calculateLogisticsCost('FBO', $volume, $input->weight, [
            'delivery_coefficient' => $deliveryCoefficient,
            'additional_percent' => $additionalPercent,
            'price' => $price,
        ]);

        // Последняя миля
        $lastMile = $this->tariffs->getLastMileCost('FBO');

        $returnCosts = $this->calculateExpectedReturnCosts('FBO', $volume, $input->redemptionRate);

        return $this->buildResult($input, $commission, $commissionRate, $acquiring, $acquiringRate, 
            $logistics, $lastMile, 0, $returnCosts['expected'], $deliveryCoefficient, $additionalPercent, 0, 0,
            $returnCosts['logistics'], $returnCosts['processing']);
    }

    /**
     * Рассчитать FBS
     */
    private function calculateFbs(CalculationInput $input): UnitEconomicsResult
    {
        $price = $input->price;
        $volume = $input->getVolumeInLiters();

        // Комиссия
        $commissionRate = $input->commissionRate 
            ?? $this->commissions->getCommissionRate($input->categoryId ?? 'default');
        $commission = $price * ($commissionRate / 100);

        // Эквайринг
        $acquiringRate = $input->acquiringPercent ?? $this->commissions->getAcquiringRate();
        $acquiring = $price * ($acquiringRate / 100);

        // Логистика FBS
        $logistics = $this->tariffs->calculateLogisticsCost('FBS', $volume, $input->weight);
        
        $processingFee = $this->tariffs->getProcessingFee('FBS', $volume);
        
        $lastMile = $this->tariffs->getLastMileCost('FBS');

        $returnCosts = $this->calculateExpectedReturnCosts('FBS', $volume, $input->redemptionRate);

        return $this->buildResult($input, $commission, $commissionRate, $acquiring, $acquiringRate,
            $logistics, $lastMile, $processingFee, $returnCosts['expected'], null, null, 0, 0,
            $returnCosts['logistics'], $returnCosts['processing']);
    }

    /**
     * Рассчитать RFBS (realFBS Standard)
     */
    private function calculateRfbs(CalculationInput $input): UnitEconomicsResult
    {
        $price = $input->price;

        // Комиссия
        $commissionRate = $input->commissionRate 
            ?? $this->commissions->getCommissionRate($input->categoryId ?? 'default');
        $commission = $price * ($commissionRate / 100);

        // Эквайринг
        $acquiringRate = $input->acquiringPercent ?? $this->commissions->getAcquiringRate();
        $acquiring = $price * ($acquiringRate / 100);

        // Агентское вознаграждение
        $agentFee = $this->tariffs->getAgentFee('RFBS');

        // Своя доставка (из настроек пользователя)
        $ownDeliveryCost = $input->ownDeliveryCost ?? 0;

        $returnRate = $input->redemptionRate !== null ? max(0, (100 - $input->redemptionRate) / 100) : 0;
        $expectedReturnCost = ($input->ownReturnCost ?? 0) * $returnRate;

        return $this->buildResult($input, $commission, $commissionRate, $acquiring, $acquiringRate,
            $ownDeliveryCost, 0, 0, $expectedReturnCost, null, null, $agentFee, 0, 0, 0);
    }

    /**
     * Рассчитать EXPRESS (realFBS Express)
     */
    private function calculateExpress(CalculationInput $input): UnitEconomicsResult
    {
        $price = $input->price;

        // Комиссия
        $commissionRate = $input->commissionRate 
            ?? $this->commissions->getCommissionRate($input->categoryId ?? 'default');
        $commission = $price * ($commissionRate / 100);

        // Эквайринг
        $acquiringRate = $input->acquiringPercent ?? $this->commissions->getAcquiringRate();
        $acquiring = $price * ($acquiringRate / 100);

        // Агентское вознаграждение
        $agentFee = $this->tariffs->getAgentFee('EXPRESS');
        
        // Возмещение за экспресс (своя служба)
        $expressCompensation = $input->marketplaceCompensation ?? $this->tariffs->getExpressCompensation();

        // Своя доставка
        $ownDeliveryCost = $input->ownDeliveryCost ?? 0;

        $returnRate = $input->redemptionRate !== null ? max(0, (100 - $input->redemptionRate) / 100) : 0;
        $expectedReturnCost = ($input->ownReturnCost ?? 0) * $returnRate;

        return $this->buildResult($input, $commission, $commissionRate, $acquiring, $acquiringRate,
            $ownDeliveryCost, 0, 0, $expectedReturnCost, null, null, $agentFee, -$expressCompensation, 0, 0);
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
        float $lastMile = 0,
        float $processingFee = 0,
        float $expectedReturnCost = 0,
        ?float $deliveryCoefficient = null,
        ?float $additionalPercent = null,
        float $agentFee = 0,
        float $integrationFee = 0,
        float $returnLogistics = 0,
        float $returnProcessing = 0
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
            deliveryCoefficient: $deliveryCoefficient,
            additionalPercent: $additionalPercent,
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
            'base_logistics' => round($logistics, 2),
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
        ];

        return $result;
     }

    /**
     * Рассчитать ожидаемые расходы на возвраты
     */
    private function calculateExpectedReturnCosts(string $scheme, float $volume, ?float $redemptionRate): array
    {
        if ($redemptionRate === null || $redemptionRate >= 100) {
            return [
                'expected' => 0.0,
                'logistics' => 0.0,
                'processing' => 0.0,
            ];
        }

        $returnLogistics = $this->tariffs->calculateReturnLogisticsCost($scheme, $volume);
        $returnProcessing = $this->tariffs->getReturnProcessingFee($scheme);
        $returnRate = (100 - $redemptionRate) / 100;

        return [
            'expected' => ($returnLogistics + $returnProcessing) * $returnRate,
            'logistics' => $returnLogistics,
            'processing' => $returnProcessing,
        ];
    }

    /**
     * Получить дополнительный % от цены для FBO
     * Зависит от коэффициента времени доставки
     */
    private function getAdditionalPercent(float $price, float $coefficient): float
    {
        // Для дешёвых товаров или высокого коэффициента
        if ($coefficient >= 1.4) {
            return 2.0;
        } elseif ($coefficient >= 1.2) {
            return 1.0;
        }
        return 0;
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
