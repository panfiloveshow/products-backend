<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WbBarcodeCost;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class WbBarcodeCostController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['integration_id' => 'required|integer']);

        $items = WbBarcodeCost::where('integration_id', $request->integration_id)
            ->orderBy('nm_id')
            ->orderBy('barcode')
            ->get();

        return response()->json(['message' => 'OK', 'data' => $items]);
    }

    public function bulkUpsert(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id'       => 'required|integer',
            'items'                => 'required|array|min:1',
            'items.*.barcode'      => 'required|string|max:255',
            'items.*.nm_id'        => 'required|string|max:255',
            'items.*.cost_price'   => 'nullable|numeric|min:0',
            'items.*.chrt_id'      => 'nullable|string|max:255',
            'items.*.size_name'    => 'nullable|string|max:255',
        ]);

        $integrationId = (int) $request->integration_id;
        $created = 0;
        $updated = 0;

        foreach ($request->items as $item) {
            $existing = WbBarcodeCost::where('integration_id', $integrationId)
                ->where('barcode', $item['barcode'])
                ->first();

            if ($existing) {
                $existing->update([
                    'nm_id'      => $item['nm_id'],
                    'cost_price' => $item['cost_price'] ?? $existing->cost_price,
                    'chrt_id'    => $item['chrt_id'] ?? $existing->chrt_id,
                    'size_name'  => $item['size_name'] ?? $existing->size_name,
                ]);
                $updated++;
            } else {
                WbBarcodeCost::create([
                    'integration_id' => $integrationId,
                    'nm_id'          => $item['nm_id'],
                    'barcode'        => $item['barcode'],
                    'cost_price'     => $item['cost_price'] ?? null,
                    'chrt_id'        => $item['chrt_id'] ?? null,
                    'size_name'      => $item['size_name'] ?? null,
                ]);
                $created++;
            }
        }

        return response()->json([
            'message' => 'OK',
            'data'    => ['created' => $created, 'updated' => $updated, 'total' => $created + $updated],
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required|integer',
            'barcode'        => 'required|string',
        ]);

        $deleted = WbBarcodeCost::where('integration_id', $request->integration_id)
            ->where('barcode', $request->barcode)
            ->delete();

        return response()->json(['message' => 'Удалено', 'data' => ['deleted' => $deleted]]);
    }
}
