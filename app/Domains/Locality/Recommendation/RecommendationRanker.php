<?php

namespace App\Domains\Locality\Recommendation;

use App\Domains\Ozon\Tariffs\OzonPricingMatrix;
use App\Domains\Ozon\UnitEconomics\MarkupReasonCode;
use App\Models\LocalityMetricDaily;
use App\Models\LocalityRecommendation;
use App\Models\OzonOrderUnitEconomics;
use App\Models\OzonWarehouseCluster;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Главная эвристика: из vectors demand/stock/markup строит отранжированный список рекомендаций.
 */
class RecommendationRanker
{
    public function __construct(
        private readonly DemandForecaster $forecaster,
        private readonly ClusterStockAggregator $stockAggr,
        private readonly RecommendationScorer $scorer = new RecommendationScorer(),
        private readonly OzonPricingMatrix $pricing = new OzonPricingMatrix(),
    ) {
    }

    /**
     * @return array{cohort_id:string, generated:int, skipped:int, stale_marked:int}
     */
    public function generate(int $integrationId): array
    {
        $lockKey = "locality:recommendations:$integrationId";
        $lockSeconds = (int) config('locality.cache.recompute_lock_seconds', 600);
        $lock = Cache::lock($lockKey, $lockSeconds);
        if (! $lock->get()) {
            Log::channel('locality')->warning('RecommendationRanker lock busy', ['integration_id' => $integrationId]);
            return ['cohort_id' => '', 'generated' => 0, 'skipped' => 0, 'stale_marked' => 0];
        }

        try {
            return $this->generateInner($integrationId);
        } finally {
            $lock->release();
        }
    }

