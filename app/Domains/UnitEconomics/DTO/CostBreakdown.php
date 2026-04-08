<?php

namespace App\Domains\UnitEconomics\DTO;

/**
 * Разбивка расходов по статьям
 */
class CostBreakdown
{
    public function __construct(
        // Комиссии
        public readonly float $commission = 0,           // Комиссия маркетплейса
        public readonly float $acquiring = 0,            // Эквайринг
        
        // Логистика
        public readonly float $logistics = 0,            // Итоговая логистика (включает нелокальную наценку если применимо)
        public readonly float $lastMile = 0,             // Последняя миля
        public readonly float $processingFee = 0,        // Обработка отправления (FBS)
        public readonly float $deliveryCost = 0,         // Итого стоимость доставки
        
        // Хранение
        public readonly float $storageCost = 0,          // Хранение на складе
        
        // Возвраты
        public readonly float $returnLogistics = 0,      // Обратная логистика
        public readonly float $returnProcessing = 0,     // Обработка возврата
        public readonly float $expectedReturnCost = 0,   // Ожидаемые расходы на возвраты
        
        // Себестоимость
        public readonly float $costPrice = 0,            // Себестоимость товара
        public readonly float $packagingCost = 0,        // Упаковка
        public readonly float $additionalCosts = 0,      // Прочие расходы
        
        // Агентские (для realFBS)
        public readonly float $agentFee = 0,             // Агентское вознаграждение
        public readonly float $integrationFee = 0,       // Сервисный сбор за интеграцию
        public readonly float $acceptanceCost = 0,       // Приёмка
        public readonly float $penaltyCost = 0,          // Штрафы
        
        // Коэффициенты (для отображения)
        public readonly ?float $deliveryCoefficient = null,  // Коэффициент времени доставки
        public readonly ?float $additionalPercent = null,    // Доп. % от цены (Ozon FBO)
    ) {}

    /**
     * Получить общие расходы маркетплейса (комиссии + логистика)
     */
    public function getMarketplaceCosts(): float
    {
        return $this->commission 
            + $this->acquiring 
            + $this->deliveryCost
            + $this->storageCost
            + $this->expectedReturnCost
            + $this->agentFee
            + $this->integrationFee
            + $this->acceptanceCost
            + $this->penaltyCost;
    }

    /**
     * Получить общие расходы на товар (себестоимость + упаковка)
     */
    public function getProductCosts(): float
    {
        return $this->costPrice + $this->packagingCost + $this->additionalCosts;
    }

    /**
     * Получить все расходы
     */
    public function getTotalCosts(): float
    {
        return $this->getMarketplaceCosts() + $this->getProductCosts();
    }

    /**
     * Конвертировать в массив
     */
    public function toArray(): array
    {
        return [
            // Комиссии
            'commission' => round($this->commission, 2),
            'acquiring' => round($this->acquiring, 2),
            
            // Логистика
            'logistics' => round($this->logistics, 2),
            'last_mile' => round($this->lastMile, 2),
            'processing_fee' => round($this->processingFee, 2),
            'delivery_cost' => round($this->deliveryCost, 2),
            
            // Хранение
            'storage_cost' => round($this->storageCost, 2),
            
            // Возвраты
            'return_logistics' => round($this->returnLogistics, 2),
            'return_processing' => round($this->returnProcessing, 2),
            'expected_return_cost' => round($this->expectedReturnCost, 2),
            
            // Себестоимость
            'cost_price' => round($this->costPrice, 2),
            'packaging_cost' => round($this->packagingCost, 2),
            'additional_costs' => round($this->additionalCosts, 2),
            
            // Агентские
            'agent_fee' => round($this->agentFee, 2),
            'integration_fee' => round($this->integrationFee, 2),
            'acceptance_cost' => round($this->acceptanceCost, 2),
            'penalty_cost' => round($this->penaltyCost, 2),
            
            // Коэффициенты
            'delivery_coefficient' => $this->deliveryCoefficient,
            'additional_percent' => $this->additionalPercent,
            
            // Итоги
            'marketplace_costs' => round($this->getMarketplaceCosts(), 2),
            'product_costs' => round($this->getProductCosts(), 2),
            'total_costs' => round($this->getTotalCosts(), 2),
        ];
    }
}
