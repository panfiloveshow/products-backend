<?php

namespace App\Http\Controllers\Api\Locality;

use App\Domains\Locality\Jobs\AggregateLocalityDailyJob;
use App\Domains\Locality\Jobs\GenerateRecommendationsJob;
use App\Domains\Locality\Jobs\ReconcileFinanceJob;
use App\Domains\Locality\Jobs\SyncClusterMapJob;
use App\Domains\Locality\Jobs\SyncFinanceTransactionsJob;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LocalityRecomputeController extends Controller
{
    /** POST /api/v1/locality/recompute */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'scope' => 'nullable|string|in:aggregation,recommendations,reconciliation,ingestion,all',
            'period_days' => 'nullable|integer|in:7,28',
        ]);

        $integration = Integration::findOrFail($validated['integration_id']);
        if ($integration->marketplace !== 'ozon') {
            return response()->json(['message' => 'Этот endpoint только для Ozon'], 422);
        }

        $scope = $validated['scope'] ?? 'aggregation';
        $period = $validated['period_days'] ?? null;
        $jobId = (string) Str::uuid();
        $dispatched = [];

        if (in_array($scope, ['ingestion', 'all'], true)) {
            SyncClusterMapJob::dispatch((int) $integration->id);
            SyncFinanceTransactionsJob::dispatch((int) $integration->id);
            $dispatched[] = 'ingestion';
        }

        if (in_array($scope, ['aggregation', 'all'], true)) {
            AggregateLocalityDailyJob::dispatch(
                (int) $integration->id,
                now()->toDateString(),
                $period,
            );
            $dispatched[] = 'aggregation';
        }

        if (in_array($scope, ['recommendations', 'all'], true)) {
            GenerateRecommendationsJob::dispatch((int) $integration->id);
            $dispatched[] = 'recommendations';
        }

        if (in_array($scope, ['reconciliation', 'all'], true)) {
            ReconcileFinanceJob::dispatch((int) $integration->id);
            $dispatched[] = 'reconciliation';
        }

        return response()->json([
            'message' => 'Queued',
            'data' => [
                'job_id' => $jobId,
                'queued_at' => now()->toIso8601String(),
                'dispatched' => $dispatched,
            ],
        ]);
    }
}
