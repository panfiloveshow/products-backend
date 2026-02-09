<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SellerWarehouseStock;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SellerWarehouseStockController extends Controller
{
    /**
     * Список остатков собственного склада
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'integration_id' => 'required|integer',
            'search' => 'nullable|string|max:200',
            'in_stock' => 'nullable|boolean',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        $query = SellerWarehouseStock::where('integration_id', $request->integration_id)
            ->orderByDesc('updated_at');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                  ->orWhere('product_name', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('in_stock')) {
            $query->where('quantity', '>', 0);
        }

        $perPage = $request->integer('per_page', 50);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'message' => 'OK',
            'data' => $paginated,
        ]);
    }

    /**
     * Создать / обновить остаток (upsert по integration_id + sku)
     */
    public function upsert(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'integration_id' => 'required|integer',
            'sku' => 'required|string|max:100',
            'quantity' => 'required|integer|min:0',
            'reserved' => 'nullable|integer|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'location' => 'nullable|string|max:200',
            'note' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        // Подтягиваем product_name и barcode из каталога
        $product = Product::where('integration_id', $request->integration_id)
            ->where('sku', $request->sku)
            ->first();

        $stock = SellerWarehouseStock::updateOrCreate(
            [
                'integration_id' => $request->integration_id,
                'sku' => $request->sku,
            ],
            [
                'quantity' => $request->quantity,
                'reserved' => $request->integer('reserved', 0),
                'cost_price' => $request->cost_price,
                'barcode' => $product?->barcode ?? $request->barcode,
                'product_name' => $product?->name ?? $request->product_name,
                'location' => $request->location,
                'note' => $request->note,
                'last_counted_at' => now(),
            ]
        );

        return response()->json([
            'message' => $stock->wasRecentlyCreated ? 'Создано' : 'Обновлено',
            'data' => $stock,
        ]);
    }

    /**
     * Массовый upsert (импорт)
     */
    public function bulkUpsert(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'integration_id' => 'required|integer',
            'items' => 'required|array|min:1|max:5000',
            'items.*.sku' => 'required|string|max:100',
            'items.*.quantity' => 'required|integer|min:0',
            'items.*.reserved' => 'nullable|integer|min:0',
            'items.*.cost_price' => 'nullable|numeric|min:0',
            'items.*.location' => 'nullable|string|max:200',
            'items.*.note' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        $integrationId = $request->integration_id;
        $items = $request->items;

        // Подтягиваем product_name и barcode из каталога
        $skus = array_column($items, 'sku');
        $products = Product::where('integration_id', $integrationId)
            ->whereIn('sku', $skus)
            ->get()
            ->keyBy('sku');

        $created = 0;
        $updated = 0;

        foreach ($items as $item) {
            $product = $products->get($item['sku']);

            $stock = SellerWarehouseStock::updateOrCreate(
                [
                    'integration_id' => $integrationId,
                    'sku' => $item['sku'],
                ],
                [
                    'quantity' => $item['quantity'],
                    'reserved' => $item['reserved'] ?? 0,
                    'cost_price' => $item['cost_price'] ?? null,
                    'barcode' => $product?->barcode,
                    'product_name' => $product?->name,
                    'location' => $item['location'] ?? null,
                    'note' => $item['note'] ?? null,
                    'last_counted_at' => now(),
                ]
            );

            if ($stock->wasRecentlyCreated) {
                $created++;
            } else {
                $updated++;
            }
        }

        return response()->json([
            'message' => "Импорт завершён: создано {$created}, обновлено {$updated}",
            'data' => [
                'created' => $created,
                'updated' => $updated,
                'total' => count($items),
            ],
        ]);
    }

    /**
     * Удалить остаток
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $stock = SellerWarehouseStock::find($id);

        if (!$stock) {
            return response()->json(['message' => 'Не найдено'], 404);
        }

        $stock->delete();

        return response()->json(['message' => 'Удалено']);
    }

    /**
     * Сводка по собственному складу
     */
    public function summary(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'integration_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        $integrationId = $request->integration_id;

        $total = SellerWarehouseStock::where('integration_id', $integrationId)->count();
        $inStock = SellerWarehouseStock::where('integration_id', $integrationId)->where('quantity', '>', 0)->count();
        $totalQty = SellerWarehouseStock::where('integration_id', $integrationId)->sum('quantity');
        $totalReserved = SellerWarehouseStock::where('integration_id', $integrationId)->sum('reserved');
        $totalValue = SellerWarehouseStock::where('integration_id', $integrationId)
            ->whereNotNull('cost_price')
            ->selectRaw('SUM(quantity * cost_price) as total')
            ->value('total') ?? 0;

        return response()->json([
            'message' => 'OK',
            'data' => [
                'total_skus' => $total,
                'in_stock_skus' => $inStock,
                'total_quantity' => (int) $totalQty,
                'total_reserved' => (int) $totalReserved,
                'total_available' => (int) ($totalQty - $totalReserved),
                'total_value' => round($totalValue, 2),
            ],
        ]);
    }
}
