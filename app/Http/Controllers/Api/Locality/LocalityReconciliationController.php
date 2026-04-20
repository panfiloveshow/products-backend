<?php

namespace App\Http\Controllers\Api\Locality;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\LocalityReconciliationLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocalityReconciliationController extends Controller
{
    /** GET /api/v1/locality/reconciliation */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $integration = Integration::findOrFail($validated['integration_id']);

        $query = LocalityReconciliationLog::query()
            ->where('integration_id', $integration->id)
            ->when(! empty($validated['from']), fn ($q) => $q->where('period_from', '>=', Carbon::parse($validated['from'])->toDateString()))
            ->when(! empty($validated['to']), fn ($q) => $q->where('period_to', '<=', Carbon::parse($validated['to'])->toDateString()))
            ->orderByDesc('run_at');

        $rows = $query->limit((int) ($validated['limit'] ?? 20))->get();

        $data = $rows->map(fn (LocalityReconciliationLog $r) => [
            'id' => $r->id,
            'period_from' => $r->period_from?->toDateString(),
            'period_to' => $r->period_to?->toDateString(),
            'run_at' => $r->run_at?->toIso8601String(),
            'source' => $r->source,
            'verdict' => $r->verdict,
            'expected' => [
                'base_logistics' => (float) $r->expected_base_logistics,
                'non_local_markup' => (float) $r->expected_non_local_markup,
            ],
            'actual' => [
                'base_logistics' => (float) $r->actual_base_logistics,
                'non_local_markup' => (float) $r->actual_non_local_markup,
            ],
            'diff' => [
                'base_logistics' => (float) $r->base_logistics_diff,
                'markup' => (float) $r->markup_diff,
                'base_logistics_percent' => $r->base_logistics_diff_percent !== null ? (float) $r->base_logistics_diff_percent : null,
                'markup_percent' => $r->markup_diff_percent !== null ? (float) $r->markup_diff_percent : null,
            ],
            'operations_count' => (int) $r->operations_count,
            'postings_matched' => (int) $r->postings_matched,
            'postings_missing' => (int) $r->postings_missing,
            'details' => $r->details,
        ])->all();

        return response()->json(['message' => 'Success', 'data' => $data]);
    }
}
