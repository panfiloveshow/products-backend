<?php

namespace App\Domains\Locality\Calculation;

use App\Domains\Locality\Ingestion\PostingEnrichmentReader;
use App\Models\LocalityMetricClusterDaily;
use App\Models\LocalityMetricDaily;
use App\Models\OzonOrderUnitEconomics;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Дневной rollup per-SKU и per-cluster_destination метрик локальности.
 * Читает ozon_order_unit_economics за [date - periodDays, date], упаковывает в snapshot.
 */
class LocalityAggregator
{
    public function __construct(
        private readonly PostingEnrichmentReader $reader,
        private readonly LocalityShareCalculator $shareCalc = new LocalityShareCalculator(),
        private readonly OverpaymentCalculator $overpayCalc = new OverpaymentCalculator(),
        private readonly LostMarginCalculator $lostMarginCalc = new LostMarginCalculator(),
    ) {
    }

    /**
     * @return array{skus_processed:int, clusters_processed:int}
     */
    public function runDaily(int $integrationId, Carbon $date, int $periodDays = 28): array
    {
        $to = $date->copy()->endOfDay();
        $from = $to->copy()->subDays($periodDays)->startOfDay();

        // Shadow-update: фиксируем старт, updateOrCreate'им актуальные SKU/кластеры,
        // в конце удаляем осиротевшие snapshot'ы этого (integration, date, period),
        // у которых updated_at остался до старта. Иначе SKU, которые перестали
        // продаваться за последние 28 дней, навсегда сохраняли бы старые orders_count.
        $startedAt = now();

        $items = $this->reader->queryForPeriod($integrationId, $from, $to)->get();

        $skusProcessed = $this->aggregateBySku($integrationId, $date, $periodDays, $items);
        $clustersProcessed = $this->aggregateByCluster($integrationId, $date, $periodDays, $items);

        $skuOrphansPruned = LocalityMetricDaily::query()
            ->where('integration_id', $integrationId)
            ->where('snapshot_date', $date->toDateString())
            ->where('period_days', $periodDays)
            ->where('updated_at', '<', $startedAt)
            ->delete();

        $clusterOrphansPruned = LocalityMetricClusterDaily::query()
            ->where('integration_id', $integrationId)
            ->where('snapshot_date', $date->toDateString())
            ->where('period_days', $periodDays)
            ->where('updated_at', '<', $startedAt)
            ->delete();

        Log::channel('locality')->info('LocalityAggregator runDaily completed', [
            'integration_id' => $integrationId,
            'snapshot_date' => $date->toDateString(),
            'period_days' => $periodDays,
            'items_scanned' => $items->count(),
            'skus' => $skusProcessed,
            'clusters' => $clustersProcessed,
            'sku_orphans_pruned' => $skuOrphansPruned,
            'cluster_orphans_pruned' => $clusterOrphansPruned,
        ]);

        return [
            'skus_processed' => $skusProcessed,
            'clusters_processed' => $clustersProcessed,
            'sku_orphans_pruned' => $skuOrphansPruned,
            'cluster_orphans_pruned' => $clusterOrphansPruned,
        ];
    }

    /** @param Collection<int,OzonOrderUnitEconomics> $items */
    private function aggregateBySku(int $integrationId, Carbon $date, int $periodDays, Collection $items): int
    {
        $bySku = $items->groupBy('sku');
        $processed = 0;

        foreach ($bySku as $sku => $group) {
            if ($sku === '' || $sku === null) {
                continue;
            }

            $share = $this->shareCalc->compute($group);
            $overpayBreakdown = $this->overpayCalc->compute($group);
            $overpayment = (float) $overpayBreakdown['potential'];
            $actualOverpayment = (float) $overpayBreakdown['actual'];
            $lostMargin = $this->lostMarginCalc->computeForItems($group);

            $revenue = round((float) $group->sum('sale_price'), 2);
            $baseTotal = round((float) $group->sum('base_logistics_tariff'), 2);
            $markupTotal = round((float) $group->where('markup_applied', true)->sum('non_local_markup_amount'), 2);

            $ordersCount = $group->count();
            $factual = $group->where('calculation_mode', 'factual')->count();
            $estimate = $group->where('calculation_mode', 'estimate')->count();

            $snapshotConfidence = $this->coverageConfidence($group);

            $avgBase = $ordersCount > 0 ? round($baseTotal / $ordersCount, 2) : null;
            $avgMarkupPct = (float) $overpayBreakdown['avg_markup_percent'];

            $tariffVersion = $group->pluck('tariff_version_used')->filter()->first();

            $dominantDest = $group->groupBy('destination_cluster_name')
                ->map->count()
                ->sortDesc()
                ->keys()
                ->first();
            $dominantShip = $group->groupBy('shipping_cluster_name')
                ->map->count()
                ->sortDesc()
                ->keys()
                ->first();

            LocalityMetricDaily::query()->updateOrCreate(
                [
                    'integration_id' => $integrationId,
                    'sku' => (string) $sku,
                    'snapshot_date' => $date->toDateString(),
                    'period_days' => $periodDays,
                ],
                [
                    'orders_count' => $ordersCount,
                    'local_orders_count' => $share['local'],
                    'non_local_orders_count' => $share['non_local'],
                    'local_share_percent' => $share['share_percent'],
                    'revenue_total' => $revenue,
                    'base_logistics_total' => $baseTotal,
                    'non_local_markup_total' => $markupTotal,
                    'overpayment_amount' => $overpayment,
                    'lost_margin_amount' => $lostMargin['amount'],
                    'avg_base_tariff' => $avgBase,
                    'avg_markup_percent' => $avgMarkupPct,
                    'factual_orders_count' => $factual,
                    'estimate_orders_count' => $estimate,
                    'calculation_confidence' => $snapshotConfidence,
                    'tariff_version_used' => $tariffVersion ? (string) $tariffVersion : null,
                    'meta' => [
                        'dominant_destination_cluster' => $dominantDest,
                        'dominant_shipping_cluster' => $dominantShip,
                        'lost_margin_degraded_items' => $lostMargin['degraded_items'],
                        'actual_overpayment_by_ozon' => round($actualOverpayment, 2),
                        'non_local_orders' => $overpayBreakdown['non_local_orders'],
                    ],
                ]
            );
            $processed++;
        }

        return $processed;
    }

