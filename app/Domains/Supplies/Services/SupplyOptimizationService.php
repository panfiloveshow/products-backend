<?php

namespace App\Domains\Supplies\Services;

use App\Domains\Marketplace\MarketplaceFactory;
use App\Models\Integration;
use App\Models\InventoryWarehouse;
use Illuminate\Support\Collection;

/**
 * Сервис оптимизации поставок
 * 
 * Функции:
 * - Подбор оптимального склада (по КС для WB)
 * - Расчёт загрузки транспорта
 * - Оптимизация состава поставки
 */
class SupplyOptimizationService
{
    /**
     * Типы транспорта с характеристиками
     */
    private const TRUCK_TYPES = [
        'gazelle' => [
            'name' => 'Газель',
            'volume' => 9.0,      // м³
            'weight' => 1500,     // кг
            'cost_base' => 3000,  // руб
            'cost_per_km' => 25,  // руб/км
        ],
        'gazelle_long' => [
            'name' => 'Газель-Long',
            'volume' => 16.0,
            'weight' => 1500,
            'cost_base' => 4000,
            'cost_per_km' => 30,
        ],
        'bychok' => [
            'name' => 'Бычок',
            'volume' => 22.0,
            'weight' => 3000,
            'cost_base' => 5500,
            'cost_per_km' => 35,
        ],
        'fura_5t' => [
            'name' => 'Фура 5т',
            'volume' => 36.0,
            'weight' => 5000,
            'cost_base' => 8000,
            'cost_per_km' => 45,
        ],
        'fura_10t' => [
            'name' => 'Фура 10т',
            'volume' => 54.0,
            'weight' => 10000,
            'cost_base' => 12000,
            'cost_per_km' => 55,
        ],
        'fura_20t' => [
            'name' => 'Фура 20т',
            'volume' => 82.0,
            'weight' => 20000,
            'cost_base' => 18000,
            'cost_per_km' => 70,
        ],
    ];

    public function __construct(
        private SupplyCalculationService $calculationService
    ) {}

    /**
     * Подобрать оптимальный транспорт
     */
    public function selectOptimalTruck(float $totalVolume, float $totalWeight): array
    {
        $suitable = [];
        
        foreach (self::TRUCK_TYPES as $key => $truck) {
            if ($truck['volume'] >= $totalVolume && $truck['weight'] >= $totalWeight) {
                $volumeUtilization = ($totalVolume / $truck['volume']) * 100;
                $weightUtilization = ($totalWeight / $truck['weight']) * 100;
                $utilization = max($volumeUtilization, $weightUtilization);
                
                $suitable[] = [
                    'key' => $key,
                    'name' => $truck['name'],
                    'volume_capacity' => $truck['volume'],
                    'weight_capacity' => $truck['weight'],
                    'volume_utilization' => round($volumeUtilization, 1),
                    'weight_utilization' => round($weightUtilization, 1),
                    'utilization' => round($utilization, 1),
                    'cost_base' => $truck['cost_base'],
                    'cost_per_km' => $truck['cost_per_km'],
                    'is_optimal' => false,
                ];
            }
        }
        
        if (empty($suitable)) {
            // Если ничего не подходит — нужно несколько машин
            return $this->calculateMultipleTrucks($totalVolume, $totalWeight);
        }
        
        // Сортируем по загрузке (чем выше — тем лучше)
        usort($suitable, fn($a, $b) => $b['utilization'] <=> $a['utilization']);
        
        // Оптимальный — с максимальной загрузкой >= 70%
        foreach ($suitable as &$truck) {
            if ($truck['utilization'] >= 70) {
                $truck['is_optimal'] = true;
                break;
            }
        }
        
        // Если нет с загрузкой >= 70%, берём минимальный подходящий
        if (!collect($suitable)->contains('is_optimal', true)) {
            $suitable[count($suitable) - 1]['is_optimal'] = true;
        }
        
        return $suitable;
    }

    /**
     * Расчёт нескольких машин если одной не хватает
     */
    private function calculateMultipleTrucks(float $totalVolume, float $totalWeight): array
    {
        $largestTruck = self::TRUCK_TYPES['fura_20t'];
        
        $trucksByVolume = ceil($totalVolume / $largestTruck['volume']);
        $trucksByWeight = ceil($totalWeight / $largestTruck['weight']);
        $trucksNeeded = max($trucksByVolume, $trucksByWeight);
        
        return [[
            'key' => 'fura_20t',
            'name' => $largestTruck['name'],
            'trucks_needed' => (int) $trucksNeeded,
            'volume_capacity' => $largestTruck['volume'] * $trucksNeeded,
            'weight_capacity' => $largestTruck['weight'] * $trucksNeeded,
            'volume_utilization' => round(($totalVolume / ($largestTruck['volume'] * $trucksNeeded)) * 100, 1),
            'weight_utilization' => round(($totalWeight / ($largestTruck['weight'] * $trucksNeeded)) * 100, 1),
            'cost_base' => $largestTruck['cost_base'] * $trucksNeeded,
            'is_optimal' => true,
            'is_multiple' => true,
        ]];
    }

