<?php

namespace App\Jobs;

use App\Models\AutoSupplyPlan;
use App\Models\AutoSupplyPlanLine;
use App\Models\Integration;
use App\Models\InventoryWarehouse;
use App\Models\OzonWarehouseCluster;
use App\Models\Product;
use App\Models\SellerWarehouseStock;
use App\Models\SupplySettings;
use App\Models\UnitEconomics;
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
        $minCoverDays = $plan->min_cover_days ?: 7;
        $turnoverLimitDays = $plan->turnover_limit_days;
        $horizonDays = $plan->horizon_days ?: 30;

        // v3: horizon_days ограничивает max_cover_days и target_cover_days
        $maxCoverDays = $plan->max_cover_days ?: min($horizonDays, 90);
        $maxCoverDays = min($maxCoverDays, $horizonDays);

        $integrationId = $plan->integration_id;
        $marketplace = $plan->marketplace;

        // v2: Загружаем SupplySettings для интеграции (ABC target days, lead time, safety stock mode)
        $settings = SupplySettings::where('integration_id', $integrationId)->first();
        $leadTimeDays = $settings->default_lead_time_days ?? AutoSupplyPlanService::LEAD_TIME_DEFAULT;
        $minSafetyDays = $settings->safety_stock_days ?? ($plan->safety_stock_days ?: 3);
        $planDefaultTargetDays = min($plan->target_cover_days ?: 21, $horizonDays);

        // Получаем все записи остатков для интеграции (с фильтром по складам если указан)
        $warehouseQuery = InventoryWarehouse::where('integration_id', $integrationId)
            ->where('marketplace', $marketplace);

        $selectedWarehouseIds = $plan->params['warehouse_ids'] ?? null;
        if (!empty($selectedWarehouseIds) && is_array($selectedWarehouseIds)) {
            $warehouseQuery->whereIn('warehouse_id', $selectedWarehouseIds);
        }

        $warehouses = $warehouseQuery->get();

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

        // Загружаем UnitEconomics для финансовых метрик
        $unitEconomics = UnitEconomics::where('integration_id', $integrationId)
            ->whereIn('sku', $skus)
            ->get()
            ->keyBy('sku');

        // Load warehouse-to-cluster mapping for geo-distribution
        $clusterMapping = ($marketplace === 'ozon') ? OzonWarehouseCluster::getAllMapping() : [];

        // Load seller warehouse stocks (optional — if available, limits recommendations)
        $sellerStockMap = SellerWarehouseStock::getStockMap($integrationId);
        $hasSellerStocks = !empty($sellerStockMap);
        $sellerStockConsumed = [];

        // v2: Загружаем in-transit из активных заявок на поставку
        $supplyInTransit = $service->getInTransitFromSupplies($integrationId);

        // v2: Загружаем рекомендации Ozon по поставкам (delivery analytics)
        $ozonAnalytics = [];
        // v3: Аналитика остатков и оборачиваемости по SKU×склад (ads_cluster, idc_cluster, turnover_grade_cluster)
        $ozonStockAnalytics = [];
        $ozonTurnover = [];
        if ($marketplace === 'ozon') {
            $integration = Integration::find($integrationId);
            if ($integration) {
                $ozonAnalytics = $service->loadOzonDeliveryAnalytics($integration);

                // v3: Загружаем аналитику остатков из /v1/analytics/stocks и /v1/analytics/turnover/stocks
                $stockAnalyticsData = $service->loadOzonStockAnalytics($integration, $products);
                $ozonStockAnalytics = $stockAnalyticsData['stock_analytics']; // [offer_id => [wh_name => data]]
                $ozonTurnover = $stockAnalyticsData['turnover']; // [offer_id => data]
            }
        }

        // v2: Предварительный расчёт выручки за 30д для ABC-приоритета
        $revenueBySkuMap = [];
        foreach ($warehouses as $wh) {
            $product = $products->get($wh->sku);
            $ue = $unitEconomics->get($wh->sku);
            $price = $product?->price ?? $ue?->price ?? 0;
            $sales30 = $wh->sales_30_days ?? 0;
            $revenue30d = $price * $sales30;
            // Берём максимальную выручку по SKU (если несколько складов)
            if (!isset($revenueBySkuMap[$wh->sku]) || $revenue30d > $revenueBySkuMap[$wh->sku]) {
                $revenueBySkuMap[$wh->sku] = $revenue30d;
            }
        }

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
            $ue = $unitEconomics->get($wh->sku);

            // --- Исключённые SKU ---
            if ($settings && $settings->isSkuExcluded($wh->sku)) {
                continue;
            }

            // --- Базовые данные ---
            $sales7 = $wh->sales_7_days ?? 0;
            $sales14 = $wh->sales_14_days ?? 0;
            $sales30 = $wh->sales_30_days ?? 0;
            $currentStock = $wh->quantity ?? 0;
            $avgDailySales = $wh->average_daily_sales ?? 0;

            // v3: Получаем аналитику Ozon для этого SKU × склад
            $ozonStockData = null;
            $ozonTurnoverData = $ozonTurnover[$wh->sku] ?? null;
            $ozonAdsCluster = null;
            $ozonIdcCluster = null;
            $ozonTurnoverGradeCluster = null;
            $ozonDaysWithoutSalesCluster = null;
            if (!empty($ozonStockAnalytics[$wh->sku])) {
                // Ищем данные для конкретного склада
                $whName = $wh->warehouse_name ?? '';
                $ozonStockData = $ozonStockAnalytics[$wh->sku][$whName] ?? null;
                // Если нет точного совпадения по имени склада, берём первый доступный
                if (!$ozonStockData && !empty($ozonStockAnalytics[$wh->sku])) {
                    $ozonStockData = reset($ozonStockAnalytics[$wh->sku]);
                }
                if ($ozonStockData) {
                    $ozonAdsCluster = $ozonStockData['ads_cluster'] ?? null;
                    $ozonIdcCluster = $ozonStockData['idc_cluster'] ?? null;
                    $ozonTurnoverGradeCluster = $ozonStockData['turnover_grade_cluster'] ?? null;
                    $ozonDaysWithoutSalesCluster = $ozonStockData['days_without_sales_cluster'] ?? null;
                }
            }

            // v3: Если Ozon даёт ads_cluster (ср. продажи/день по кластеру), используем как доп. источник
            // ads_cluster > 0 означает что Ozon видит реальные продажи на этом складе
            if ($ozonAdsCluster !== null && $ozonAdsCluster > 0) {
                $avgDailySales = max($avgDailySales, $ozonAdsCluster);
            }

            // v2: In-transit = API + заявки на поставку
            $inTransitApi = $wh->in_transit ?? 0;
            $inTransitSupplies = $supplyInTransit[$wh->sku] ?? 0;
            $inTransit = $inTransitApi + $inTransitSupplies;

            // v2: Данные для улучшенного прогноза
            $realAvgDailySales = $wh->real_avg_daily_sales ?? 0;
            $effectiveDailySales = $wh->effective_daily_sales ?? 0;
            $daysInStock30 = $wh->days_in_stock_30 ?? 30;
            $redemptionRate = $ue?->redemption_rate ?? 100;

            // --- Тренд продаж (улучшенный: 3 периода + учёт OOS) ---
            $salesTrend = 'stable';
            $salesTrendPercent = 0;

            $avg7 = $sales7 > 0 ? $sales7 / 7 : 0;
            $avg14 = $sales14 > 0 ? $sales14 / 14 : 0;
            $avg30 = ($daysInStock30 > 0 && $daysInStock30 < 25 && $effectiveDailySales > 0)
                ? $effectiveDailySales
                : ($sales30 > 0 ? $sales30 / 30 : 0);

            if ($avg30 > 0 && $sales14 > 0) {
                $avg8_14 = ($sales14 - $sales7) / 7;
                $shortTrend = $avg8_14 > 0 ? (($avg7 - $avg8_14) / $avg8_14) * 100 : 0;
                $older16Avg = ($sales30 - $sales14) / 16;
                $midTrend = $older16Avg > 0 ? (($avg14 - $older16Avg) / $older16Avg) * 100 : 0;
                $salesTrendPercent = round($shortTrend * 0.6 + $midTrend * 0.4, 2);

                if ($salesTrendPercent > 10) $salesTrend = 'growing';
                elseif ($salesTrendPercent < -10) $salesTrend = 'declining';
            }

            // --- v3: Определяем тип поставки (подпитка / новый склад / мёртвый сток) ---
            $hasWarehouseSales = ($sales7 > 0 || $sales14 > 0 || $sales30 > 0
                || $realAvgDailySales > 0 || $effectiveDailySales > 0
                || ($ozonAdsCluster !== null && $ozonAdsCluster > 0));
            $hasCurrentStock = ($currentStock > 0 || $inTransitApi > 0);

            // Мёртвый сток: Ozon говорит "нет продаж" на этом складе
            $isDeadStock = false;
            if ($ozonTurnoverGradeCluster === 'NO_SALES' || $ozonTurnoverGradeCluster === 'RESTRICTED_NO_SALES') {
                $isDeadStock = true;
            }
            if ($ozonDaysWithoutSalesCluster !== null && $ozonDaysWithoutSalesCluster > 60 && !$hasCurrentStock) {
                $isDeadStock = true;
            }

            // Определяем supply_type
            $supplyType = 'replenishment'; // подпитка (по умолчанию)
            if ($isDeadStock && !$hasCurrentStock && !$hasWarehouseSales) {
                $supplyType = 'dead_stock';
            } elseif (!$hasWarehouseSales && !$hasCurrentStock) {
                $supplyType = 'new_warehouse';
            }

            // Мёртвый сток — пропускаем (не рекомендуем отгрузку)
            if ($supplyType === 'dead_stock') {
                continue;
            }

            // --- v2: Улучшенный прогноз спроса (real > effective > EWMA > API avg > 7d fallback) ---
            $demandResult = $service->calculateDailyDemandV2(
                $ewmaAlpha, $sales7, $sales14, $sales30,
                $realAvgDailySales, $effectiveDailySales, $daysInStock30,
                $redemptionRate, $salesTrend, $salesTrendPercent,
                $avgDailySales
            );
            $dailyDemand = $demandResult['daily_demand'];
            $demandSource = $demandResult['source'];
            $needsManualReview = $demandResult['needs_manual_review'];

            // v3: Для нового склада — сниженный спрос (пробная партия)
            // Берём 30% от общего avg_daily_sales как тестовый объём
            if ($supplyType === 'new_warehouse' && $dailyDemand > 0) {
                $dailyDemand = $dailyDemand * 0.3;
                $demandSource = 'new_warehouse_trial';
                $needsManualReview = true;
            }

            // --- v2: ABC-приоритет ---
            $revenue30d = $revenueBySkuMap[$wh->sku] ?? 0;
            $abcPriority = $service->calculateAbcPriority($revenue30d);
            $targetCoverDays = $service->getTargetDaysByAbc($abcPriority, $settings, $planDefaultTargetDays);

            // v3: Для нового склада — короткий горизонт покрытия (макс 14 дней)
            if ($supplyType === 'new_warehouse') {
                $targetCoverDays = min(14, $targetCoverDays);
            }

            // --- v2: Динамический safety stock ---
            $volatility = $service->calculateVolatility($avg7, $avg14, $avg30);
            $safetyStock = $service->calculateDynamicSafetyStock($dailyDemand, $volatility, $leadTimeDays, $minSafetyDays);

            // v3: Для нового склада — минимальный safety stock
            if ($supplyType === 'new_warehouse') {
                $safetyStock = min($safetyStock, $dailyDemand * 3);
            }

            // --- v2: Needed с учётом safety stock ---
            $coverBefore = ($currentStock + $inTransit) / max($dailyDemand, AutoSupplyPlanService::EPS);

            $baseResult = $service->calculateNeededWithSafety($dailyDemand, $targetCoverDays, $safetyStock, $currentStock, $inTransit);
            $targetStock = $baseResult['target_stock'];
            $neededBeforeCaps = $baseResult['needed_before_caps'];

            // --- Max cover cap ---
            $capResult = $service->applyMaxCoverCap($neededBeforeCaps, $dailyDemand, $maxCoverDays, $currentStock, $inTransit);
            $needed = $capResult['needed'];
            $capStock = $capResult['cap_stock'];
            $capNeeded = $capResult['cap_needed'];
            $capsApplied = $capResult['caps_applied'];

            // --- Turnover limit ---
            $turnoverResult = $service->applyTurnoverLimit($needed, $dailyDemand, $turnoverLimitDays, $currentStock, $inTransit, $capsApplied);
            $needed = $turnoverResult['needed'];
            $capsApplied = $turnoverResult['caps_applied'];

            // --- Destination ---
            $destinationId = $wh->warehouse_id ?? null;
            $destinationType = $destinationId ? 'warehouse' : 'all';

            // --- Geo-distribution: resolve cluster ---
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

            // --- Округление qty ---
            $packMultiple = $settings->default_pack_multiple ?? 1;
            if ($product && isset($product->ozon_data['pack_multiple'])) {
                $packMultiple = max(1, (int) $product->ozon_data['pack_multiple']);
            }
            $qtyRounded = $service->roundToPackMultiple($needed, $packMultiple);

            // --- Own stock accounting (optional) ---
            $ownStock = null;
            $ownStockReserved = null;
            $deficit = null;

            if ($hasSellerStocks && isset($sellerStockMap[$wh->sku])) {
                $sellerInfo = $sellerStockMap[$wh->sku];
                $ownStock = $sellerInfo['quantity'];
                $ownStockReserved = $sellerInfo['reserved'];
                $alreadyConsumed = $sellerStockConsumed[$wh->sku] ?? 0;
                $availableOwn = max(0, $sellerInfo['available'] - $alreadyConsumed);

                if ($qtyRounded > $availableOwn) {
                    $deficit = $qtyRounded - $availableOwn;
                    $qtyRounded = $availableOwn;
                    if ($packMultiple > 1 && $qtyRounded > 0) {
                        $qtyRounded = (int) floor($qtyRounded / $packMultiple) * $packMultiple;
                    }
                }

                $sellerStockConsumed[$wh->sku] = $alreadyConsumed + $qtyRounded;
            }

            // --- Симуляция и риск ---
            $simulation = $service->buildSimulation($currentStock, $inTransit, $dailyDemand, $qtyRounded, $horizonDays);
            $oosDate = $service->findOosDate($simulation);
            $coverAfter = ($currentStock + $inTransit + $qtyRounded) / max($dailyDemand, AutoSupplyPlanService::EPS);
            $surplusDays = $coverAfter > $targetCoverDays ? (int) ($coverAfter - $targetCoverDays) : null;
            $riskLevel = $service->determineRiskLevel($oosDate, $coverBefore, $minCoverDays);

            // --- Финансовые метрики ---
            $offerId = $wh->sku;
            $barcode = $product?->barcode;
            $price = $product?->price ?? $ue?->price ?? 0;
            $costPrice = $ue?->cost_price ?? $wh->cost_price ?? 0;
            $storageCostDaily = $wh->storage_cost_per_day ?? 0;
            $storageCostMonthly = $wh->storage_cost_per_month ?? 0;
            $marginPercent = $ue?->margin_percent ?? 0;
            $commissionPercent = $ue?->commission_percent ?? 0;
            $logisticsCost = $ue?->logistics_cost ?? 0;

            $lostRevenueDaily = $dailyDemand * $price;
            $supplyCostEstimate = $costPrice > 0 ? $costPrice * $qtyRounded : 0;
            $expectedRevenue = $price > 0 ? $dailyDemand * $targetCoverDays * $price : 0;

            // v2: Улучшенный расчёт прибыли (с учётом комиссии и логистики)
            $commissionCost = $expectedRevenue * ($commissionPercent / 100);
            $totalLogisticsCost = $logisticsCost * $qtyRounded;
            $expectedProfit = $expectedRevenue - $supplyCostEstimate - $commissionCost - $totalLogisticsCost - ($storageCostDaily * $targetCoverDays);
            $roiPercent = $supplyCostEstimate > 0 ? round(($expectedProfit / $supplyCostEstimate) * 100, 2) : 0;
            $turnoverDays = $dailyDemand > 0 ? round(($currentStock + $inTransit + $qtyRounded) / $dailyDemand, 1) : null;

            // --- v2: Ozon рекомендации для этого SKU ---
            $ozonSkuData = $ozonAnalytics[$wh->sku] ?? null;
            $ozonRecommendedSupply = $ozonSkuData['total_recommended_supply'] ?? null;
            $ozonLostProfit = $ozonSkuData['total_lost_profit'] ?? 0;
            $ozonAvgDeliveryTime = $ozonSkuData['max_delivery_time'] ?? null;
            $ozonAttentionLevel = $ozonSkuData['max_attention_level'] ?? null;

            // --- v2: Улучшенный приоритет (ABC + маржа + Ozon lost profit) ---
            $priorityResult = $service->calculatePriorityScoreV2(
                $abcPriority, $oosDate, $coverBefore, $minCoverDays,
                $salesTrend, $marginPercent, $ozonLostProfit, $lostRevenueDaily
            );
            $priorityScore = $priorityResult['score'];
            $priority = $priorityResult['priority'];

            // --- Explain JSON (v2: расширенный) ---
            $shortAvg = $sales7 > 0 ? $sales7 / 7 : 0;
            $longAvg = $sales30 > 0 ? $sales30 / 30 : 0;

            $explainJson = [
                'version' => 3,
                'inputs' => [
                    'supply_type' => $supplyType,
                    'stock_now' => $currentStock,
                    'in_transit_api' => $inTransitApi,
                    'in_transit_supplies' => $inTransitSupplies,
                    'in_transit_total' => $inTransit,
                    'daily_demand' => round($dailyDemand, 4),
                    'demand_source' => $demandSource,
                    'ewma_alpha' => $ewmaAlpha,
                    'sales_7d' => $sales7,
                    'sales_14d' => $sales14,
                    'sales_30d' => $sales30,
                    'short_avg' => round($shortAvg, 4),
                    'long_avg' => round($longAvg, 4),
                    'real_avg_daily_sales' => round($realAvgDailySales, 4),
                    'effective_daily_sales' => round($effectiveDailySales, 4),
                    'redemption_rate' => $redemptionRate,
                    'horizon_days' => $horizonDays,
                    'target_cover_days' => $targetCoverDays,
                    'min_cover_days' => $minCoverDays,
                    'max_cover_days' => $maxCoverDays,
                    'lead_time_days' => $leadTimeDays,
                    'pack_multiple' => $packMultiple,
                    'abc_priority' => $abcPriority,
                    'revenue_30d' => round($revenue30d, 2),
                ],
                'math' => [
                    'target_stock' => round($targetStock, 2),
                    'safety_stock' => round($safetyStock, 2),
                    'safety_stock_type' => 'dynamic',
                    'volatility' => $volatility,
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
                    'missing_sources' => $service->detectMissingSources($wh, $product, $marketplace, $ue),
                    'fallbacks' => $dailyDemand === 0.0 ? ['no_sales_data'] : [],
                ],
                'trend' => [
                    'sales_7d' => $sales7,
                    'sales_14d' => $sales14,
                    'sales_30d' => $sales30,
                    'avg_daily_7d' => round($avg7, 4),
                    'avg_daily_14d' => round($avg14, 4),
                    'avg_daily_30d' => round($avg30, 4),
                    'days_in_stock_30' => $daysInStock30,
                    'oos_adjusted' => ($daysInStock30 < 25 && $effectiveDailySales > 0),
                    'short_trend_pct' => isset($shortTrend) ? round($shortTrend, 2) : null,
                    'mid_trend_pct' => isset($midTrend) ? round($midTrend, 2) : null,
                    'weighted_trend_pct' => $salesTrendPercent,
                    'result' => $salesTrend,
                ],
                'ozon_analytics' => [
                    'recommended_supply' => $ozonRecommendedSupply,
                    'lost_profit' => $ozonLostProfit,
                    'avg_delivery_time' => $ozonAvgDeliveryTime,
                    'attention_level' => $ozonAttentionLevel,
                    'our_vs_ozon_diff' => $ozonRecommendedSupply !== null ? $qtyRounded - $ozonRecommendedSupply : null,
                ],
                'ozon_stock_analytics' => [
                    'ads_cluster' => $ozonAdsCluster,
                    'idc_cluster' => $ozonIdcCluster,
                    'turnover_grade_cluster' => $ozonTurnoverGradeCluster,
                    'days_without_sales_cluster' => $ozonDaysWithoutSalesCluster,
                    'ads_all' => $ozonStockData['ads'] ?? null,
                    'idc_all' => $ozonStockData['idc'] ?? null,
                    'turnover_grade_all' => $ozonStockData['turnover_grade'] ?? null,
                    'warehouse_name_matched' => $ozonStockData['warehouse_name'] ?? null,
                    // Оборачиваемость (из /v1/analytics/turnover/stocks)
                    'turnover_60d_ads' => $ozonTurnoverData['ads'] ?? null,
                    'turnover_days' => $ozonTurnoverData['turnover'] ?? null,
                    'turnover_idc' => $ozonTurnoverData['idc'] ?? null,
                    'turnover_idc_grade' => $ozonTurnoverData['idc_grade'] ?? null,
                    'turnover_grade' => $ozonTurnoverData['turnover_grade'] ?? null,
                ],
                'unit_economics' => [
                    'margin_percent' => $marginPercent,
                    'commission_percent' => $commissionPercent,
                    'logistics_cost' => $logisticsCost,
                    'redemption_rate' => $redemptionRate,
                    'roi_percent' => $roiPercent,
                ],
                'simulation_summary' => [
                    'oos_date' => $oosDate,
                    'min_stock' => $service->findMinStock($simulation),
                ],
            ];

            // --- Data quality counters (once per unique SKU) ---
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
                'own_stock' => $ownStock,
                'own_stock_reserved' => $ownStockReserved,
                'deficit' => $deficit,
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

        // --- Data quality score ---
        $qualityJson = $service->calculateDataQuality(
            $totalSkus, $qStocksCoverage, $qSalesHistory,
            $qInTransit, $qDestination, $qBarcode, $marketplace
        );
        $qualityScore = $qualityJson['total'];

        $plan->markReady($qualityScore, $totalLines, $totalQty, $qualityJson);

        Log::info('CalculateAutoSupplyPlanJob v3 completed', [
            'plan_id' => $plan->id,
            'total_lines' => $totalLines,
            'total_qty' => $totalQty,
            'quality_score' => $qualityScore,
            'ozon_analytics_skus' => count($ozonAnalytics),
            'ozon_stock_analytics_skus' => count($ozonStockAnalytics),
            'ozon_turnover_skus' => count($ozonTurnover),
            'supply_in_transit_skus' => count($supplyInTransit),
            'settings_used' => $settings ? true : false,
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