    /** @param Collection<int,OzonOrderUnitEconomics> $items */
    private function aggregateByCluster(int $integrationId, Carbon $date, int $periodDays, Collection $items): int
    {
        $byCluster = $items->groupBy('destination_cluster_name');
        $processed = 0;

        foreach ($byCluster as $clusterName => $group) {
            if ($clusterName === null || $clusterName === '') {
                continue;
            }

            $share = $this->shareCalc->compute($group);
            $overpayBreakdown = $this->overpayCalc->compute($group);
            $overpayment = (float) $overpayBreakdown['potential'];
            $actualOverpayment = (float) $overpayBreakdown['actual'];
            $lostMargin = $this->lostMarginCalc->computeForItems($group);

            $revenue = round((float) $group->sum('sale_price'), 2);
            $destClusterId = $group->pluck('destination_cluster_id')->filter()->first();

            $shippingBreakdown = $group
                ->groupBy('shipping_cluster_name')
                ->map(fn ($sub) => $sub->count())
                ->sortDesc()
                ->all();

            $nonLocalItems = $group->filter(fn ($i) => $i->shipping_cluster_name !== null
                && $i->destination_cluster_name !== null
                && $i->shipping_cluster_name !== $i->destination_cluster_name
                && ! in_array($i->markup_reason_code, ['cancelled_order', 'not_redeemed'], true));

            $topSkus = $nonLocalItems
                ->groupBy('sku')
                ->map(function ($sub) {
                    $breakdown = $this->overpayCalc->compute($sub);
                    return [
                        'sku' => (string) $sub->first()->sku,
                        'overpayment' => (float) $breakdown['potential'],
                        'orders_count' => $sub->count(),
                    ];
                })
                ->sortByDesc('overpayment')
                ->values()
                ->take(10)
                ->all();

            LocalityMetricClusterDaily::query()->updateOrCreate(
                [
                    'integration_id' => $integrationId,
                    'destination_cluster_name' => (string) $clusterName,
                    'snapshot_date' => $date->toDateString(),
                    'period_days' => $periodDays,
                ],
                [
                    'destination_cluster_id' => $destClusterId ? (string) $destClusterId : null,
                    'orders_count' => $group->count(),
                    'local_orders_count' => $share['local'],
                    'local_share_percent' => $share['share_percent'],
                    'total_revenue' => $revenue,
                    'total_overpayment' => $overpayment,
                    'lost_margin_amount' => $lostMargin['amount'],
                    'distinct_skus_affected' => $group->pluck('sku')->unique()->count(),
                    'top_skus_by_loss' => $topSkus,
                    'shipping_cluster_breakdown' => $shippingBreakdown,
                    'meta' => [
                        'lost_margin_degraded_items' => $lostMargin['degraded_items'],
                        'actual_overpayment_by_ozon' => round($actualOverpayment, 2),
                        'non_local_orders' => $overpayBreakdown['non_local_orders'],
                    ],
                ]
            );
            $processed++;
        }

        return $processed;
    }

    /** @param list<string> $confidences */
    private function reduceConfidence(array $confidences, bool $lostMarginDegraded): string
    {
        // Deprecated helper (used until confidence стал coverage-based).
        return 'medium';
    }

    /**
     * Уверенность = покрытие кластеров в постингах за период.
     * ≥95% заказов с known(shipping) && known(destination) → high
     * ≥70% → medium
     * иначе → low
     *
     * @param Collection<int,OzonOrderUnitEconomics> $items
     */
    private function coverageConfidence(Collection $items): string
    {
        $considered = $items->whereNotIn('markup_reason_code', ['cancelled_order', 'not_redeemed']);
        $total = $considered->count();
        if ($total === 0) {
            return 'low';
        }
        $withBoth = $considered->filter(fn ($i) => $i->shipping_cluster_name !== null
            && $i->destination_cluster_name !== null)->count();
        $coverage = $withBoth / $total;
        return match (true) {
            $coverage >= 0.95 => 'high',
            $coverage >= 0.70 => 'medium',
            default => 'low',
        };
    }
}
