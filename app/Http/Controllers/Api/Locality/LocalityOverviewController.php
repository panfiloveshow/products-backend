<?php

namespace App\Http\Controllers\Api\Locality;

use App\Domains\Locality\Presentation\DTO\LocalityOverviewDto;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\LocalityMetricClusterDaily;
use App\Models\LocalityMetricDaily;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocalityOverviewController extends Controller
{
    /** GET /api/v1/locality/overview */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'period' => 'nullable|integer|in:7,28',
            'as_of' => 'nullable|date_format:Y-m-d',
        ]);

        $integration = Integration::findOrFail($validated['integration_id']);
        if ($integration->marketplace !== 'ozon') {
            return response()->json(['message' => 'Этот endpoint только для Ozon'], 422);
        }

        $period = (int) ($validated['period'] ?? config('locality.period.default_days', 28));
        if (isset($validated['as_of'])) {
            $asOf = Carbon::parse($validated['as_of']);
        } else {
            $latest = LocalityMetricDaily::query()
                ->where('integration_id', $integration->id)
                ->where('period_days', $period)
                ->max('snapshot_date');
            $asOf = $latest ? Carbon::parse($latest) : now()->startOfDay();
        }

        $skuRows = LocalityMetricDaily::query()
            ->where('integration_id', $integration->id)
            ->where('snapshot_date', $asOf->toDateString())
            ->where('period_days', $period)
            ->get();

        $clusterRows = LocalityMetricClusterDaily::query()
            ->where('integration_id', $integration->id)
            ->where('snapshot_date', $asOf->toDateString())
            ->where('period_days', $period)
            ->get();

        $ordersCount = (int) $skuRows->sum('orders_count');
        $local = (int) $skuRows->sum('local_orders_count');
        $nonLocal = (int) $skuRows->sum('non_local_orders_count');
        $considered = $local + $nonLocal;
        $localShare = $considered > 0 ? round(($local / $considered) * 100, 2) : 0.0;

        $overpayment = (float) $skuRows->sum('overpayment_amount');
        $lostMargin = (float) $skuRows->sum('lost_margin_amount');
        $revenue = (float) $skuRows->sum('revenue_total');
        $opToRev = $revenue > 0 ? round(($overpayment / $revenue) * 100, 2) : 0.0;

        $factual = (int) $skuRows->sum('factual_orders_count');
        $factualPct = $ordersCount > 0 ? round(($factual / $ordersCount) * 100, 2) : 0.0;

        $confidence = $this->storeConfidence($skuRows, $ordersCount, $factualPct);

        $topSkus = $skuRows
            ->sortByDesc(fn ($r) => (float) $r->overpayment_amount)
            ->take(5)
            ->map(fn ($r) => [
                'sku' => (string) $r->sku,
                'orders_count' => (int) $r->orders_count,
                'local_share_percent' => $r->local_share_percent !== null ? (float) $r->local_share_percent : null,
                'overpayment_amount' => (float) $r->overpayment_amount,
                'lost_margin_amount' => (float) $r->lost_margin_amount,
            ])
            ->values()
            ->all();

        $topClusters = $clusterRows
            ->sortByDesc(fn ($r) => (float) $r->total_overpayment)
            ->take(5)
            ->map(fn ($r) => [
                'destination_cluster_id' => $r->destination_cluster_id,
                'destination_cluster_name' => (string) $r->destination_cluster_name,
                'orders_count' => (int) $r->orders_count,
                'local_share_percent' => $r->local_share_percent !== null ? (float) $r->local_share_percent : null,
                'total_overpayment' => (float) $r->total_overpayment,
                'distinct_skus_affected' => (int) $r->distinct_skus_affected,
            ])
            ->values()
            ->all();

        $dominantDest = $clusterRows
            ->sortByDesc(fn ($r) => (int) $r->orders_count)
            ->take(5)
            ->map(fn ($r) => [
                'destination_cluster_name' => (string) $r->destination_cluster_name,
                'orders_count' => (int) $r->orders_count,
                'share_percent' => $ordersCount > 0 ? round(($r->orders_count / $ordersCount) * 100, 2) : 0.0,
            ])
            ->values()
            ->all();

        $dto = new LocalityOverviewDto(
            integrationId: (int) $integration->id,
            asOf: $asOf->toDateString(),
            periodDays: $period,
            ordersCount: $ordersCount,
            localSharePercent: $localShare,
            overpaymentTotal: $overpayment,
            lostMarginTotal: $lostMargin,
            revenueTotal: $revenue,
            overpaymentToRevenuePercent: $opToRev,
            dominantDestinationClusters: $dominantDest,
            calculationConfidence: $confidence,
            factualOrdersPercent: $factualPct,
            topOffenderSkus: $topSkus,
            topOffenderClusters: $topClusters,
        );

        return response()->json([
            'message' => 'Success',
            'data' => $dto->toArray(),
        ]);
    }

    /**
     * Store-level confidence must describe the reliability of the overall store picture,
     * not the weakest long-tail SKU. A store with hundreds of factual orders should not
     * be marked as low just because a few rare SKUs have low per-SKU coverage.
     */
    private function storeConfidence($skuRows, int $ordersCount, float $factualPct): string
    {
        if ($ordersCount <= 0) {
            return 'low';
        }

        if ($ordersCount >= 100 && $factualPct >= 90.0) {
            return 'high';
        }

        if ($ordersCount >= 30 && $factualPct >= 70.0) {
            return 'medium';
        }

        $rank = ['low' => 0, 'medium' => 1, 'high' => 2];
        $weightedScore = 0.0;
        $weightedOrders = 0;

        foreach ($skuRows as $row) {
            $rowOrders = (int) $row->orders_count;
            if ($rowOrders <= 0) {
                continue;
            }

            $confidence = (string) ($row->calculation_confidence ?? 'low');
            $weightedScore += ($rank[$confidence] ?? 0) * $rowOrders;
            $weightedOrders += $rowOrders;
        }

        if ($weightedOrders <= 0) {
            return 'low';
        }

        $score = $weightedScore / $weightedOrders;

        return match (true) {
            $score >= 1.7 => 'high',
            $score >= 0.8 => 'medium',
            default => 'low',
        };
    }
}