    private function generateInner(int $integrationId): array
    {
        $cfg = config('locality.recommendation');
        $periodDays = (int) config('locality.period.default_days', 28);

        $minOrders = (int) $cfg['min_orders_28d'];
        $minMarkup = (float) $cfg['min_markup_percent'];
        $minSavings = (float) $cfg['min_expected_savings_rub'];
        $targetDays = (int) $cfg['target_days_of_cover'];
        $leadTime = (int) $cfg['supply_lead_time_days'];
        $maxCover = (int) $cfg['max_cover_days'];
        $topN = (int) $cfg['top_n_overpayers'];
        $storageCutoff = (float) $cfg['storage_cost_savings_ratio_cutoff'];

        $from = now()->subDays($periodDays);
        $to = now();

        $topSkus = LocalityMetricDaily::query()
            ->where('integration_id', $integrationId)
            ->where('snapshot_date', $to->toDateString())
            ->where('period_days', $periodDays)
            ->orderByDesc('overpayment_amount')
            ->limit($topN)
            ->pluck('sku')
            ->map(fn ($s) => (string) $s)
            ->all();

        if (empty($topSkus)) {
            // У интеграции больше нет кандидатов на рекомендации (все продажи
            // локальные, или не хватает данных). Помечаем все существующие
            // NEW-рекомендации как STALE, иначе старые записи в state='new'
            // остаются навсегда — тот же класс orphan-бага, что у locality_metrics_daily.
            $staleMarked = LocalityRecommendation::query()
                ->where('integration_id', $integrationId)
                ->where('state', LocalityRecommendation::STATE_NEW)
                ->update(['state' => LocalityRecommendation::STATE_STALE]);

            if ($staleMarked > 0) {
                Log::channel('locality')->info('RecommendationRanker: empty topSkus → marked old NEW stale', [
                    'integration_id' => $integrationId,
                    'stale_marked' => $staleMarked,
                ]);
            }

            return ['cohort_id' => '', 'generated' => 0, 'skipped' => 0, 'stale_marked' => $staleMarked];
        }

        $demand = $this->forecaster->forIntegration($integrationId, $periodDays);
        $stock = $this->stockAggr->byIntegration($integrationId);
        $clusterIds = $this->clusterIdsByName();
        $markupByCluster = $this->markupByDestinationCluster($integrationId, $topSkus, $from, $to);

        $cohortId = (string) Str::uuid();
        $generated = 0;
        $skipped = 0;

        foreach ($topSkus as $sku) {
            $perCluster = $demand[$sku] ?? [];
            foreach ($perCluster as $clusterName => $demandVec) {
                if ($demandVec['sales_28d'] < $minOrders) {
                    $skipped++;
                    continue;
                }
                $markupPct = (float) ($markupByCluster[$sku][$clusterName]['markup_percent_avg'] ?? 0);
                if ($markupPct < $minMarkup) {
                    $skipped++;
                    continue;
                }

                $currentStock = (int) ($stock[$sku][$clusterName]['on_hand'] ?? 0);
                $inTransit = (int) ($stock[$sku][$clusterName]['in_transit'] ?? 0);
                $dailyDemand = (float) $demandVec['daily_demand'];
                $currentDays = $dailyDemand > 0 ? ($currentStock + $inTransit) / $dailyDemand : INF;

                if ($currentDays >= 0.5 * $targetDays) {
                    $skipped++;
                    continue;
                }

                $targetStock = (int) ceil($dailyDemand * ($leadTime + $targetDays));
                $maxCap = (int) ceil($dailyDemand * $maxCover);
                $recommendedQty = max(0, min($targetStock, $maxCap) - $currentStock - $inTransit);
                if ($recommendedQty <= 0) {
                    $skipped++;
                    continue;
                }

                $nonLocalOrders28d = (int) ($markupByCluster[$sku][$clusterName]['orders'] ?? 0);
                $avgOverpay = (float) ($markupByCluster[$sku][$clusterName]['avg_overpayment'] ?? 0);
                $fillRatio = $dailyDemand > 0 ? min(1.0, $recommendedQty / max(1.0, $dailyDemand * 28)) : 0.0;
                $expectedSavings = round($nonLocalOrders28d * $avgOverpay * $fillRatio, 2);
                if ($expectedSavings < $minSavings) {
                    $skipped++;
                    continue;
                }

                // storage cost guard (упрощённо): средняя стоимость хранения считается по InventoryWarehouse
                $storageCost28d = $this->estimateStorageCost28d($integrationId, $sku, $recommendedQty, $clusterName);
                if ($storageCost28d > 0 && $storageCost28d > $storageCutoff * $expectedSavings) {
                    $skipped++;
                    continue;
                }

                $totalOrdersSku = LocalityMetricDaily::query()
                    ->where('integration_id', $integrationId)
                    ->where('sku', $sku)
                    ->where('snapshot_date', $to->toDateString())
                    ->where('period_days', $periodDays)
                    ->value('orders_count') ?? 1;
                $upliftPp = $totalOrdersSku > 0
                    ? round(($nonLocalOrders28d / $totalOrdersSku) * $fillRatio * 100, 2)
                    : 0.0;

                $factualRatio = $this->factualRatio($integrationId, $sku, $from, $to);
                $score = $this->scorer->score([
                    'factual_ratio' => $factualRatio,
                    'orders_28d' => $demandVec['sales_28d'],
                    'cluster_known' => isset($clusterIds[$clusterName]),
                    'cost_price_known' => $this->hasCostPrice($integrationId, $sku),
                    'tariff_official' => true,
                ]);

                $rankScore = round($expectedSavings * ($score['score'] / 100), 2);

                $reasoning = sprintf(
                    'За 28 дней %d заказов ушли в %s non-local с средней наценкой %.2f%%. '
                    . 'В кластере остаток %d ед. (in-transit %d). Поставка %d ед. покрывает спрос на %d дней '
                    . 'и ориентировочно сэкономит %s ₽.',
                    $nonLocalOrders28d,
                    $clusterName,
                    $markupPct,
                    $currentStock,
                    $inTransit,
                    $recommendedQty,
                    (int) min($maxCover, $targetDays + $leadTime),
                    number_format($expectedSavings, 2, '.', ' ')
                );

                LocalityRecommendation::query()->updateOrCreate(
                    [
                        'integration_id' => $integrationId,
                        'sku' => $sku,
                        'target_cluster_id' => (string) ($clusterIds[$clusterName] ?? ''),
                        'cohort_id' => $cohortId,
                    ],
                    [
                        'offer_id' => null,
                        'product_id' => null,
                        'target_cluster_name' => $clusterName,
                        'recommended_qty_units' => $recommendedQty,
                        'current_stock_cluster' => $currentStock,
                        'in_transit_cluster' => $inTransit,
                        'daily_demand_cluster' => $dailyDemand,
                        'volatility_cluster' => $demandVec['volatility'],
                        'gap_units' => $recommendedQty,
                        'expected_savings_rub' => $expectedSavings,
                        'expected_monthly_savings_rub' => round($expectedSavings * (30 / 28), 2),
                        'expected_local_share_uplift_pp' => $upliftPp,
                        'expected_days_of_cover' => $dailyDemand > 0
                            ? round(($currentStock + $inTransit + $recommendedQty) / $dailyDemand, 2)
                            : null,
                        'avg_markup_amount_rub' => $avgOverpay,
                        'avg_base_logistics_rub' => (float) ($markupByCluster[$sku][$clusterName]['avg_base_logistics'] ?? 0),
                        'confidence' => $score['confidence'],
                        'confidence_score' => $score['score'],
                        'rank_score' => $rankScore,
                        'reasoning_text' => $reasoning,
                        'warnings' => $this->buildWarnings($demandVec, $storageCost28d, $expectedSavings),
                        'constraints_checked' => ['cluster_known' => isset($clusterIds[$clusterName])],
                        'state' => LocalityRecommendation::STATE_NEW,
                        'computed_at' => now(),
                        'period_from' => $from->toDateString(),
                        'period_to' => $to->toDateString(),
                        'basis_snapshot_date' => $to->toDateString(),
                        'lead_time_days' => $leadTime,
                        'expires_at' => $to->copy()->addDays((int) $cfg['stale_after_days'])->toDateString(),
                    ]
                );
                $generated++;
            }
        }

        // Любая NEW-рекомендация не из текущего cohort_id — устаревшая (включая today,
        // если в один день было несколько recompute).
        $staleMarked = LocalityRecommendation::query()
            ->where('integration_id', $integrationId)
            ->where('state', LocalityRecommendation::STATE_NEW)
            ->where('cohort_id', '!=', $cohortId)
            ->update(['state' => LocalityRecommendation::STATE_STALE]);

        Log::channel('locality')->info('RecommendationRanker done', [
            'integration_id' => $integrationId,
            'cohort_id' => $cohortId,
            'generated' => $generated,
            'skipped' => $skipped,
            'stale_marked' => $staleMarked,
        ]);

        return [
            'cohort_id' => $cohortId,
            'generated' => $generated,
            'skipped' => $skipped,
            'stale_marked' => $staleMarked,
        ];
    }

