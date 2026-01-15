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
        $acquiringRate = $this->commissions->getAcquiringRate();
        $acquiring = $price * ($acquiringRate / 100);

        // Логистика FBO
        $deliveryCoefficient = $input->deliveryCoefficient ?? 1.0;
        $additionalPercent = $this->getAdditionalPercent($price, $deliveryCoefficient);
        
        $logistics = $this->tariffs->calculateLogisticsCost('FBO', $volume, $input->weight, [
            'delivery_coefficient' => $deliveryCoefficient,
            'additional_percent' => $additionalPercent,
            'price' => $price,
        ]);

        // Последняя миля
        $lastMile = 25.0; // Максимум

        // Возвраты
        $expectedReturnCost = $this->calculateExpectedReturnCost('FBO', $volume, $input->redemptionRate);

        return $this->buildResult($input, $commission, $commissionRate, $acquiring, $acquiringRate, 
            $logistics, $lastMile, 0, $expectedReturnCost, $deliveryCoefficient, $additionalPercent);
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
        $acquiringRate = $this->commissions->getAcquiringRate();
        $acquiring = $price * ($acquiringRate / 100);

        // Логистика FBS (включает обработку)
        $logistics = $this->tariffs->calculateLogisticsCost('FBS', $volume, $input->weight);
        
        // Обработка отправления (20₽ базовая)
        $processingFee = 20.0;
        
        // Последняя миля
        $lastMile = 25.0;

        // Возвраты
        $expectedReturnCost = $this->calculateExpectedReturnCost('FBS', $volume, $input->redemptionRate);

        return $this->buildResult($input, $commission, $commissionRate, $acquiring, $acquiringRate,
            $logistics, $lastMile, $processingFee, $expectedReturnCost);
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
        $acquiringRate = $this->commissions->getAcquiringRate();
        $acquiring = $price * ($acquiringRate / 100);

        // Агентское вознаграждение
        $agentFee = $this->tariffs->getAgentFee('RFBS');

        // Своя доставка (из настроек пользователя)
        $ownDeliveryCost = 0; // Пользователь указывает сам

        return $this->buildResult($input, $commission, $commissionRate, $acquiring, $acquiringRate,
            $ownDeliveryCost, 0, 0, 0, null, null, $agentFee);
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
        $acquiringRate = $this->commissions->getAcquiringRate();
        $acquiring = $price * ($acquiringRate / 100);

        // Агентское вознаграждение
        $agentFee = $this->tariffs->getAgentFee('EXPRESS');
        
        // Возмещение за экспресс (своя служба)
        $expressCompensation = $this->tariffs->getExpressCompensation(); // -199₽ (возврат от Ozon)

        // Своя доставка
        $ownDeliveryCost = 0;

        return $this->buildResult($input, $commission, $commissionRate, $acquiring, $acquiringRate,
            $ownDeliveryCost, 0, 0, 0, null, null, $agentFee, -$expressCompensation);
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
        float $integrationFee = 0
    ): UnitEconomicsResult {
        $price = $input->price;
        
        // Себестоимость
        $costPrice = $input->costPrice ?? 0;
        $packagingCost = $input->packagingCost ?? 0;
        $additionalCosts = $input->additionalCosts ?? 0;

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
            storageCost: 0,
            returnLogistics: 0,
            returnProcessing: 0,
            expectedReturnCost: $expectedReturnCost,
            costPrice: $costPrice,
            packagingCost: $packagingCost,
            additionalCosts: $additionalCosts,
            agentFee: $agentFee,
            integrationFee: $integrationFee,
            deliveryCoefficient: $deliveryCoefficient,
            additionalPercent: $additionalPercent,
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
    private function calculateExpectedReturnCost(string $scheme, float $volume, ?float $redemptionRate): float
    {
        if ($redemptionRate === null || $redemptionRate >= 100) {
            return 0;
        }

        $returnLogistics = $this->tariffs->calculateReturnLogisticsCost($scheme, $volume);
        $returnRate = (100 - $redemptionRate) / 100;
        
        return $returnLogistics * $returnRate;
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
