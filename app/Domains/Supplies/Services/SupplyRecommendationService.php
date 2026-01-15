<?php

namespace App\Domains\Supplies\Services;

use App\Models\Integration;
use App\Models\InventoryWarehouse;
use App\Models\SupplyRecommendation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Сервис генерации рекомендаций по поставкам
 */
class SupplyRecommendationService
{
    public function __construct(
        private SupplyCalculationService $calculationService,
        private SupplyOptimizationService $optimizationService
    ) {}

    /**
     * Сгенерировать рекомендации для интеграции
     */
    public function generateForIntegration(Integration $integration): Collection
    {
        $calculations = $this->calculationService->calculateForIntegration($integration->id);
        
        if ($calculations->isEmpty()) {
            return collect();
        }
        
        // Группируем по складам
        $byWarehouse = $this->groupByWarehouse($integration->id, $calculations);
        
        $recommendations = collect();
        
        foreach ($byWarehouse as $warehouseId => $items) {
            $recommendation = $this->createRecommendation($integration, $warehouseId, $items);
            if ($recommendation) {
                $recommendations->push($recommendation);
            }
        }
        
        return $recommendations;
    }

    /**
     * Группировка товаров по складам
     */
    private function groupByWarehouse(int $integrationId, Collection $calculations): array
    {
        $result = [];
        
        foreach ($calculations as $calc) {
            // Находим склады для этого SKU
            $warehouses = InventoryWarehouse::where('integration_id', $integrationId)
                ->where('sku', $calc->sku)
                ->get();
            
            foreach ($warehouses as $wh) {
                $warehouseId = $wh->warehouse_id ?? 'default';
                
                if (!isset($result[$warehouseId])) {
                    $result[$warehouseId] = [
                        'warehouse_name' => $wh->warehouse_name,
                        'items' => [],
                    ];
                }
                
                $result[$warehouseId]['items'][] = $calc;
            }
        }
        
        return $result;
    }

    /**
     * Создать рекомендацию для склада
     */
    private function createRecommendation(
        Integration $integration,
        string $warehouseId,
        array $warehouseData
    ): ?SupplyRecommendation {
        $items = collect($warehouseData['items']);
        
        if ($items->isEmpty()) {
            return null;
        }
        
        // Критические товары (urgent + high)
        $criticalItems = $items->filter(fn($i) => in_array($i->priority, ['urgent', 'high']));
        
        // Определяем приоритет рекомендации
        $priority = match (true) {
            $criticalItems->contains(fn($i) => $i->priority === 'urgent') => SupplyRecommendation::PRIORITY_URGENT,
            $criticalItems->isNotEmpty() => SupplyRecommendation::PRIORITY_HIGH,
            default => SupplyRecommendation::PRIORITY_MEDIUM,
        };
        
        // Формируем заголовок
        $title = match ($priority) {
            SupplyRecommendation::PRIORITY_URGENT => "Срочная поставка: {$criticalItems->count()} критических товаров",
            SupplyRecommendation::PRIORITY_HIGH => "Рекомендуемая поставка: {$items->count()} товаров",
            default => "Плановая поставка: {$items->count()} товаров",
        };
        
        // Расчёт итогов
        $totals = $this->calculationService->calculateTotalCost($items);
        
        // Формируем массивы для JSON
        $criticalItemsArray = $criticalItems->map(fn($i) => [
            'sku' => $i->sku,
            'days_of_stock' => $i->daysOfStock,
            'recommended_quantity' => $i->optimalQuantity,
            'estimated_cost' => $i->totalCost,
            'priority' => $i->priority,
            'reason' => $i->reason,
        ])->values()->toArray();
        
        $recommendedItemsArray = $items->map(fn($i) => [
            'sku' => $i->sku,
            'quantity' => $i->optimalQuantity,
            'cost_price' => $i->totalCost ? ($i->totalCost / max(1, $i->optimalQuantity)) : null,
            'total_cost' => $i->totalCost,
            'volume_per_unit' => $i->totalVolume ? ($i->totalVolume / max(1, $i->optimalQuantity)) : null,
            'weight_per_unit' => $i->totalWeight ? ($i->totalWeight / max(1, $i->optimalQuantity)) : null,
            'priority' => $i->priority,
        ])->values()->toArray();
        
        // Определяем дедлайн
        $deadline = null;
        $minDaysOfStock = $criticalItems->min('daysOfStock');
        if ($minDaysOfStock !== null && $minDaysOfStock <= 7) {
            $deadline = now()->addDays(max(1, $minDaysOfStock - 2))->toDateString();
        }
        
        // Причина
        $reason = $this->generateReason($criticalItems, $items);
        
        return SupplyRecommendation::create([
            'integration_id' => $integration->id,
            'marketplace' => $integration->marketplace,
            'warehouse_id' => $warehouseId,
            'warehouse_name' => $warehouseData['warehouse_name'],
            'priority' => $priority,
            'title' => $title,
            'description' => "Рекомендация сформирована автоматически на основе анализа остатков и продаж",
            'reason' => $reason,
            'critical_items' => $criticalItemsArray,
            'recommended_items' => $recommendedItemsArray,
            'total_items' => $totals['total_items'],
            'total_quantity' => $totals['total_quantity'],
            'total_cost' => $totals['total_cost'] ?? 0,
            'total_volume' => $totals['total_volume'] ?? 0,
            'total_weight' => $totals['total_weight'] ?? 0,
            'deadline' => $deadline,
        ]);
    }

