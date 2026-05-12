<?php

namespace App\Domains\Locality\Integration;

use App\Domains\Locality\Recommendation\DemandForecaster;
use App\Domains\Ozon\Tariffs\OzonPricingMatrix;
use App\Models\AutoSupplyPlan;
use App\Models\LocalityMetricClusterDaily;
use App\Models\LocalityMetricDaily;
use App\Models\LocalityRecommendation;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Мост между Locality Engine и AutoSupplyPlan.
 * Обогащает строки плана метриками локальности и (опционально) разбивает qty по кластерам
 * на основе LocalityRecommendation.
 *
 * Все методы — stateless. Batch-ориентированные (одна выборка на план, не N запросов на SKU).
 */
class LocalityEnrichmentService
{
    /** Стратегии распределения qty при split_by_cluster */
    public const STRATEGY_RECOMMENDATIONS = 'recommendations';
    public const STRATEGY_DEMAND_WEIGHTED = 'demand_weighted';
    public const STRATEGY_PROPORTIONAL = 'proportional';

    public const DEFAULT_PERIOD_DAYS = 28;
    public const DEFAULT_MAX_CLUSTERS = 5;
    public const DEFAULT_MIN_CONFIDENCE = 'medium';

    public function __construct(
        private readonly OzonPricingMatrix $pricing = new OzonPricingMatrix(),
    ) {
    }

    /**
     * Загрузить метрики локальности последнего снапшота per SKU.
     *
     * @param list<string> $skus
     * @return array<string, LocalityMetricDaily>
     */
    public function loadMetricsForSkus(int $integrationId, array $skus, int $periodDays = self::DEFAULT_PERIOD_DAYS): array
    {
        if (empty($skus)) {
            return [];
        }

        $latestDate = LocalityMetricDaily::query()
            ->where('integration_id', $integrationId)
            ->where('period_days', $periodDays)
            ->max('snapshot_date');

        if ($latestDate === null) {
            return [];
        }

        return LocalityMetricDaily::query()
            ->where('integration_id', $integrationId)
            ->where('snapshot_date', $latestDate)
            ->where('period_days', $periodDays)
            ->whereIn('sku', $skus)
            ->get()
            ->keyBy(fn (LocalityMetricDaily $row) => (string) $row->sku)
            ->all();
    }

    /**
     * Загрузить активные рекомендации Locality per SKU.
     *
     * @param list<string> $skus
     * @return array<string, Collection<int, LocalityRecommendation>>
     */
    public function loadRecommendationsForSkus(
        int $integrationId,
        array $skus,
        string $minConfidence = self::DEFAULT_MIN_CONFIDENCE,
        ?array $allowedClusterIds = null,
    ): array {
        if (empty($skus)) {
            return [];
        }

        $allowedConfidence = match ($minConfidence) {
            'low' => ['low', 'medium', 'high'],
            'medium' => ['medium', 'high'],
            'high' => ['high'],
            default => ['medium', 'high'],
        };

        $query = LocalityRecommendation::query()
            ->where('integration_id', $integrationId)
            ->where('state', LocalityRecommendation::STATE_NEW)
            ->whereIn('sku', $skus)
            ->whereIn('confidence', $allowedConfidence);

        if (is_array($allowedClusterIds) && $allowedClusterIds !== []) {
            $query->whereIn('target_cluster_id', array_map('strval', $allowedClusterIds));
        }

        return $query->orderByDesc('rank_score')
            ->get()
            ->groupBy(fn (LocalityRecommendation $r) => (string) $r->sku);
    }

