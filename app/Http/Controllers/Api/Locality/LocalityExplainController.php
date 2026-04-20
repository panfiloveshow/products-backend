<?php

namespace App\Http\Controllers\Api\Locality;

use App\Domains\Locality\Explainability\LocalityExplanationService;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocalityExplainController extends Controller
{
    public function __construct(
        private readonly LocalityExplanationService $service,
    ) {
    }

    /** GET /api/v1/locality/sku/{sku}/explain  OR  GET /api/v1/locality/explain?sku= */
    public function show(Request $request, ?string $sku = null): JsonResponse
    {
        $validated = $request->validate([
            'sku' => 'nullable|string',
            'integration_id' => 'required|exists:integrations,id',
            'period' => 'nullable|integer|in:7,28',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'include_timeline' => 'nullable|string',
        ]);

        $resolvedSku = $sku ?? ($validated['sku'] ?? null);
        if ($resolvedSku === null || $resolvedSku === '') {
            return response()->json(['message' => 'sku is required'], 422);
        }

        Integration::findOrFail($validated['integration_id']);

        [$from, $to] = $this->resolvePeriod($validated);
        $includeTimeline = array_key_exists('include_timeline', $validated)
            ? filter_var($validated['include_timeline'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true
            : true;

        $dto = $this->service->explainForSku(
            (int) $validated['integration_id'],
            $resolvedSku,
            $from,
            $to,
            $includeTimeline,
        );

        return response()->json([
            'message' => 'Success',
            'data' => $dto->toArray(),
        ]);
    }

    /** POST /api/v1/locality/sku/{sku}/counterfactual  OR  POST /api/v1/locality/counterfactual */
    public function counterfactual(Request $request, ?string $sku = null): JsonResponse
    {
        $validated = $request->validate([
            'sku' => 'nullable|string',
            'integration_id' => 'required|exists:integrations,id',
            'target_cluster_id' => 'required|string',
            'hypothetical_qty' => 'required|integer|min:1|max:100000',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'period' => 'nullable|integer|in:7,28',
        ]);

        $resolvedSku = $sku ?? ($validated['sku'] ?? null);
        if ($resolvedSku === null || $resolvedSku === '') {
            return response()->json(['message' => 'sku is required'], 422);
        }

        [$from, $to] = $this->resolvePeriod($validated);

        $dto = $this->service->explainForSku(
            (int) $validated['integration_id'],
            $resolvedSku,
            $from,
            $to,
            includeTimeline: false,
            counterfactualQty: (int) $validated['hypothetical_qty'],
            counterfactualClusterId: (string) $validated['target_cluster_id'],
        );

        return response()->json([
            'message' => 'Success',
            'data' => $dto->toArray(),
        ]);
    }

    /** @return array{0:Carbon,1:Carbon} */
    private function resolvePeriod(array $v): array
    {
        if (! empty($v['from']) && ! empty($v['to'])) {
            return [Carbon::parse($v['from'])->startOfDay(), Carbon::parse($v['to'])->endOfDay()];
        }

        $period = (int) ($v['period'] ?? config('locality.period.default_days', 28));
        $to = now()->endOfDay();
        $from = $to->copy()->subDays($period)->startOfDay();
        return [$from, $to];
    }
}
