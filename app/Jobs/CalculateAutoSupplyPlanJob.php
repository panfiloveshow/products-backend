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
use App\Models\WbBarcodeCost;
use App\Models\UnitEconomics;
use App\Services\AutoSupplyPlanService;
use App\Services\AutoSupplyPlanning\MarketplaceConstraintService;
use App\Services\AutoSupplyPlanning\MarketplacePlanningCapabilityService;
use App\Services\AutoSupplyPlanning\PlanningFactSnapshotService;
use App\Services\AutoSupplyPlanning\TerritorialPlanningService;
use App\Services\AutoSupplyPlanning\DeficitSurplusPlanningService;
use App\Services\AutoSupplyPlanning\PlanQualityAuditService;
use App\Services\Ozon\OzonPerformanceApiService;
use App\Services\SellicoApiService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\OzonOrderReport;
use App\Services\AutoSupplyPlanning\PlanLineOptimizer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

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
            app(PlanningFactSnapshotService::class)->fail($plan, $e->getMessage());
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
        $params = is_array($plan->params) ? $plan->params : [];
        $analysisPeriodDays = $this->analysisPeriodDays($params, $horizonDays);
        $seasonalityMultiplier = max(0.1, min(5.0, (float) ($params['demand_seasonality_multiplier'] ?? 1.0)));
        $trendMultiplier = max(0.1, min(5.0, (float) ($params['trend_multiplier'] ?? 1.0)));
        $promoMode = (string) ($params['promo_mode'] ?? 'none');
        $includeInTransit = array_key_exists('include_in_transit', $params) ? (bool) $params['include_in_transit'] : true;
        $requestedOzonQtyAnchor = (string) ($params['ozon_qty_anchor'] ?? 'internal');
        $ozonQtyAnchor = $this->effectiveOzonQtyAnchor($requestedOzonQtyAnchor, $marketplace);
        $ozonQtyAnchorWasDeprecated = $marketplace === 'ozon' && $requestedOzonQtyAnchor !== $ozonQtyAnchor;
        $skipNegativeProfit = (bool) ($params['skip_negative_profit'] ?? false);
        $ozonAdvertisingImpact = $this->loadOzonAdvertisingImpact($plan, $params);
        $ozonAdvertisingByOffer = is_array($ozonAdvertisingImpact['by_offer_id'] ?? null)
            ? $ozonAdvertisingImpact['by_offer_id']
            : [];
        $constraintService = app(MarketplaceConstraintService::class);
        $marketplaceNeedFacts = $constraintService->marketplaceNeedFacts($plan, $marketplace);
        $constraintNeedSkus = array_values(array_unique(array_filter(array_map(
            static fn (array $need): ?string => isset($need['sku']) && trim((string) $need['sku']) !== ''
                ? trim((string) $need['sku'])
                : null,
            $marketplaceNeedFacts
        ))));

        app(PlanningFactSnapshotService::class)->start($plan, [
            'constraints' => [
                'selected_cluster_ids' => $params['cluster_ids'] ?? null,
                'selected_warehouse_ids' => $params['warehouse_ids'] ?? null,
                'analysis_period_days' => $analysisPeriodDays,
                'include_in_transit' => $includeInTransit,
                'budget_limit' => $plan->budget_limit,
                'promo_mode' => $promoMode,
                'trend_multiplier' => $trendMultiplier,
                'seasonality_multiplier' => $seasonalityMultiplier,
                'constraint_metadata' => $params['constraint_metadata'] ?? null,
                'performance_report_uuid' => $ozonAdvertisingImpact['uuid'] ?? null,
            ],
        ]);

        // v2: Загружаем SupplySettings для интеграции (ABC target days, lead time, safety stock mode)
        $settings = SupplySettings::where('integration_id', $integrationId)->first();
        $leadTimeDays = $settings->default_lead_time_days ?? AutoSupplyPlanService::LEAD_TIME_DEFAULT;
        $minSafetyDays = $settings->safety_stock_days ?? ($plan->safety_stock_days ?: 3);
        $planDefaultTargetDays = min($plan->target_cover_days ?: 21, $horizonDays);

        // Получаем только актуальные записи остатков для интеграции:
        // SKU включается если есть остаток > 0 ИЛИ продажи за 30 дней > 0
        // Это исключает "мёртвые" товары которых давно нет и которые засоряют план
        $activeSkuQuery = InventoryWarehouse::where('integration_id', $integrationId)
            ->when(
                in_array($marketplace, ['yandex', 'yandex_market'], true),
                // BUG FIX: исторически записи могут хранить и 'yandex', и 'yandex_market' — ищем оба варианта
                fn ($q) => $q->whereIn('marketplace', ['yandex', 'yandex_market']),
                fn ($q) => $q->where('marketplace', $marketplace)
            );

        if ($marketplace !== 'ozon') {
            $activeSkuQuery->where(function ($q) {
                $q->where('quantity', '>', 0)
                  ->orWhere('sales_30_days', '>', 0);
            });
        }

        $activeSKUs = $activeSkuQuery
            ->pluck('sku')
            ->unique()
            ->toArray();

        $warehouseQuery = InventoryWarehouse::where('integration_id', $integrationId)
            ->when(
                in_array($marketplace, ['yandex', 'yandex_market'], true),
                fn ($q) => $q->whereIn('marketplace', ['yandex', 'yandex_market']),
                fn ($q) => $q->where('marketplace', $marketplace)
            )
            ->whereIn('sku', $activeSKUs);

        $selectedWarehouseIds = $plan->params['warehouse_ids'] ?? null;
        if (!empty($selectedWarehouseIds) && is_array($selectedWarehouseIds)) {
            $warehouseQuery->whereIn('warehouse_id', $selectedWarehouseIds);
        }

        $warehouses = $warehouseQuery->get();

        if ($warehouses->isEmpty() && $constraintNeedSkus === []) {
            $plan->markReady(0, 0, 0, $service->emptyQualityJson());
            return;
        }

        // Загружаем продукты для метаданных (barcode, name, pack_multiple, price)
        $skus = array_values(array_unique(array_merge($warehouses->pluck('sku')->unique()->toArray(), $constraintNeedSkus)));
        // BUG FIX: для Yandex ищем товары и 'yandex', и 'yandex_market' — аналогично inventory
        $productsRaw = Product::where('integration_id', $integrationId)
            ->when(
                in_array($marketplace, ['yandex', 'yandex_market'], true),
                fn ($q) => $q->whereIn('marketplace', ['yandex', 'yandex_market']),
                fn ($q) => $q->where('marketplace', $marketplace)
            )
            ->where(function ($q) use ($skus) {
                $q->whereIn('sku', $skus)->orWhereIn('barcode', $skus);
            })
            ->get();

        $products = collect();
        foreach ($productsRaw as $prod) {
            if (in_array($prod->sku, $skus)) {
                $products->put($prod->sku, $prod);
            }
            if ($prod->barcode && in_array($prod->barcode, $skus) && !$products->has($prod->barcode)) {
                $products->put($prod->barcode, $prod);
            }
            // WB: баркоды размеров внутри wb_data.sizes[].skus[]
            if (!empty($prod->wb_data['sizes'])) {
                foreach ($prod->wb_data['sizes'] as $size) {
                    foreach ($size['skus'] ?? [] as $wbBarcode) {
                        if (in_array($wbBarcode, $skus) && !$products->has($wbBarcode)) {
                            $products->put($wbBarcode, $prod);
                        }
                    }
                }
            }
        }

        // WB: дополнительный JSONB-поиск для баркодов не найденных по sku/barcode
        $notFoundSkus = array_diff($skus, $products->keys()->toArray());
        if (!empty($notFoundSkus) && $marketplace === 'wildberries') {
            $wbExtra = Product::where('integration_id', $integrationId)
                ->where('marketplace', 'wildberries')
                ->whereNotNull('wb_data')
                ->where(function ($q) use ($notFoundSkus) {
                    foreach ($notFoundSkus as $s) {
                        $q->orWhereRaw("wb_data::text LIKE ?", ["%{$s}%"]);
                    }
                })
                ->get();
            foreach ($wbExtra as $prod) {
                if (!empty($prod->wb_data['sizes'])) {
                    foreach ($prod->wb_data['sizes'] as $size) {
                        foreach ($size['skus'] ?? [] as $wbBarcode) {
                            if (in_array($wbBarcode, $notFoundSkus) && !$products->has($wbBarcode)) {
                                $products->put($wbBarcode, $prod);
                            }
                        }
                    }
                }
            }
        }

        // Загружаем UnitEconomics для финансовых метрик
        // Для WB-баркодов размеров строим маппинг barcode → product.sku через $products
        $skusForUe = $skus;
        $barcodeToProductSku = []; // barcode_wh -> sku_in_products (для маппинга UE)
        foreach ($products as $invSku => $prod) {
            if ($prod->sku !== $invSku) {
                // invSku это баркод WB, prod->sku — настоящий SKU товара
                $barcodeToProductSku[$invSku] = $prod->sku;
                if (!in_array($prod->sku, $skusForUe)) {
                    $skusForUe[] = $prod->sku;
                }
            }
        }

        // BUG FIX: для Yandex UE хранится как 'yandex_market' (нормализовано)
        $unitEconomicsRaw = UnitEconomics::where(function ($q) use ($integrationId) {
                $q->where('integration_id', $integrationId)
                  ->orWhereNull('integration_id');
            })
            ->when(
                in_array($marketplace, ['yandex', 'yandex_market'], true),
                fn ($q) => $q->whereIn('marketplace', ['yandex', 'yandex_market']),
                fn ($q) => $q->where('marketplace', $marketplace)
            )
            ->whereIn('sku', $skusForUe)
            ->orderByDesc('integration_id')
            ->get()
            ->keyBy('sku');

        // Строим итоговую карту: invSku → UE (через маппинг для WB-баркодов)
        $unitEconomics = collect();
        foreach ($skus as $invSku) {
            if ($unitEconomicsRaw->has($invSku)) {
                $unitEconomics->put($invSku, $unitEconomicsRaw->get($invSku));
            } elseif (isset($barcodeToProductSku[$invSku]) && $unitEconomicsRaw->has($barcodeToProductSku[$invSku])) {
                $unitEconomics->put($invSku, $unitEconomicsRaw->get($barcodeToProductSku[$invSku]));
            }
        }

        // Load warehouse-to-cluster mapping for geo-distribution
        $clusterMapping = ($marketplace === 'ozon') ? OzonWarehouseCluster::getAllMapping() : [];

        // Ozon now plans FBO supplies by delivery cluster. Calculate demand against
        // the whole cluster stock instead of treating each warehouse as isolated.
        $selectedOzonClusterIds = $marketplace === 'ozon'
            ? $this->selectedOzonClusterIds($plan)
            : [];

        if ($marketplace === 'ozon' && ! empty($clusterMapping)) {
            if ($selectedOzonClusterIds !== []) {
                $warehouses = $warehouses
                    ->filter(function (InventoryWarehouse $warehouse) use ($clusterMapping, $selectedOzonClusterIds): bool {
                        $cluster = $this->resolveOzonCluster($warehouse, $clusterMapping);

                        return $cluster !== null && in_array((int) $cluster['cluster_id'], $selectedOzonClusterIds, true);
                    })
                    ->values();
            }

            $warehouses = $this->aggregateOzonWarehousesByCluster($warehouses, $clusterMapping);
        }

        // Load seller warehouse stocks (optional — if available, limits recommendations)
        $sellerStockMap = SellerWarehouseStock::getStockMap($integrationId);
        $hasSellerStocks = !empty($sellerStockMap);
        $sellerStockConsumed = [];

        // WB v4: Карта себестоимостей по баркодам [barcode => cost_price]
        $wbBarcodeCostMap = ($marketplace === 'wildberries')
            ? WbBarcodeCost::getCostMap($integrationId)
            : [];

        // WB v5: FBS-остатки на складах продавца [sku => total_fbs_qty]
        // FBS-остатки снижают потребность в поставке на склад WB (FBO)
        $wbFbsStockMap = [];
        if ($marketplace === 'wildberries') {
            $fbsRows = InventoryWarehouse::where('integration_id', $integrationId)
                ->where('marketplace', 'wildberries')
                ->where('fulfillment_type', 'fbs')
                ->selectRaw('sku, SUM(quantity) as total_qty')
                ->groupBy('sku')
                ->pluck('total_qty', 'sku')
                ->toArray();
            $wbFbsStockMap = array_map('intval', $fbsRows);
        }

        // Yandex: FBS-остатки на складах продавца (аналогично WB)
        $yandexFbsStockMap = [];
        if (in_array($marketplace, ['yandex', 'yandex_market'], true)) {
            $fbsRows = InventoryWarehouse::where('integration_id', $integrationId)
                ->whereIn('marketplace', ['yandex', 'yandex_market'])
                ->where('fulfillment_type', 'FBS')
                ->selectRaw('sku, SUM(quantity) as total_qty')
                ->groupBy('sku')
                ->pluck('total_qty', 'sku')
                ->toArray();
            $yandexFbsStockMap = array_map('intval', $fbsRows);
        }

        // v2: Загружаем in-transit из активных заявок на поставку
        $supplyInTransit = $service->getInTransitFromSupplies($integrationId);
        $supplyInTransitByCluster = ($marketplace === 'ozon' && ! empty($clusterMapping))
            ? $this->getOzonInTransitFromSuppliesByCluster($integrationId, $clusterMapping)
            : [];

        // v3: Проверяем, загружен ли CSV-отчёт заказов для этой интеграции
        $hasOzonReport = ($marketplace === 'ozon') && OzonOrderReport::where('integration_id', $integrationId)->exists();

        // v2: Загружаем рекомендации Ozon по поставкам (delivery analytics)
        $ozonAnalytics = [];
        $ozonPostingDemand = ['by_warehouse' => [], 'by_cluster' => [], 'by_offer' => [], 'source' => 'posting_fbo_v3', 'days' => $analysisPeriodDays];
        $ozonProductStocks = [];
        // v3: Аналитика остатков и оборачиваемости по SKU×склад (ads_cluster, idc_cluster, turnover_grade_cluster)
        $ozonStockAnalytics = [];
        $ozonStockAnalyticsCluster = [];
        $ozonTurnover = [];
        if ($marketplace === 'ozon') {
            $integration = Integration::find($integrationId);
            if ($integration) {
                $ozonAnalytics = $service->loadOzonDeliveryAnalytics($integration);
                $ozonPostingDemand = $service->loadOzonPostingDemand($integration, $products, $clusterMapping, $analysisPeriodDays);
                $ozonProductStocks = $service->loadOzonProductStocks($integration, $products);

                // v3: Загружаем аналитику остатков из /v1/analytics/stocks и /v1/analytics/turnover/stocks
                $stockAnalyticsData = $service->loadOzonStockAnalytics($integration, $products);
                $ozonStockAnalytics = $stockAnalyticsData['stock_analytics']; // [offer_id => [wh_name => data]]
                $ozonStockAnalyticsCluster = $stockAnalyticsData['stock_analytics_cluster'] ?? []; // [offer_id => [cluster_id => data]]
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

        // v4: Карты избытка и дефицита для матрицы перераспределения
        $surplusMap = [];  // [sku => [warehouse_id => surplus_qty]]
        $deficitMap = [];  // [sku => [warehouse_id => deficit_qty]]

        // Data quality counters (per unique SKU)
        $qStocksCoverage = 0;
        $qSalesHistory = 0;
        $qInTransit = 0;
        $qDestination = 0;
        $qBarcode = 0;
        $qualitySeenSkus = [];
        $totalSkus = $warehouses->pluck('sku')->unique()->count();
        $demandSourceCounts = [];
        $missingSourceCounts = [];
        $fallbackLongLines = 0;
        $negativeProfitLines = 0;
        $skippedNegativeProfitLines = 0;
        $budgetSkippedLines = 0;
        $budgetUsed = 0.0;
        $deprecatedOzonRecommendedLines = 0;
        $manualReviewLines = 0;
        $lowConfidenceTrialLines = 0;
        $advertisingDrivenLines = 0;
        $advertisingHighDrrLines = 0;
        $advertisingProfitAdjustedLines = 0;

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
            $localAvgDailyBeforePosting = (float) $avgDailySales;
            $clusterIdForAnalytics = $wh->getAttribute('cluster_id');
            $postingDemandData = null;
            $postingDemandApplied = false;
            $postingDemandShape = null;
            $postingDemandWeakOfferOnly = false;
            $aggregateDemandShape = null;

            if ($marketplace === 'ozon') {
                if (!$clusterIdForAnalytics && !empty($ozonProductStocks[$wh->sku])) {
                    $currentStock = max((int) $currentStock, (int) ($ozonProductStocks[$wh->sku]['total'] ?? 0));
                }

                if ($clusterIdForAnalytics && !empty($ozonPostingDemand['by_cluster'][$wh->sku][(string) $clusterIdForAnalytics])) {
                    $postingDemandData = $ozonPostingDemand['by_cluster'][$wh->sku][(string) $clusterIdForAnalytics];
                } elseif (! $wh->getAttribute('is_cluster_aggregate') && !empty($ozonPostingDemand['by_offer'][$wh->sku])) {
                    $postingDemandData = $ozonPostingDemand['by_offer'][$wh->sku];
                } elseif ($wh->getAttribute('is_cluster_aggregate') && !empty($ozonPostingDemand['by_offer'][$wh->sku])) {
                    // Общий спрос SKU нельзя размазывать на выбранный кластер:
                    // это главный источник завышения после промо-всплесков.
                    $postingDemandWeakOfferOnly = true;
                }
            }

            // v3: Получаем аналитику Ozon для этого SKU × склад
            $ozonStockData = null;
            $ozonTurnoverData = $ozonTurnover[$wh->sku] ?? null;
            $ozonAdsCluster = null;
            $ozonIdcCluster = null;
            $ozonTurnoverGradeCluster = null;
            $ozonDaysWithoutSalesCluster = null;
            if ($marketplace === 'ozon' && $clusterIdForAnalytics && !empty($ozonStockAnalyticsCluster[$wh->sku][(string) $clusterIdForAnalytics])) {
                $ozonStockData = $ozonStockAnalyticsCluster[$wh->sku][(string) $clusterIdForAnalytics];
                $ozonAdsCluster = $ozonStockData['ads_cluster'] ?? null;
                $ozonIdcCluster = $ozonStockData['idc_cluster'] ?? null;
                $ozonTurnoverGradeCluster = $ozonStockData['turnover_grade_cluster'] ?? null;
                $ozonDaysWithoutSalesCluster = $ozonStockData['days_without_sales_cluster'] ?? null;
            } elseif (!empty($ozonStockAnalytics[$wh->sku])) {
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

            if ($marketplace === 'ozon' && $postingDemandData && (float) ($postingDemandData['avg_daily_sales'] ?? 0) > 0) {
                $postingDemandShape = $service->shapeOzonPostingDemand(
                    $postingDemandData,
                    $localAvgDailyBeforePosting,
                    $ozonAdsCluster !== null ? (float) $ozonAdsCluster : null
                );

                // Trust the actual postings windows instead of max-picking the
                // highest source. max() made promotion spikes sticky and inflated.
                $sales7 = (int) ($postingDemandData['sales_7_days'] ?? 0);
                $sales14 = (int) ($postingDemandData['sales_14_days'] ?? 0);
                $sales30 = (int) ($postingDemandData['sales_30_days'] ?? 0);
                $avgDailySales = (float) ($postingDemandShape['daily_demand'] ?? $postingDemandData['avg_daily_sales']);
                $postingDemandApplied = true;
            }

            // v2: In-transit = API + заявки на поставку
            $inTransitApi = $wh->in_transit ?? 0;
            if ($marketplace === 'ozon' && $wh->getAttribute('is_cluster_aggregate') && $ozonStockData) {
                $apiValidStock = (int) ($ozonStockData['valid_stock_count'] ?? 0);
                $apiAvailableStock = (int) ($ozonStockData['available_stock_count'] ?? 0);
                $apiTransitStock = (int) ($ozonStockData['transit_stock_count'] ?? 0);

                if ($apiValidStock > 0 || $apiAvailableStock > 0) {
                    $currentStock = max($apiValidStock, $apiAvailableStock);
                }
                if ($apiTransitStock > 0) {
                    $inTransitApi = $apiTransitStock;
                }
            }
            $clusterIdForTransit = $wh->getAttribute('cluster_id');
            $inTransitSupplies = ($marketplace === 'ozon' && $clusterIdForTransit)
                ? ($supplyInTransitByCluster[$wh->sku . '|' . $clusterIdForTransit] ?? 0)
                : ($supplyInTransit[$wh->sku] ?? 0);
            $inTransit = $inTransitApi + $inTransitSupplies;
            if (! $includeInTransit) {
                $inTransitApi = 0;
                $inTransitSupplies = 0;
                $inTransit = 0;
            }

            // WB v4: Товары на возврате с клиентов — уже едут обратно на склад
            // in_way_from_client снижает реальную потребность в новой поставке
            $inWayFromClient = 0;
            if ($marketplace === 'wildberries') {
                $inWayFromClient = $wh->in_way_from_client ?? 0;
                // Возвраты частично восполняют потребность (с коэффициентом 0.8 — часть бракуется)
                $inTransit = $inTransit + (int) round($inWayFromClient * 0.8);
            }

            // WB v5: FBS-остатки продавца — товары уже у продавца, можно отгрузить на WB
            // Учитываем как дополнительный "виртуальный транзит" (товар уже есть, нужна только отгрузка)
            $wbFbsStock = 0;
            if ($marketplace === 'wildberries') {
                $wbFbsStock = $wbFbsStockMap[$wh->sku] ?? 0;
                if ($wbFbsStock > 0) {
                    // FBS = уже на складе продавца, учитываем как in-transit с коэффициентом 1.0
                    $inTransit = $inTransit + $wbFbsStock;
                }
            }

            // Yandex: FBS-остатки продавца — аналогично WB
            $yandexFbsStock = 0;
            if (in_array($marketplace, ['yandex', 'yandex_market'], true)) {
                $yandexFbsStock = $yandexFbsStockMap[$wh->sku] ?? 0;
                if ($yandexFbsStock > 0) {
                    // FBS = уже на складе продавца, учитываем как in-transit
                    $inTransit = $inTransit + $yandexFbsStock;
                }
            }

            // v2: Данные для улучшенного прогноза
            $realAvgDailySales = $wh->real_avg_daily_sales ?? 0;
            $effectiveDailySales = $wh->effective_daily_sales ?? 0;
            $realAvgDemandSource = 'ozon_order_report';
            if ($postingDemandApplied && $realAvgDailySales <= 0) {
                $realAvgDailySales = (float) ($postingDemandShape['daily_demand'] ?? $postingDemandData['avg_daily_sales'] ?? 0);
                $realAvgDemandSource = 'posting_fbo_v3';
            }
            if ($postingDemandApplied && $effectiveDailySales <= 0) {
                $effectiveDailySales = (float) ($postingDemandShape['daily_demand'] ?? $postingDemandData['avg_daily_sales'] ?? 0);
            }
            $daysInStock30 = $wh->days_in_stock_30 ?? 30;

            // WB v4: % выкупа — вычисляем из реальных данных unit_economics если есть
            $redemptionRate = 100;
            if ($ue) {
                if ($ue->redemption_rate > 0 && $ue->redemption_rate < 100) {
                    // Есть явный % выкупа — используем
                    $redemptionRate = (float) $ue->redemption_rate;
                } elseif ($marketplace === 'wildberries' && ($ue->orders_count ?? 0) > 0 && ($ue->returns_count ?? 0) >= 0) {
                    // Вычисляем из заказов/возвратов
                    $calcRate = (($ue->orders_count - $ue->returns_count) / $ue->orders_count) * 100;
                    if ($calcRate > 10 && $calcRate <= 100) {
                        $redemptionRate = round($calcRate, 1);
                    }
                }
                // Если ничего нет — для WB дефолт 85% (реальный средний по рынку)
                if ($marketplace === 'wildberries' && $redemptionRate === 100 && ($ue->orders_count ?? 0) === 0) {
                    $redemptionRate = 85.0;
                }
            } elseif ($marketplace === 'wildberries') {
                // Нет UE данных для WB — дефолтный % выкупа 85%
                $redemptionRate = 85.0;
            }

            // --- Тренд продаж (улучшенный: 3 периода + учёт OOS) ---
            $salesTrend = 'stable';
            $salesTrendPercent = 0;

            $avg7 = $sales7 > 0 ? $sales7 / 7 : 0;
            $avg14 = $sales14 > 0 ? $sales14 / 14 : 0;
            $avg30 = ($daysInStock30 > 0 && $daysInStock30 < 25 && $effectiveDailySales > 0)
                ? $effectiveDailySales
                : ($sales30 > 0 ? $sales30 / 30 : 0);

            if ($avg30 > 0 && $sales14 > 0) {
                // Fix H11: возвраты и корректировки могут сделать sales14<sales7
                // или sales30<sales14 → avg_*_Avg становится отрицательным, и
                // salesTrendPercent улетал в +-1000%+. Клампим к [-100..+100] чтобы
                // тренд не уводил priority-score в крайности.
                $avg8_14 = max(0, ($sales14 - $sales7) / 7);
                $shortTrend = $avg8_14 > 0 ? (($avg7 - $avg8_14) / $avg8_14) * 100 : 0;
                $older16Avg = max(0, ($sales30 - $sales14) / 16);
                $midTrend = $older16Avg > 0 ? (($avg14 - $older16Avg) / $older16Avg) * 100 : 0;

                $shortTrend = max(-100.0, min(100.0, $shortTrend));
                $midTrend = max(-100.0, min(100.0, $midTrend));
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
                $avgDailySales, $realAvgDemandSource
            );
            $dailyDemand = $demandResult['daily_demand'];
            $demandSource = $demandResult['source'];
            $needsManualReview = $demandResult['needs_manual_review'];

            if ($marketplace === 'ozon' && ! $postingDemandApplied && $dailyDemand > 0) {
                $aggregateDemandShape = $service->shapeOzonAggregateDemand(
                    $dailyDemand,
                    (float) $sales7,
                    (float) $sales14,
                    (float) $sales30,
                    (float) $avgDailySales
                );

                $dailyDemand = min($dailyDemand, (float) ($aggregateDemandShape['daily_demand'] ?? $dailyDemand));
                $demandSource = 'ozon_aggregate_robust';
                $needsManualReview = true;
            }

            if ($seasonalityMultiplier !== 1.0 && $dailyDemand > 0) {
                $dailyDemand *= $seasonalityMultiplier;
            }
            if ($trendMultiplier !== 1.0 && $dailyDemand > 0) {
                $dailyDemand *= $trendMultiplier;
            }

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

            $postingOrderedUnits = (int) ($postingDemandData['ordered_units_total'] ?? 0);
            $isPostingLowVolume = $postingDemandApplied && $postingOrderedUnits > 0 && $postingOrderedUnits < 5;
            $isPostingSpikeSuspected = (bool) ($postingDemandShape['suspected_spike'] ?? false);
            $isAggregateDemandSpikeSuspected = (bool) ($aggregateDemandShape['suspected_spike'] ?? false);
            $isAggregateDemandWeak = $aggregateDemandShape !== null
                && in_array(($aggregateDemandShape['confidence_level'] ?? 'warning'), ['warning', 'low', 'bad'], true);
            $isLowDemandTrial = $marketplace === 'ozon' && (
                ($sales30 > 0 && $sales30 < 3 && ! $postingDemandApplied)
                || $isPostingLowVolume
                || $isPostingSpikeSuspected
                || $isAggregateDemandSpikeSuspected
                || $isAggregateDemandWeak
                || $postingDemandWeakOfferOnly
            );
            if ($isLowDemandTrial) {
                $targetCoverDays = min($targetCoverDays, ($isPostingSpikeSuspected || $isAggregateDemandSpikeSuspected) ? 7 : 14);
                $needsManualReview = true;
                if (! str_contains($demandSource, '_low_confidence_trial')) {
                    $demandSource = $demandSource . '_low_confidence_trial';
                }
                $lowConfidenceTrialLines++;
            }
            if (in_array($promoMode, ['cautious', 'post_promo'], true) && $marketplace === 'ozon') {
                $targetCoverDays = min($targetCoverDays, 14);
                $needsManualReview = true;
            }

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
            if ($isLowDemandTrial) {
                $safetyStock = min($safetyStock, $dailyDemand * 3);
            }
            if (in_array($promoMode, ['cautious', 'post_promo'], true) && $marketplace === 'ozon') {
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
            if ($marketplace === 'ozon' && $wh->getAttribute('cluster_id')) {
                $clusterId = $wh->getAttribute('cluster_id');
                $clusterName = $wh->getAttribute('cluster_name');
                $clusterRegion = $wh->getAttribute('cluster_region');
            } elseif (!empty($clusterMapping) && $wh->warehouse_name) {
                $normalizedName = OzonWarehouseCluster::normalizeWarehouseName($wh->warehouse_name);
                if (isset($clusterMapping[$normalizedName])) {
                    $clusterId = $clusterMapping[$normalizedName]['cluster_id'];
                    $clusterName = $clusterMapping[$normalizedName]['cluster_name'];
                    $clusterRegion = $clusterMapping[$normalizedName]['region'];
                }
            }

            // Старый Ozon per-SKU/per-cluster recommended_supply больше не
            // является источником количества: публичный метод с ним obsolete,
            // а новый planning engine считает qty из фактов спроса/остатков.
            $ozonSkuData = $ozonAnalytics[$wh->sku] ?? null;
            $deprecatedOzonRecommendedSupply = null;
            if ($clusterId && isset($ozonSkuData['clusters'][$clusterId]['recommended_supply'])) {
                $deprecatedOzonRecommendedSupply = (int) $ozonSkuData['clusters'][$clusterId]['recommended_supply'];
            }
            $deprecatedOzonRecommendedSupply = $deprecatedOzonRecommendedSupply ?? ($ozonSkuData['total_recommended_supply'] ?? null);
            $ozonRecommendedSupply = null;
            $ozonLostProfit = $clusterId && isset($ozonSkuData['clusters'][$clusterId]['lost_profit'])
                ? (float) $ozonSkuData['clusters'][$clusterId]['lost_profit']
                : (float) ($ozonSkuData['total_lost_profit'] ?? 0);
            $ozonAvgDeliveryTime = $clusterId && isset($ozonSkuData['clusters'][$clusterId]['average_delivery_time'])
                ? $ozonSkuData['clusters'][$clusterId]['average_delivery_time']
                : ($ozonSkuData['max_delivery_time'] ?? null);
            $ozonAttentionLevel = $clusterId && isset($ozonSkuData['clusters'][$clusterId]['attention_level'])
                ? $ozonSkuData['clusters'][$clusterId]['attention_level']
                : ($ozonSkuData['max_attention_level'] ?? null);
            if ($marketplace === 'ozon' && $deprecatedOzonRecommendedSupply !== null && $deprecatedOzonRecommendedSupply > 0) {
                $deprecatedOzonRecommendedLines++;
            }

            $quantityGuardResult = [
                'qty' => null,
                'applied' => false,
                'cap_qty' => null,
                'trial_cover_days' => null,
                'reason' => null,
                'reasons' => [],
            ];

            // --- Округление qty ---
            $packMultiple = $settings->default_pack_multiple ?? 1;
            if ($product && isset($product->ozon_data['pack_multiple'])) {
                $packMultiple = max(1, (int) $product->ozon_data['pack_multiple']);
            }
            $qtyRounded = $service->roundToPackMultiple($needed, $packMultiple);
            $internalQtyRounded = $qtyRounded;

            if ($marketplace === 'ozon' && $ozonRecommendedSupply !== null && $ozonRecommendedSupply >= 0 && $ozonQtyAnchor !== 'internal') {
                $ozonQtyRounded = $service->roundToPackMultiple((float) $ozonRecommendedSupply, $packMultiple);
                $qtyRounded = match ($ozonQtyAnchor) {
                    'ozon' => $ozonQtyRounded,
                    'min' => min($internalQtyRounded, $ozonQtyRounded),
                    'max' => max($internalQtyRounded, $ozonQtyRounded),
                    'average' => $service->roundToPackMultiple(($internalQtyRounded + $ozonQtyRounded) / 2, $packMultiple),
                    default => $internalQtyRounded,
                };
            }

            if ($marketplace === 'ozon') {
                $quantityGuardReasons = [];
                if ($postingDemandWeakOfferOnly) {
                    $quantityGuardReasons[] = 'cluster_posting_demand_missing';
                }
                if ($postingDemandShape && !empty($postingDemandShape['confidence_reasons'])) {
                    $quantityGuardReasons = array_merge($quantityGuardReasons, (array) $postingDemandShape['confidence_reasons']);
                }
                if ($aggregateDemandShape && !empty($aggregateDemandShape['confidence_reasons'])) {
                    $quantityGuardReasons = array_merge($quantityGuardReasons, (array) $aggregateDemandShape['confidence_reasons']);
                }

                $quantityGuardResult = $service->applyProtectiveQuantityGuard(
                    qty: $qtyRounded,
                    dailyDemand: (float) $dailyDemand,
                    currentStock: (int) $currentStock,
                    inTransit: (int) $inTransit,
                    packMultiple: (int) $packMultiple,
                    confidenceReasons: $quantityGuardReasons,
                    lowConfidenceTrial: (bool) $isLowDemandTrial,
                    promoMode: $promoMode,
                    marketplace: $marketplace,
                );

                if (!empty($quantityGuardResult['applied'])) {
                    $qtyRounded = (int) $quantityGuardResult['qty'];
                    $needed = min((float) $needed, (float) $qtyRounded);
                    $capsApplied[] = 'protective_trial_quantity';
                    $needsManualReview = true;
                    if (! str_contains($demandSource, '_protected_trial')) {
                        $demandSource .= '_protected_trial';
                    }
                }
            }

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

            // v5: Матрица перераспределения — surplus/deficit по реальному состоянию склада,
            // взаимоисключающие. Один склад для одного SKU попадает либо в surplus, либо в deficit.
            $whKey = $wh->warehouse_id ?? $wh->warehouse_name ?? 'unknown';
            if ($dailyDemand > 0) {
                $availableNow = $currentStock + $inTransit;
                $targetStockUnits = $targetCoverDays * $dailyDemand;
                $minStockUnits = $minCoverDays * $dailyDemand;

                if ($availableNow > $targetStockUnits) {
                    // Реальный избыток сверх target_cover — кандидат на отгрузку другому складу
                    $surplusQty = (int) floor($availableNow - $targetStockUnits);
                    if ($surplusQty > 0) {
                        $surplusMap[$wh->sku][$whKey] = [
                            'qty'            => $surplusQty,
                            'warehouse_name' => $wh->warehouse_name,
                            'daily_demand'   => $dailyDemand,
                            'current_stock'  => $currentStock,
                        ];
                    }
                } elseif ($availableNow < $minStockUnits && $qtyRounded > 0) {
                    // Реальная нехватка ниже min_cover, и план запросил поставку — можно покрыть
                    // переброской вместо новой закупки. Берём минимум из плановой qty и нехватки.
                    $deficitQty = (int) min($qtyRounded, ceil($minStockUnits - $availableNow));
                    if ($deficitQty > 0) {
                        $deficitMap[$wh->sku][$whKey] = [
                            'qty'            => $deficitQty,
                            'warehouse_name' => $wh->warehouse_name,
                            'daily_demand'   => $dailyDemand,
                            'current_stock'  => $currentStock,
                        ];
                    }
                }
            }

            // --- Финансовые метрики ---
            $offerId = $wh->sku;
            $advertisingImpact = $marketplace === 'ozon' && isset($ozonAdvertisingByOffer[$offerId]) && is_array($ozonAdvertisingByOffer[$offerId])
                ? $ozonAdvertisingByOffer[$offerId]
                : null;
            $barcode = $product?->barcode;
            $price = $product?->price ?? $ue?->price ?? 0;

            // WB v4: себестоимость по баркоду (приоритет) > unit_economics > inventory
            $costPrice = 0;
            if ($marketplace === 'wildberries' && $barcode && isset($wbBarcodeCostMap[$barcode])) {
                $costPrice = $wbBarcodeCostMap[$barcode];
            } else {
                $costPrice = $ue?->cost_price ?? 0;
            }
            $storageCostMonthly = $wh->storage_cost_per_month > 0
                ? $wh->storage_cost_per_month
                : ($ue?->storage_cost ?? 0);
            $storageCostDaily = $wh->storage_cost_per_day > 0
                ? $wh->storage_cost_per_day
                : ($storageCostMonthly > 0 ? round($storageCostMonthly / 30, 2) : 0);
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
            $expectedProfitBeforeAds = $expectedProfit;
            $advertisingSpendPerOrder = $advertisingImpact ? (float) ($advertisingImpact['ad_spend_per_order'] ?? 0) : 0.0;
            $expectedAdvertisingCost = $advertisingSpendPerOrder > 0
                ? $advertisingSpendPerOrder * $dailyDemand * $targetCoverDays
                : 0.0;
            if ($expectedAdvertisingCost > 0) {
                $expectedProfit -= $expectedAdvertisingCost;
                $advertisingProfitAdjustedLines++;
            }
            if ($advertisingImpact && in_array('ads_driven_demand', (array) ($advertisingImpact['signals'] ?? []), true)) {
                $advertisingDrivenLines++;
            }
            if ($advertisingImpact && in_array('high_ad_cost', (array) ($advertisingImpact['signals'] ?? []), true)) {
                $advertisingHighDrrLines++;
            }
            $roiPercent = $supplyCostEstimate > 0 ? round(($expectedProfit / $supplyCostEstimate) * 100, 2) : 0;
            $turnoverDays = $dailyDemand > 0 ? round(($currentStock + $inTransit + $qtyRounded) / $dailyDemand, 1) : null;

            // --- v2: Улучшенный приоритет (ABC + маржа + Ozon lost profit) ---
            $priorityResult = $service->calculatePriorityScoreV2(
                $abcPriority, $oosDate, $coverBefore, $minCoverDays,
                $salesTrend, $marginPercent, $ozonLostProfit, $lostRevenueDaily
            );
            $priorityScore = $priorityResult['score'];
            $priority = $priorityResult['priority'];
            $missingSources = $service->detectMissingSources($wh, $product, $marketplace, $ue, $hasOzonReport, $postingDemandApplied);
            $demandSourceCounts[$demandSource] = ($demandSourceCounts[$demandSource] ?? 0) + 1;
            if (str_contains($demandSource, 'fallback_long')) {
                $fallbackLongLines++;
            }
            if ($needsManualReview) {
                $manualReviewLines++;
            }
            foreach ($missingSources as $missingSource) {
                $missingSourceCounts[$missingSource] = ($missingSourceCounts[$missingSource] ?? 0) + 1;
            }
            if ($expectedProfit < 0) {
                $negativeProfitLines++;
            }

            $confidenceReasons = [];
            if ($postingDemandWeakOfferOnly) {
                $confidenceReasons[] = 'cluster_posting_demand_missing';
            }
            if ($postingDemandShape && !empty($postingDemandShape['confidence_reasons'])) {
                $confidenceReasons = array_merge($confidenceReasons, (array) $postingDemandShape['confidence_reasons']);
            }
            if ($aggregateDemandShape && !empty($aggregateDemandShape['confidence_reasons'])) {
                $confidenceReasons = array_merge($confidenceReasons, (array) $aggregateDemandShape['confidence_reasons']);
            }
            if (!empty($quantityGuardResult['applied']) && !empty($quantityGuardResult['reasons'])) {
                $confidenceReasons = array_merge($confidenceReasons, (array) $quantityGuardResult['reasons']);
            }
            if ($expectedProfit < 0) {
                $confidenceReasons[] = 'negative_expected_profit';
            }
            foreach ((array) ($advertisingImpact['signals'] ?? []) as $advertisingSignal) {
                $confidenceReasons[] = 'performance_' . (string) $advertisingSignal;
            }
            $confidenceReasons = array_values(array_unique($confidenceReasons));
            $confidenceLevel = 'good';
            if ($isLowDemandTrial || ($postingDemandShape['confidence_level'] ?? null) === 'low' || ($aggregateDemandShape['confidence_level'] ?? null) === 'low') {
                $confidenceLevel = 'low';
            } elseif ($needsManualReview || $confidenceReasons !== [] || ($postingDemandShape['confidence_level'] ?? null) === 'warning' || ($aggregateDemandShape['confidence_level'] ?? null) === 'warning') {
                $confidenceLevel = 'warning';
            }

            // --- Explain JSON (v2: расширенный) ---
            $shortAvg = $sales7 > 0 ? $sales7 / 7 : 0;
            $longAvg = $sales30 > 0 ? $sales30 / 30 : 0;

            $explainJson = [
                'version' => 3,
                'inputs' => [
                    'supply_type' => $supplyType,
                    'stock_now' => $currentStock,
                    'stock_scope' => $marketplace === 'ozon' && $wh->getAttribute('is_cluster_aggregate') ? 'cluster' : 'warehouse',
                    'cluster_warehouse_names' => $wh->getAttribute('cluster_warehouse_names') ?? null,
                    'in_transit_api' => $inTransitApi,
                    'in_transit_supplies' => $inTransitSupplies,
                    'in_way_from_client' => $inWayFromClient,
                    'fbs_stock' => $wbFbsStock + $yandexFbsStock,
                    'fbs_stock_wb' => $wbFbsStock,
                    'fbs_stock_yandex' => $yandexFbsStock,
                    'in_transit_total' => $inTransit,
                    'daily_demand' => round($dailyDemand, 4),
                    'demand_source' => $demandSource,
                    'analysis_period_days' => $analysisPeriodDays,
                    'demand_seasonality_multiplier' => $seasonalityMultiplier,
                    'demand_trend_multiplier' => $trendMultiplier,
                    'promo_mode' => $promoMode,
                    'ewma_alpha' => $ewmaAlpha,
                    'sales_7d' => $sales7,
                    'sales_14d' => $sales14,
                    'sales_30d' => $sales30,
                    'short_avg' => round($shortAvg, 4),
                    'long_avg' => round($longAvg, 4),
                    'real_avg_daily_sales' => round($realAvgDailySales, 4),
                    'effective_daily_sales' => round($effectiveDailySales, 4),
                    'posting_fbo_v3_avg_daily_sales' => $postingDemandApplied ? round((float) ($postingDemandData['avg_daily_sales'] ?? 0), 4) : null,
                    'posting_fbo_v3_robust_daily_sales' => $postingDemandApplied ? round((float) ($postingDemandShape['daily_demand'] ?? 0), 4) : null,
                    'posting_fbo_v3_ordered_units' => $postingDemandApplied ? (int) ($postingDemandData['ordered_units_total'] ?? 0) : null,
                    'posting_fbo_v3_analysis_days' => $postingDemandApplied ? (int) ($ozonPostingDemand['days'] ?? $analysisPeriodDays) : null,
                    'posting_fbo_v3_shape' => $postingDemandShape,
                    'ozon_aggregate_input_daily_sales' => $aggregateDemandShape ? round((float) ($aggregateDemandShape['input_daily_demand'] ?? 0), 4) : null,
                    'ozon_aggregate_robust_daily_sales' => $aggregateDemandShape ? round((float) ($aggregateDemandShape['daily_demand'] ?? 0), 4) : null,
                    'ozon_aggregate_demand_shape' => $aggregateDemandShape,
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
                    'internal_qty_rounded' => $internalQtyRounded,
                    'qty_anchor' => $marketplace === 'ozon' ? $ozonQtyAnchor : 'internal',
                    'requested_qty_anchor' => $marketplace === 'ozon' ? $requestedOzonQtyAnchor : 'internal',
                    'qty_anchor_policy' => $ozonQtyAnchorWasDeprecated
                        ? 'Старый сигнал Ozon по рекомендуемому количеству отключён: количество считает внутренний модуль планирования.'
                        : null,
                    'ozon_recommended_supply_used' => $ozonRecommendedSupply,
                    'deprecated_ozon_recommended_supply_seen' => $deprecatedOzonRecommendedSupply,
                    'protective_quantity_guard' => $quantityGuardResult,
                    'qty_rounded' => $qtyRounded,
                    'cover_before' => round($coverBefore, 2),
                    'cover_after' => round($coverAfter, 2),
                ],
                'confidence' => [
                    'needs_manual_review' => $needsManualReview,
                    'missing_sources' => $missingSources,
                    'fallbacks' => $dailyDemand === 0.0 ? ['no_sales_data'] : [],
                    'low_confidence_trial' => $isLowDemandTrial,
                    'confidence_level' => $confidenceLevel,
                    'confidence_reasons' => $confidenceReasons,
                    'sources' => [
                        'demand' => $demandSource,
                        'stock' => $ozonStockData ? 'analytics_stocks' : (!empty($ozonProductStocks[$wh->sku]) ? 'product_info_stocks' : 'inventory_warehouses'),
                        'turnover' => $ozonTurnoverData ? 'turnover_stocks' : null,
                        'in_transit' => $inTransitSupplies > 0 ? 'supply_orders' : ($inTransitApi > 0 ? 'marketplace_inventory' : null),
                    ],
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
                    'deprecated_recommended_supply' => $deprecatedOzonRecommendedSupply,
                    'recommended_supply_policy' => 'deprecated_not_used_for_quantity',
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
                    'expected_profit_before_ads' => round($expectedProfitBeforeAds, 2),
                    'expected_ads_cost' => round($expectedAdvertisingCost, 2),
                    'expected_profit_after_ads' => round($expectedProfit, 2),
                ],
                'ozon_performance' => [
                    'source' => $advertisingImpact ? 'ozon_performance_product_report' : null,
                    'report_uuid' => $ozonAdvertisingImpact['uuid'] ?? null,
                    'offer_id' => $advertisingImpact['offer_id'] ?? null,
                    'ozon_sku' => $advertisingImpact['ozon_sku'] ?? null,
                    'ad_enabled' => $advertisingImpact['ad_enabled'] ?? null,
                    'ad_spend' => $advertisingImpact['ad_spend'] ?? null,
                    'ad_revenue' => $advertisingImpact['ad_revenue'] ?? null,
                    'ad_orders' => $advertisingImpact['ad_orders'] ?? null,
                    'ad_drr_percent' => $advertisingImpact['ad_drr_percent'] ?? null,
                    'ad_spend_per_order' => $advertisingImpact['ad_spend_per_order'] ?? null,
                    'signals' => $advertisingImpact['signals'] ?? [],
                    'signals_ru' => $advertisingImpact['signals_ru'] ?? [],
                ],
                'regional_demand' => [
                    'source' => $ue?->marketplace_data ? 'unit_economics.marketplace_data' : null,
                    'route_label' => $ue?->route_label,
                    'expected_locality_rate' => $this->numericOrNull($ue?->marketplace_data['expected_locality_rate'] ?? null),
                    'dominant_demand_cluster_id' => $ue?->marketplace_data['dominant_demand_cluster_id'] ?? null,
                    'dominant_demand_cluster_share' => $this->numericOrNull($ue?->marketplace_data['dominant_demand_cluster_share'] ?? null),
                    'dominant_sales_cluster_id' => $ue?->marketplace_data['dominant_sales_cluster_id'] ?? null,
                    'dominant_sales_cluster_share' => $this->numericOrNull($ue?->marketplace_data['dominant_sales_cluster_share'] ?? null),
                    'dominant_stock_cluster_id' => $ue?->marketplace_data['dominant_stock_cluster_id'] ?? null,
                    'dominant_stock_cluster_share' => $this->numericOrNull($ue?->marketplace_data['dominant_stock_cluster_share'] ?? null),
                    'clusters_summary' => $this->compactRegionalProfile($ue?->marketplace_data['clusters_summary'] ?? null),
                    'sales_profile' => $this->compactRegionalProfile($ue?->marketplace_data['sales_profile'] ?? null),
                    'delivery_fo_profile' => $this->compactRegionalProfile(
                        $ue?->marketplace_data['delivery_fo_profile']
                            ?? $ue?->marketplace_data['by_delivery_fo']
                            ?? null
                    ),
                    'warehouse_sales_profile' => $this->compactRegionalProfile($ue?->marketplace_data['warehouse_sales_profile'] ?? null),
                    'stock_profile' => $this->compactRegionalProfile($ue?->marketplace_data['stock_profile'] ?? null),
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
                'destination_type' => $marketplace === 'ozon' && $clusterId ? 'cluster' : $destinationType,
                'qty_recommended' => round($needed, 2),
                'qty_rounded' => $qtyRounded,
                'current_stock' => $currentStock,
                'in_transit' => $inTransit,
                'sales_7_days' => $sales7,
                'sales_14_days' => $sales14,
                'sales_30_days' => $sales30,
                'avg_daily_sales' => round($avgDailySales, 4),
                'ewma_daily_sales' => round($dailyDemand, 4),
                'demand_daily' => round($dailyDemand, 4),
                'sales_trend' => $salesTrend,
                'sales_trend_percent' => $salesTrendPercent,
                'cover_days_before' => round($coverBefore, 2),
                'cover_days_after' => round($coverAfter, 2),
                'oos_date' => $oosDate,
                'surplus_days' => $surplusDays,
                'storage_cost_daily' => round($storageCostDaily, 2),
                'storage_cost_monthly' => round($storageCostMonthly, 2),
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

        // --- Locality enrichment + cluster split (Ozon only) ---
        if ($marketplace === 'ozon' && ! empty($lines)) {
            try {
                $enricher = app(\App\Domains\Locality\Integration\LocalityEnrichmentService::class);

                // split_by_cluster: per-план через params > per-integration через SupplySettings > default true
                $splitByCluster = array_key_exists('split_by_cluster', $plan->params ?? [])
                    ? (bool) $plan->params['split_by_cluster']
                    : (bool) ($settings->locality_split_default ?? true);
                $minConfidence = $plan->params['minimum_locality_confidence']
                    ?? ($settings->locality_min_confidence_default ?? \App\Domains\Locality\Integration\LocalityEnrichmentService::DEFAULT_MIN_CONFIDENCE);
                $maxClusters = (int) ($settings->locality_max_split_clusters
                    ?? \App\Domains\Locality\Integration\LocalityEnrichmentService::DEFAULT_MAX_CLUSTERS);
                $strategy = (string) ($plan->params['locality_distribution_strategy']
                    ?? \App\Domains\Locality\Integration\LocalityEnrichmentService::STRATEGY_RECOMMENDATIONS);

                $skuList = array_values(array_unique(array_column($lines, 'sku')));
                $metrics = $enricher->loadMetricsForSkus((int) $integrationId, $skuList);
                // Для выбранного Ozon-кластера рекомендации не должны расширять план на другие кластеры.
                $recsPerSku = $enricher->loadRecommendationsForSkus(
                    (int) $integrationId,
                    $skuList,
                    (string) $minConfidence,
                    $selectedOzonClusterIds !== [] ? $selectedOzonClusterIds : null
                );

                $enriched = [];
                foreach ($lines as $line) {
                    $sku = (string) $line['sku'];
                    $metric = $metrics[$sku] ?? null;
                    $recs = $recsPerSku[$sku] ?? collect([]);

                    // 1) Cluster split (если включено и есть что делить)
                    if ($splitByCluster && ! empty($recs) && $recs->isNotEmpty()) {
                        $ozonClusterData = $ozonAnalytics[$sku]['clusters'] ?? [];
                        $packMultiple = (int) ($settings->default_pack_multiple ?? 1);

                        $split = $enricher->applyClusterSplit(
                            $line,
                            $recs,
                            $ozonClusterData,
                            (string) $strategy,
                            $maxClusters,
                            max(1, $packMultiple)
                        );

                        foreach ($split['children'] as $child) {
                            $enrichedChild = $enricher->enrichLine($child, $metric, $recs, null);
                            $enrichedChild['created_at'] = $child['created_at'] ?? now();
                            $enrichedChild['updated_at'] = $child['updated_at'] ?? now();
                            // array-поля → json для bulk insert
                            foreach (['cluster_split_json', 'linked_locality_recommendation_ids'] as $jsonKey) {
                                if (isset($enrichedChild[$jsonKey]) && is_array($enrichedChild[$jsonKey])) {
                                    $enrichedChild[$jsonKey] = json_encode($enrichedChild[$jsonKey]);
                                }
                            }
                            $enriched[] = $enrichedChild;
                        }
                        continue;
                    }

                    // 2) Только enrichment без split
                    $enrichedLine = $enricher->enrichLine($line, $metric, $recs, null);
                    foreach (['linked_locality_recommendation_ids'] as $jsonKey) {
                        if (isset($enrichedLine[$jsonKey]) && is_array($enrichedLine[$jsonKey])) {
                            $enrichedLine[$jsonKey] = json_encode($enrichedLine[$jsonKey]);
                        }
                    }
                    $enriched[] = $enrichedLine;
                }

                $lines = $enriched;
                $totalLines = count($lines);
                $totalQty = array_sum(array_column($lines, 'qty_rounded'));
            } catch (\Throwable $e) {
                Log::warning('Locality enrichment failed, using plain lines', [
                    'plan_id' => $plan->id,
                    'error' => $e->getMessage(),
                    'trace' => substr($e->getTraceAsString(), 0, 1000),
                ]);
            }
        }

        if ($marketplace === 'ozon' && $selectedOzonClusterIds !== []) {
            $lines = array_values(array_filter($lines, function (array $line) use ($selectedOzonClusterIds): bool {
                $clusterId = (int) ($line['cluster_id'] ?? 0);

                return $clusterId > 0 && in_array($clusterId, $selectedOzonClusterIds, true);
            }));
            $totalLines = count($lines);
            $totalQty = array_sum(array_map(fn (array $line) => (int) ($line['qty_rounded'] ?? 0), $lines));
        }

        $lines = $constraintService->appendMarketplaceNeedCandidates($lines, $plan, $marketplace, $products, $unitEconomics);
        $candidateLinesBeforeConstraints = count($lines);
        $candidateQtyBeforeConstraints = array_sum(array_map(fn (array $line): int => (int) ($line['qty_rounded'] ?? 0), $lines));
        $constraintResult = $constraintService->apply($lines, $plan, $marketplace);
        $lines = $constraintResult['lines'];
        $constraintsSummary = $constraintResult['summary'];

        $lines = app(TerritorialPlanningService::class)->enrichLines($lines, $plan);

        $budgetLimit = (float) ($plan->budget_limit ?? 0);
        $optimization = app(PlanLineOptimizer::class)->optimize($lines, $plan, [
            'min_cover_days' => $minCoverDays,
            'marketplace' => $marketplace,
            'source_candidates_total' => $candidateLinesBeforeConstraints,
            'source_qty_total' => $candidateQtyBeforeConstraints,
            'constraints_summary' => $constraintsSummary,
        ]);
        $lines = $optimization['lines'];
        $selectionSummary = $optimization['summary'];
        $skippedNegativeProfitLines = (int) ($selectionSummary['negative_profit_skipped_lines'] ?? 0);
        $budgetSkippedLines = (int) ($selectionSummary['budget_skipped_lines'] ?? 0);
        $budgetUsed = (float) ($selectionSummary['budget_used'] ?? 0);
        $totalLines = count($lines);
        $totalQty = array_sum(array_map(fn (array $line) => (int) ($line['qty_rounded'] ?? 0), $lines));

        // Bulk insert lines
        if (!empty($lines)) {
            foreach (array_chunk($lines, 500) as $chunk) {
                AutoSupplyPlanLine::insert($chunk);
            }
        }

        // v6: Матрица перераспределения — отключена для Ozon/WB FBO.
        // В FBO продавец физически не может перевозить товар между складами маркетплейса.
        // Эта секция применима только для собственных складов / 3PL-режимов.
        $redistributionSuggestions = [];
        $redistributionAllowed = ! in_array($marketplace, ['ozon', 'wildberries'], true);

        if ($redistributionAllowed) foreach ($deficitMap as $sku => $deficitWarehouses) {
            if (empty($surplusMap[$sku])) continue;

            // Локальные копии, чтобы уменьшать остатки по мере матчинга
            $surplusRemaining = [];
            foreach ($surplusMap[$sku] as $sWhKey => $sInfo) {
                $surplusRemaining[$sWhKey] = $sInfo;
            }
            $deficitRemaining = [];
            foreach ($deficitWarehouses as $dWhKey => $dInfo) {
                $deficitRemaining[$dWhKey] = $dInfo;
            }

            // Сортируем дефицит по убыванию qty (сначала самые «голодные» склады)
            uasort($deficitRemaining, fn ($a, $b) => $b['qty'] <=> $a['qty']);

            $product = $products->get($sku);
            $ue = $unitEconomics->get($sku);
            $costPrice = $ue?->cost_price ?? 0;

            foreach ($deficitRemaining as $defWhKey => &$defInfo) {
                if ($defInfo['qty'] <= 0) continue;

                // Сортируем surplus по убыванию qty — отдаём приоритет самому большому донору
                uasort($surplusRemaining, fn ($a, $b) => $b['qty'] <=> $a['qty']);

                foreach ($surplusRemaining as $surWhKey => &$surInfo) {
                    if ($surInfo['qty'] <= 0) continue;
                    if ($surWhKey === $defWhKey) continue;

                    $transferQty = (int) min($defInfo['qty'], $surInfo['qty']);
                    if ($transferQty <= 0) continue;

                    $redistributionSuggestions[] = [
                        'sku'            => $sku,
                        'product_name'   => $product?->name,
                        'from_warehouse' => $surInfo['warehouse_name'],
                        'to_warehouse'   => $defInfo['warehouse_name'],
                        'transfer_qty'   => $transferQty,
                        'saves_cost'     => $costPrice > 0 ? round($transferQty * $costPrice, 2) : null,
                        'surplus_qty'    => $surInfo['qty'],
                        'deficit_qty'    => $defInfo['qty'],
                    ];

                    // Уменьшаем остатки — больше эта пара не сматчится сама с собой
                    // и не создастся симметричная (т.к. surplus/deficit взаимоисключающи на этапе сборки)
                    $surInfo['qty'] -= $transferQty;
                    $defInfo['qty'] -= $transferQty;

                    if ($defInfo['qty'] <= 0) break;
                }
                unset($surInfo);
            }
            unset($defInfo);
        }

        $economicsSummary = [
            'total_supply_cost' => 0.0,
            'total_expected_revenue' => 0.0,
            'total_expected_profit' => 0.0,
            'budget_limit' => $budgetLimit > 0 ? $budgetLimit : null,
            'budget_used' => $budgetLimit > 0 ? round($budgetUsed, 2) : null,
            'budget_skipped_lines' => $budgetSkippedLines,
        ];

        foreach ($lines as $line) {
            $economicsSummary['total_supply_cost'] += (float) ($line['supply_cost_estimate'] ?? 0);
            $economicsSummary['total_expected_revenue'] += (float) ($line['expected_revenue'] ?? 0);
            $economicsSummary['total_expected_profit'] += (float) ($line['expected_profit'] ?? 0);
        }
        $economicsSummary['total_supply_cost'] = round($economicsSummary['total_supply_cost'], 2);
        $economicsSummary['total_expected_revenue'] = round($economicsSummary['total_expected_revenue'], 2);
        $economicsSummary['total_expected_profit'] = round($economicsSummary['total_expected_profit'], 2);

        $deficitSurplusSummary = app(DeficitSurplusPlanningService::class)->analyze($lines, $plan, [
            'min_cover_days' => $minCoverDays,
            'target_cover_days' => $targetCoverDays,
        ]);
        $deficitSummary = $deficitSurplusSummary['deficit_summary'];
        $surplusSummary = $deficitSurplusSummary['surplus_summary'];

        $inventoryLastUpdated = $warehouses->max('updated_at');
        $inventoryLastUpdatedIso = $inventoryLastUpdated
            ? Carbon::parse($inventoryLastUpdated)->toISOString()
            : null;

        $factsFreshness = [
            'inventory_warehouses' => [
                'items' => $warehouses->count(),
                'last_updated_at' => $inventoryLastUpdatedIso,
            ],
            'products' => [
                'items' => $products->count(),
            ],
            'unit_economics' => [
                'items' => $unitEconomics->count(),
            ],
        ];

        $demandGranularity = match ($marketplace) {
            'ozon' => 'cluster',
            'yandex', 'yandex_market' => 'sku',
            default => 'warehouse',
        };
        $marketplaceCapabilities = app(MarketplacePlanningCapabilityService::class)->forMarketplace($marketplace);
        $territorialSummary = app(TerritorialPlanningService::class)->summarize($lines, $plan);
        $planQualityAudit = app(PlanQualityAuditService::class)->audit($lines, $plan, [
            'selection_summary' => $selectionSummary,
            'constraints_summary' => $constraintsSummary,
            'territorial_summary' => $territorialSummary,
            'economics_summary' => $economicsSummary,
            'deficit_surplus_summary' => $deficitSurplusSummary,
        ]);

        $resultJson = [
            'redistribution' => $redistributionSuggestions,
            'facts_freshness' => $factsFreshness,
            'demand_granularity' => $demandGranularity,
            'deficit_summary' => $deficitSummary,
            'surplus_summary' => $surplusSummary,
            'deficit_surplus_summary' => $deficitSurplusSummary,
            'economics_summary' => $economicsSummary,
            'selection_summary' => $selectionSummary,
            'constraints_summary' => $constraintsSummary,
            'territorial_summary' => $territorialSummary,
            'plan_quality_audit' => $planQualityAudit,
            'marketplace_capabilities' => $marketplaceCapabilities,
        ];

        // --- Locality summary (Ozon only) ---
        if ($marketplace === 'ozon') {
            try {
                $enricher = app(\App\Domains\Locality\Integration\LocalityEnrichmentService::class);
                $plan->load('lines');
                $resultJson['locality_summary'] = $enricher->buildPlanSummary($plan);
            } catch (\Throwable $e) {
                Log::warning('Locality summary build failed', [
                    'plan_id' => $plan->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $plan->result_json = $resultJson;

        // --- Data quality score ---
        $qualityJson = $service->calculateDataQuality(
            $totalSkus, $qStocksCoverage, $qSalesHistory,
            $qInTransit, $qDestination, $qBarcode, $marketplace
        );
        $demandEvaluatedLines = max(1, array_sum($demandSourceCounts));
        $fallbackLongShare = min(100.0, round(($fallbackLongLines / $demandEvaluatedLines) * 100, 2));
        $remainingNegativeProfitLines = max(0, $negativeProfitLines - $skippedNegativeProfitLines);
        $negativeProfitShare = ($totalLines + $skippedNegativeProfitLines) > 0
            ? min(100.0, round(($remainingNegativeProfitLines / max($totalLines + $skippedNegativeProfitLines, 1)) * 100, 2))
            : 0.0;
        $qualityGateStatus = 'good';
        $qualityGateReasons = [];

        if ($marketplace === 'ozon') {
            $hasOzonPostingDemand = !empty($ozonPostingDemand['by_offer']);
            $hasTrustedOzonDemand = $hasOzonReport || $hasOzonPostingDemand;
            if (! $hasTrustedOzonDemand) {
                $qualityGateReasons[] = 'нет автоматического спроса из заказов Ozon';
            }
            if (count($ozonStockAnalytics) === 0 && count($ozonStockAnalyticsCluster) === 0) {
                $qualityGateReasons[] = 'нет аналитики остатков Ozon';
            }
            if (count($ozonAnalytics) === 0) {
                $qualityGateReasons[] = 'нет сводки Ozon по скорости доставки';
            }
            if ($fallbackLongLines > 0) {
                $qualityGateReasons[] = "{$fallbackLongLines} строк рассчитаны только по длинному окну продаж: коротких данных недостаточно";
            }
            if ($remainingNegativeProfitLines > 0) {
                $qualityGateReasons[] = "{$remainingNegativeProfitLines} строк с отрицательной прибылью";
            }
            if ($skippedNegativeProfitLines > 0) {
                $qualityGateReasons[] = "{$skippedNegativeProfitLines} убыточных строк отсечены";
            }
            if ($manualReviewLines > 0) {
                $qualityGateReasons[] = "{$manualReviewLines} строк требуют проверки спроса";
            }
            if ($advertisingDrivenLines > 0) {
                $qualityGateReasons[] = "{$advertisingDrivenLines} строк со спросом, поддержанным рекламой";
            }
            if ($advertisingHighDrrLines > 0) {
                $qualityGateReasons[] = "{$advertisingHighDrrLines} строк с высоким ДРР";
            }

            if (! $hasTrustedOzonDemand || $fallbackLongShare >= 50 || $negativeProfitShare >= 40) {
                $qualityGateStatus = 'bad';
            } elseif ($qualityGateReasons !== []) {
                $qualityGateStatus = 'warning';
            }
        }

        $planningFactSources = [
            'demand' => !empty($ozonPostingDemand['by_offer']) ? 'posting_fbo_v3' : ($hasOzonReport ? 'ozon_order_report' : null),
            'stock' => (count($ozonStockAnalytics) > 0 || count($ozonStockAnalyticsCluster) > 0)
                ? 'analytics_stocks'
                : (count($ozonProductStocks) > 0 ? 'product_info_stocks' : 'inventory_warehouses'),
            'turnover' => count($ozonTurnover) > 0 ? 'turnover_stocks' : null,
            'delivery_health' => count($ozonAnalytics) > 0 ? 'average_delivery_time_summary' : null,
            'in_transit' => $includeInTransit && count($supplyInTransit) > 0 ? 'supply_orders' : null,
            'advertising' => ($ozonAdvertisingImpact['success'] ?? false) ? 'ozon_performance_product_report' : null,
        ];
        $planningFactSources = app(PlanningFactSnapshotService::class)->withConstraintSources($planningFactSources, $constraintsSummary);

        $qualityJson['meta'] = array_merge($qualityJson['meta'] ?? [], [
            'quality_gate_status' => $qualityGateStatus,
            'quality_gate_reasons' => $qualityGateReasons,
            'demand_source_counts' => $demandSourceCounts,
            'missing_source_counts' => $missingSourceCounts,
            'planning_fact_sources' => $planningFactSources,
            'analysis_period_days' => $analysisPeriodDays,
            'ozon_posting_demand_skus' => count($ozonPostingDemand['by_offer'] ?? []),
            'ozon_product_stock_skus' => count($ozonProductStocks),
            'fallback_long_lines' => $fallbackLongLines,
            'fallback_long_share_percent' => $fallbackLongShare,
            'negative_profit_lines' => $remainingNegativeProfitLines,
            'negative_profit_share_percent' => $negativeProfitShare,
            'skipped_negative_profit_lines' => $skippedNegativeProfitLines,
            'ozon_recommended_lines' => 0,
            'deprecated_ozon_recommended_lines' => $deprecatedOzonRecommendedLines,
            'manual_review_lines' => $manualReviewLines,
            'low_confidence_trial_lines' => $lowConfidenceTrialLines,
            'advertising_report_uuid' => $ozonAdvertisingImpact['uuid'] ?? null,
            'advertising_report_loaded' => (bool) ($ozonAdvertisingImpact['success'] ?? false),
            'advertising_products_count' => (int) ($ozonAdvertisingImpact['summary']['products_count'] ?? 0),
            'advertising_driven_lines' => $advertisingDrivenLines,
            'advertising_high_drr_lines' => $advertisingHighDrrLines,
            'advertising_profit_adjusted_lines' => $advertisingProfitAdjustedLines,
            'plan_quality_audit' => $planQualityAudit,
            'advanced_params_applied' => [
                'analysis_period_days' => $analysisPeriodDays,
                'demand_seasonality_multiplier' => $seasonalityMultiplier,
                'trend_multiplier' => $trendMultiplier,
                'promo_mode' => $promoMode,
                'include_in_transit' => $includeInTransit,
                'ozon_qty_anchor' => $ozonQtyAnchor,
                'requested_ozon_qty_anchor' => $requestedOzonQtyAnchor,
                'ozon_qty_anchor_deprecated' => $ozonQtyAnchorWasDeprecated,
                'skip_negative_profit' => $skipNegativeProfit,
                'budget_limit' => $budgetLimit > 0 ? $budgetLimit : null,
                'budget_used' => $budgetLimit > 0 ? round($budgetUsed, 2) : null,
                'budget_skipped_lines' => $budgetSkippedLines,
                'selection_summary' => $selectionSummary,
                'constraints_summary' => $constraintsSummary,
                'territorial_summary' => $territorialSummary,
                'performance_report_uuid' => $ozonAdvertisingImpact['uuid'] ?? null,
                'performance_summary' => $ozonAdvertisingImpact['summary'] ?? null,
                'plan_quality_audit' => $planQualityAudit,
            ],
        ]);

        app(PlanningFactSnapshotService::class)->complete($plan, [
            'facts_freshness' => $factsFreshness,
            'planning_sources' => $planningFactSources,
            'demand_facts' => [
                'analysis_period_days' => $analysisPeriodDays,
                'ozon_posting_demand_days' => (int) ($ozonPostingDemand['days'] ?? $analysisPeriodDays),
                'source_counts' => $demandSourceCounts,
                'fallback_long_lines' => $fallbackLongLines,
                'manual_review_lines' => $manualReviewLines,
                'low_confidence_trial_lines' => $lowConfidenceTrialLines,
                'granularity' => $demandGranularity,
                'advertising_driven_lines' => $advertisingDrivenLines,
                'advertising_high_drr_lines' => $advertisingHighDrrLines,
            ],
            'stock_facts' => [
                'inventory_rows' => $warehouses->count(),
                'ozon_product_stock_skus' => count($ozonProductStocks),
                'ozon_stock_analytics_skus' => count($ozonStockAnalytics),
            ],
            'supply_facts' => [
                'include_in_transit' => $includeInTransit,
                'supply_orders_skus' => count($supplyInTransit),
            ],
            'economics_facts' => array_merge($economicsSummary, [
                'advertising_report_uuid' => $ozonAdvertisingImpact['uuid'] ?? null,
                'advertising_summary' => $ozonAdvertisingImpact['summary'] ?? null,
                'advertising_profit_adjusted_lines' => $advertisingProfitAdjustedLines,
            ]),
            'constraints_facts' => [
                'selected_cluster_ids' => $selectedOzonClusterIds,
                'budget_limit' => $budgetLimit > 0 ? $budgetLimit : null,
                'budget_used' => $budgetLimit > 0 ? round($budgetUsed, 2) : null,
                'budget_skipped_lines' => $budgetSkippedLines,
                'constraints_summary' => $constraintsSummary,
                'constraint_metadata' => $params['constraint_metadata'] ?? null,
                'territorial_summary' => $territorialSummary,
                'plan_quality_audit' => $planQualityAudit,
                'marketplace_capabilities' => $marketplaceCapabilities,
            ],
            'summary' => [
                'total_lines' => $totalLines,
                'total_qty' => $totalQty,
                'deficit_summary' => $deficitSummary,
                'surplus_summary' => $surplusSummary,
                'deficit_surplus_summary' => $deficitSurplusSummary,
                'economics_summary' => $economicsSummary,
                'selection_summary' => $selectionSummary,
                'constraints_summary' => $constraintsSummary,
                'territorial_summary' => $territorialSummary,
                'plan_quality_audit' => $planQualityAudit,
            ],
        ]);
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
        if ($plan) {
            app(PlanningFactSnapshotService::class)->fail($plan, $exception->getMessage());
            $plan->markError('Job failed: ' . $exception->getMessage());
        }
    }

    /**
     * Collapse Ozon inventory rows from SKU×warehouse to SKU×delivery cluster.
     *
     * Ozon's own planning/recommendation logic is cluster-oriented: demand and
     * available stock should be evaluated across every warehouse in the same
     * delivery cluster. Rows without a cluster mapping stay warehouse-scoped.
     */
    private function aggregateOzonWarehousesByCluster(Collection $warehouses, array $clusterMapping): Collection
    {
        return $warehouses
            ->groupBy(function (InventoryWarehouse $warehouse) use ($clusterMapping): string {
                $cluster = $this->resolveOzonCluster($warehouse, $clusterMapping);

                if ($cluster !== null) {
                    return $warehouse->sku . '|cluster|' . $cluster['cluster_id'];
                }

                return $warehouse->sku . '|warehouse|' . ($warehouse->warehouse_id ?? $warehouse->warehouse_name ?? $warehouse->id);
            })
            ->map(function (Collection $group) use ($clusterMapping): InventoryWarehouse {
                /** @var InventoryWarehouse $first */
                $first = $group->first();
                $cluster = $this->resolveOzonCluster($first, $clusterMapping);

                if ($cluster === null) {
                    return $first;
                }

                $aggregate = $first->replicate();
                $warehouseNames = $group
                    ->pluck('warehouse_name')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                $aggregate->forceFill([
                    'warehouse_id' => 'cluster:' . $cluster['cluster_id'],
                    'warehouse_name' => $cluster['cluster_name'],
                    'region' => $cluster['region'] ?? null,
                    'quantity' => (int) $group->sum('quantity'),
                    'reserved' => (int) $group->sum('reserved'),
                    'in_transit' => (int) $group->sum('in_transit'),
                    'average_daily_sales' => (float) $group->sum('average_daily_sales'),
                    'effective_daily_sales' => (float) $group->sum('effective_daily_sales'),
                    'real_avg_daily_sales' => (float) $group->sum('real_avg_daily_sales'),
                    'sales_7_days' => (int) $group->sum('sales_7_days'),
                    'sales_14_days' => (int) $group->sum('sales_14_days'),
                    'sales_30_days' => (int) $group->sum('sales_30_days'),
                    'storage_cost_per_day' => (float) $group->sum('storage_cost_per_day'),
                    'storage_cost_per_month' => (float) $group->sum('storage_cost_per_month'),
                    'storage_fee_total' => (float) $group->sum('storage_fee_total'),
                    'recommended_quantity' => (int) $group->sum('recommended_quantity'),
                    'days_in_stock_30' => (int) $group->max('days_in_stock_30'),
                    'days_of_stock' => null,
                    'turnover_days' => null,
                ]);

                $aggregate->setAttribute('cluster_id', $cluster['cluster_id']);
                $aggregate->setAttribute('cluster_name', $cluster['cluster_name']);
                $aggregate->setAttribute('cluster_region', $cluster['region'] ?? null);
                $aggregate->setAttribute('cluster_warehouse_names', $warehouseNames);
                $aggregate->setAttribute('is_cluster_aggregate', true);

                return $aggregate;
            })
            ->values();
    }

    /**
     * @return list<int>
     */
    private function selectedOzonClusterIds(AutoSupplyPlan $plan): array
    {
        return array_values(array_filter(
            array_map('intval', (array) ($plan->params['cluster_ids'] ?? [])),
            fn (int $clusterId) => $clusterId > 0
        ));
    }

    private function resolveOzonCluster(InventoryWarehouse $warehouse, array $clusterMapping): ?array
    {
        if (! $warehouse->warehouse_name) {
            return null;
        }

        $normalizedName = OzonWarehouseCluster::normalizeWarehouseName($warehouse->warehouse_name);

        return $clusterMapping[$normalizedName] ?? null;
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function loadOzonAdvertisingImpact(AutoSupplyPlan $plan, array $params): array
    {
        if ($plan->marketplace !== 'ozon') {
            return [
                'success' => false,
                'status' => 'not_applicable',
            ];
        }

        $uuid = trim((string) ($params['performance_report_uuid'] ?? $params['ozon_performance_report_uuid'] ?? ''));
        if ($uuid === '') {
            return [
                'success' => false,
                'status' => 'not_requested',
            ];
        }

        try {
            $integration = Integration::find((int) $plan->integration_id);
            $workspaceId = (int) ($integration?->work_space_id ?? 0);
            $remote = app(SellicoApiService::class)->getIntegrationById(
                (int) $plan->integration_id,
                $workspaceId > 0 ? $workspaceId : null
            );

            if (! ($remote['success'] ?? false)) {
                return [
                    'success' => false,
                    'status' => 'integration_credentials_unavailable',
                    'uuid' => $uuid,
                    'message' => $remote['error'] ?? 'Не удалось получить интеграцию из основного backend',
                ];
            }

            $impact = app(OzonPerformanceApiService::class)->productAdvertisingImpact(
                is_array($remote['credentials'] ?? null) ? $remote['credentials'] : [],
                $uuid,
                5000,
                is_array($params['performance_period'] ?? null) ? ($params['performance_period']['date_from'] ?? null) : null,
                is_array($params['performance_period'] ?? null) ? ($params['performance_period']['date_to'] ?? null) : null,
                (int) $plan->integration_id
            );
            $impact['uuid'] = $uuid;

            return $impact;
        } catch (\Throwable $e) {
            Log::warning('CalculateAutoSupplyPlanJob: Ozon Performance impact failed', [
                'plan_id' => $plan->id,
                'integration_id' => $plan->integration_id,
                'uuid' => $uuid,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => 'request_failed',
                'uuid' => $uuid,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Период анализа продаж управляет источником спроса, а horizon_days —
     * горизонтом покрытия. Раньше Ozon postings всегда грузились за 60 дней,
     * из-за чего выбранный пользователем период не влиял на главный сигнал.
     *
     * @param array<string, mixed> $params
     */
    private function analysisPeriodDays(array $params, int $horizonDays): int
    {
        $value = (int) ($params['analysis_period_days'] ?? $horizonDays);
        $allowed = [7, 14, 28, 30, 56, 60, 90];

        if (in_array($value, $allowed, true)) {
            return $value;
        }

        $closest = 30;
        $smallestDiff = PHP_INT_MAX;
        foreach ($allowed as $allowedValue) {
            $diff = abs($allowedValue - $value);
            if ($diff < $smallestDiff) {
                $closest = $allowedValue;
                $smallestDiff = $diff;
            }
        }

        return $closest;
    }

    /**
     * Ozon no longer has a confirmed per-SKU/per-cluster recommended_supply API
     * contract for supply quantity. Keep old request values accepted for
     * backwards compatibility, but always route Ozon quantity through the
     * internal planning engine.
     */
    private function effectiveOzonQtyAnchor(string $requestedAnchor, string $marketplace): string
    {
        if ($marketplace !== 'ozon') {
            return 'internal';
        }

        return 'internal';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function compactRegionalProfile(mixed $profile): array
    {
        if (! is_array($profile)) {
            return [];
        }

        $rows = [];
        foreach ($profile as $key => $row) {
            if (is_array($row)) {
                $rows[] = $row;
                continue;
            }

            if (is_numeric($row) && (string) $key !== '') {
                $rows[] = [
                    'region' => (string) $key,
                    'name' => (string) $key,
                    'orders' => (float) $row,
                ];
            }
        }

        $totalOrders = array_sum(array_map(
            static fn (mixed $row): float => is_array($row) && is_numeric($row['orders'] ?? null) ? (float) $row['orders'] : 0.0,
            $rows
        ));
        $compact = [];
        foreach (array_slice($rows, 0, 8) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $compact[] = array_filter([
                'cluster_id' => $row['cluster_id'] ?? $row['id'] ?? null,
                'cluster_name' => $row['cluster_name'] ?? $row['name'] ?? null,
                'warehouse_id' => $row['warehouse_id'] ?? null,
                'warehouse_name' => $row['warehouse_name'] ?? null,
                'region' => $row['region'] ?? $row['fo'] ?? $row['district'] ?? null,
                'share_percent' => $this->numericOrNull($row['share_percent'] ?? $row['share'] ?? $row['percent'] ?? null)
                    ?? ($totalOrders > 0 && is_numeric($row['orders'] ?? null) ? round(((float) $row['orders'] / $totalOrders) * 100, 2) : null),
                'orders' => $this->numericOrNull($row['orders'] ?? $row['qty'] ?? $row['quantity'] ?? null),
                'locality_rate' => $this->numericOrNull($row['locality_rate'] ?? $row['local_share_percent'] ?? null),
            ], static fn ($value): bool => $value !== null && $value !== '');
        }

        return $compact;
    }

    /**
     * @return array<string, int> keyed by "sku|cluster_id"
     */
    private function getOzonInTransitFromSuppliesByCluster(int $integrationId, array $clusterMapping): array
    {
        try {
            $rows = \Illuminate\Support\Facades\DB::table('supply_items')
                ->join('supplies', 'supply_items.supply_id', '=', 'supplies.id')
                ->where('supplies.integration_id', $integrationId)
                ->whereIn('supplies.status', [
                    'draft_ozon',
                    'slot_booked',
                    'preparing',
                    'ready_to_ship',
                    'shipped',
                    'in_transit',
                ])
                ->selectRaw('supply_items.sku, supplies.warehouse_name, SUM(supply_items.planned_qty) as qty')
                ->groupBy('supply_items.sku', 'supplies.warehouse_name')
                ->get();
        } catch (\Throwable) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            if (! $row->warehouse_name) {
                continue;
            }

            $normalizedName = OzonWarehouseCluster::normalizeWarehouseName((string) $row->warehouse_name);
            $cluster = $clusterMapping[$normalizedName] ?? null;
            if ($cluster === null) {
                continue;
            }

            $key = (string) $row->sku . '|' . (int) $cluster['cluster_id'];
            $result[$key] = ($result[$key] ?? 0) + (int) $row->qty;
        }

        return $result;
    }
}