    /**
     * Найти оптимальный склад для поставки (по КС для WB)
     */
    public function findOptimalWarehouse(Integration $integration): ?array
    {
        $marketplace = MarketplaceFactory::create(
            $integration->marketplace,
            $integration->getDecryptedCredentials(),
            $integration
        );
        
        // Для WB — используем коэффициенты складов
        if ($integration->marketplace === 'wildberries') {
            if (!method_exists($marketplace, 'getWarehouseCoefficients')) {
                return null;
            }
            
            $coefficients = $marketplace->getWarehouseCoefficients();
            
            if (empty($coefficients)) {
                return null;
            }
            
            // Сортируем по коэффициенту (меньше = лучше)
            uasort($coefficients, fn($a, $b) => 
                ($a['delivery_coef'] ?? 999) <=> ($b['delivery_coef'] ?? 999)
            );
            
            $optimal = reset($coefficients);
            $optimalId = key($coefficients);
            
            return [
                'warehouse_id' => $optimalId,
                'warehouse_name' => $optimal['warehouse_name'] ?? null,
                'coefficient' => $optimal['delivery_coef'] ?? null,
                'reason' => 'Минимальный коэффициент приёмки',
                'all_warehouses' => array_slice($coefficients, 0, 10, true),
            ];
        }
        
        // Для Ozon — просто возвращаем список складов
        if ($integration->marketplace === 'ozon') {
            if (!method_exists($marketplace, 'getWarehouses')) {
                return null;
            }
            
            $warehouses = $marketplace->getWarehouses();
            
            if (empty($warehouses)) {
                return null;
            }
            
            $first = reset($warehouses);
            
            return [
                'warehouse_id' => $first['warehouse_id'] ?? $first['id'] ?? null,
                'warehouse_name' => $first['name'] ?? null,
                'reason' => 'Основной склад Ozon',
                'all_warehouses' => $warehouses,
            ];
        }
        
        return null;
    }

    /**
     * Рассчитать экономию при выборе склада с низким КС
     */
    public function calculateWarehouseSavings(
        Integration $integration,
        string $currentWarehouseId,
        string $optimalWarehouseId,
        float $totalVolume
    ): ?array {
        if ($integration->marketplace !== 'wildberries') {
            return null;
        }
        
        $marketplace = MarketplaceFactory::create(
            $integration->marketplace,
            $integration->getDecryptedCredentials(),
            $integration
        );
        
        if (!method_exists($marketplace, 'getWarehouseCoefficients')) {
            return null;
        }
        
        $coefficients = $marketplace->getWarehouseCoefficients();
        
        $currentCoef = $coefficients[$currentWarehouseId]['delivery_coef'] ?? null;
        $optimalCoef = $coefficients[$optimalWarehouseId]['delivery_coef'] ?? null;
        
        if ($currentCoef === null || $optimalCoef === null) {
            return null;
        }
        
        // Базовый тариф логистики ~50 руб/л
        $baseLogisticsCost = 50;
        
        $currentCost = $totalVolume * $baseLogisticsCost * $currentCoef;
        $optimalCost = $totalVolume * $baseLogisticsCost * $optimalCoef;
        $savings = $currentCost - $optimalCost;
        
        return [
            'current_warehouse_id' => $currentWarehouseId,
            'current_coefficient' => $currentCoef,
            'current_cost' => round($currentCost, 2),
            'optimal_warehouse_id' => $optimalWarehouseId,
            'optimal_coefficient' => $optimalCoef,
            'optimal_cost' => round($optimalCost, 2),
            'savings' => round($savings, 2),
            'savings_percent' => $currentCost > 0 
                ? round(($savings / $currentCost) * 100, 1) 
                : 0,
        ];
    }

    /**
     * Оптимизировать состав поставки для максимальной загрузки
     */
    public function optimizeShipmentComposition(
        Collection $items,
        float $maxVolume,
        float $maxWeight
    ): array {
        // Сортируем по приоритету (urgent первые)
        $sorted = $items->sortBy(function ($item) {
            return match ($item['priority'] ?? $item->priority ?? 'low') {
                'urgent' => 0,
                'high' => 1,
                'medium' => 2,
                'low' => 3,
                default => 4,
            };
        });
        
        $included = [];
        $excluded = [];
        $currentVolume = 0;
        $currentWeight = 0;
        
        foreach ($sorted as $item) {
            $itemVolume = $item['total_volume'] ?? $item->totalVolume ?? 0;
            $itemWeight = $item['total_weight'] ?? $item->totalWeight ?? 0;
            
            if (($currentVolume + $itemVolume) <= $maxVolume && 
                ($currentWeight + $itemWeight) <= $maxWeight) {
                $included[] = $item;
                $currentVolume += $itemVolume;
                $currentWeight += $itemWeight;
            } else {
                $excluded[] = $item;
            }
        }
        
        return [
            'included' => $included,
            'excluded' => $excluded,
            'total_volume' => round($currentVolume, 3),
            'total_weight' => round($currentWeight, 3),
            'volume_utilization' => $maxVolume > 0 
                ? round(($currentVolume / $maxVolume) * 100, 1) 
                : 0,
            'weight_utilization' => $maxWeight > 0 
                ? round(($currentWeight / $maxWeight) * 100, 1) 
                : 0,
        ];
    }

    /**
     * Получить типы транспорта
     */
    public function getTruckTypes(): array
    {
        return self::TRUCK_TYPES;
    }
}
