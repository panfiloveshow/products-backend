<?php

namespace App\Services\Supply;

use App\Models\Integration;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Models\Supply;
use App\Models\SupplyRecommendation;
use App\Models\SupplySettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Сервис расчёта рекомендаций на поставку
 * 
 * Формула потребности (MVP):
 * demand = avg_sales_per_day(window) * target_days
 * need = max(0, demand - (stock_fbo + in_transit - safety_buffer))
 * need_rounded = округление по кратности (короб/минималка)
 */
class SupplyRecommendationService
{
    /**
     * Рассчитать рекомендации для интеграции
     */
    public function calculateRecommendations(Integration $integration, ?string $clusterId = null): Collection
    {
        $settings = SupplySettings::getOrCreate($integration->id);
        
        if (!$settings->is_active) {
            Log::info("Supply recommendations disabled for integration {$integration->id}");
            return collect();
        }

        // Получаем данные о продажах и остатках
        $salesData = $this->getSalesData($integration->id);
        $stockData = $this->getStockData($integration->id, $clusterId);
        $transitData = $this->getTransitData($integration->id);

        $recommendations = collect();

        foreach ($stockData as $sku => $stocks) {
            foreach ($stocks as $warehouseId => $stockInfo) {
                // Пропускаем исключённые SKU
                if ($settings->isSkuExcluded($sku)) {
                    continue;
                }

                $sales = $salesData[$sku] ?? null;
                if (!$sales) {
                    continue; // Нет данных о продажах
                }

                $recommendation = $this->calculateForSku(
                    $integration,
                    $settings,
                    $sku,
                    $stockInfo,
                    $sales,
                    $transitData[$sku] ?? 0
                );

                if ($recommendation && $recommendation['recommended_qty'] > 0) {
                    $recommendations->push($recommendation);
                }
            }
        }

        return $recommendations->sortByDesc('priority_score');
    }

    /**
     * Рассчитать рекомендацию для одного SKU
     */
    protected function calculateForSku(
        Integration $integration,
        SupplySettings $settings,
        string $sku,
        array $stockInfo,
        array $sales,
        int $inTransit
    ): ?array {
        // Выбираем окно продаж
        $avgSales = $this->selectSalesWindow($sales, $settings->default_sales_window);
        
        if ($avgSales <= 0) {
            return null; // Нет продаж
        }

        // Определяем приоритет ABC
        $priority = $this->calculatePriority($sales);
        $targetDays = $settings->getTargetDays($priority);
        
        // Рассчитываем страховой запас
        $safetyStock = $settings->calculateSafetyStock($avgSales);
        
        // Текущий остаток
        $currentStock = $stockInfo['quantity'] ?? 0;
        
        // Формула потребности
        $demand = (int) ceil($avgSales * $targetDays);
        $needRaw = max(0, $demand - ($currentStock + $inTransit - $safetyStock));
        
        if ($needRaw <= 0) {
            return null; // Нет потребности
        }

        // Округление по кратности
        $packMultiple = $stockInfo['pack_multiple'] ?? $settings->default_pack_multiple;
        $minOrderQty = $stockInfo['min_order_qty'] ?? $settings->min_order_qty;
        $recommendedQty = $this->roundToMultiple($needRaw, $packMultiple, $minOrderQty);

        // Дни запаса
        $daysOfStock = $avgSales > 0 ? (int) floor($currentStock / $avgSales) : 999;
        
        // Риски
        $oosRisk = $daysOfStock <= $settings->oos_risk_days;
        $overstockRisk = $daysOfStock > $settings->overstock_days;

        // Приоритетный скор
        $priorityScore = $this->calculatePriorityScore($priority, $oosRisk, $sales, $daysOfStock);

        // Причины и предупреждения
        $reasons = $this->buildReasons($avgSales, $targetDays, $currentStock, $inTransit, $safetyStock, $demand, $needRaw);
        $warnings = $this->buildWarnings($settings, $sku, $packMultiple, $recommendedQty, $needRaw);
        $restrictions = $settings->isSkuRestricted($sku) ? ['restricted' => true] : null;

        // Рекомендуемые даты
        $leadTimeDays = $settings->default_lead_time_days;
        $recommendedCreateDate = now()->addDays(max(0, $daysOfStock - $leadTimeDays - 1))->toDateString();
        $recommendedDeliveryDate = now()->addDays($daysOfStock)->toDateString();

        return [
            'integration_id' => $integration->id,
            'sku' => $sku,
            'ozon_product_id' => $stockInfo['ozon_product_id'] ?? null,
            'product_name' => $stockInfo['product_name'] ?? null,
            'product_id' => $stockInfo['product_id'] ?? null,
            'cluster_id' => $stockInfo['cluster_id'] ?? null,
            'cluster_name' => $stockInfo['cluster_name'] ?? null,
            'warehouse_id' => $stockInfo['warehouse_id'] ?? null,
            'warehouse_name' => $stockInfo['warehouse_name'] ?? null,
            'avg_sales_7d' => $sales['avg_7d'] ?? 0,
            'avg_sales_14d' => $sales['avg_14d'] ?? 0,
            'avg_sales_28d' => $sales['avg_28d'] ?? 0,
            'avg_sales_used' => $avgSales,
            'sales_window' => $settings->default_sales_window,
            'current_stock' => $currentStock,
            'in_transit' => $inTransit,
            'safety_stock' => $safetyStock,
            'target_days' => $targetDays,
            'demand' => $demand,
            'need_raw' => $needRaw,
            'recommended_qty' => $recommendedQty,
            'pack_multiple' => $packMultiple,
            'min_order_qty' => $minOrderQty,
            'priority' => $priority,
            'priority_score' => $priorityScore,
            'days_of_stock' => $daysOfStock,
            'oos_risk' => $oosRisk,
            'overstock_risk' => $overstockRisk,
            'reasons' => $reasons,
            'warnings' => $warnings,
            'restrictions' => $restrictions,
            'recommended_create_date' => $recommendedCreateDate,
            'recommended_delivery_date' => $recommendedDeliveryDate,
            'lead_time_days' => $leadTimeDays,
            'state' => SupplyRecommendation::STATE_NEW,
        ];
    }

