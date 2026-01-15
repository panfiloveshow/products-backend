<?php

namespace App\Domains\Supplies\Services;

use App\Domains\Supplies\DTO\SupplyCalculationInput;
use App\Domains\Supplies\DTO\SupplyCalculationResult;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Сервис расчёта оптимального количества для поставки
 * 
 * Формула:
 * OptimalQuantity = (AvgDailySales × TargetDays) + SafetyStock - CurrentStock - InTransit
 * 
 * Где:
 * - AvgDailySales = средние продажи в день (из InventoryWarehouse)
 * - TargetDays = целевой запас в днях (настраиваемо, default 30)
 * - SafetyStock = страховой запас = AvgDailySales × SafetyDays
 * - CurrentStock = текущий остаток на складе
 * - InTransit = товары в пути
 */
class SupplyCalculationService
{
    /**
     * Рассчитать оптимальное количество для одного SKU
     */
    public function calculate(SupplyCalculationInput $input): SupplyCalculationResult
    {
        $avgDailySales = $input->avgDailySales;
        
        // Страховой запас = продажи за N дней
        $safetyStock = (int) ceil($avgDailySales * $input->safetyStockDays);
        
        // Точка заказа = продажи за время доставки + страховой запас
        $reorderPoint = (int) ceil($avgDailySales * $input->leadTimeDays) + $safetyStock;
        
        // Целевой запас = продажи за целевой период
        $targetStock = (int) ceil($avgDailySales * $input->targetDaysOfStock);
        
        // Оптимальное количество заказа
        $optimalQuantity = $targetStock + $safetyStock - $input->currentStock - $input->inTransit;
        
        // Дней запаса при текущих остатках
        $daysOfStock = $avgDailySales > 0 
            ? (int) floor($input->currentStock / $avgDailySales) 
            : ($input->currentStock > 0 ? 999 : 0);

        return SupplyCalculationResult::fromCalculation(
            sku: $input->sku,
            optimalQuantity: $optimalQuantity,
            reorderPoint: $reorderPoint,
            safetyStock: $safetyStock,
            daysOfStock: $daysOfStock,
            costPrice: $input->costPrice,
            volumePerUnit: $input->volumePerUnit,
            weightPerUnit: $input->weightPerUnit,
        );
    }

    /**
     * Рассчитать для всех товаров интеграции
     * 
     * @param int $integrationId ID интеграции
     * @param array $settings Настройки расчёта
     * @return Collection<SupplyCalculationResult>
     */
    public function calculateForIntegration(int $integrationId, array $settings = []): Collection
    {
        $targetDays = $settings['target_days_of_stock'] ?? 30;
        $safetyDays = $settings['safety_stock_days'] ?? 7;
        $leadTimeDays = $settings['lead_time_days'] ?? 5;
        
        // Получаем все товары интеграции с остатками
        $warehouses = InventoryWarehouse::where('integration_id', $integrationId)
            ->with('product')
            ->get();
        
        // Группируем по SKU (суммируем остатки со всех складов)
        $groupedBySku = $warehouses->groupBy('sku');
        
        $results = collect();
        
        foreach ($groupedBySku as $sku => $warehouseItems) {
            $totalStock = $warehouseItems->sum('quantity');
            $totalInTransit = $warehouseItems->sum('in_transit');
            $avgDailySales = $warehouseItems->max('average_daily_sales') ?? 0;
            
            $product = $warehouseItems->first()?->product;
            
            $input = new SupplyCalculationInput(
                sku: $sku,
                currentStock: $totalStock,
                avgDailySales: $avgDailySales,
                targetDaysOfStock: $targetDays,
                safetyStockDays: $safetyDays,
                leadTimeDays: $leadTimeDays,
                inTransit: $totalInTransit,
                costPrice: $product?->unitEconomics()->latest()->value('cost_price'),
                volumePerUnit: $product?->volume_weight,
                weightPerUnit: $product?->weight ? $product->weight / 1000 : null,
            );
            
            $result = $this->calculate($input);
            
            // Добавляем только если нужен заказ
            if ($result->needsReorder && $result->optimalQuantity > 0) {
                $results->push($result);
            }
        }
        
        // Сортируем по приоритету
        return $results->sortBy(function ($result) {
            return match ($result->priority) {
                'urgent' => 0,
                'high' => 1,
                'medium' => 2,
                'low' => 3,
                default => 4,
            };
        })->values();
    }

    /**
     * Рассчитать для конкретного склада маркетплейса
     */
    public function calculateForWarehouse(
        int $integrationId,
        string $warehouseId,
        array $settings = []
    ): Collection {
        $targetDays = $settings['target_days_of_stock'] ?? 30;
        $safetyDays = $settings['safety_stock_days'] ?? 7;
        $leadTimeDays = $settings['lead_time_days'] ?? 5;
        
        $warehouses = InventoryWarehouse::where('integration_id', $integrationId)
            ->where('warehouse_id', $warehouseId)
            ->with('product')
            ->get();
        
        $results = collect();
        
        foreach ($warehouses as $wh) {
            $product = $wh->product;
            
            $input = new SupplyCalculationInput(
                sku: $wh->sku,
                currentStock: $wh->quantity,
                avgDailySales: $wh->average_daily_sales ?? 0,
                targetDaysOfStock: $wh->target_days_of_stock ?? $targetDays,
                safetyStockDays: $wh->safety_stock_days ?? $safetyDays,
                leadTimeDays: $wh->lead_time_days ?? $leadTimeDays,
                inTransit: $wh->in_transit ?? 0,
                costPrice: $product?->unitEconomics()->latest()->value('cost_price'),
                volumePerUnit: $product?->volume_weight,
                weightPerUnit: $product?->weight ? $product->weight / 1000 : null,
            );
            
            $result = $this->calculate($input);
            
            if ($result->needsReorder && $result->optimalQuantity > 0) {
                $results->push($result);
            }
        }
        
        return $results->sortBy(fn($r) => match ($r->priority) {
            'urgent' => 0, 'high' => 1, 'medium' => 2, default => 3,
        })->values();
    }

    /**
     * Получить критические товары (требуют срочной поставки)
     */
    public function getCriticalItems(int $integrationId): Collection
    {
        return $this->calculateForIntegration($integrationId)
            ->filter(fn($r) => in_array($r->priority, ['urgent', 'high']));
    }

    /**
     * Рассчитать общую стоимость поставки
     */
    public function calculateTotalCost(Collection $results): array
    {
        return [
            'total_items' => $results->count(),
            'total_quantity' => $results->sum('optimalQuantity'),
            'total_cost' => $results->sum('totalCost'),
            'total_volume' => $results->sum('totalVolume'),
            'total_weight' => $results->sum('totalWeight'),
            'by_priority' => [
                'urgent' => $results->where('priority', 'urgent')->count(),
                'high' => $results->where('priority', 'high')->count(),
                'medium' => $results->where('priority', 'medium')->count(),
                'low' => $results->where('priority', 'low')->count(),
            ],
        ];
    }
}
