<?php

namespace App\Http\Controllers\Api\Locality;

use App\Domains\Locality\Presentation\DTO\ClusterLocalityDto;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\LocalityMetricClusterDaily;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocalityClusterController extends Controller
{
    private const ALLOWED_SORT = ['overpayment', 'orders', 'local_share_asc', 'revenue'];

    /** GET /api/v1/locality/clusters */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'period' => 'nullable|integer|in:7,28',
            'as_of' => 'nullable|date_format:Y-m-d',
            'sort' => 'nullable|string|in:' . implode(',', self::ALLOWED_SORT),
        ]);

        $integration = Integration::findOrFail($validated['integration_id']);
        $period = (int) ($validated['period'] ?? config('locality.period.default_days', 28));
        if (isset($validated['as_of'])) {
            $asOf = Carbon::parse($validated['as_of']);
        } else {
            $latest = \App\Models\LocalityMetricClusterDaily::query()
                ->where('integration_id', $integration->id)
                ->where('period_days', $period)
                ->max('snapshot_date');
            $asOf = $latest ? Carbon::parse($latest) : now()->startOfDay();
        }
        $sort = $validated['sort'] ?? 'overpayment';

        $query = LocalityMetricClusterDaily::query()
            ->where('integration_id', $integration->id)
            ->where('snapshot_date', $asOf->toDateString())
            ->where('period_days', $period);

        match ($sort) {
            'overpayment' => $query->orderByDesc('total_overpayment'),
            'orders' => $query->orderByDesc('orders_count'),
            'local_share_asc' => $query->orderBy('local_share_percent'),
            'revenue' => $query->orderByDesc('total_revenue'),
        };

        $rows = $query->get();

        $data = $rows->map(function ($row) {
            $dto = new ClusterLocalityDto(
                destinationClusterId: $row->destination_cluster_id,
                destinationClusterName: (string) $row->destination_cluster_name,
                ordersCount: (int) $row->orders_count,
                localOrdersCount: (int) $row->local_orders_count,
                localSharePercent: $row->local_share_percent !== null ? (float) $row->local_share_percent : null,
                totalRevenue: (float) $row->total_revenue,
                totalOverpayment: (float) $row->total_overpayment,
                lostMarginAmount: (float) $row->lost_margin_amount,
                distinctSkusAffected: (int) $row->distinct_skus_affected,
                topSkusByLoss: is_array($row->top_skus_by_loss) ? $row->top_skus_by_loss : [],
                shippingClusterBreakdown: is_array($row->shipping_cluster_breakdown) ? $row->shipping_cluster_breakdown : [],
            );
            return $dto->toArray();
        })->all();

        return response()->json([
            'message' => 'Success',
            'data' => $data,
            'meta' => [
                'sort' => $sort,
                'period_days' => $period,
                'as_of' => $asOf->toDateString(),
                'count' => count($data),
            ],
        ]);
    }
}
