<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\IndexInventoryRequest;
use App\Models\InventoryAlert;
use App\Models\InventoryHistory;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Models\UnitEconomics;
use App\Jobs\SyncStorageFeesJob;
use App\Models\Integration;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        $preloaded = $this->inventoryService->preloadFormatProductInventoryData($products->getCollection());
        $items = $products->getCollection()->map(function ($product) use ($preloaded) {
            return $this->inventoryService->formatProductInventory($product, $preloaded);
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

    public function syncStorageFees(Request $request): JsonResponse
    {
        $integrationId = $request->input('integration_id');
        if (!$integrationId) {
            return response()->json(['message' => 'integration_id обязателен'], 422);
        }

        $integration = Integration::find($integrationId);
        if (!$integration || $integration->marketplace !== 'wildberries') {
            return response()->json(['message' => 'WB интеграция не найдена'], 404);
        }

        $credentials = $integration->credentials ?? [];
        if (empty($credentials)) {
            return response()->json(['message' => 'Credentials интеграции не заполнены'], 422);
        }

        SyncStorageFeesJob::dispatch($integrationId, $credentials, 4);

        return response()->json([
            'message' => 'Синхронизация начислений за хранение запущена',
            'data' => ['integration_id' => $integrationId],
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
        $integrationId    = $request->input('integration_id');
        $search           = $request->input('search');
        $sort             = $request->input('sort', 'total_stock');
        $sortOrder        = $request->input('sort_order', 'desc');
        $stockStatus      = $request->input('stock_status');
        $page             = (int) $request->input('page', 1);
        $perPage          = (int) $request->input('per_page', 30);
        $fulfillmentType  = $request->input('fulfillment_type', 'all'); // all / fbo / fbs

        $query = \App\Models\InventoryWarehouse::query()
            ->when($integrationId, fn($q) => $q->where('integration_id', $integrationId))
            ->when($fulfillmentType && $fulfillmentType !== 'all', function ($q) use ($fulfillmentType) {
                $q->whereRaw('LOWER(fulfillment_type) = ?', [strtolower($fulfillmentType)]);
            });
        $marketplace = (clone $query)->value('marketplace');

        // Получаем ВСЕ склады (включая пустые) — сначала с остатком, потом пустые
        $warehouses = (clone $query)
            ->select('warehouse_id', 'warehouse_name', 'marketplace', 'fulfillment_type', 'region')
            ->selectRaw('SUM(quantity) as total_qty')
            ->groupBy('warehouse_id', 'warehouse_name', 'marketplace', 'fulfillment_type', 'region')
            ->orderByDesc(DB::raw('SUM(quantity)'))
            ->get()
            ->map(fn($w) => [
                'warehouse_id'      => $w->warehouse_id,
                'warehouse_name'    => $w->warehouse_name,
                'marketplace'       => $w->marketplace,
                'fulfillment_type'  => $w->fulfillment_type,
                'region'            => $w->region,
                'total_qty'         => (int) $w->total_qty,
            ]);

        // Агрегируем по SKU с учётом фильтра по fulfillment_type
        // MAX() для sales — данные пишутся одинаково на каждый склад SKU, SUM() раздует значения.
        // SUM() только для quantity/reserved/in_transit/storage_cost — они реально разные по складам.
        $skuQuery = (clone $query)
            ->select('sku')
            ->selectRaw('SUM(quantity) as total_stock')
            ->selectRaw('SUM(reserved) as reserved')
            ->selectRaw('SUM(in_transit) as in_transit')
            ->selectRaw('MAX(sales_7_days) as sales_7_days')
            ->selectRaw('MAX(sales_14_days) as sales_14_days')
            ->selectRaw('MAX(sales_30_days) as sales_30_days')
            ->selectRaw('MAX(average_daily_sales) as avg_daily_sales')
            ->selectRaw('MAX(effective_daily_sales) as effective_daily_sales')
            ->selectRaw('MAX(days_in_stock_30) as days_in_stock_30')
            ->selectRaw('CASE WHEN MAX(average_daily_sales) > 0 THEN ROUND(SUM(quantity)::numeric / MAX(average_daily_sales), 1) ELSE NULL END as turnover_days')
            ->selectRaw('CASE WHEN MAX(average_daily_sales) > 0 THEN ROUND(SUM(quantity)::numeric / MAX(average_daily_sales)) ELSE NULL END as days_of_stock')
            ->selectRaw('MAX(stock_status) as stock_status')
            ->selectRaw('SUM(storage_cost_per_day) as storage_cost_daily')
            ->selectRaw('SUM(storage_cost_per_month) as storage_cost_monthly')
            ->selectRaw('MAX(real_avg_daily_sales) as real_avg_daily_sales')
            ->selectRaw('MAX(real_turnover_days) as real_turnover_days')
            ->selectRaw('MAX(real_days_of_stock) as real_days_of_stock')
            ->selectRaw('MAX(real_sales_period_days) as real_sales_period_days')
            ->selectRaw('SUM(storage_fee_total) as storage_fee_total')
            ->selectRaw('SUM(storage_fee_last_week) as storage_fee_last_week')
            ->selectRaw('MIN(storage_fee_report_from) as storage_fee_report_from')
            ->selectRaw('MAX(storage_fee_report_to) as storage_fee_report_to')
            ->selectRaw('SUM(storage_fee_prev_month) as storage_fee_prev_month')
            ->selectRaw('MAX(storage_fee_prev_month_period) as storage_fee_prev_month_period')
            ->groupBy('sku');

        // Полный список для поиска и сортировки (спрос для дней запаса/оборачиваемости — с fallback как в синке UE)
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
            $demand = $this->resolveInventoryMatrixDailyDemand($row);
            $qty = (int) ($row->total_stock ?? 0);
            $dos = $demand > 0 ? $qty / $demand : 9999.0;

            return match ($sort) {
                'total_stock' => (int) $row->total_stock,
                'sales_30_days' => (int) $row->sales_30_days,
                'avg_daily_sales' => $demand > 0 ? $demand : (float) ($row->avg_daily_sales ?? 0),
                'days_of_stock' => $dos,
                'turnover_days' => $dos,
                'stock_value' => (float) $row->total_stock,
                default => (int) $row->total_stock,
            };
        };

        $sortedSkus = $sortOrder === 'asc'
            ? $allSkus->sortBy($sortCallback)
            : $allSkus->sortByDesc($sortCallback);

        // Фильтр по статусу — применяем на уровне PHP с корректным расчётом
        if ($stockStatus) {
            $sortedSkus = $sortedSkus->filter(function ($row) use ($stockStatus) {
                $qty = (int) ($row->total_stock ?? 0);
                $avg = $this->resolveInventoryMatrixDailyDemand($row);
                $dos = $avg > 0 ? round($qty / $avg) : null;
                if ($qty <= 0) {
                    $s = 'out_of_stock';
                } elseif ($dos !== null && $dos <= 7) {
                    $s = 'critical';
                } elseif ($dos !== null && $dos <= 14) {
                    $s = 'low';
                } elseif ($dos !== null && $dos > 60) {
                    $s = 'excess';
                } else {
                    $s = 'optimal';
                }
                return $s === $stockStatus;
            });
        }

        $total      = $sortedSkus->count();
        $pagedSkus  = $sortedSkus->slice(($page - 1) * $perPage, $perPage)->keys()->toArray();

        // Загружаем товары по sku ИЛИ barcode (WB: инвентарь использует все баркоды размеров)
        $productsQuery = \App\Models\Product::where(function ($q) use ($pagedSkus) {
            $q->whereIn('sku', $pagedSkus)->orWhereIn('barcode', $pagedSkus);
        });
        if ($integrationId) {
            $productsQuery->where(function ($q) use ($integrationId) {
                $q->where('integration_id', $integrationId)->orWhereNull('integration_id');
            })->orderByRaw('CASE WHEN integration_id = ? THEN 0 ELSE 1 END', [$integrationId]);
        }
        // Строим карту: invSku -> product (sku совпадение приоритетнее barcode)
        $productsRaw = $productsQuery->get();
        $products = collect();
        foreach ($productsRaw as $prod) {
            // Если SKU совпадает напрямую — добавляем под этим SKU
            if (in_array($prod->sku, $pagedSkus)) {
                $products->put($prod->sku, $prod);
            }
            // Если barcode совпадает с одним из SKU инвентаря — добавляем под barcode (не перетираем прямое совпадение)
            if ($prod->barcode && in_array($prod->barcode, $pagedSkus) && !$products->has($prod->barcode)) {
                $products->put($prod->barcode, $prod);
            }
            // WB: дополнительный поиск по всем баркодам из wb_data.sizes[].skus[]
            // У WB-товара с несколькими размерами каждый размер имеет свой баркод в инвентаре
            if (!empty($prod->wb_data['sizes'])) {
                foreach ($prod->wb_data['sizes'] as $size) {
                    foreach ($size['skus'] ?? [] as $wbBarcode) {
                        if (in_array($wbBarcode, $pagedSkus) && !$products->has($wbBarcode)) {
                            $products->put($wbBarcode, $prod);
                        }
                    }
                }
            }
        }

        $unitEconomicsQuery = UnitEconomics::query()
            ->whereIn('sku', $pagedSkus)
            ->when($marketplace, fn($q) => $q->where('marketplace', $marketplace));
        if ($integrationId) {
            $unitEconomicsQuery->where(function ($q) use ($integrationId) {
                $q->where('integration_id', $integrationId)->orWhereNull('integration_id');
            })->orderByDesc('integration_id');
        }
        $unitEconomics = $unitEconomicsQuery->get()->keyBy('sku');

        // Ozon FBS: для SKU вида "00808-16" (offer_id с суффиксом количества)
        // ищем продукт по ozon_data->>'offer_id' = invSku или по базовому SKU (до дефиса)
        $notFoundOzonSkus = array_diff($pagedSkus, $products->keys()->toArray());
        if (!empty($notFoundOzonSkus)) {
            // Собираем базовые SKU (до последнего дефиса): "00808-16" -> "00808"
            $baseSkuMap = []; // baseSku => [invSku, ...]
            foreach ($notFoundOzonSkus as $invSku) {
                $lastDash = strrpos($invSku, '-');
                if ($lastDash !== false) {
                    $baseSku = substr($invSku, 0, $lastDash);
                    $baseSkuMap[$baseSku][] = $invSku;
                }
            }
            if (!empty($baseSkuMap)) {
                $ozonFallbackQuery = \App\Models\Product::where('marketplace', 'ozon')
                    ->whereIn('sku', array_keys($baseSkuMap));
                if ($integrationId) {
                    $ozonFallbackQuery->where(function ($q) use ($integrationId) {
                        $q->where('integration_id', $integrationId)->orWhereNull('integration_id');
                    });
                }
                foreach ($ozonFallbackQuery->get() as $prod) {
                    foreach ($baseSkuMap[$prod->sku] ?? [] as $invSku) {
                        if (!$products->has($invSku)) {
                            $products->put($invSku, $prod);
                        }
                    }
                }
            }
        }

        // WB: дополнительный JSONB-запрос для баркодов которые не нашлись по sku/barcode
        // но могут быть внутри wb_data->sizes[*]->skus[] других товаров
        $notFoundSkus = array_diff($pagedSkus, $products->keys()->toArray());
        if (!empty($notFoundSkus)) {
            $wbExtraQuery = \App\Models\Product::where('marketplace', 'wildberries')
                ->whereNotNull('wb_data');
            if ($integrationId) {
                $wbExtraQuery->where(function ($q) use ($integrationId) {
                    $q->where('integration_id', $integrationId)->orWhereNull('integration_id');
                });
            }
            // Ищем через JSONB: баркод присутствует в wb_data::text
            $wbExtraQuery->where(function ($q) use ($notFoundSkus) {
                foreach ($notFoundSkus as $notFoundSku) {
                    $q->orWhereRaw("wb_data::text LIKE ?", ["%{$notFoundSku}%"]);
                }
            });
            $wbExtraProducts = $wbExtraQuery->get();
            foreach ($wbExtraProducts as $prod) {
                if (!empty($prod->wb_data['sizes'])) {
                    foreach ($prod->wb_data['sizes'] as $size) {
                        foreach ($size['skus'] ?? [] as $wbBarcode) {
                            if (in_array($wbBarcode, $notFoundSkus) && !$products->has($wbBarcode)) {
                                $products->put($wbBarcode, $prod);
                            }
                        }
                    }
                }
            }
        }

        // Загружаем строки складов для страницы (с учётом фильтра по fulfillment_type)
        $warehouseRows = \App\Models\InventoryWarehouse::whereIn('sku', $pagedSkus)
            ->when($integrationId, fn($q) => $q->where('integration_id', $integrationId))
            ->when($fulfillmentType && $fulfillmentType !== 'all', function ($q) use ($fulfillmentType) {
                $q->whereRaw('LOWER(fulfillment_type) = ?', [strtolower($fulfillmentType)]);
            })
            ->get()
            ->groupBy('sku');

        // Строим items
        $items = [];
        foreach ($pagedSkus as $sku) {
            $agg  = $allSkus[$sku] ?? null;
            $prod = $products[$sku] ?? null;
            $rows = $warehouseRows[$sku] ?? collect();

            // Определяем stock_status по total_stock и avg_daily_sales
            $totalStock = (int) ($agg->total_stock ?? 0);
            $resolvedDemand = $this->resolveInventoryMatrixDailyDemand($agg);
            $avgDailySales = $resolvedDemand > 0 ? $resolvedDemand : (float) ($agg->avg_daily_sales ?? 0);
            $salesTrend7 = (int) ($agg->sales_7_days ?? 0);
            $sales30 = (int) ($agg->sales_30_days ?? 0);
            $daysOfStock = $avgDailySales > 0 ? round($totalStock / $avgDailySales) : null;

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

            $ue          = $unitEconomics->get($sku);
            $productCost = (float) ($prod?->cost_price ?? 0);
            $productPrice= (float) ($prod?->price ?? 0);
            $costPrice   = $productCost > 0 ? $productCost : (float) ($ue?->cost_price ?? 0);
            $price       = $productPrice > 0 ? $productPrice : (float) ($ue?->price ?? 0);
            $stockValue  = $totalStock * ($price ?: $costPrice);

            $storageFeeRow = (float) ($agg->storage_fee_total ?? 0);
            $storageMonthlyRow = (float) ($agg->storage_cost_monthly ?? 0);
            $storageCurrentDisplay = $storageFeeRow > 0 ? $storageFeeRow : $storageMonthlyRow;

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
                'image_url'            => (function() use ($prod) {
                    if (!$prod) return null;
                    // 1. Первое изображение из массива images
                    if (is_array($prod->images) && !empty($prod->images)) return $prod->images[0];
                    // 2. Ozon: primary_image из ozon_data
                    if (!empty($prod->ozon_data['primary_image'])) {
                        $pi = $prod->ozon_data['primary_image'];
                        return is_array($pi) ? ($pi[0] ?? null) : $pi;
                    }
                    // 3. WB: первое фото из wb_data
                    if (!empty($prod->wb_data['photos'][0]['big'])) return $prod->wb_data['photos'][0]['big'];
                    if (!empty($prod->wb_data['mediaFiles'][0])) return $prod->wb_data['mediaFiles'][0];
                    return null;
                })(),
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
                'avg_daily_sales'      => round($avgDailySales, 2),
                'effective_daily_sales'=> round((float) ($agg->effective_daily_sales ?? 0), 2),
                'days_in_stock_30'     => (int) ($agg->days_in_stock_30 ?? 30),
                'turnover_days'        => $avgDailySales > 0 ? round($totalStock / $avgDailySales, 1) : null,
                'days_of_stock'        => $daysOfStock,
                'sales_trend'          => $trend,
                'sales_trend_pct'      => $trendPct,
                'stock_status'         => $computedStatus,
                'storage_cost_daily'   => round((float) ($agg->storage_cost_daily   ?? 0), 2),
                'storage_cost_monthly' => round((float) ($agg->storage_cost_monthly ?? 0), 2),
                'storage_fee_total'    => round($storageCurrentDisplay, 2),
                'storage_fee_last_week'=> round((float) ($agg->storage_fee_last_week ?? 0), 2),
                'storage_fee_report_from'  => $agg->storage_fee_report_from  ? (string) $agg->storage_fee_report_from  : null,
                'storage_fee_report_to'    => $agg->storage_fee_report_to    ? (string) $agg->storage_fee_report_to    : null,
                'storage_fee_prev_month'   => round((float) ($agg->storage_fee_prev_month ?? 0), 2),
                'storage_fee_prev_month_period' => $agg->storage_fee_prev_month_period ? (string) $agg->storage_fee_prev_month_period : null,
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

        // Подсчёт статусов через правильный computedStatus (не MAX(stock_status))
        $outOfStockCount = 0;
        $criticalCount   = 0;
        $lowCount        = 0;
        foreach ($allSkus as $row) {
            $qty = (int) ($row->total_stock ?? 0);
            $avg = $this->resolveInventoryMatrixDailyDemand($row);
            $dos = $avg > 0 ? round($qty / $avg) : null;
            if ($qty <= 0) {
                $outOfStockCount++;
            } elseif ($dos !== null && $dos <= 7) {
                $criticalCount++;
            } elseif ($dos !== null && $dos <= 14) {
                $lowCount++;
            }
        }

        // Суммы хранения: WB — storage_fee_*; для Ozon и др. часто заполнен только storage_cost_monthly
        $storageFeeTotalRaw = $allSkus->sum(fn ($r) => (float) ($r->storage_fee_total ?? 0));
        $storageMonthlySum = $allSkus->sum(fn ($r) => (float) ($r->storage_cost_monthly ?? 0));
        $storageFeeTotal = $storageFeeTotalRaw > 0 ? $storageFeeTotalRaw : $storageMonthlySum;
        $storageFeePrevMonth = $allSkus->sum(fn($r) => (float) ($r->storage_fee_prev_month ?? 0));
        $storageFeeFromVals  = $allSkus->filter(fn($r) => !empty($r->storage_fee_report_from))->pluck('storage_fee_report_from');
        $storageFeeToVals    = $allSkus->filter(fn($r) => !empty($r->storage_fee_report_to))->pluck('storage_fee_report_to');
        $storageFeeFrom      = $storageFeeFromVals->min();
        $storageFeeTo        = $storageFeeToVals->max();
        $storagePrevPeriod   = $allSkus->filter(fn($r) => !empty($r->storage_fee_prev_month_period))->first()?->storage_fee_prev_month_period;

        // total_stock_value считаем по всем SKU через products + allSkus
        $allProductSkus = $allSkus->keys()->toArray();
        $allProductsMap = \App\Models\Product::whereIn('sku', $allProductSkus)
            ->when($marketplace, fn($q) => $q->where('marketplace', $marketplace))
            ->when($integrationId, fn($q) => $q->where(function ($q) use ($integrationId) {
                $q->where('integration_id', $integrationId)->orWhereNull('integration_id');
            }))
            ->get(['sku', 'price', 'cost_price'])
            ->keyBy('sku');
        $allUnitEconomicsQuery = UnitEconomics::query()
            ->whereIn('sku', $allProductSkus)
            ->when($marketplace, fn($q) => $q->where('marketplace', $marketplace));
        if ($integrationId) {
            $allUnitEconomicsQuery->where(function ($q) use ($integrationId) {
                $q->where('integration_id', $integrationId)->orWhereNull('integration_id');
            })->orderByDesc('integration_id');
        }
        $allUnitEconomicsMap = $allUnitEconomicsQuery->get()->keyBy('sku');
        $totalStockValue = 0;
        foreach ($allSkus as $sku => $row) {
            $prod = $allProductsMap[$sku] ?? null;
            $ue = $allUnitEconomicsMap[$sku] ?? null;
            $productPrice = (float) ($prod?->price ?? 0);
            $productCost = (float) ($prod?->cost_price ?? 0);
            $price = $productPrice > 0 ? $productPrice : (float) ($ue?->price ?? 0);
            $costPrice = $productCost > 0 ? $productCost : (float) ($ue?->cost_price ?? 0);
            $qty = (int) ($row->total_stock ?? 0);
            $totalStockValue += $qty * ($price ?: $costPrice);
        }

        // avg_turnover_days считаем по всем SKU с корректным MAX(avg_daily_sales)
        $avgTurnoverDays = 0;
        $turnoverCount = 0;
        foreach ($allSkus as $row) {
            $avg = $this->resolveInventoryMatrixDailyDemand($row);
            $qty = (int) ($row->total_stock ?? 0);
            if ($avg > 0) {
                $avgTurnoverDays += round($qty / $avg, 1);
                $turnoverCount++;
            }
        }
        $avgTurnoverDays = $turnoverCount > 0 ? round($avgTurnoverDays / $turnoverCount, 1) : 0;

        $summary = [
            'total_products'    => $total,
            'total_warehouses'  => (clone $summaryQuery)->distinct('warehouse_id')->count('warehouse_id'),
            'total_stock'       => (int) (clone $summaryQuery)->sum('quantity'),
            'total_stock_value' => round($totalStockValue, 2),
            'avg_turnover_days' => $avgTurnoverDays,
            'out_of_stock_count'=> $outOfStockCount,
            'critical_count'    => $criticalCount,
            'low_count'         => $lowCount,
            'storage_totals'    => [
                'current_month' => [
                    'total' => round($storageFeeTotal, 2),
                    'from'  => $storageFeeFrom ? (string) $storageFeeFrom : null,
                    'to'    => $storageFeeTo   ? (string) $storageFeeTo   : null,
                ],
                'prev_month' => [
                    'total' => round($storageFeePrevMonth, 2),
                    'from'  => $storagePrevPeriod ? substr($storagePrevPeriod, 0, 7) . '-01' : null,
                    'to'    => $storagePrevPeriod ?? null,
                ],
            ],
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

    /**
     * Единый дневной спрос для матрицы: как в AutoSupplyPlan — real / effective / API avg, затем sales_30/30, sales_7/7.
     */
    private function resolveInventoryMatrixDailyDemand(object $row): float
    {
        foreach ([
            (float) ($row->real_avg_daily_sales ?? 0),
            (float) ($row->effective_daily_sales ?? 0),
            (float) ($row->avg_daily_sales ?? 0),
        ] as $v) {
            if ($v > 0) {
                return $v;
            }
        }
        $s30 = (int) ($row->sales_30_days ?? 0);
        if ($s30 > 0) {
            return $s30 / 30.0;
        }
        $s7 = (int) ($row->sales_7_days ?? 0);
        if ($s7 > 0) {
            return $s7 / 7.0;
        }

        return 0.0;
    }
}
