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

        $query = Product::with(['inventoryWarehouses'])
            ->whereHas('inventoryWarehouses');

        if (!empty($validated['search'])) {
            $query->search($validated['search']);
        }

        if (!empty($validated['marketplace']) && $validated['marketplace'] !== 'all') {
            $query->whereHas('inventoryWarehouses', function ($q) use ($validated) {
                $q->where('marketplace', $validated['marketplace']);
            });
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

    public function matrix(Request $request): JsonResponse
    {
        $integrationId = $request->input('integration_id');
        $search        = $request->input('search');
        $sort          = $request->input('sort', 'total_stock');
        $sortOrder     = $request->input('sort_order', 'desc');
        $stockStatus   = $request->input('stock_status');
        $page          = (int) $request->input('page', 1);
        $perPage       = (int) $request->input('per_page', 30);

        $query = \App\Models\InventoryWarehouse::query()
            ->when($integrationId, fn($q) => $q->where('integration_id', $integrationId));

        // Получаем уникальные склады для заголовков
        $warehouses = (clone $query)
            ->select('warehouse_id', 'warehouse_name', 'marketplace', 'fulfillment_type', 'region')
            ->distinct()
            ->get()
            ->map(fn($w) => [
                'warehouse_id'      => $w->warehouse_id,
                'warehouse_name'    => $w->warehouse_name,
                'marketplace'       => $w->marketplace,
                'fulfillment_type'  => $w->fulfillment_type,
                'region'            => $w->region,
            ]);

        // Агрегируем по SKU
        // average_daily_sales суммируется (продажи по каждому складу независимы)
        // days_of_stock/turnover_days вычисляется как total_stock / sum_daily_sales
        $skuQuery = \App\Models\InventoryWarehouse::query()
            ->select('sku')
            ->selectRaw('SUM(quantity) as total_stock')
            ->selectRaw('SUM(reserved) as reserved')
            ->selectRaw('SUM(in_transit) as in_transit')
            ->selectRaw('SUM(sales_7_days) as sales_7_days')
            ->selectRaw('SUM(sales_14_days) as sales_14_days')
            ->selectRaw('SUM(sales_30_days) as sales_30_days')
            ->selectRaw('SUM(average_daily_sales) as avg_daily_sales')
            ->selectRaw('SUM(effective_daily_sales) as effective_daily_sales')
            ->selectRaw('AVG(days_in_stock_30) as days_in_stock_30')
            ->selectRaw('CASE WHEN SUM(average_daily_sales) > 0 THEN ROUND(SUM(quantity)::numeric / SUM(average_daily_sales), 1) ELSE NULL END as turnover_days')
            ->selectRaw('CASE WHEN SUM(average_daily_sales) > 0 THEN ROUND(SUM(quantity)::numeric / SUM(average_daily_sales)) ELSE NULL END as days_of_stock')
            ->selectRaw('MAX(stock_status) as stock_status')
            ->selectRaw('SUM(storage_cost_per_day) as storage_cost_daily')
            ->selectRaw('SUM(storage_cost_per_month) as storage_cost_monthly')
            ->selectRaw('SUM(real_avg_daily_sales) as real_avg_daily_sales')
            ->selectRaw('AVG(real_turnover_days) as real_turnover_days')
            ->selectRaw('AVG(real_days_of_stock) as real_days_of_stock')
            ->selectRaw('AVG(real_sales_period_days) as real_sales_period_days')
            ->when($integrationId, fn($q) => $q->where('integration_id', $integrationId))
            ->groupBy('sku');

        // Фильтр по статусу остатка
        if ($stockStatus) {
            $skuQuery->having(\DB::raw('MAX(stock_status)'), $stockStatus);
        }

        // Получаем полный список для поиска и сортировки с джойном на products
        $allSkus = $skuQuery->get()->keyBy('sku');

        // Получаем данные о товарах
        $productSkus = $allSkus->keys()->toArray();

        if (!empty($search)) {
            $matchingProducts = \App\Models\Product::whereIn('sku', $productSkus)
                ->where(fn($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('sku', 'ilike', "%{$search}%"))
                ->pluck('sku')
                ->toArray();
            $productSkus = array_intersect($productSkus, $matchingProducts);
            $allSkus = $allSkus->only($productSkus);
        }

        // Сортировка
        $sortCallback = function ($row) use ($sort) {
            return match ($sort) {
                'total_stock'    => (int)   $row->total_stock,
                'sales_30_days'  => (int)   $row->sales_30_days,
                'avg_daily_sales'=> (float) $row->avg_daily_sales,
                'days_of_stock'  => (float) ($row->days_of_stock ?? 9999),
                'turnover_days'  => (float) ($row->turnover_days ?? 9999),
                'stock_value'    => (float) $row->total_stock,
                default          => (int)   $row->total_stock,
            };
        };

        $sortedSkus = $sortOrder === 'asc'
            ? $allSkus->sortBy($sortCallback)
            : $allSkus->sortByDesc($sortCallback);

        $total      = $sortedSkus->count();
        $pagedSkus  = $sortedSkus->slice(($page - 1) * $perPage, $perPage)->keys()->toArray();

        // Загружаем товары
        $products = \App\Models\Product::whereIn('sku', $pagedSkus)
            ->get()
            ->keyBy('sku');

        // Загружаем строки складов для страницы
        $warehouseRows = \App\Models\InventoryWarehouse::whereIn('sku', $pagedSkus)
            ->when($integrationId, fn($q) => $q->where('integration_id', $integrationId))
            ->get()
            ->groupBy('sku');

        // Строим items
        $items = [];
        foreach ($pagedSkus as $sku) {
            $agg  = $allSkus[$sku] ?? null;
            $prod = $products[$sku] ?? null;
            $rows = $warehouseRows[$sku] ?? collect();

            // Определяем stock_status по total_stock и avg_daily_sales
            $totalStock    = (int)   ($agg->total_stock    ?? 0);
            $avgDailySales = (float) ($agg->avg_daily_sales ?? 0);
            $salesTrend7   = (int)   ($agg->sales_7_days   ?? 0);
            $sales30       = (int)   ($agg->sales_30_days  ?? 0);
            $daysOfStock   = $avgDailySales > 0 ? round($totalStock / $avgDailySales) : null;

            if ($totalStock <= 0) {
                $computedStatus = 'out_of_stock';
            } elseif ($daysOfStock !== null && $daysOfStock <= 7) {
                $computedStatus = 'critical';
            } elseif ($daysOfStock !== null && $daysOfStock <= 14) {
                $computedStatus = 'low';
            } elseif ($daysOfStock !== null && $daysOfStock > 60) {
                $computedStatus = 'excess';
            } else {
                $computedStatus = 'optimal';
            }

            // Тренд продаж
            $sales14 = (int) ($agg->sales_14_days ?? 0);
            $s1 = $sales30 > 0 ? $sales30 / 30 : 0;
            $s2 = $salesTrend7 > 0 ? $salesTrend7 / 7 : 0;
            $trendPct = $s1 > 0 ? round(($s2 - $s1) / $s1 * 100) : 0;
            $trend = $trendPct > 10 ? 'growing' : ($trendPct < -10 ? 'declining' : 'stable');

            $costPrice   = (float) ($agg->cost_price ?? $prod?->cost_price ?? 0);
            $price       = (float) ($prod?->price ?? 0);
            $stockValue  = $totalStock * ($price ?: $costPrice);

            // Матрица по складам
            $warehouseMatrix = $rows->map(fn($w) => [
                'warehouse_id'         => $w->warehouse_id,
                'quantity'             => (int)   $w->quantity,
                'reserved'             => (int)   $w->reserved,
                'in_transit'           => (int)   $w->in_transit,
                'sales_7_days'         => (int)   $w->sales_7_days,
                'sales_30_days'        => (int)   $w->sales_30_days,
                'average_daily_sales'  => (float) $w->average_daily_sales,
                'days_of_stock'        => $w->days_of_stock,
                'turnover_days'        => $w->turnover_days,
                'storage_cost_per_day' => (float) ($w->storage_cost_per_day ?? 0),
                'real_avg_daily_sales' => $w->real_avg_daily_sales,
                'real_items_sold'      => null,
                'real_turnover_days'   => $w->real_turnover_days,
                'real_days_of_stock'   => $w->real_days_of_stock,
                'real_sales_period_days' => $w->real_sales_period_days,
                'stock_status'         => $w->stock_status,
                'has_stock'            => $w->quantity > 0,
            ])->values()->toArray();

            $items[] = [
                'sku'                  => $sku,
                'name'                 => $prod?->name,
                'barcode'              => $prod?->barcode,
                'image_url'            => $prod?->images[0] ?? null,
                'price'                => $price,
                'cost_price'           => $costPrice,
                'marketplace'          => $prod?->marketplace ?? ($rows->first()?->marketplace ?? ''),
                'total_stock'          => $totalStock,
                'reserved'             => (int)   ($agg->reserved   ?? 0),
                'in_transit'           => (int)   ($agg->in_transit  ?? 0),
                'stock_value'          => round($stockValue, 2),
                'sales_7_days'         => $salesTrend7,
                'sales_14_days'        => $sales14,
                'sales_30_days'        => $sales30,
                'avg_daily_sales'      => round((float) ($agg->avg_daily_sales  ?? 0), 2),
                'effective_daily_sales'=> round((float) ($agg->effective_daily_sales ?? 0), 2),
                'days_in_stock_30'     => (int) ($agg->days_in_stock_30 ?? 30),
                'turnover_days'        => $agg->turnover_days ? round($agg->turnover_days, 1) : null,
                'days_of_stock'        => $daysOfStock,
                'sales_trend'          => $trend,
                'sales_trend_pct'      => $trendPct,
                'stock_status'         => $computedStatus,
                'storage_cost_daily'   => round((float) ($agg->storage_cost_daily   ?? 0), 2),
                'storage_cost_monthly' => round((float) ($agg->storage_cost_monthly ?? 0), 2),
                'real_avg_daily_sales' => $agg->real_avg_daily_sales ? round($agg->real_avg_daily_sales, 2) : null,
                'real_turnover_days'   => $agg->real_turnover_days   ? round($agg->real_turnover_days, 1)  : null,
                'real_days_of_stock'   => $agg->real_days_of_stock   ? round($agg->real_days_of_stock)     : null,
                'real_sales_period_days' => $agg->real_sales_period_days ? round($agg->real_sales_period_days) : null,
                'warehouses_with_stock'=> $rows->where('quantity', '>', 0)->count(),
                'warehouses_total'     => $rows->count(),
                'warehouse_matrix'     => $warehouseMatrix,
            ];
        }

        // Summary
        $summaryQuery = \App\Models\InventoryWarehouse::query()
            ->when($integrationId, fn($q) => $q->where('integration_id', $integrationId));

        $summary = [
            'total_products'    => $total,
            'total_warehouses'  => (clone $summaryQuery)->distinct('warehouse_id')->count('warehouse_id'),
            'total_stock'       => (int) (clone $summaryQuery)->sum('quantity'),
            'total_stock_value' => 0,
            'avg_turnover_days' => round((float) (clone $summaryQuery)->avg('turnover_days') ?? 0, 1),
            'out_of_stock_count'=> $allSkus->where('total_stock', '<=', 0)->count(),
            'critical_count'    => $allSkus->filter(fn($r) => in_array($r->stock_status, ['critical']))->count(),
            'low_count'         => $allSkus->filter(fn($r) => $r->stock_status === 'low')->count(),
        ];

        return response()->json([
            'message' => 'OK',
            'data' => [
                'items'      => $items,
                'warehouses' => $warehouses->values(),
                'pagination' => [
                    'current_page' => $page,
                    'last_page'    => (int) ceil($total / $perPage),
                    'per_page'     => $perPage,
                    'total'        => $total,
                ],
                'summary' => $summary,
            ],
        ]);
    }
}