    /** @return array<string,int|string> */
    private function clusterIdsByName(): array
    {
        $map = [];
        foreach (OzonWarehouseCluster::query()->select('cluster_id', 'cluster_name')->distinct()->get() as $row) {
            $map[(string) $row->cluster_name] = $row->cluster_id;
        }
        return $map;
    }

    /**
     * Агрегат non-local заказов per (sku, destination_cluster) для потенциальной переплаты.
     *
     * ВАЖНО: используем ставку из таблицы Ozon (OzonPricingMatrix), а не из `non_local_markup_percent`
     * в OUE — последний заполнен только когда Ozon реально списывал наценку (markup_applied=true).
     * Для магазинов с <50 FBO/7дн наценка не применяется, но рекомендации всё равно нужны
     * на основе потенциальной экономии.
     */
    private function markupByDestinationCluster(int $integrationId, array $skus, Carbon $from, Carbon $to): array
    {
        if (empty($skus)) {
            return [];
        }

        $rows = OzonOrderUnitEconomics::query()
            ->where('integration_id', $integrationId)
            ->whereIn('sku', $skus)
            ->whereBetween('order_date', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->whereNotIn('markup_reason_code', MarkupReasonCode::excludedValues())
            ->whereNotNull('shipping_cluster_name')
            ->whereNotNull('destination_cluster_name')
            ->whereColumn('shipping_cluster_name', '!=', 'destination_cluster_name')
            ->get([
                'sku',
                'destination_cluster_name',
                'sale_price',
                'base_logistics_tariff',
            ]);

        $result = [];
        foreach ($rows->groupBy(['sku', 'destination_cluster_name']) as $sku => $byCluster) {
            foreach ($byCluster as $clusterName => $group) {
                $markupPct = (float) $this->pricing->resolveDestinationMarkupPercent((string) $clusterName);
                $avgPrice = (float) $group->avg('sale_price');
                $avgOverpayment = round($avgPrice * ($markupPct / 100), 2);

                $result[$sku][$clusterName] = [
                    'orders' => $group->count(),
                    'avg_overpayment' => $avgOverpayment,
                    'markup_percent_avg' => $markupPct,
                    'avg_base_logistics' => round((float) $group->avg('base_logistics_tariff'), 2),
                ];
            }
        }
        return $result;
    }

    private function factualRatio(int $integrationId, string $sku, Carbon $from, Carbon $to): float
    {
        $row = OzonOrderUnitEconomics::query()
            ->where('integration_id', $integrationId)
            ->where('sku', $sku)
            ->whereBetween('order_date', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->selectRaw('COUNT(*) AS total, SUM(CASE WHEN calculation_mode = ? THEN 1 ELSE 0 END) AS factual', ['factual'])
            ->first();

        if ($row === null || (int) $row->total === 0) {
            return 0.0;
        }

        return (int) $row->factual / (int) $row->total;
    }

    private function hasCostPrice(int $integrationId, string $sku): bool
    {
        return DB::table('products')
            ->where('integration_id', $integrationId)
            ->where('sku', $sku)
            ->whereNotNull('cost_price')
            ->where('cost_price', '>', 0)
            ->exists();
    }

    private function estimateStorageCost28d(int $integrationId, string $sku, int $qty, string $clusterName): float
    {
        $row = DB::table('inventory_warehouses')
            ->join('ozon_warehouse_clusters', DB::raw('UPPER(ozon_warehouse_clusters.warehouse_name)'), '=', DB::raw('UPPER(inventory_warehouses.warehouse_name)'))
            ->where('inventory_warehouses.integration_id', $integrationId)
            ->where('inventory_warehouses.sku', $sku)
            ->where('ozon_warehouse_clusters.cluster_name', $clusterName)
            ->selectRaw('AVG(inventory_warehouses.storage_cost_per_day) AS avg_per_day')
            ->first();

        $perDay = (float) ($row->avg_per_day ?? 0);
        return round($qty * $perDay * 28, 2);
    }

    private function buildWarnings(array $demandVec, float $storageCost28d, float $savings): array
    {
        $w = [];
        if ($demandVec['source'] === 'cold_start') {
            $w[] = 'cold_start_insufficient_history';
        }
        if ($demandVec['volatility'] > 1.0) {
            $w[] = 'high_demand_volatility';
        }
        if ($storageCost28d > 0.15 * $savings) {
            $w[] = 'storage_cost_significant';
        }
        return $w;
    }
}
