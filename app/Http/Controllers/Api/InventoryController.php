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

    /**
     * GET /api/inventory/matrix?integration_id=...&search=...&page=...&per_page=...
     * Матрица: артикулы × склады (все склады, включая где товара нет)
     */
    public function matrix(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required|integer',
            'search' => 'nullable|string|max:200',
            'sort' => 'nullable|string|in:sku,name,total_stock,avg_daily_sales,turnover_days,days_of_stock',
            'sort_order' => 'nullable|string|in:asc,desc',
            'stock_status' => 'nullable|string|in:all,critical,low,optimal,excess,out_of_stock',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $integrationId = $request->input('integration_id');
        $perPage = $request->input('per_page', 50);

        // 1. Все уникальные склады для этой интеграции
        $allWarehouses = InventoryWarehouse::where('integration_id', $integrationId)
            ->select('warehouse_id', 'warehouse_name', 'marketplace', 'fulfillment_type', 'region')
            ->distinct()
            ->orderBy('warehouse_name')
            ->get()
            ->unique('warehouse_id')
            ->values();

        // 2. Все товары интеграции
        $productsQuery = Product::where('integration_id', $integrationId);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $productsQuery->where(function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        // Сортировка
        $sortField = $request->input('sort', 'sku');
        $sortOrder = $request->input('sort_order', 'asc');
        $productsQuery->orderBy($sortField === 'total_stock' ? 'sku' : ($sortField === 'avg_daily_sales' ? 'sku' : ($sortField === 'turnover_days' ? 'sku' : ($sortField === 'days_of_stock' ? 'sku' : $sortField))), $sortOrder);

        $products = $productsQuery->paginate($perPage);

        // 3. Загружаем все остатки для этих SKU
        $skus = $products->pluck('sku')->toArray();
        $inventoryRows = InventoryWarehouse::where('integration_id', $integrationId)
            ->whereIn('sku', $skus)
            ->get()
            ->groupBy('sku');

        // 4. UnitEconomics
        $unitEconomics = \App\Models\UnitEconomics::where('integration_id', $integrationId)
            ->whereIn('sku', $skus)
            ->get()
            ->keyBy('sku');

        // 5. Формируем матрицу
        $items = $products->getCollection()->map(function ($product) use ($inventoryRows, $allWarehouses, $unitEconomics) {
            $whRows = $inventoryRows->get($product->sku, collect());
            $whByWarehouseId = $whRows->keyBy('warehouse_id');

            $ue = $unitEconomics->get($product->sku);

            // Агрегированные данные
            $totalStock = $whRows->sum('quantity');
            $totalReserved = $whRows->sum('reserved');
            $totalInTransit = $whRows->max('in_transit') ?? 0;
            $sales7 = $whRows->max('sales_7_days') ?? 0;
            $sales14 = $whRows->max('sales_14_days') ?? 0;
            $sales30 = $whRows->max('sales_30_days') ?? 0;
            $avgDailySales = $whRows->max('average_daily_sales') ?? ($product->avg_daily_sales ?? 0);
            $effectiveDailySales = $whRows->max('effective_daily_sales') ?? 0;
            $daysInStock30 = $whRows->max('days_in_stock_30') ?? 30;

            $turnoverDays = $avgDailySales > 0 ? round($totalStock / $avgDailySales, 1) : null;
            $daysOfStock = $avgDailySales > 0 ? (int) round($totalStock / $avgDailySales) : ($totalStock > 0 ? 999 : 0);

            // Реальные данные из отчёта Ozon (агрегированные по SKU)
            $realAvgDaily = $whRows->whereNotNull('real_avg_daily_sales')->sum('real_avg_daily_sales');
            $hasRealData = $realAvgDaily > 0;
            $realTurnoverDays = $hasRealData ? round($totalStock / $realAvgDaily, 1) : null;
            $realDaysOfStock = $hasRealData ? (int) ceil($totalStock / $realAvgDaily) : null;
            $realSalesPeriodDays = $whRows->whereNotNull('real_sales_period_days')->max('real_sales_period_days');

            $costPrice = $ue?->cost_price ?? $product->cost_price ?? 0;
            $price = $product->price ?? $ue?->price ?? 0;
            $stockValue = $totalStock * $costPrice;

            // Тренд
            $salesTrend = 'stable';
            $salesTrendPct = 0;
            if ($sales30 > 0 && $sales14 > 0) {
                $avg14 = $sales14 / 14;
                $older16 = ($sales30 - $sales14) / 16;
                if ($older16 > 0) {
                    $salesTrendPct = round((($avg14 - $older16) / $older16) * 100, 1);
                    if ($salesTrendPct > 10) $salesTrend = 'growing';
                    elseif ($salesTrendPct < -10) $salesTrend = 'declining';
                }
            }

            // Статус
            $stockStatus = 'optimal';
            if ($totalStock <= 0) $stockStatus = 'out_of_stock';
            elseif ($daysOfStock <= 7) $stockStatus = 'critical';
            elseif ($daysOfStock <= 14) $stockStatus = 'low';
            elseif ($daysOfStock > 60) $stockStatus = 'excess';

            // Стоимость хранения
            $storageCostDaily = $whRows->sum('storage_cost_per_day') ?? 0;
            $storageCostMonthly = $whRows->sum('storage_cost_per_month') ?? 0;

            // Фактические начисления за хранение (отчёты Ozon/WB)
            // Используем max — данные записаны в одну запись на SKU, чтобы не дублировать при sum
            $storageFeeTotal = $whRows->max('storage_fee_total') ?? 0;
            $storageFeeLastWeek = $whRows->max('storage_fee_last_week') ?? 0;
            $storageFeeReportFrom = $whRows->min('storage_fee_report_from');
            $storageFeeReportTo = $whRows->max('storage_fee_report_to');

            // Хранение за прошлый месяц
            $storageFeePrevMonth = $whRows->max('storage_fee_prev_month') ?? 0;
            $storageFeePrevMonthPeriod = $whRows->pluck('storage_fee_prev_month_period')->filter()->first();

            // Матрица по складам
            $warehouseMatrix = $allWarehouses->map(function ($wh) use ($whByWarehouseId) {
                $row = $whByWarehouseId->get($wh->warehouse_id);
                return [
                    'warehouse_id' => $wh->warehouse_id,
                    'quantity' => $row?->quantity ?? 0,
                    'reserved' => $row?->reserved ?? 0,
                    'in_transit' => $row?->in_transit ?? 0,
                    'sales_7_days' => $row?->sales_7_days ?? 0,
                    'sales_30_days' => $row?->sales_30_days ?? 0,
                    'average_daily_sales' => $row?->average_daily_sales ?? 0,
                    'days_of_stock' => $row?->days_of_stock,
                    'turnover_days' => $row?->turnover_days,
                    'storage_cost_per_day' => $row?->storage_cost_per_day ?? 0,
                    'real_avg_daily_sales' => $row?->real_avg_daily_sales,
                    'real_items_sold' => ($row?->real_avg_daily_sales && $row?->real_sales_period_days) ? round($row->real_avg_daily_sales * $row->real_sales_period_days) : null,
                    'real_turnover_days' => $row?->real_turnover_days,
                    'real_days_of_stock' => $row?->real_days_of_stock,
                    'real_sales_period_days' => $row?->real_sales_period_days,
                    'stock_status' => $row ? ($row->stock_status ?? 'optimal') : 'empty',
                    'has_stock' => ($row?->quantity ?? 0) > 0,
                ];
            });

            return [
                'sku' => $product->sku,
                'name' => $product->name,
                'barcode' => $product->barcode,
                'image_url' => $product->images[0] ?? null,
                'price' => $price,
                'cost_price' => $costPrice,
                'marketplace' => $product->marketplace,
                'total_stock' => $totalStock,
                'reserved' => $totalReserved,
                'in_transit' => $totalInTransit,
                'stock_value' => round($stockValue, 2),
                'sales_7_days' => $sales7,
                'sales_14_days' => $sales14,
                'sales_30_days' => $sales30,
                'avg_daily_sales' => $avgDailySales,
                'effective_daily_sales' => $effectiveDailySales,
                'days_in_stock_30' => $daysInStock30,
                'turnover_days' => $turnoverDays,
                'days_of_stock' => $daysOfStock,
                'sales_trend' => $salesTrend,
                'sales_trend_pct' => $salesTrendPct,
                'stock_status' => $stockStatus,
                'storage_cost_daily' => round($storageCostDaily, 2),
                'storage_cost_monthly' => round($storageCostMonthly, 2),
                'real_avg_daily_sales' => $hasRealData ? round($realAvgDaily, 2) : null,
                'real_turnover_days' => $realTurnoverDays,
                'real_days_of_stock' => $realDaysOfStock,
                'real_sales_period_days' => $realSalesPeriodDays,
                'storage_fee_total' => round($storageFeeTotal, 2),
                'storage_fee_last_week' => round($storageFeeLastWeek, 2),
                'storage_fee_report_from' => $storageFeeReportFrom,
                'storage_fee_report_to' => $storageFeeReportTo,
                'storage_fee_prev_month' => round($storageFeePrevMonth, 2),
                'storage_fee_prev_month_period' => $storageFeePrevMonthPeriod,
                'warehouses_with_stock' => $whRows->where('quantity', '>', 0)->count(),
                'warehouses_total' => $allWarehouses->count(),
                'warehouse_matrix' => $warehouseMatrix,
            ];
        });

        // Фильтр по stock_status (после расчёта)
        if ($request->filled('stock_status') && $request->input('stock_status') !== 'all') {
            $statusFilter = $request->input('stock_status');
            $items = $items->filter(fn($item) => $item['stock_status'] === $statusFilter)->values();
        }

        // Сортировка по вычисляемым полям
        if (in_array($sortField, ['total_stock', 'avg_daily_sales', 'turnover_days', 'days_of_stock'])) {
            $items = $items->sortBy($sortField, SORT_REGULAR, $sortOrder === 'desc')->values();
        }

        $summaryStorageFeeFrom = $items->pluck('storage_fee_report_from')->filter()->min();
        $summaryStorageFeeTo = $items->pluck('storage_fee_report_to')->filter()->max();

        return response()->json([
            'message' => 'OK',
            'data' => [
                'items' => $items->values(),
                'warehouses' => $allWarehouses,
                'pagination' => [
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                ],
                'summary' => [
                    'total_products' => $products->total(),
                    'total_warehouses' => $allWarehouses->count(),
                    'total_stock' => $inventoryRows->flatten()->sum('quantity'),
                    'total_stock_value' => round($items->sum('stock_value'), 2),
                    'avg_turnover_days' => round($items->whereNotNull('turnover_days')->avg('turnover_days') ?? 0, 1),
                    'out_of_stock_count' => $items->where('stock_status', 'out_of_stock')->count(),
                    'critical_count' => $items->where('stock_status', 'critical')->count(),
                    'low_count' => $items->where('stock_status', 'low')->count(),
                    'storage_fee_total' => round($items->sum('storage_fee_total') ?? 0, 2),
                    'storage_fee_report_from' => $summaryStorageFeeFrom,
                    'storage_fee_report_to' => $summaryStorageFeeTo,
                    'storage_totals' => $this->getStorageTotals($request->input('integration_id')),
                ],
            ],
        ]);
    }

    /**
     * Получить общие суммы хранения — вычисляем напрямую из InventoryWarehouse.
     * Fallback на SyncLog.metadata если прямой расчёт не дал результата.
     */
    private function getStorageTotals(?string $integrationId): ?array
    {
        if (!$integrationId) return null;

        $rows = InventoryWarehouse::where('integration_id', $integrationId)->get();

        if ($rows->isEmpty()) return null;

        // Текущий месяц — суммируем storage_fee_total по уникальным SKU (берём max по SKU)
        $currentMonthTotal = $rows->groupBy('sku')->sum(function ($group) {
            return $group->max('storage_fee_total') ?? 0;
        });
        $currentFrom = $rows->pluck('storage_fee_report_from')->filter()->min();
        $currentTo = $rows->pluck('storage_fee_report_to')->filter()->max();

        // Прошлый месяц — суммируем storage_fee_prev_month по уникальным SKU
        $prevMonthTotal = $rows->groupBy('sku')->sum(function ($group) {
            return $group->max('storage_fee_prev_month') ?? 0;
        });
        $prevPeriod = $rows->pluck('storage_fee_prev_month_period')->filter()->first();

        // Если есть данные — возвращаем
        if ($currentMonthTotal > 0 || $prevMonthTotal > 0) {
            $result = [];

            $result['current_month'] = [
                'total' => round($currentMonthTotal, 2),
                'from' => $currentFrom,
                'to' => $currentTo,
            ];

            // Парсим период прошлого месяца (формат "2026-01-01 – 2026-01-31")
            $prevFrom = null;
            $prevTo = null;
            if ($prevPeriod && str_contains($prevPeriod, '–')) {
                $parts = array_map('trim', explode('–', $prevPeriod));
                $prevFrom = $parts[0] ?? null;
                $prevTo = $parts[1] ?? null;
            } elseif ($prevPeriod && str_contains($prevPeriod, ' - ')) {
                $parts = array_map('trim', explode(' - ', $prevPeriod));
                $prevFrom = $parts[0] ?? null;
                $prevTo = $parts[1] ?? null;
            }

            $result['prev_month'] = [
                'total' => round($prevMonthTotal, 2),
                'from' => $prevFrom,
                'to' => $prevTo,
            ];

            return $result;
        }

        // Fallback: SyncLog.metadata
        $syncLog = \App\Models\SyncLog::where('integration_id', $integrationId)
            ->where('sync_type', 'inventory')
            ->where('status', 'completed')
            ->whereNotNull('metadata')
            ->orderByDesc('created_at')
            ->first();

        if (!$syncLog) return null;

        $metadata = $syncLog->metadata;
        if (!is_array($metadata)) {
            $metadata = json_decode($metadata, true);
        }

        return $metadata['storage_totals'] ?? null;
    }
}
