<?php

namespace App\Http\Controllers\Api\Locality;

use App\Domains\Locality\Jobs\GenerateRecommendationsJob;
use App\Domains\Locality\Presentation\DTO\RecommendationDto;
use App\Domains\Locality\Recommendation\LocalityDraftApplier;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\LocalityRecommendation;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocalityRecommendationController extends Controller
{
    private const ALLOWED_SORT = ['savings', 'confidence', 'uplift', 'score'];
    private const ALLOWED_DISMISS_REASONS = ['not_profitable', 'wrong_sku', 'wrong_cluster', 'already_planned', 'other'];

    /** GET /api/v1/locality/recommendations */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'state' => 'nullable|string|in:new,dismissed,applied,superseded_by_supply,stale,expired',
            'cluster_id' => 'nullable|string',
            'min_savings' => 'nullable|numeric|min:0',
            'confidence' => 'nullable|string|in:low,medium,high',
            'sort' => 'nullable|string|in:' . implode(',', self::ALLOWED_SORT),
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $integration = Integration::findOrFail($validated['integration_id']);
        $state = $validated['state'] ?? LocalityRecommendation::STATE_NEW;
        $sort = $validated['sort'] ?? 'savings';
        $perPage = (int) ($validated['per_page'] ?? 20);

        $query = LocalityRecommendation::query()
            ->forIntegration((int) $integration->id)
            ->where('state', $state)
            ->when(! empty($validated['cluster_id']), fn ($q) => $q->where('target_cluster_id', $validated['cluster_id']))
            ->when(isset($validated['min_savings']), fn ($q) => $q->where('expected_savings_rub', '>=', (float) $validated['min_savings']))
            ->when(! empty($validated['confidence']), fn ($q) => $q->where('confidence', $validated['confidence']));

        match ($sort) {
            'savings' => $query->orderByDesc('expected_savings_rub'),
            'confidence' => $query->orderByDesc('confidence_score')->orderByDesc('expected_savings_rub'),
            'uplift' => $query->orderByDesc('expected_local_share_uplift_pp'),
            'score' => $query->orderByDesc('rank_score'),
        };

        $paginator = $query->paginate($perPage);

        $productNames = Product::query()
            ->where('integration_id', $integration->id)
            ->whereIn('sku', $paginator->pluck('sku')->all())
            ->pluck('name', 'sku')
            ->all();

        $data = collect($paginator->items())->map(function (LocalityRecommendation $r) use ($productNames, $integration) {
            $explainUrl = sprintf(
                '/api/v1/locality/sku/%s/explain?integration_id=%d',
                urlencode($r->sku),
                $integration->id
            );
            return RecommendationDto::fromModel($r, $productNames[$r->sku] ?? null, $explainUrl)->toArray();
        })->all();

        return response()->json([
            'message' => 'Success',
            'data' => $data,
            'stats' => $this->aggregateStats((int) $integration->id, $state),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /** GET /api/v1/locality/recommendations/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $rec = LocalityRecommendation::query()->findOrFail($id);
        $product = Product::query()
            ->where('integration_id', $rec->integration_id)
            ->where('sku', $rec->sku)
            ->first();

        $explainUrl = sprintf(
            '/api/v1/locality/sku/%s/explain?integration_id=%d',
            urlencode($rec->sku),
            $rec->integration_id
        );

        return response()->json([
            'message' => 'Success',
            'data' => RecommendationDto::fromModel($rec, $product?->name, $explainUrl)->toArray(),
        ]);
    }

    /** POST /api/v1/locality/recommendations/{id}/dismiss */
    public function dismiss(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|in:' . implode(',', self::ALLOWED_DISMISS_REASONS),
        ]);

        $rec = LocalityRecommendation::query()->findOrFail($id);
        if ($rec->state !== LocalityRecommendation::STATE_NEW) {
            return response()->json(['message' => 'Only new recommendations can be dismissed'], 422);
        }

        $rec->fill([
            'state' => LocalityRecommendation::STATE_DISMISSED,
            'dismissed_at' => now(),
            'dismiss_reason' => $validated['reason'] ?? 'other',
        ])->save();

        return response()->json([
            'message' => 'Dismissed',
            'data' => RecommendationDto::fromModel($rec, null, '')->toArray(),
        ]);
    }

    /** POST /api/v1/locality/recommendations/{id}/draft/preview */
    public function draftPreview(Request $request, int $id, LocalityDraftApplier $applier): JsonResponse
    {
        $rec = LocalityRecommendation::query()->findOrFail($id);
        return response()->json([
            'message' => 'Success',
            'data' => $applier->buildPayload($rec),
        ]);
    }

    /** POST /api/v1/locality/recommendations/{id}/draft/create */
    public function draftCreate(Request $request, int $id, LocalityDraftApplier $applier): JsonResponse
    {
        $rec = LocalityRecommendation::query()->findOrFail($id);
        if ($rec->state !== LocalityRecommendation::STATE_NEW) {
            return response()->json(['message' => 'Only new recommendations can be applied'], 422);
        }

        $result = $applier->apply($rec);
        $status = $result['success'] ? 200 : 422;

        return response()->json([
            'message' => $result['success'] ? 'Draft created' : 'Failed',
            'data' => array_merge($result, ['recommendation_id' => $rec->id]),
        ], $status);
    }

    /** POST /api/v1/locality/recommendations/recompute */
    public function recompute(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
        ]);

        GenerateRecommendationsJob::dispatch((int) $validated['integration_id']);

        return response()->json([
            'message' => 'Queued',
            'data' => ['queued_at' => now()->toIso8601String()],
        ]);
    }

    /** @return array<string,mixed> */
    private function aggregateStats(int $integrationId, string $state): array
    {
        $rows = LocalityRecommendation::query()
            ->forIntegration($integrationId)
            ->where('state', $state)
            ->get();

        $byCluster = $rows
            ->groupBy('target_cluster_name')
            ->map(fn ($g) => [
                'cluster_name' => (string) $g->first()->target_cluster_name,
                'count' => $g->count(),
                'savings_rub' => round((float) $g->sum('expected_savings_rub'), 2),
            ])
            ->sortByDesc('savings_rub')
            ->values()
            ->all();

        $byConfidence = [
            'high' => $rows->where('confidence', 'high')->count(),
            'medium' => $rows->where('confidence', 'medium')->count(),
            'low' => $rows->where('confidence', 'low')->count(),
        ];

        return [
            'total_active' => $rows->count(),
            'total_savings_available_rub' => round((float) $rows->sum('expected_savings_rub'), 2),
            'by_cluster' => $byCluster,
            'by_confidence' => $byConfidence,
        ];
    }
}
