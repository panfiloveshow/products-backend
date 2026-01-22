<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\IndexInventoryRequest;
use App\Models\InventoryAlert;
use App\Models\InventoryHistory;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(
        private InventoryService $inventoryService
    ) {}

    public function index(IndexInventoryRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $integrationId = $validated['integration_id'] ?? null;

        // Показываем ВСЕ товары, не только с остатками
        // Фильтруем inventoryWarehouses по integration_id для корректного отображения
        $query = Product::with(['inventoryWarehouses' => function ($q) use ($integrationId) {
            if ($integrationId) {
                $q->where('integration_id', $integrationId);
            }
        }]);

        if (!empty($validated['search'])) {
            $query->search($validated['search']);
        }

        if (!empty($validated['marketplace']) && $validated['marketplace'] !== 'all') {
            $query->where('marketplace', $validated['marketplace']);
        }

        if ($integrationId) {
            $query->where('integration_id', $integrationId);
        }

        if (!empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }

        if (isset($validated['low_stock']) && $validated['low_stock']) {
            $query->whereHas('inventoryWarehouses', function ($q) {
                $q->lowStock();
            });
        }

        if (isset($validated['out_of_stock']) && $validated['out_of_stock']) {
            $query->whereHas('inventoryWarehouses', function ($q) {
                $q->outOfStock();
            });
        }

        $sortField = $validated['sort'] ?? 'sku';
        $sortOrder = $validated['sort_order'] ?? 'asc';
        $query->orderBy($sortField, $sortOrder);

        $limit = $validated['limit'] ?? 50;
        $page = $validated['page'] ?? 1;

        $products = $query->paginate($limit, ['*'], 'page', $page);

        $items = $products->getCollection()->map(function ($product) {
            return $this->inventoryService->formatProductInventory($product);
        });

        $stats = $this->inventoryService->getInventoryStats($validated);

        return response()->json([
            'data' => [
                'items' => $items,
                'total' => $products->total(),
            ],
            'stats' => $stats,
        ]);
    }

    public function show(string $sku): JsonResponse
    {
        $product = Product::with(['inventoryWarehouses', 'alerts'])
            ->where('sku', $sku)
            ->firstOrFail();

        $inventoryData = $this->inventoryService->formatProductInventory($product);

        return response()->json([
            'data' => $inventoryData,
        ]);
    }

    public function history(string $sku): JsonResponse
    {
        $history = InventoryHistory::where('sku', $sku)
            ->orderBy('date', 'desc')
            ->limit(30)
            ->get();

        return response()->json([
            'data' => $history,
        ]);
    }

    public function forecast(string $sku): JsonResponse
    {
        $forecast = $this->inventoryService->getForecast($sku);

        return response()->json([
            'data' => $forecast,
        ]);
    }

    /**
     * Получение стоимости хранения по SKU
     * GET /api/inventory/{sku}/storage-cost
     */
    public function storageCost(string $sku): JsonResponse
    {
        $storageCost = $this->inventoryService->getStorageCost($sku);

        return response()->json([
            'data' => $storageCost,
        ]);
    }

    /**
     * Получение финансовой аналитики по SKU
     * GET /api/inventory/{sku}/analytics
     * 
     * Возвращает:
     * - stock_value: стоимость остатков
     * - frozen_capital: замороженный капитал
     * - turnover_rate: оборачиваемость (раз/год)
     * - days_of_stock: дней запаса
     */
    public function analytics(string $sku): JsonResponse
    {
        $analytics = $this->inventoryService->getFinancialAnalytics($sku);

        return response()->json([
            'data' => $analytics,
        ]);
    }

    /**
     * Синхронизация стоимости хранения с маркетплейсов
     * POST /api/inventory/sync-storage-cost
     */
    public function syncStorageCost(Request $request): JsonResponse
    {
        $marketplace = $request->input('marketplace');
        
        \App\Jobs\SyncStorageCostJob::dispatch($marketplace);

        return response()->json([
            'data' => [
                'message' => 'Storage cost sync started',
                'marketplace' => $marketplace ?? 'all',
            ],
        ]);
    }

    /**
     * Запуск синхронизации остатков с маркетплейса
     * POST /api/inventory/sync/{marketplace}
     * 
     * Body:
     * - api_key: string (обязательно для WB)
     * - client_id: string (обязательно для Ozon)
     * - token: string (обязательно для Yandex)
     * - campaign_id: string (обязательно для Yandex)
     * - integration_id: int (опционально)
     */
    public function sync(Request $request, string $marketplace): JsonResponse
    {
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

        $integrationId = $request->input('integration_id');

        try {
            $syncLog = $this->inventoryService->startSync(
                $marketplace,
                $credentials,
                $integrationId
            );

            return response()->json([
                'data' => [
                    'sync_id' => $syncLog->id,
                    'status' => $syncLog->status,
                    'message' => "Inventory sync started for {$marketplace}",
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Inventory sync failed', [
                'marketplace' => $marketplace,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка запуска синхронизации: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function syncStatus(): JsonResponse
    {
        $statuses = $this->inventoryService->getSyncStatuses();

        return response()->json([
            'data' => $statuses,
        ]);
    }

    public function alerts(): JsonResponse
    {
        $alerts = InventoryAlert::active()
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $alerts,
        ]);
    }

    public function recommendations(): JsonResponse
    {
        $recommendations = $this->inventoryService->getAIRecommendations();

        return response()->json([
            'data' => $recommendations,
        ]);
    }

    public function redistribution(): JsonResponse
    {
        $suggestions = $this->inventoryService->getRedistributionSuggestions();

        return response()->json([
            'data' => $suggestions,
        ]);
    }

    public function stats(): JsonResponse
    {
        $stats = $this->inventoryService->getOverallStats();

        return response()->json([
            'data' => $stats,
        ]);
    }
}
