<?php

namespace App\Domains\Supplies\DTO;

/**
 * DTO для входных данных расчёта поставки
 */
class SupplyCalculationInput
{
    public function __construct(
        public readonly string $sku,
        public readonly int $currentStock,
        public readonly float $avgDailySales,
        public readonly int $targetDaysOfStock = 30,
        public readonly int $safetyStockDays = 7,
        public readonly int $leadTimeDays = 5,
        public readonly int $inTransit = 0,
        public readonly ?float $costPrice = null,
        public readonly ?float $volumePerUnit = null,
        public readonly ?float $weightPerUnit = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            sku: $data['sku'],
            currentStock: (int) ($data['current_stock'] ?? $data['quantity'] ?? 0),
            avgDailySales: (float) ($data['avg_daily_sales'] ?? $data['average_daily_sales'] ?? 0),
            targetDaysOfStock: (int) ($data['target_days_of_stock'] ?? 30),
            safetyStockDays: (int) ($data['safety_stock_days'] ?? 7),
            leadTimeDays: (int) ($data['lead_time_days'] ?? 5),
            inTransit: (int) ($data['in_transit'] ?? 0),
            costPrice: isset($data['cost_price']) ? (float) $data['cost_price'] : null,
            volumePerUnit: isset($data['volume_per_unit']) ? (float) $data['volume_per_unit'] : null,
            weightPerUnit: isset($data['weight_per_unit']) ? (float) $data['weight_per_unit'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'sku' => $this->sku,
            'current_stock' => $this->currentStock,
            'avg_daily_sales' => $this->avgDailySales,
            'target_days_of_stock' => $this->targetDaysOfStock,
            'safety_stock_days' => $this->safetyStockDays,
            'lead_time_days' => $this->leadTimeDays,
            'in_transit' => $this->inTransit,
            'cost_price' => $this->costPrice,
            'volume_per_unit' => $this->volumePerUnit,
            'weight_per_unit' => $this->weightPerUnit,
        ];
    }
}
