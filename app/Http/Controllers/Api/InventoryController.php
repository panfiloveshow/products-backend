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

        // === Summary по ВСЕМ товарам интеграции (не только текущая страница) ===
        $globalSummary = $this->calculateGlobalSummary($integrationId);

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
                    'total_stock' => $globalSummary['total_stock'],
                    'total_stock_value' => $globalSummary['total_stock_value'],
                    'avg_turnover_days' => $globalSummary['avg_turnover_days'],
                    'out_of_stock_count' => $globalSummary['out_of_stock_count'],
                    'critical_count' => $globalSummary['critical_count'],
                    'low_count' => $globalSummary['low_count'],
                    'storage_fee_total' => $globalSummary['storage_fee_total'],
                    'storage_fee_report_from' => $globalSummary['storage_fee_report_from'],
                    'storage_fee_report_to' => $globalSummary['storage_fee_report_to'],
                    'storage_totals' => $this->getStorageTotals($request->input('integration_id')),
                ],
            ],
        ]);
    }

    /**
     * Рассчитать summary по ВСЕМ товарам интеграции (не только текущая страница).
     * Использует агрегатные SQL-запросы для производительности.
     */
    private function calculateGlobalSummary(int $integrationId): array
    {
        // Агрегаты по InventoryWarehouse: total_stock, avg_daily_sales по SKU
        $skuAggregates = \Illuminate\Support\Facades\DB::table('inventory_warehouses')
            ->where('integration_id', $integrationId)
            ->select(
                'sku',
                \Illuminate\Support\Facades\DB::raw('SUM(quantity) as total_qty'),
                \Illuminate\Support\Facades\DB::raw('MAX(average_daily_sales) as max_avg_daily'),
                \Illuminate\Support\Facades\DB::raw('MAX(storage_fee_total) as max_storage_fee'),
                \Illuminate\Support\Facades\DB::raw('MIN(storage_fee_report_from) as min_report_from'),
                \Illuminate\Support\Facades\DB::raw('MAX(storage_fee_report_to) as max_report_to')
            )
            ->groupBy('sku')
            ->get();

        $totalStock = 0;
        $totalStockValue = 0;
        $totalStorageFee = 0;
        $criticalCount = 0;
        $lowCount = 0;
        $turnoverSum = 0;
        $turnoverCount = 0;
        $globalReportFrom = null;
        $globalReportTo = null;

        // Загружаем cost_price для расчёта stock_value
        $costPrices = \Illuminate\Support\Facades\DB::table('unit_economics')
            ->where('integration_id', $integrationId)
            ->pluck('cost_price', 'sku');

        $productCostPrices = \Illuminate\Support\Facades\DB::table('products')
            ->where('integration_id', $integrationId)
            ->whereNotNull('cost_price')
            ->pluck('cost_price', 'sku');

        // Товары без записей в inventory_warehouses тоже считаются out_of_stock
        $totalProductsCount = \Illuminate\Support\Facades\DB::table('products')
            ->where('integration_id', $integrationId)
            ->count();
        $skusWithInventory = $skuAggregates->count();
        $productsWithoutInventory = max(0, $totalProductsCount - $skusWithInventory);
        $outOfStockCount = $productsWithoutInventory; // начинаем с товаров без остатков

        foreach ($skuAggregates as $row) {
            $qty = (int) $row->total_qty;
            $avgDaily = (float) ($row->max_avg_daily ?? 0);
            $storageFee = (float) ($row->max_storage_fee ?? 0);

            $totalStock += $qty;

            // Cost price: UnitEconomics → Product fallback
            $costPrice = (float) ($costPrices[$row->sku] ?? $productCostPrices[$row->sku] ?? 0);
            $totalStockValue += $qty * $costPrice;

            $totalStorageFee += $storageFee;

            // Days of stock
            $daysOfStock = $avgDaily > 0 ? (int) round($qty / $avgDaily) : ($qty > 0 ? 999 : 0);

            // Turnover
            if ($avgDaily > 0) {
                $turnoverSum += round($qty / $avgDaily, 1);
                $turnoverCount++;
            }

            // Stock status
            if ($qty <= 0) $outOfStockCount++;
            elseif ($daysOfStock <= 7) $criticalCount++;
            elseif ($daysOfStock <= 14) $lowCount++;

            // Report dates
            if ($row->min_report_from) {
                if ($globalReportFrom === null || $row->min_report_from < $globalReportFrom) {
                    $globalReportFrom = $row->min_report_from;
                }
            }
            if ($row->max_report_to) {
                if ($globalReportTo === null || $row->max_report_to > $globalReportTo) {
                    $globalReportTo = $row->max_report_to;
                }
            }
        }

        return [
            'total_stock' => $totalStock,
            'total_stock_value' => round($totalStockValue, 2),
            'avg_turnover_days' => $turnoverCount > 0 ? round($turnoverSum / $turnoverCount, 1) : 0,
            'out_of_stock_count' => $outOfStockCount,
            'critical_count' => $criticalCount,
            'low_count' => $lowCount,
            'storage_fee_total' => round($totalStorageFee, 2),
            'storage_fee_report_from' => $globalReportFrom,
            'storage_fee_report_to' => $globalReportTo,
        ];
    }

    /**
     * Получить общие суммы хранения за текущий и прошлый месяц.
     * 
     * Ozon: cash-flow-statement API (MarketplaceServiceStorageItem) — единственный
     *       корректный источник (совпадает с ЛК Ozon). Кэшируется на 2 часа.
     * WB:   суммируем storage_fee_total / storage_fee_prev_month из InventoryWarehouse.
     */
    private function getStorageTotals(?string $integrationId): ?array
    {
        if (!$integrationId) return null;

        $integration = \App\Models\Integration::find($integrationId);
        if (!$integration) return null;

        $marketplace = $integration->marketplace ?? '';

        // Хелпер: привести дату к формату YYYY-MM-DD
        $fmtDate = function ($d): ?string {
            if (!$d) return null;
            try {
                return \Carbon\Carbon::parse($d)->format('Y-m-d');
            } catch (\Throwable) {
                return is_string($d) ? substr($d, 0, 10) : null;
            }
        };

        // === Ozon: cash-flow API ===
        if ($marketplace === 'ozon') {
            return $this->getOzonStorageTotals($integration, $fmtDate);
        }

        // === WB и остальные: из InventoryWarehouse ===
        return $this->getWbStorageTotals($integrationId, $fmtDate);
    }

    /**
     * Ozon: получить суммы хранения из cash-flow-statement API.
     * Кэшируется на 2 часа чтобы не нагружать API на каждый запрос.
     */
    private function getOzonStorageTotals(\App\Models\Integration $integration, callable $fmtDate): ?array
    {
        $cacheKey = "ozon_storage_totals_{$integration->id}";
        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached ?: null;
        }

        // Сначала пробуем SyncLog.metadata (данные от последней синхронизации)
        $syncLog = \App\Models\SyncLog::where('integration_id', $integration->id)
            ->where('sync_type', 'inventory')
            ->where('status', 'completed')
            ->whereNotNull('metadata')
            ->orderByDesc('created_at')
            ->first();

        if ($syncLog) {
            $metadata = $syncLog->metadata;
            if (!is_array($metadata)) {
                $metadata = json_decode($metadata, true);
            }
            $storageTotals = $metadata['storage_totals'] ?? null;
            if ($storageTotals && (($storageTotals['current_month']['total'] ?? 0) > 0 || ($storageTotals['prev_month']['total'] ?? 0) > 0)) {
                // Форматируем даты
                $storageTotals['current_month']['from'] = $fmtDate($storageTotals['current_month']['from'] ?? null);
                $storageTotals['current_month']['to'] = $fmtDate($storageTotals['current_month']['to'] ?? null);
                $storageTotals['prev_month']['from'] = $fmtDate($storageTotals['prev_month']['from'] ?? null);
                $storageTotals['prev_month']['to'] = $fmtDate($storageTotals['prev_month']['to'] ?? null);
                \Illuminate\Support\Facades\Cache::put($cacheKey, $storageTotals, 7200);
                return $storageTotals;
            }
        }

        // Если SyncLog не помог — вызываем Ozon cash-flow API напрямую
        try {
            $credentials = $integration->getDecryptedCredentials();
            if (empty($credentials)) {
                \Illuminate\Support\Facades\Cache::put($cacheKey, false, 3600);
                return null;
            }

            $mp = \App\Domains\Marketplace\MarketplaceFactory::create('ozon', $credentials, $integration);

            if (!method_exists($mp, 'getStorageTotalFromCashFlow')) {
                \Illuminate\Support\Facades\Cache::put($cacheKey, false, 3600);
                return null;
            }

            $currentMonthFrom = now()->startOfMonth()->format('Y-m-d');
            $currentMonthTo = now()->format('Y-m-d');
            $prevMonthFrom = now()->subMonth()->startOfMonth()->format('Y-m-d');
            $prevMonthTo = now()->subMonth()->endOfMonth()->format('Y-m-d');

            $currentMonth = $mp->getStorageTotalFromCashFlow($currentMonthFrom, $currentMonthTo);
            $prevMonth = $mp->getStorageTotalFromCashFlow($prevMonthFrom, $prevMonthTo);

            $result = [
                'current_month' => [
                    'total' => round($currentMonth['total'] ?? 0, 2),
                    'from' => $currentMonthFrom,
                    'to' => $currentMonthTo,
                ],
                'prev_month' => [
                    'total' => round($prevMonth['total'] ?? 0, 2),
                    'from' => $prevMonthFrom,
                    'to' => $prevMonthTo,
                ],
            ];

            \Illuminate\Support\Facades\Cache::put($cacheKey, $result, 7200);
            return $result;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('getOzonStorageTotals: API call failed', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);
            \Illuminate\Support\Facades\Cache::put($cacheKey, false, 1800);
            return null;
        }
    }

    /**
     * WB: получить суммы хранения из InventoryWarehouse.
     */
    private function getWbStorageTotals(string $integrationId, callable $fmtDate): ?array
    {
        $rows = InventoryWarehouse::where('integration_id', $integrationId)->get();
        if ($rows->isEmpty()) return null;

        $currentMonthTotal = $rows->groupBy('sku')->sum(function ($group) {
            return $group->max('storage_fee_total') ?? 0;
        });
        $currentFrom = $rows->pluck('storage_fee_report_from')->filter()->min();
        $currentTo = $rows->pluck('storage_fee_report_to')->filter()->max();

        $prevMonthTotal = $rows->groupBy('sku')->sum(function ($group) {
            return $group->max('storage_fee_prev_month') ?? 0;
        });
        $prevPeriod = $rows->pluck('storage_fee_prev_month_period')->filter()->first();

        if ($currentMonthTotal <= 0 && $prevMonthTotal <= 0) return null;

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

        return [
            'current_month' => [
                'total' => round($currentMonthTotal, 2),
                'from' => $fmtDate($currentFrom),
                'to' => $fmtDate($currentTo),
            ],
            'prev_month' => [
                'total' => round($prevMonthTotal, 2),
                'from' => $fmtDate($prevFrom),
                'to' => $fmtDate($prevTo),
            ],
        ];
    }
}