    /**
     * Выбрать окно продаж
     */
    protected function selectSalesWindow(array $sales, string $window): float
    {
        return match ($window) {
            '7d' => $sales['avg_7d'] ?? 0,
            '14d' => $sales['avg_14d'] ?? 0,
            '28d' => $sales['avg_28d'] ?? 0,
            default => $sales['avg_14d'] ?? 0,
        };
    }

    /**
     * Определить приоритет ABC на основе продаж
     */
    protected function calculatePriority(array $sales): string
    {
        $revenue30d = $sales['revenue_30d'] ?? 0;
        
        // Простая логика: топ по выручке = A, средние = B, остальные = C
        if ($revenue30d >= 100000) {
            return SupplyRecommendation::PRIORITY_A;
        } elseif ($revenue30d >= 30000) {
            return SupplyRecommendation::PRIORITY_B;
        }
        return SupplyRecommendation::PRIORITY_C;
    }

    /**
     * Рассчитать числовой скор приоритета
     */
    protected function calculatePriorityScore(string $priority, bool $oosRisk, array $sales, int $daysOfStock): float
    {
        $score = 0;

        // Базовый скор по ABC
        $score += match ($priority) {
            SupplyRecommendation::PRIORITY_A => 100,
            SupplyRecommendation::PRIORITY_B => 50,
            SupplyRecommendation::PRIORITY_C => 25,
            default => 0,
        };

        // Бонус за OOS риск
        if ($oosRisk) {
            $score += 50;
        }

        // Бонус за критически низкий запас
        if ($daysOfStock <= 1) {
            $score += 30;
        } elseif ($daysOfStock <= 3) {
            $score += 15;
        }

        // Бонус за высокую выручку
        $revenue30d = $sales['revenue_30d'] ?? 0;
        if ($revenue30d >= 500000) {
            $score += 20;
        } elseif ($revenue30d >= 200000) {
            $score += 10;
        }

        return round($score, 2);
    }

    /**
     * Округление по кратности
     */
    protected function roundToMultiple(int $value, int $multiple, int $minQty): int
    {
        if ($multiple <= 1) {
            return max($value, $minQty);
        }
        
        $rounded = (int) ceil($value / $multiple) * $multiple;
        return max($rounded, $minQty);
    }

    /**
     * Построить причины рекомендации
     */
    protected function buildReasons(
        float $avgSales,
        int $targetDays,
        int $currentStock,
        int $inTransit,
        int $safetyStock,
        int $demand,
        int $needRaw
    ): array {
        return [
            'formula' => "demand({$demand}) = avg_sales({$avgSales}) × target_days({$targetDays})",
            'calculation' => "need({$needRaw}) = demand({$demand}) - (stock({$currentStock}) + transit({$inTransit}) - safety({$safetyStock}))",
            'avg_daily_sales' => round($avgSales, 2),
            'target_coverage_days' => $targetDays,
            'current_stock' => $currentStock,
            'in_transit' => $inTransit,
            'safety_stock' => $safetyStock,
        ];
    }

    /**
     * Построить предупреждения
     */
    protected function buildWarnings(
        SupplySettings $settings,
        string $sku,
        int $packMultiple,
        int $recommendedQty,
        int $needRaw
    ): ?array {
        $warnings = [];

        // Предупреждение о кратности
        if ($packMultiple > 1 && $recommendedQty != $needRaw) {
            $warnings[] = [
                'type' => 'pack_multiple',
                'message' => "Количество округлено до кратности {$packMultiple}",
            ];
        }

        // Предупреждение об ограничениях
        if ($settings->isSkuRestricted($sku)) {
            $warnings[] = [
                'type' => 'restricted',
                'message' => 'SKU имеет ограничения на поставку',
            ];
        }

        return empty($warnings) ? null : $warnings;
    }

