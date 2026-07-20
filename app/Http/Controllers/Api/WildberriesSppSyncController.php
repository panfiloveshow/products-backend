<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncWildberriesSppJob;
use App\Models\Integration;
use App\Models\Product;
use App\Models\WildberriesSppSnapshot;
use App\Models\WildberriesSppSyncState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class WildberriesSppSyncController extends Controller
{
    private const START_COOLDOWN_SECONDS = 300;

    public function start(Request $request, int $integrationId): JsonResponse
    {
        $integration = $this->authorizedIntegration($request);
        if (! $integration || $integration->marketplace !== 'wildberries') {
            return response()->json([
                'success' => false,
                'message' => 'Синхронизация СПП доступна только для Wildberries',
            ], 422);
        }

        $state = WildberriesSppSyncState::query()->firstOrCreate(
            ['integration_id' => $integrationId],
            ['status' => 'idle'],
        );

        $nextAllowedAt = $this->nextAllowedAt($state);
        if ($nextAllowedAt !== null && $nextAllowedAt->isFuture()) {
            return response()->json([
                'data' => $this->responseData(
                    $state,
                    'Последнее обновление уже завершено. Повторный запуск будет доступен через несколько минут.',
                ),
            ]);
        }

        if (! in_array($state->status, ['queued', 'running', 'retrying'], true)) {
            $state->update([
                'status' => 'queued',
                'attempt' => 0,
                'updated_count' => 0,
                'preserved_count' => 0,
                'source' => null,
                'source_counts' => null,
                'message' => 'Синхронизация СПП поставлена в очередь',
                'last_error' => null,
                'requested_at' => now(),
                'started_at' => null,
                'finished_at' => null,
                'retry_at' => null,
            ]);

            SyncWildberriesSppJob::dispatch($integrationId)->onQueue('unit-economics');
            $state->refresh();
        }

        return response()->json(['data' => $this->responseData($state)], 202);
    }

    public function status(Request $request, int $integrationId): JsonResponse
    {
        $integration = $this->authorizedIntegration($request);
        if (! $integration || $integration->marketplace !== 'wildberries') {
            return response()->json([
                'success' => false,
                'message' => 'Синхронизация СПП доступна только для Wildberries',
            ], 422);
        }

        $state = WildberriesSppSyncState::query()
            ->where('integration_id', $integrationId)
            ->first();

        return response()->json([
            'data' => $state
                ? $this->responseData($state)
                : $this->idleResponse($integrationId),
        ]);
    }

    private function authorizedIntegration(Request $request): ?Integration
    {
        $integration = $request->attributes->get('authorized_integration');

        return $integration instanceof Integration ? $integration : null;
    }

    private function responseData(WildberriesSppSyncState $state, ?string $message = null): array
    {
        $summary = $this->snapshotSummary((int) $state->integration_id);
        $catalogTotal = Product::query()
            ->where('integration_id', $state->integration_id)
            ->where('marketplace', 'wildberries')
            ->count();
        $total = max((int) $state->total_count, $catalogTotal);
        $freshCoverage = $total > 0
            ? round($state->updated_count / $total * 100, 2)
            : 0.0;
        $knownCoverage = $total > 0
            ? round($summary['known'] / $total * 100, 2)
            : 0.0;

        return [
            'status' => $state->status,
            'updated' => $state->updated_count,
            'total' => $total,
            'preserved' => $state->preserved_count,
            'source' => $state->source,
            'source_counts' => $state->source_counts ?? [],
            'coverage' => $knownCoverage,
            'fresh_coverage' => $freshCoverage,
            'known' => $summary['known'],
            'known_coverage' => $knownCoverage,
            'known_source_counts' => $summary['source_counts'],
            'message' => $message ?? $state->message,
            'last_success_at' => $state->last_success_at?->toIso8601String(),
            'retry_at' => $state->retry_at?->toIso8601String(),
            'next_allowed_at' => $this->nextAllowedAt($state)?->toIso8601String(),
            'error' => $state->last_error,
        ];
    }

    private function idleResponse(int $integrationId): array
    {
        $summary = $this->snapshotSummary($integrationId);
        $total = Product::query()
            ->where('integration_id', $integrationId)
            ->where('marketplace', 'wildberries')
            ->count();
        $knownCoverage = $total > 0
            ? round($summary['known'] / $total * 100, 2)
            : 0.0;

        return [
            'status' => 'idle',
            'updated' => 0,
            'total' => $total,
            'preserved' => 0,
            'source' => null,
            'source_counts' => [],
            'coverage' => $knownCoverage,
            'fresh_coverage' => 0.0,
            'known' => $summary['known'],
            'known_coverage' => $knownCoverage,
            'known_source_counts' => $summary['source_counts'],
            'message' => 'СПП ещё не синхронизировался',
            'last_success_at' => null,
            'retry_at' => null,
            'next_allowed_at' => null,
            'error' => null,
        ];
    }

    /** @return array{known:int,source_counts:array<string,int>} */
    private function snapshotSummary(int $integrationId): array
    {
        $sourceCounts = WildberriesSppSnapshot::query()
            ->where('integration_id', $integrationId)
            ->selectRaw('source, COUNT(*) as count')
            ->groupBy('source')
            ->pluck('count', 'source')
            ->map(fn ($count) => (int) $count)
            ->all();

        return [
            'known' => array_sum($sourceCounts),
            'source_counts' => $sourceCounts,
        ];
    }

    private function nextAllowedAt(WildberriesSppSyncState $state): ?Carbon
    {
        if (in_array($state->status, ['queued', 'running', 'retrying'], true) || $state->last_success_at === null) {
            return null;
        }

        return $state->last_success_at->copy()->addSeconds(self::START_COOLDOWN_SECONDS);
    }
}
