<?php

namespace App\Jobs;

use App\Models\AutoSupplyPlan;
use App\Models\AutoSupplyPlanLine;
use App\Models\InventoryWarehouse;
use App\Models\OzonWarehouseCluster;
use App\Models\Product;
use App\Services\AutoSupplyPlanService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CalculateAutoSupplyPlanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;

    public function __construct(
        private string $planId
    ) {}

    public function handle(AutoSupplyPlanService $service): void
    {
        $plan = AutoSupplyPlan::find($this->planId);

        if (!$plan) {
            Log::error('CalculateAutoSupplyPlanJob: plan not found', ['id' => $this->planId]);
            return;
        }

        $plan->markCalculating();

        try {
            $this->calculate($plan, $service);
        } catch (\Throwable $e) {
            Log::error('CalculateAutoSupplyPlanJob failed', [
                'plan_id' => $plan->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $plan->markError($e->getMessage());
        }
    }

    private function calculate(AutoSupplyPlan $plan, AutoSupplyPlanService $service): void
    {
        $ewmaAlpha = 0.35;
        $targetCoverDays = $plan->target_cover_days ?: 21;
        $minCoverDays = $plan->min_cover_days ?: 7;
        $maxCoverDays = $plan->max_cover_days ?: 42;
        $safetyStockDays = $plan->safety_stock_days ?: 5;
        $turnoverLimitDays = $plan->turnover_limit_days;
        $horizonDays = $plan->horizon_days ?: 28;

        $integrationId = $plan->integration_id;
        $marketplace = $plan->marketplace;

        // Получаем все записи остатков для интеграции
        $warehouses = InventoryWarehouse::where('integration_id', $integrationId)
            ->where('marketplace', $marketplace)
            ->get();

        if ($warehouses->isEmpty()) {
            $plan->markReady(0, 0, 0, $service->emptyQualityJson());
            return;
        }

        // Загружаем продукты для метаданных (barcode, name, pack_multiple, price)
        $skus = $warehouses->pluck('sku')->unique()->toArray();
        $products = Product::where('integration_id', $integrationId)
            ->where('marketplace', $marketplace)
            ->whereIn('sku', $skus)
            ->get()
            ->keyBy('sku');

        // Загружаем UnitEconomics для финансовых метрик (cost_price, storage, delivery)
        $unitEconomics = \App\Models\UnitEconomics::where('integration_id', $integrationId)
            ->whereIn('sku', $skus)
            ->get()
            ->keyBy('sku');

        // Load warehouse-to-cluster mapping for geo-distribution
        $clusterMapping = ($marketplace === 'ozon') ? OzonWarehouseCluster::getAllMapping() : [];

        $totalLines = 0;
        $totalQty = 0;
        $lines = [];

        // Data quality counters (per unique SKU)
        $qStocksCoverage = 0;
        $qSalesHistory = 0;
        $qInTransit = 0;
        $qDestination = 0;
        $qBarcode = 0;
        $qualitySeenSkus = [];
        $totalSkus = $warehouses->pluck('sku')->unique()->count();

        foreach ($warehouses as $wh) {
            $product = $products->get($wh->sku);

            // --- 3.1 Forecast (EWMA) ---
            $sales7 = $wh->sales_7_days ?? 0;
            $sales14 = $wh->sales_14_days ?? 0;
            $sales30 = $wh->sales_30_days ?? 0;

            $demandResult = $service->calculateDailyDemand($ewmaAlpha, $sales7, $sales14, $sales30);
            $dailyDemand = $demandResult['daily_demand'];
            $needsManualReview = $demandResult['needs_manual_review'];

            $currentStock = $wh->quantity ?? 0;
            $inTransit = $wh->in_transit ?? 0;
            $avgDailySales = $wh->average_daily_sales ?? 0;

            // --- 3.2 Базовые формулы ---
            $coverBefore = ($currentStock + $inTransit) / max($dailyDemand, AutoSupplyPlanService::EPS);
            $safetyStock = $dailyDemand * $safetyStockDays;

            $baseResult = $service->calculateNeededBeforeCaps($dailyDemand, $targetCoverDays, $currentStock, $inTransit);
            $targetStock = $baseResult['target_stock'];
            $neededBeforeCaps = $baseResult['needed_before_caps'];

            // --- 3.3 Max cover cap ---
            $capResult = $service->applyMaxCoverCap($neededBeforeCaps, $dailyDemand, $maxCoverDays, $currentStock, $inTransit);
            $needed = $capResult['needed'];
            $capStock = $capResult['cap_stock'];
            $capNeeded = $capResult['cap_needed'];
            $capsApplied = $capResult['caps_applied'];

            // --- 3.4 Turnover limit ---
            $turnoverResult = $service->applyTurnoverLimit($needed, $dailyDemand, $turnoverLimitDays, $currentStock, $inTransit, $capsApplied);
            $needed = $turnoverResult['needed'];
            $capsApplied = $turnoverResult['caps_applied'];

            // --- 3.5 Destination ---
            $destinationId = $wh->warehouse_id ?? null;
            $destinationType = $destinationId ? 'warehouse' : 'all';

            // --- 3.5b Geo-distribution: resolve cluster ---
            $clusterId = null;
            $clusterName = null;
            $clusterRegion = null;
            if (!empty($clusterMapping) && $wh->warehouse_name) {
                $normalizedName = OzonWarehouseCluster::normalizeWarehouseName($wh->warehouse_name);
                if (isset($clusterMapping[$normalizedName])) {
                    $clusterId = $clusterMapping[$normalizedName]['cluster_id'];
                    $clusterName = $clusterMapping[$normalizedName]['cluster_name'];
                    $clusterRegion = $clusterMapping[$normalizedName]['region'];
                }
            }

            // --- 3.6 Округление qty ---
            $packMultiple = 1;
            if ($product && isset($product->ozon_data['pack_multiple'])) {
                $packMultiple = max(1, (int) $product->ozon_data['pack_multiple']);
            }
            $qtyRounded = $service->roundToPackMultiple($needed, $packMultiple);

            // --- 3.7 Симуляция и риск ---
            $simulation = $service->buildSimulation($currentStock, $inTransit, $dailyDemand, $qtyRounded, $horizonDays);
            $oosDate = $service->findOosDate($simulation);
            $coverAfter = ($currentStock + $inTransit + $qtyRounded) / max($dailyDemand, AutoSupplyPlanService::EPS);
            $surplusDays = $coverAfter > $targetCoverDays ? (int) ($coverAfter - $targetCoverDays) : null;
            $riskLevel = $service->determineRiskLevel($oosDate, $coverBefore, $minCoverDays);

            // --- 3.8 Explain ---
            $shortAvg = $sales7 > 0 ? $sales7 / 7 : 0;
            $longAvg = $sales30 > 0 ? $sales30 / 30 : 0;

            $explainJson = [
                'inputs' => [
                    'stock_now' => $currentStock,
                    'in_transit' => $inTransit,
                    'daily_demand' => round($dailyDemand, 4),
                    'ewma_alpha' => $ewmaAlpha,
                    'sales_7d' => $sales7,
                    'sales_14d' => $sales14,
                    'sales_30d' => $sales30,
                    'short_avg' => round($shortAvg, 4),
                    'long_avg' => round($longAvg, 4),
                    'target_cover_days' => $targetCoverDays,
                    'min_cover_days' => $minCoverDays,
                    'max_cover_days' => $maxCoverDays,
                    'safety_stock_days' => $safetyStockDays,
                    'turnover_limit_days' => $turnoverLimitDays,
                    'pack_multiple' => $packMultiple,
                ],
                'math' => [
                    'target_stock' => round($targetStock, 2),
                    'safety_stock' => round($safetyStock, 2),
                    'needed_before_caps' => round($neededBeforeCaps, 2),
                    'cap_stock' => round($capStock, 2),
                    'cap_needed' => round($capNeeded, 2),
                    'caps_applied' => $capsApplied,
                    'needed_after_caps' => round($needed, 2),
                    'qty_rounded' => $qtyRounded,
                    'cover_before' => round($coverBefore, 2),
                    'cover_after' => round($coverAfter, 2),
                ],
                'confidence' => [
                    'needs_manual_review' => $needsManualReview,
                    'missing_sources' => $service->detectMissingSources($wh, $product, $marketplace),
                    'fallbacks' => $dailyDemand === 0.0 ? ['no_sales_data'] : [],
                ],
                'simulation_summary' => [
                    'oos_date' => $oosDate,
                    'min_stock' => $service->findMinStock($simulation),
                ],
            ];

            // Определяем offer_id и barcode
            $offerId = $wh->sku;
            $barcode = $product?->barcode;

            // --- 3.9 Финансовые метрики + Тренд ---
            $ue = $unitEconomics->get($wh->sku);
            $price = $product?->price ?? $ue?->price ?? 0;
            $costPrice = $ue?->cost_price ?? $wh->cost_price ?? 0;
            $storageCostDaily = $wh->storage_cost_per_day ?? 0;
            $storageCostMonthly = $wh->storage_cost_per_month ?? 0;

            // Тренд продаж (улучшенный: 3 периода + учёт OOS)
            $salesTrend = 'stable';
            $salesTrendPercent = 0;

            // Используем effective_daily_sales если товар был OOS (корректировка на дни в наличии)
            $daysInStock30 = $wh->days_in_stock_30 ?? 30;
            $effectiveDailySales = $wh->effective_daily_sales ?? 0;

            // Среднедневные по периодам
            $avg7 = $sales7 > 0 ? $sales7 / 7 : 0;
            $avg14 = $sales14 > 0 ? $sales14 / 14 : 0;
            // Для 30д: если были OOS-дни, используем effective_daily_sales
            $avg30 = ($daysInStock30 > 0 && $daysInStock30 < 25 && $effectiveDailySales > 0)
                ? $effectiveDailySales
                : ($sales30 > 0 ? $sales30 / 30 : 0);

            if ($avg30 > 0 && $sales14 > 0) {
                // Краткосрочный тренд: 7д vs 8-14д
                $avg8_14 = ($sales14 - $sales7) / 7;
                $shortTrend = $avg8_14 > 0 ? (($avg7 - $avg8_14) / $avg8_14) * 100 : 0;

                // Среднесрочный тренд: 14д vs 15-30д
                $older16Avg = ($sales30 - $sales14) / 16;
                $midTrend = $older16Avg > 0 ? (($avg14 - $older16Avg) / $older16Avg) * 100 : 0;

                // Взвешенный тренд: 60% краткосрочный + 40% среднесрочный
                $salesTrendPercent = round($shortTrend * 0.6 + $midTrend * 0.4, 2);

                if ($salesTrendPercent > 10) $salesTrend = 'growing';
                elseif ($salesTrendPercent < -10) $salesTrend = 'declining';
            }

            // Добавляем детали тренда в explain_json
            $explainJson['trend'] = [
                'sales_7d' => $sales7,
                'sales_14d' => $sales14,
                'sales_30d' => $sales30,
                'avg_daily_7d' => round($avg7, 4),
                'avg_daily_14d' => round($avg14, 4),
                'avg_daily_30d' => round($avg30, 4),
                'days_in_stock_30' => $daysInStock30,
                'effective_daily_sales' => round($effectiveDailySales, 4),
                'oos_adjusted' => ($daysInStock30 < 25 && $effectiveDailySales > 0),
                'short_trend_pct' => isset($shortTrend) ? round($shortTrend, 2) : null,
                'mid_trend_pct' => isset($midTrend) ? round($midTrend, 2) : null,
                'weighted_trend_pct' => $salesTrendPercent,
                'result' => $salesTrend,
            ];

            // Потерянная выручка при OOS (если товар закончится)
            $lostRevenueDaily = $dailyDemand * $price;

            // Стоимость поставки (себестоимость * количество)
            $supplyCostEstimate = $costPrice > 0 ? $costPrice * $qtyRounded : 0;

            // Ожидаемая выручка за период покрытия
            $expectedRevenue = $price > 0 ? $dailyDemand * $targetCoverDays * $price : 0;

            // Ожидаемая прибыль
            $expectedProfit = $expectedRevenue - $supplyCostEstimate - ($storageCostDaily * $targetCoverDays);

            // ROI поставки
            $roiPercent = $supplyCostEstimate > 0 ? round(($expectedProfit / $supplyCostEstimate) * 100, 2) : 0;

            // Оборачиваемость
            $turnoverDays = $dailyDemand > 0 ? round(($currentStock + $inTransit + $qtyRounded) / $dailyDemand, 1) : null;

            // Приоритет поставки
            $priorityScore = 0;
            if ($oosDate) $priorityScore += 40;
            if ($coverBefore < $minCoverDays) $priorityScore += 30;
            if ($riskLevel === 'high') $priorityScore += 20;
            if ($salesTrend === 'growing') $priorityScore += 10;
            $priorityScore = min(100, $priorityScore);

            $priority = 'low';
            if ($priorityScore >= 70) $priority = 'critical';
            elseif ($priorityScore >= 50) $priority = 'high';
            elseif ($priorityScore >= 30) $priority = 'medium';

            // --- 4. Data quality counters (once per unique SKU) ---
            if (!isset($qualitySeenSkus[$wh->sku])) {
                $qualitySeenSkus[$wh->sku] = true;
                if ($currentStock > 0 || $inTransit > 0) $qStocksCoverage++;
                if ($sales30 > 0) $qSalesHistory++;
                if ($inTransit > 0) $qInTransit++;
                if ($destinationId) $qDestination++;
                if ($marketplace === 'wildberries' && !empty($barcode)) $qBarcode++;
            }

            // Пропускаем строки с нулевым количеством
            if ($qtyRounded <= 0) {
                continue;
            }

            $lines[] = [
                'auto_supply_plan_id' => $plan->id,
                'tenant_id' => $plan->tenant_id,
                'sku' => $wh->sku,
                'offer_id' => $offerId,
                'product_name' => $product?->name,
                'barcode' => $barcode,
                'price' => $price > 0 ? round($price, 2) : null,
                'cost_price' => $costPrice > 0 ? round($costPrice, 2) : null,
                'warehouse_id' => $wh->warehouse_id,
                'warehouse_name' => $wh->warehouse_name,
                'cluster_id' => $clusterId,
                'cluster_name' => $clusterName,
                'region' => $clusterRegion,
                'destination' => $wh->warehouse_name,
                'destination_id' => $destinationId,
                'destination_type' => $destinationType,
                'qty_recommended' => round($needed, 2),
                'qty_rounded' => $qtyRounded,
                'current_stock' => $currentStock,
                'in_transit' => $inTransit,
                'avg_daily_sales' => round($avgDailySales, 4),
                'ewma_daily_sales' => round($dailyDemand, 4),
                'demand_daily' => round($dailyDemand, 4),
                'sales_trend' => $salesTrend,
                'sales_trend_percent' => $salesTrendPercent,
                'cover_days_before' => round($coverBefore, 2),
                'cover_days_after' => round($coverAfter, 2),
                'oos_date' => $oosDate,
                'surplus_days' => $surplusDays,
                'storage_cost_daily' => $storageCostDaily > 0 ? round($storageCostDaily, 2) : null,
                'storage_cost_monthly' => $storageCostMonthly > 0 ? round($storageCostMonthly, 2) : null,
                'lost_revenue_daily' => $lostRevenueDaily > 0 ? round($lostRevenueDaily, 2) : null,
                'supply_cost_estimate' => $supplyCostEstimate > 0 ? round($supplyCostEstimate, 2) : null,
                'expected_revenue' => $expectedRevenue > 0 ? round($expectedRevenue, 2) : null,
                'expected_profit' => round($expectedProfit, 2),
                'roi_percent' => $roiPercent,
                'priority_score' => $priorityScore,
                'priority' => $priority,
                'turnover_days' => $turnoverDays,
                'explain_json' => json_encode($explainJson),
                'risk_level' => $riskLevel,
                'simulation_json' => json_encode($simulation),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $totalQty += $qtyRounded;
            $totalLines++;
        }

        // Bulk insert lines
        if (!empty($lines)) {
            foreach (array_chunk($lines, 500) as $chunk) {
                AutoSupplyPlanLine::insert($chunk);
            }
        }

        // --- 4. Data quality score ---
        $qualityJson = $service->calculateDataQuality(
            $totalSkus, $qStocksCoverage, $qSalesHistory,
            $qInTransit, $qDestination, $qBarcode, $marketplace
        );
        $qualityScore = $qualityJson['total'];

        $plan->markReady($qualityScore, $totalLines, $totalQty, $qualityJson);

        Log::info('CalculateAutoSupplyPlanJob completed', [
            'plan_id' => $plan->id,
            'total_lines' => $totalLines,
            'total_qty' => $totalQty,
            'quality_score' => $qualityScore,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('CalculateAutoSupplyPlanJob failed permanently', [
            'plan_id' => $this->planId,
            'error' => $exception->getMessage(),
        ]);

        $plan = AutoSupplyPlan::find($this->planId);
        $plan?->markError('Job failed: ' . $exception->getMessage());
    }
}
