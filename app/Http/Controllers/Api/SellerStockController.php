<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SellerWarehouseStock;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SellerStockController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required|integer',
            'search'         => 'nullable|string|max:255',
            'in_stock'       => 'nullable|boolean',
            'page'           => 'nullable|integer|min:1',
            'per_page'       => 'nullable|integer|min:1|max:200',
        ]);

        $query = SellerWarehouseStock::where('integration_id', $request->integration_id);

        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('sku', 'ilike', $s)
                  ->orWhere('product_name', 'ilike', $s)
                  ->orWhere('barcode', 'ilike', $s);
            });
        }

        if ($request->boolean('in_stock')) {
            $query->where('quantity', '>', 0);
        }

        $perPage = $request->input('per_page', 50);
        $items   = $query->orderBy('quantity', 'desc')->paginate($perPage);

        return response()->json([
            'message' => 'OK',
            'data'    => [
                'data'         => $items->items(),
                'current_page' => $items->currentPage(),
                'last_page'    => $items->lastPage(),
                'total'        => $items->total(),
            ],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $request->validate(['integration_id' => 'required|integer']);

        $integrationId = (int) $request->integration_id;

        $rows = SellerWarehouseStock::where('integration_id', $integrationId)->get();

        $totalSkus    = $rows->count();
        $inStockSkus  = $rows->where('quantity', '>', 0)->count();
        $totalQty     = $rows->sum('quantity');
        $totalReserved = $rows->sum('reserved');
        $totalAvailable = $rows->sum(fn($r) => max(0, $r->quantity - $r->reserved));
        $totalValue   = $rows->sum(fn($r) => $r->quantity * ($r->cost_price ?? 0));

        return response()->json([
            'message' => 'OK',
            'data'    => [
                'total_skus'       => $totalSkus,
                'in_stock_skus'    => $inStockSkus,
                'total_quantity'   => $totalQty,
                'total_reserved'   => $totalReserved,
                'total_available'  => $totalAvailable,
                'total_value'      => round($totalValue, 2),
            ],
        ]);
    }

    public function catalog(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required|integer',
            'search'         => 'nullable|string|max:255',
            'filter'         => 'nullable|in:all,in_stock,no_stock',
            'page'           => 'nullable|integer|min:1',
            'per_page'       => 'nullable|integer|min:1|max:200',
        ]);

        $integrationId = (int) $request->integration_id;
        $perPage = (int) $request->input('per_page', 50);
        $filter  = $request->input('filter', 'all');

        $query = Product::where('products.integration_id', $integrationId)
            ->leftJoin('seller_warehouse_stocks as sws', function ($join) use ($integrationId) {
                $join->on('sws.sku', '=', 'products.sku')
                     ->where('sws.integration_id', '=', $integrationId);
            })
            ->select([
                'products.sku',
                'products.name',
                'products.barcode',
                'products.price',
                'products.category',
                'products.brand',
                'sws.id as own_stock_id',
                DB::raw('COALESCE(sws.quantity, 0) as own_quantity'),
                DB::raw('COALESCE(sws.reserved, 0) as own_reserved'),
                DB::raw('GREATEST(0, COALESCE(sws.quantity, 0) - COALESCE(sws.reserved, 0)) as own_available'),
                'sws.cost_price as own_cost_price',
                'sws.location as own_location',
                'sws.last_counted_at as own_last_counted_at',
                DB::raw('CASE WHEN sws.id IS NOT NULL AND sws.quantity > 0 THEN true ELSE false END as has_own_stock'),
            ]);

        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('products.sku', 'ilike', $s)
                  ->orWhere('products.name', 'ilike', $s)
                  ->orWhere('products.barcode', 'ilike', $s);
            });
        }

        if ($filter === 'in_stock') {
            $query->where('sws.quantity', '>', 0);
        } elseif ($filter === 'no_stock') {
            $query->where(function ($q) {
                $q->whereNull('sws.id')->orWhere('sws.quantity', '=', 0);
            });
        }

        $query->orderByRaw('COALESCE(sws.quantity, 0) DESC')->orderBy('products.sku');

        $paginated = $query->paginate($perPage);

        $totalWithStock = Product::where('products.integration_id', $integrationId)
            ->join('seller_warehouse_stocks as sws2', function ($join) use ($integrationId) {
                $join->on('sws2.sku', '=', 'products.sku')
                     ->where('sws2.integration_id', '=', $integrationId)
                     ->where('sws2.quantity', '>', 0);
            })
            ->count();

        return response()->json([
            'message' => 'OK',
            'data'    => [
                'items'           => $paginated->items(),
                'current_page'    => $paginated->currentPage(),
                'last_page'       => $paginated->lastPage(),
                'total'           => $paginated->total(),
                'per_page'        => $paginated->perPage(),
                'total_with_stock' => $totalWithStock,
            ],
        ]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required|integer',
            'sku'            => 'required|string|max:255',
            'quantity'       => 'required|integer|min:0',
            'reserved'       => 'nullable|integer|min:0',
            'cost_price'     => 'nullable|numeric|min:0',
            'location'       => 'nullable|string|max:255',
            'note'           => 'nullable|string|max:1000',
        ]);

        $stock = SellerWarehouseStock::updateOrCreate(
            [
                'integration_id' => $request->integration_id,
                'sku'            => $request->sku,
            ],
            [
                'quantity'       => $request->quantity,
                'reserved'       => $request->input('reserved', 0),
                'cost_price'     => $request->cost_price,
                'location'       => $request->location,
                'note'           => $request->note,
                'last_counted_at' => now(),
            ]
        );

        return response()->json(['message' => 'OK', 'data' => $stock], 200);
    }

    public function bulkUpsert(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required|integer',
            'items'          => 'required|array|min:1',
            'items.*.sku'    => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:0',
            'items.*.reserved'   => 'nullable|integer|min:0',
            'items.*.cost_price' => 'nullable|numeric|min:0',
            'items.*.location'   => 'nullable|string|max:255',
        ]);

        $integrationId = (int) $request->integration_id;
        $created = 0;
        $updated = 0;

        foreach ($request->items as $item) {
            $existing = SellerWarehouseStock::where('integration_id', $integrationId)
                ->where('sku', $item['sku'])
                ->first();

            if ($existing) {
                $existing->update([
                    'quantity'       => $item['quantity'],
                    'reserved'       => $item['reserved'] ?? $existing->reserved,
                    'cost_price'     => $item['cost_price'] ?? $existing->cost_price,
                    'location'       => $item['location'] ?? $existing->location,
                    'last_counted_at' => now(),
                ]);
                $updated++;
            } else {
                SellerWarehouseStock::create([
                    'integration_id' => $integrationId,
                    'sku'            => $item['sku'],
                    'quantity'       => $item['quantity'],
                    'reserved'       => $item['reserved'] ?? 0,
                    'cost_price'     => $item['cost_price'] ?? null,
                    'location'       => $item['location'] ?? null,
                    'last_counted_at' => now(),
                ]);
                $created++;
            }
        }

        return response()->json([
            'message' => 'OK',
            'data'    => ['created' => $created, 'updated' => $updated, 'total' => $created + $updated],
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $stock = SellerWarehouseStock::findOrFail($id);
        $stock->delete();

        return response()->json(['message' => 'Удалено']);
    }
}
