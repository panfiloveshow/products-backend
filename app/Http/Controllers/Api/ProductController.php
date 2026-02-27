<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\IndexProductRequest;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(
        private ProductService $productService
    ) {}

    public function index(IndexProductRequest $request): JsonResponse
    {
        $validated = $request->validated();
        
        $query = Product::query();

        if (!empty($validated['search'])) {
            $query->search($validated['search']);
        }

        if (!empty($validated['marketplace'])) {
            $query->marketplace($validated['marketplace']);
        }

        if (!empty($validated['integration_id'])) {
            $integrationId = (int) $validated['integration_id'];
            \Log::info('ProductController: Фильтр по integration_id', [
                'raw_value' => $validated['integration_id'],
                'casted_value' => $integrationId,
            ]);
            $query->where('integration_id', $integrationId);
        }

        if (!empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }

        if (!empty($validated['brand'])) {
            $query->where('brand', $validated['brand']);
        }

        if (isset($validated['price_from'])) {
            $query->where('price', '>=', $validated['price_from']);
        }

        if (isset($validated['price_to'])) {
            $query->where('price', '<=', $validated['price_to']);
        }

        if (isset($validated['in_stock']) && $validated['in_stock']) {
            $query->inStock();
        }

        $sortField = $validated['sort'] ?? 'created_at';
        $sortOrder = $validated['sort_order'] ?? 'desc';
        $query->orderBy($sortField, $sortOrder);

        $limit = min($validated['limit'] ?? 50, 200);
        $page = $validated['page'] ?? 1;

        $products = $query->paginate($limit, ['*'], 'page', $page);

        // Подгружаем остатки из inventory_warehouses
        $productSkus = $products->pluck('sku')->toArray();
        $inventoryQuery = \DB::table('inventory_warehouses')
            ->whereIn('sku', $productSkus);
        
        // Фильтруем по integration_id если он указан
        if (!empty($validated['integration_id'])) {
            $inventoryQuery->where('integration_id', (int) $validated['integration_id']);
        }
        
        $inventoryStocks = $inventoryQuery
            ->select('sku', \DB::raw('SUM(quantity) as total_stock'))
            ->groupBy('sku')
            ->pluck('total_stock', 'sku');

        // Обновляем stock в товарах
        $productsWithStock = $products->getCollection()->map(function ($product) use ($inventoryStocks) {
            $productArray = $product->toArray();
            $productArray['stock'] = (int) ($inventoryStocks[$product->sku] ?? 0);
            return $productArray;
        });

        $stats = $this->productService->getProductsStats($validated);

        return response()->json([
            'data' => [
                'products' => $productsWithStock,
                'total' => $products->total(),
                'page' => $products->currentPage(),
                'limit' => $products->perPage(),
                'has_more' => $products->hasMorePages(),
            ],
            'stats' => $stats,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $product = Product::with(['inventoryWarehouses', 'unitEconomics', 'alerts'])
            ->findOrFail($id);

        return response()->json([
            'data' => $product,
        ]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = Product::create($request->validated());

        return response()->json([
            'data' => $product,
            'message' => 'Product created successfully',
        ], 201);
    }

    public function update(UpdateProductRequest $request, string $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $product->update($request->validated());

        return response()->json([
            'data' => $product->fresh(),
            'message' => 'Product updated successfully',
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $product->delete();

        return response()->json([
            'message' => 'Product deleted successfully',
        ]);
    }

    /**
     * Запуск синхронизации товаров с маркетплейса
     * POST /api/products/sync/{marketplace}
     * 
     * Body:
     * - api_key: string (обязательно для WB)
     * - client_id: string (обязательно для Ozon)
     * - token: string (обязательно для Yandex)
     * - campaign_id: string (обязательно для Yandex)
     * - integration_id: int (опционально, ID интеграции из Sellico)
     */
    public function sync(Request $request, string $marketplace): JsonResponse
    {
        $integrationId = $request->input('integration_id');
        
        // Если передан integration_id, берём credentials из интеграции
        if ($integrationId) {
            $integration = \App\Models\Integration::find($integrationId);
            if (!$integration) {
                return response()->json(['error' => 'Integration not found'], 404);
            }
            $credentials = $integration->credentials ?? [];
        } else {
            // Валидация credentials в зависимости от маркетплейса
            $rules = match ($marketplace) {
                'wildberries' => ['api_key' => 'required|string'],
                'ozon' => ['client_id' => 'required|string', 'api_key' => 'required|string'],
                'yandex' => ['token' => 'required|string', 'campaign_id' => 'required|string'],
                default => [],
            };

            $request->validate($rules);

            // Собираем credentials из запроса
            $credentials = match ($marketplace) {
                'wildberries' => ['api_key' => $request->input('api_key')],
                'ozon' => [
                    'client_id' => $request->input('client_id'),
                    'api_key' => $request->input('api_key'),
                ],
                'yandex' => [
                    'token' => $request->input('token'),
                    'campaign_id' => $request->input('campaign_id'),
                ],
                default => [],
            };
        }

        $syncLog = $this->productService->startSync(
            $marketplace, 
            $credentials,
            $integrationId
        );

        return response()->json([
            'data' => [
                'sync_id' => $syncLog->id,
                'status' => $syncLog->status,
                'message' => "Sync started for {$marketplace}",
            ],
        ]);
    }

    public function syncStatus(): JsonResponse
    {
        $statuses = $this->productService->getSyncStatuses();

        return response()->json([
            'data' => $statuses,
        ]);
    }
}
