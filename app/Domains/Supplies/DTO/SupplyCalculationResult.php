<?php

namespace App\Domains\Supplies\DTO;

/**
 * DTO для результата расчёта поставки
 */
class SupplyCalculationResult
{
    public function __construct(
        public readonly string $sku,
        public readonly int $optimalQuantity,
        public readonly int $reorderPoint,
        public readonly int $safetyStock,
        public readonly int $daysOfStock,
        public readonly string $priority,
        public readonly ?float $totalCost = null,
        public readonly ?float $totalVolume = null,
        public readonly ?float $totalWeight = null,
        public readonly ?string $reason = null,
        public readonly bool $needsReorder = false,
    ) {}

    public static function fromCalculation(
        string $sku,
        int $optimalQuantity,
        int $reorderPoint,
        int $safetyStock,
        int $daysOfStock,
        ?float $costPrice = null,
        ?float $volumePerUnit = null,
        ?float $weightPerUnit = null,
    ): self {
        $priority = self::calculatePriority($daysOfStock);
        $needsReorder = $daysOfStock <= $reorderPoint;
        
        $reason = match (true) {
            $daysOfStock <= 0 => 'Товар закончился на складе',
            $daysOfStock <= 7 => 'Критически низкий остаток (менее 7 дней)',
            $daysOfStock <= 14 => 'Низкий остаток (менее 14 дней)',
            $needsReorder => 'Достигнута точка заказа',
            default => null,
        };

        return new self(
            sku: $sku,
            optimalQuantity: max(0, $optimalQuantity),
            reorderPoint: $reorderPoint,
            safetyStock: $safetyStock,
            daysOfStock: $daysOfStock,
            priority: $priority,
            totalCost: $costPrice ? $optimalQuantity * $costPrice : null,
            totalVolume: $volumePerUnit ? $optimalQuantity * $volumePerUnit : null,
            totalWeight: $weightPerUnit ? $optimalQuantity * $weightPerUnit : null,
            reason: $reason,
            needsReorder: $needsReorder,
        );
    }

    private static function calculatePriority(int $daysOfStock): string
    {
        return match (true) {
            $daysOfStock <= 0 => 'urgent',
            $daysOfStock <= 7 => 'urgent',
            $daysOfStock <= 14 => 'high',
            $daysOfStock <= 21 => 'medium',
            default => 'low',
        };
    }

    public function toArray(): array
    {
        return [
            'sku' => $this->sku,
            'optimal_quantity' => $this->optimalQuantity,
            'reorder_point' => $this->reorderPoint,
            'safety_stock' => $this->safetyStock,
            'days_of_stock' => $this->daysOfStock,
            'priority' => $this->priority,
            'total_cost' => $this->totalCost,
            'total_volume' => $this->totalVolume,
            'total_weight' => $this->totalWeight,
            'reason' => $this->reason,
            'needs_reorder' => $this->needsReorder,
        ];
    }
}