    /**
     * Получить данные о продажах из inventory_warehouses
     */
    protected function getSalesData(int $integrationId): array
    {
        // Используем данные о продажах из inventory_warehouses (агрегированные по SKU)
        $sales = DB::table('inventory_warehouses')
            ->select([
                'sku',
                DB::raw('SUM(COALESCE(sales_7_days, 0)) as qty_7d'),
                DB::raw('SUM(COALESCE(sales_14_days, 0)) as qty_14d'),
                DB::raw('SUM(COALESCE(sales_28_days, 0)) as qty_28d'),
                DB::raw('SUM(COALESCE(sales_30_days, 0)) as qty_30d'),
            ])
            ->where('integration_id', $integrationId)
            ->whereNotNull('sku')
            ->groupBy('sku')
            ->get();

        $result = [];
        foreach ($sales as $row) {
            $result[$row->sku] = [
                'avg_7d' => $row->qty_7d > 0 ? $row->qty_7d / 7 : 0,
                'avg_14d' => $row->qty_14d > 0 ? $row->qty_14d / 14 : 0,
                'avg_28d' => $row->qty_28d > 0 ? $row->qty_28d / 28 : 0,
                'revenue_30d' => 0, // Выручка рассчитывается отдельно через unit_economics
            ];
        }

        return $result;
    }

    /**
     * Получить данные об остатках
     */
    protected function getStockData(int $integrationId, ?string $clusterId = null): array
    {
        $query = InventoryWarehouse::query()
            ->where('integration_id', $integrationId)
            ->where('fulfillment_type', 'FBO');

        if ($clusterId) {
            $query->where('macrolocal_cluster_id', $clusterId);
        }

        $stocks = $query->get();

        $result = [];
        foreach ($stocks as $stock) {
            $sku = $stock->sku;
            $warehouseId = $stock->warehouse_id ?? $stock->warehouse_name;

            if (!isset($result[$sku])) {
                $result[$sku] = [];
            }

            $result[$sku][$warehouseId] = [
                'product_id' => $stock->product_id,
                'ozon_product_id' => $stock->ozon_product_id,
                'product_name' => $stock->product_name,
                'quantity' => $stock->quantity ?? 0,
                'reserved' => $stock->reserved ?? 0,
                'warehouse_id' => $stock->warehouse_id,
                'warehouse_name' => $stock->warehouse_name,
                'cluster_id' => $stock->macrolocal_cluster_id,
                'cluster_name' => $stock->cluster_name,
                'pack_multiple' => $stock->pack_multiple ?? 1,
                'min_order_qty' => $stock->min_order_qty ?? 1,
            ];
        }

        return $result;
    }

    /**
     * Получить данные о товарах в пути (созданные поставки)
     */
    protected function getTransitData(int $integrationId): array
    {
        $transit = DB::table('supply_items')
            ->join('supplies', 'supply_items.supply_id', '=', 'supplies.id')
            ->where('supplies.integration_id', $integrationId)
            ->whereIn('supplies.status', [
                Supply::STATUS_DRAFT_OZON,
                Supply::STATUS_SLOT_BOOKED,
                Supply::STATUS_PREPARING,
                Supply::STATUS_READY_TO_SHIP,
                Supply::STATUS_SHIPPED,
                Supply::STATUS_IN_TRANSIT,
            ])
            ->select('supply_items.sku', DB::raw('SUM(supply_items.planned_qty) as qty'))
            ->groupBy('supply_items.sku')
            ->get();

        $result = [];
        foreach ($transit as $row) {
            $result[$row->sku] = (int) $row->qty;
        }

        return $result;
    }

    /**
     * Сохранить рекомендации в БД
     */
    public function saveRecommendations(Collection $recommendations): int
    {
        $saved = 0;

        foreach ($recommendations as $data) {
            // Проверяем, нет ли уже активной рекомендации для этого SKU/склада
            $existing = SupplyRecommendation::where('integration_id', $data['integration_id'])
                ->where('sku', $data['sku'])
                ->where('warehouse_id', $data['warehouse_id'])
                ->whereIn('state', [
                    SupplyRecommendation::STATE_NEW,
                    SupplyRecommendation::STATE_ACCEPTED,
                    SupplyRecommendation::STATE_POSTPONED,
                ])
                ->first();

            if ($existing) {
                // Обновляем существующую
                $existing->update($data);
            } else {
                // Создаём новую
                SupplyRecommendation::create($data);
            }

            $saved++;
        }

        return $saved;
    }

    /**
     * Пометить устаревшие рекомендации
     */
    public function expireOldRecommendations(int $integrationId, int $daysOld = 7): int
    {
        return SupplyRecommendation::where('integration_id', $integrationId)
            ->where('state', SupplyRecommendation::STATE_NEW)
            ->where('created_at', '<', now()->subDays($daysOld))
            ->update(['state' => SupplyRecommendation::STATE_EXPIRED]);
    }
}
