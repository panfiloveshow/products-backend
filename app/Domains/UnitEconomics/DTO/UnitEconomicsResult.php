<?php

namespace App\Domains\UnitEconomics\DTO;

/**
 * Результат расчёта юнит-экономики
 */
class UnitEconomicsResult
{
    public function __construct(
        // Идентификаторы
        public readonly string $sku,
        public readonly string $marketplace,
        public readonly string $fulfillmentType,
        
        // Цены
        public readonly float $price,
        
        // Разбивка расходов
        public readonly CostBreakdown $costs,
        
        // Финансовые метрики
        public readonly float $revenue,           // Выручка (= цена)
        public readonly float $totalCosts,        // Все расходы
        public readonly float $netProfit,         // Чистая прибыль
        public readonly float $marginPercent,     // Маржа %
        public readonly float $marginAbsolute,    // Маржа ₽
        
        // Комиссии в процентах (для отображения)
        public readonly float $commissionPercent,
        public readonly float $acquiringPercent,
        
        // Флаги
        public readonly bool $isProfitable,
        public readonly bool $hasCostPrice,
        
        // Опциональные параметры (в конце)
        public readonly ?float $oldPrice = null,
        public readonly bool $isActualScheme = false,
        public readonly ?string $productName = null,
        public readonly ?string $calculatedAt = null,
        public array $metadata = [],
    ) {}

    /**
     * Рассчитать ROI (Return on Investment)
     */
    public function getRoi(): ?float
    {
        $productCosts = $this->costs->getProductCosts();
        if ($productCosts <= 0) {
            return null;
        }
        return ($this->netProfit / $productCosts) * 100;
    }

    /**
     * Конвертировать в массив
     */
    public function toArray(): array
    {
        return [
            // Идентификаторы
            'sku' => $this->sku,
            'marketplace' => $this->marketplace,
            'fulfillment_type' => $this->fulfillmentType,
            'product_name' => $this->productName,
            
            // Цены
            'price' => round($this->price, 2),
            'old_price' => $this->oldPrice ? round($this->oldPrice, 2) : null,
            
            // Финансовые метрики
            'revenue' => round($this->revenue, 2),
            'total_costs' => round($this->totalCosts, 2),
            'net_profit' => round($this->netProfit, 2),
            'margin_percent' => round($this->marginPercent, 2),
            'margin_absolute' => round($this->marginAbsolute, 2),
            'roi' => $this->getRoi() ? round($this->getRoi(), 2) : null,
            
            // Комиссии
            'commission_percent' => round($this->commissionPercent, 2),
            'acquiring_percent' => round($this->acquiringPercent, 2),
            
            // Разбивка расходов
            'costs' => $this->costs->toArray(),
            
            // Флаги
            'is_profitable' => $this->isProfitable,
            'has_cost_price' => $this->hasCostPrice,
            'is_actual_scheme' => $this->isActualScheme,
            
            // Мета
            'calculated_at' => $this->calculatedAt ?? now()->toIso8601String(),
            
            // Дополнительные поля маркетплейса
            ...$this->metadata,
        ];
    }

    /**
     * Конвертировать в JSON
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
    }
}