    /**
     * Сгенерировать причину рекомендации
     */
    private function generateReason(Collection $criticalItems, Collection $allItems): string
    {
        $reasons = [];
        
        $urgentCount = $criticalItems->where('priority', 'urgent')->count();
        $highCount = $criticalItems->where('priority', 'high')->count();
        
        if ($urgentCount > 0) {
            $reasons[] = "{$urgentCount} товаров с критически низким остатком (менее 7 дней)";
        }
        
        if ($highCount > 0) {
            $reasons[] = "{$highCount} товаров с низким остатком (менее 14 дней)";
        }
        
        $outOfStock = $allItems->where('daysOfStock', '<=', 0)->count();
        if ($outOfStock > 0) {
            $reasons[] = "{$outOfStock} товаров закончились на складе";
        }
        
        if (empty($reasons)) {
            $reasons[] = "Плановое пополнение запасов";
        }
        
        return implode('. ', $reasons);
    }

    /**
     * Получить активные рекомендации
     */
    public function getActiveRecommendations(?int $integrationId = null): Collection
    {
        $query = SupplyRecommendation::active()
            ->orderByRaw("CASE priority 
                WHEN 'urgent' THEN 1 
                WHEN 'high' THEN 2 
                WHEN 'medium' THEN 3 
                ELSE 4 END")
            ->orderBy('created_at', 'desc');
        
        if ($integrationId) {
            $query->where('integration_id', $integrationId);
        }
        
        return $query->get();
    }

    /**
     * Получить рекомендации по маркетплейсу
     */
    public function getByMarketplace(string $marketplace): Collection
    {
        return SupplyRecommendation::active()
            ->marketplace($marketplace)
            ->orderByRaw("CASE priority 
                WHEN 'urgent' THEN 1 
                WHEN 'high' THEN 2 
                WHEN 'medium' THEN 3 
                ELSE 4 END")
            ->get();
    }

    /**
     * Очистить устаревшие рекомендации
     */
    public function cleanupOldRecommendations(int $daysOld = 30): int
    {
        return SupplyRecommendation::where('created_at', '<', now()->subDays($daysOld))
            ->where(function ($q) {
                $q->where('is_used', true)
                    ->orWhere('is_dismissed', true);
            })
            ->delete();
    }

    /**
     * Статистика по рекомендациям
     */
    public function getStats(?int $integrationId = null): array
    {
        $query = SupplyRecommendation::query();
        
        if ($integrationId) {
            $query->where('integration_id', $integrationId);
        }
        
        $active = (clone $query)->active();
        
        return [
            'total' => $query->count(),
            'active' => $active->count(),
            'by_priority' => [
                'urgent' => (clone $active)->priority('urgent')->count(),
                'high' => (clone $active)->priority('high')->count(),
                'medium' => (clone $active)->priority('medium')->count(),
                'low' => (clone $active)->priority('low')->count(),
            ],
            'used' => (clone $query)->where('is_used', true)->count(),
            'dismissed' => (clone $query)->where('is_dismissed', true)->count(),
            'with_deadline' => (clone $active)->withDeadline()->count(),
            'overdue' => (clone $active)
                ->whereNotNull('deadline')
                ->where('deadline', '<', now()->toDateString())
                ->count(),
        ];
    }
}