    /**
     * Обогатить существующие line-data полями локальности.
     *
     * @param array<string,mixed> $lineData
     * @param array<string,mixed>|null $splitResult результат applyClusterSplit() для single child
     * @return array<string,mixed>
     */
    public function enrichLine(
        array $lineData,
        ?LocalityMetricDaily $metric,
        ?Collection $recommendations,
        ?array $splitResult = null,
    ): array {
        if ($metric !== null) {
            $lineData['local_share_percent'] = $metric->local_share_percent !== null
                ? (float) $metric->local_share_percent
                : null;
            $lineData['potential_overpayment_rub'] = (float) $metric->overpayment_amount;
            $lineData['lost_margin_rub'] = (float) $metric->lost_margin_amount;
            $lineData['locality_confidence'] = (string) $metric->calculation_confidence;
        }

        $recsCollection = $recommendations instanceof Collection ? $recommendations : collect([]);
        if ($recsCollection->isNotEmpty()) {
            $lineData['expected_local_share_after_pp'] = round(
                (float) $recsCollection->sum('expected_local_share_uplift_pp'),
                2
            );
            $lineData['expected_savings_rub'] = round(
                (float) $recsCollection->sum('expected_savings_rub'),
                2
            );
            $lineData['linked_locality_recommendation_ids'] = $recsCollection
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        if ($splitResult !== null) {
            $lineData['cluster_split_json'] = $splitResult['split_json'] ?? null;
            $lineData['is_cluster_split'] = ($splitResult['is_split'] ?? false) === true;
            if (isset($splitResult['cluster_id'])) {
                $lineData['cluster_id'] = $splitResult['cluster_id'];
            }
            if (isset($splitResult['cluster_name'])) {
                $lineData['cluster_name'] = $splitResult['cluster_name'];
            }
            if (isset($splitResult['parent_line_key'])) {
                $lineData['parent_line_key'] = $splitResult['parent_line_key'];
            }
            if (isset($splitResult['qty_rounded'])) {
                $lineData['qty_rounded'] = (int) $splitResult['qty_rounded'];
            }
            if (isset($splitResult['aggregated_qty_rounded'])) {
                $lineData['aggregated_qty_rounded'] = (int) $splitResult['aggregated_qty_rounded'];
            }
        }

        return $lineData;
    }

    /**
     * Разбить qty одной SKU-линии на N child-строк по целевым кластерам.
     *
     * @param array<string,mixed> $line — исходная агрегированная строка (qty_rounded уже известен)
     * @param Collection<int, LocalityRecommendation> $recommendations
     * @param array<string, array<string,mixed>> $ozonClusterAnalytics — результат loadOzonDeliveryAnalytics[$sku]['clusters']
     * @return array{children: list<array<string,mixed>>, is_split: bool}
     */
    public function applyClusterSplit(
        array $line,
        Collection $recommendations,
        array $ozonClusterAnalytics = [],
        string $strategy = self::STRATEGY_RECOMMENDATIONS,
        int $maxClusters = self::DEFAULT_MAX_CLUSTERS,
        int $packMultiple = 1,
    ): array {
        $totalQty = (int) ($line['qty_rounded'] ?? 0);
        $sku = (string) ($line['sku'] ?? '');
        $warehouseId = (string) ($line['warehouse_id'] ?? '');
        $parentKey = trim($sku . ':' . $warehouseId, ':');

        // Не делим мелкие qty (< 2 × pack_multiple) — нет смысла
        if ($totalQty <= 0 || $totalQty < max(2, $packMultiple * 2)) {
            return ['children' => [$line + [
                'parent_line_key' => $parentKey,
                'is_cluster_split' => false,
                'aggregated_qty_rounded' => $totalQty,
            ]], 'is_split' => false];
        }

        $splits = $this->resolveSplitWeights($strategy, $recommendations, $ozonClusterAnalytics, $maxClusters);
        if (empty($splits)) {
            return ['children' => [$line + [
                'parent_line_key' => $parentKey,
                'is_cluster_split' => false,
                'aggregated_qty_rounded' => $totalQty,
            ]], 'is_split' => false];
        }

        // Распределяем qty по весам + округление до pack_multiple
        $weightsSum = array_sum(array_column($splits, 'weight'));
        if ($weightsSum <= 0) {
            return ['children' => [$line + [
                'parent_line_key' => $parentKey,
                'is_cluster_split' => false,
                'aggregated_qty_rounded' => $totalQty,
            ]], 'is_split' => false];
        }

        $children = [];
        $distributedQty = 0;
        $splitJsonRows = [];

        foreach ($splits as $i => $split) {
            $share = $split['weight'] / $weightsSum;
            $rawQty = $totalQty * $share;
            $isLast = ($i === array_key_last($splits));

            // Последняя строка забирает остаток, чтобы сумма совпала с totalQty
            $qty = $isLast
                ? max(0, $totalQty - $distributedQty)
                : $this->roundToPackMultiple((int) floor($rawQty), $packMultiple);

            if ($qty <= 0) {
                continue;
            }

            $distributedQty += $qty;

            $child = $line;
            $child['cluster_id'] = $split['cluster_id'];
            $child['cluster_name'] = $split['cluster_name'];
            $child['warehouse_id'] = 'cluster:' . $split['cluster_id'];
            $child['warehouse_name'] = $split['cluster_name'];
            $child['destination'] = $split['cluster_name'];
            $child['destination_id'] = 'cluster:' . $split['cluster_id'];
            $child['destination_type'] = 'cluster';
            $child['qty_rounded'] = $qty;
            $child['qty_recommended'] = $qty;
            $child['parent_line_key'] = $parentKey;
            $child['is_cluster_split'] = true;
            $child['aggregated_qty_rounded'] = $totalQty;

            $expectedSavingsForChild = $this->estimateSavingsForSplit($sku, $split, $qty, $recommendations);

            $splitJsonRows[] = [
                'cluster_id' => $split['cluster_id'],
                'cluster_name' => $split['cluster_name'],
                'qty' => $qty,
                'expected_savings_rub' => $expectedSavingsForChild,
                'rec_id' => $split['rec_id'] ?? null,
                'weight' => round($share * 100, 2),
            ];

            $child['cluster_split_json'] = null; // split_json пишется только на parent row (первой child), остальные — null
            $children[] = $child;
        }

        if (! empty($children)) {
            $children[0]['cluster_split_json'] = $splitJsonRows;
        }

        return ['children' => $children, 'is_split' => count($children) > 1];
    }

    /**
     * Построить summary локальности для всего плана (для result_json.locality_summary).
     *
     * @return array<string,mixed>
     */
    public function buildPlanSummary(AutoSupplyPlan $plan): array
    {
        $plan->loadMissing('lines');
        $lines = $plan->lines ?? collect();

        // Текущая локальность — взвешенное среднее local_share_percent по orders_count (из метрик SKU).
        $ordersSum = 0;
        $localOrdersSum = 0;
        $potentialOverpaymentSum = 0.0;
        $expectedSavingsSum = 0.0;
        $nonLocalOrdersSum = 0;

        $integrationId = (int) ($plan->integration_id ?? 0);
        $skus = $lines->pluck('sku')->filter()->unique()->values()->all();
        $metrics = $this->loadMetricsForSkus($integrationId, $skus);

        foreach ($skus as $sku) {
            $metric = $metrics[(string) $sku] ?? null;
            if ($metric === null) {
                continue;
            }
            $ordersSum += (int) $metric->orders_count;
            $localOrdersSum += (int) $metric->local_orders_count;
            $nonLocalOrdersSum += (int) $metric->non_local_orders_count;
            $potentialOverpaymentSum += (float) $metric->overpayment_amount;
        }

        foreach ($lines as $line) {
            $expectedSavingsSum += (float) ($line->expected_savings_rub ?? 0);
        }

        $considered = $localOrdersSum + $nonLocalOrdersSum;
        $currentShare = $considered > 0 ? round(($localOrdersSum / $considered) * 100, 2) : 0.0;

        // Ожидаемая локальность после применения плана = текущая + взвешенная сумма uplifts
        $expectedUpliftPp = $this->estimatePlanUpliftPp($plan, $lines, $metrics);
        $expectedShareAfter = min(100.0, round($currentShare + $expectedUpliftPp, 2));

        // Top offender кластеров — из LocalityMetricClusterDaily за тот же период
        $topOffenders = LocalityMetricClusterDaily::query()
            ->where('integration_id', $integrationId)
            ->where('period_days', self::DEFAULT_PERIOD_DAYS)
            ->orderByDesc('snapshot_date')
            ->orderByDesc('total_overpayment')
            ->limit(3)
            ->get()
            ->map(fn ($c) => [
                'cluster_id' => $c->destination_cluster_id,
                'cluster_name' => (string) $c->destination_cluster_name,
                'overpayment_rub' => (float) $c->total_overpayment,
                'sku_count' => (int) $c->distinct_skus_affected,
                'local_share_percent' => $c->local_share_percent !== null ? (float) $c->local_share_percent : null,
            ])
            ->values()
            ->all();

        // Coverage рекомендаций (честная метрика):
        // Рекомендация считается "покрытой планом", если в плане есть строка с тем же SKU
        // И тем же target_cluster_id. Просто наличие SKU в плане недостаточно — рекомендация
        // адресная, она про конкретную пару (SKU × кластер).
        $explicitlyLinkedIds = $lines
            ->flatMap(fn ($l) => is_array($l->linked_locality_recommendation_ids)
                ? $l->linked_locality_recommendation_ids
                : [])
            ->unique()
            ->values();

        // Пары (sku, cluster_id), которые трогает план
        $planSkuClusters = $lines
            ->filter(fn ($l) => $l->sku && $l->cluster_id)
            ->map(fn ($l) => (string) $l->sku . '|' . (string) $l->cluster_id)
            ->unique()
            ->values()
            ->all();

        $activeRecsQuery = LocalityRecommendation::query()
            ->where('integration_id', $integrationId)
            ->where('state', LocalityRecommendation::STATE_NEW);

        $totalActive = (clone $activeRecsQuery)->count();

        $skuClusterCoveredIds = $planSkuClusters !== []
            ? (clone $activeRecsQuery)
                ->get(['id', 'sku', 'target_cluster_id'])
                ->filter(fn ($r) => in_array(
                    (string) $r->sku . '|' . (string) ($r->target_cluster_id ?? ''),
                    $planSkuClusters,
                    true
                ))
                ->pluck('id')
            : collect();

        $coveredIds = $explicitlyLinkedIds->concat($skuClusterCoveredIds)->unique()->values();

        $coveragePercent = $totalActive > 0
            ? round(($coveredIds->count() / $totalActive) * 100, 2)
            : 0.0;

        return [
            'current_local_share_percent' => $currentShare,
            'expected_local_share_after_percent' => $expectedShareAfter,
            'expected_uplift_pp' => round($expectedUpliftPp, 2),
            'total_current_overpayment_rub' => round($potentialOverpaymentSum, 2),
            'total_expected_savings_rub' => round($expectedSavingsSum, 2),
            'top_offender_clusters' => $topOffenders,
            'recommendations_covered' => [
                'total_active' => $totalActive,
                'covered' => $coveredIds->count(),
                'coverage_percent' => $coveragePercent,
            ],
            'calculated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Короткий human-readable текст для UI / API:
     * «Применение плана увеличит долю локальности с 56% до 73% и сэкономит 430 000 ₽ за 28 дней».
     */
    public function narrate(array $summary): string
    {
        $before = (float) ($summary['current_local_share_percent'] ?? 0);
        $after = (float) ($summary['expected_local_share_after_percent'] ?? 0);
        $uplift = (float) ($summary['expected_uplift_pp'] ?? 0);
        $savings = (float) ($summary['total_expected_savings_rub'] ?? 0);

        $savingsFmt = number_format($savings, 0, '.', ' ');

        if ($uplift <= 0.1 && $savings <= 100) {
            return 'План не даёт заметного улучшения локальности по текущим данным. '
                . 'Проверьте, что в Locality Engine есть активные рекомендации.';
        }

        return sprintf(
            'Применение плана увеличит долю локальности с %.1f%% до %.1f%% (+%.1f п.п.) и сэкономит %s ₽ за 28 дней.',
            $before,
            $after,
            $uplift,
            $savingsFmt
        );
    }

    /**
     * Нормализуем стратегию split в набор целевых кластеров с весами.
     *
     * @return list<array{cluster_id:?string, cluster_name:string, weight:float, rec_id:?int}>
     */
    private function resolveSplitWeights(
        string $strategy,
        Collection $recommendations,
        array $ozonClusterAnalytics,
        int $maxClusters,
    ): array {
        if ($strategy === self::STRATEGY_RECOMMENDATIONS && $recommendations->isNotEmpty()) {
            return $this->weightsFromRecommendations($recommendations, $maxClusters);
        }

        if ($strategy === self::STRATEGY_DEMAND_WEIGHTED && ! empty($ozonClusterAnalytics)) {
            return $this->weightsFromOzonAnalytics($ozonClusterAnalytics, $maxClusters);
        }

        // Fallback: если есть рекомендации — используем их, иначе Ozon analytics, иначе пусто (single row).
        if ($recommendations->isNotEmpty()) {
            return $this->weightsFromRecommendations($recommendations, $maxClusters);
        }
        if (! empty($ozonClusterAnalytics)) {
            return $this->weightsFromOzonAnalytics($ozonClusterAnalytics, $maxClusters);
        }
        return [];
    }

    /** @return list<array{cluster_id:?string, cluster_name:string, weight:float, rec_id:?int}> */
    private function weightsFromRecommendations(Collection $recommendations, int $maxClusters): array
    {
        $top = $recommendations->sortByDesc('rank_score')->take($maxClusters)->values();
        $result = [];
        foreach ($top as $r) {
            $qty = max(0, (int) $r->recommended_qty_units);
            if ($qty <= 0) {
                continue;
            }
            $result[] = [
                'cluster_id' => $r->target_cluster_id !== null ? (string) $r->target_cluster_id : null,
                'cluster_name' => (string) $r->target_cluster_name,
                'weight' => (float) $qty,
                'rec_id' => (int) $r->id,
            ];
        }
        return $result;
    }

    /** @return list<array{cluster_id:?string, cluster_name:string, weight:float, rec_id:?int}> */
    private function weightsFromOzonAnalytics(array $ozonClusterAnalytics, int $maxClusters): array
    {
        // Формат из AutoSupplyPlanService::loadOzonDeliveryAnalytics:
        // $ozonClusterAnalytics = [cluster_id => ['recommended_supply' => int, 'cluster_name' => string, ...]]
        $rows = [];
        foreach ($ozonClusterAnalytics as $clusterId => $data) {
            $supply = (int) ($data['recommended_supply'] ?? 0);
            if ($supply <= 0) {
                continue;
            }
            $rows[] = [
                'cluster_id' => (string) $clusterId,
                'cluster_name' => (string) ($data['cluster_name'] ?? ''),
                'weight' => (float) $supply,
                'rec_id' => null,
            ];
        }
        usort($rows, fn ($a, $b) => $b['weight'] <=> $a['weight']);
        return array_slice($rows, 0, $maxClusters);
    }

    private function roundToPackMultiple(int $qty, int $packMultiple): int
    {
        if ($packMultiple <= 1) {
            return $qty;
        }
        return (int) (ceil($qty / $packMultiple) * $packMultiple);
    }

    /**
     * @param array{cluster_id:?string, cluster_name:string, weight:float, rec_id:?int} $split
     * @param Collection<int, LocalityRecommendation> $recommendations
     */
    private function estimateSavingsForSplit(
        string $sku,
        array $split,
        int $qty,
        Collection $recommendations,
    ): float {
        if ($split['rec_id'] !== null) {
            $rec = $recommendations->firstWhere('id', $split['rec_id']);
            if ($rec !== null) {
                $recQty = max(1, (int) $rec->recommended_qty_units);
                $savings = (float) $rec->expected_savings_rub;
                return round($savings * min(1.0, $qty / $recQty), 2);
            }
        }

        // Fallback: приблизительная оценка через таблицу наценок Ozon.
        $markupPct = (float) $this->pricing->resolveDestinationMarkupPercent($split['cluster_name']);
        if ($markupPct <= 0) {
            return 0.0;
        }
        return round($qty * ($markupPct / 100) * 500, 2); // ~500₽ — средняя цена позиции, fallback-оценка.
    }

    /**
     * Оценка ожидаемого уплифта локальности для плана в п.п.
     *
     * Источник uplift'a per SKU = max(uplift из явно залинкованных рекомендаций строк плана,
     * uplift из всех активных рекомендаций по этому SKU).
     * Это работает даже если cluster split не присвоил рекомендации напрямую к строкам
     * (например, при малых qty или несовпадении target_cluster_id).
     *
     * Взвешивание по orders_count из LocalityMetricDaily.
     *
     * @param Collection $lines
     * @param array<string, LocalityMetricDaily> $metrics
     */
    private function estimatePlanUpliftPp(AutoSupplyPlan $plan, Collection $lines, array $metrics): float
    {
        $integrationId = (int) ($plan->integration_id ?? 0);
        $planSkus = $lines->pluck('sku')->filter()->unique()->values()->all();

        if ($planSkus === [] || $integrationId <= 0) {
            return 0.0;
        }

        // 1) Uplift из явно залинкованных рекомендаций (приоритет — он отражает фактический cluster split)
        $perSkuUpliftFromLines = $lines
            ->groupBy('sku')
            ->map(fn (Collection $linesForSku) => (float) $linesForSku->max('expected_local_share_after_pp'));

        // 2) Uplift из активных рекомендаций, чья пара (sku, target_cluster_id) фактически в плане
        $planSkuClusters = $lines
            ->filter(fn ($l) => $l->sku && $l->cluster_id)
            ->map(fn ($l) => (string) $l->sku . '|' . (string) $l->cluster_id)
            ->unique()
            ->values()
            ->all();

        $matchingRecs = LocalityRecommendation::query()
            ->where('integration_id', $integrationId)
            ->where('state', LocalityRecommendation::STATE_NEW)
            ->whereIn('sku', $planSkus)
            ->get(['sku', 'target_cluster_id', 'expected_local_share_uplift_pp'])
            ->filter(fn ($r) => in_array(
                (string) $r->sku . '|' . (string) ($r->target_cluster_id ?? ''),
                $planSkuClusters,
                true
            ))
            ->groupBy(fn ($r) => (string) $r->sku);

        $totalOrders = 0;
        $upliftWeighted = 0.0;

        foreach ($planSkus as $sku) {
            $metric = $metrics[(string) $sku] ?? null;
            if ($metric === null) {
                continue;
            }
            $orders = (int) $metric->orders_count;
            if ($orders <= 0) {
                continue;
            }

            $upliftFromLines = (float) ($perSkuUpliftFromLines[(string) $sku] ?? 0);
            $upliftFromRecs = (float) ($matchingRecs->get((string) $sku)?->sum('expected_local_share_uplift_pp') ?? 0);
            $upliftPp = max($upliftFromLines, $upliftFromRecs);

            if ($upliftPp <= 0) {
                continue;
            }

            $totalOrders += $orders;
            $upliftWeighted += $orders * $upliftPp;
        }

        return $totalOrders > 0 ? $upliftWeighted / $totalOrders : 0.0;
    }
}
