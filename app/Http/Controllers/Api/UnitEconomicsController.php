<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UnitEconomics\IndexUnitEconomicsRequest;
use App\Http\Requests\UnitEconomics\CalculateRequest;
use App\Http\Requests\UnitEconomics\StoreUnitEconomicsRequest;
use App\Http\Requests\UnitEconomics\UpdateUnitEconomicsRequest;
use App\Models\UnitEconomics;
use App\Services\UnitEconomicsService;
use Illuminate\Http\JsonResponse;

class UnitEconomicsController extends Controller
{
    public function __construct(
        private UnitEconomicsService $unitEconomicsService
    ) {}

    public function index(IndexUnitEconomicsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $query = UnitEconomics::query();

        if (!empty($validated['marketplace'])) {
            $query->marketplace($validated['marketplace']);
        }

        if (!empty($validated['search'])) {
            $query->search($validated['search']);
        }

        if (!empty($validated['integration_id'])) {
            $query->where('integration_id', $validated['integration_id']);
        }

        if (!empty($validated['profitability'])) {
            if ($validated['profitability'] === 'profitable') {
                $query->profitable();
            } elseif ($validated['profitability'] === 'unprofitable') {
                $query->unprofitable();
            }
        }

        $query->marginRange(
            $validated['margin_min'] ?? null,
            $validated['margin_max'] ?? null
        );

        $query->priceRange(
            $validated['price_min'] ?? null,
            $validated['price_max'] ?? null
        );

        // Default sort: сначала товары с активными продажами за 28д (из ozon_order_unit_economics,
        // то же что показывает Locality). Если unit_economics.sales_count устарел или пустой —
        // всё равно SKU с заказами попадут наверх.
        $integrationIdForSort = $validated['integration_id'] ?? null;
        $marketplaceForSort = $validated['marketplace'] ?? null;

        if (isset($validated['sort'])) {
            $sortField = $validated['sort'];
            $sortOrder = $validated['sort_order'] ?? 'asc';
            $query->orderBy($sortField, $sortOrder);
        } elseif ($marketplaceForSort === 'ozon' && $integrationIdForSort) {
            $recentOrdersSub = \Illuminate\Support\Facades\DB::table('ozon_order_unit_economics')
                ->selectRaw('sku, COUNT(*) AS recent_orders_28d')
                ->where('integration_id', $integrationIdForSort)
                ->where('order_date', '>=', now()->subDays(28))
                ->whereNotIn('markup_reason_code', ['cancelled_order', 'not_redeemed'])
                ->groupBy('sku');

            $query->leftJoinSub($recentOrdersSub, 'recent', function ($join) {
                    $join->on('recent.sku', '=', 'unit_economics.sku');
                })
                ->select('unit_economics.*')
                ->orderByRaw('COALESCE(recent.recent_orders_28d, 0) DESC')
                ->orderByRaw('COALESCE(unit_economics.sales_count, 0) DESC')
                ->orderBy('unit_economics.sku', 'asc');
        } else {
            $query->orderByRaw('COALESCE(sales_count, 0) DESC, sku ASC');
        }

        $limit = $validated['limit'] ?? 50;
        $page = $validated['page'] ?? 1;

        $items = $query->paginate($limit, ['*'], 'page', $page);

        $stats = $this->unitEconomicsService->getStats($validated);

        return response()->json([
            'data' => [
                'items' => $items->items(),
                'total' => $items->total(),
            ],
            'stats' => $stats,
        ]);
    }

    public function byMarketplace(IndexUnitEconomicsRequest $request, string $marketplace): JsonResponse
    {
        $marketplace = $this->normalizeMarketplace($marketplace);
        $validated = $request->validated();
        $validated['marketplace'] = $marketplace;

        $integrationId = $validated['integration_id'] ?? null;
        $fulfillmentType = $validated['fulfillment_type'] ?? null;

        // Базовый запрос по маркетплейсу и интеграции
        $baseQuery = UnitEconomics::marketplace($marketplace);
        if ($integrationId) {
            $baseQuery->where('integration_id', $integrationId);
        }

        // Считаем количество товаров по каждой схеме (для переключателей на фронте)
        $schemeCounts = (clone $baseQuery)
            ->selectRaw('fulfillment_type, count(*) as cnt')
            ->groupBy('fulfillment_type')
            ->pluck('cnt', 'fulfillment_type')
            ->toArray();

        // Определяем актуальную схему (если не передана явно)
        $actualScheme = null;
        if ($integrationId) {
            $actualScheme = UnitEconomics::where('integration_id', $integrationId)
                ->where('marketplace', $marketplace)
                ->where('is_actual_scheme', true)
                ->value('fulfillment_type');
        }

        // Фильтруем по fulfillment_type если передан, иначе — только актуальная схема
        $query = clone $baseQuery;
        if ($fulfillmentType) {
            $query->where('fulfillment_type', strtoupper($fulfillmentType));
        } elseif ($actualScheme) {
            $query->where('fulfillment_type', $actualScheme);
        } else {
            // BUG FIX: если нет записей с is_actual_scheme=true, показываем все записи
            // а не пустую таблицу (актуально для Yandex Market где ранее is_actual_scheme не заполнялся)
            $hasActual = (clone $baseQuery)->where('is_actual_scheme', true)->exists();
            if ($hasActual) {
                $query->where('is_actual_scheme', true);
            }
        }

        if (!empty($validated['search'])) {
            $query->search($validated['search']);
        }

        if (!empty($validated['profitability'])) {
            if ($validated['profitability'] === 'profitable') {
                $query->profitable();
            } elseif ($validated['profitability'] === 'unprofitable') {
                $query->unprofitable();
            }
        }

        $query->marginRange(
            $validated['margin_min'] ?? null,
            $validated['margin_max'] ?? null
        );

        $query->priceRange(
            $validated['price_min'] ?? null,
            $validated['price_max'] ?? null
        );

        // Default sort: товары с активными продажами за 28д (из ozon_order_unit_economics) первыми.
        if (isset($validated['sort'])) {
            $sortField = $validated['sort'];
            $sortOrder = $validated['sort_order'] ?? 'asc';
            $query->orderBy($sortField, $sortOrder);
        } elseif ($marketplace === 'ozon' && $integrationId) {
            $recentOrdersSub = \Illuminate\Support\Facades\DB::table('ozon_order_unit_economics')
                ->selectRaw('sku, COUNT(*) AS recent_orders_28d')
                ->where('integration_id', $integrationId)
                ->where('order_date', '>=', now()->subDays(28))
                ->whereNotIn('markup_reason_code', ['cancelled_order', 'not_redeemed'])
                ->groupBy('sku');

            $query->leftJoinSub($recentOrdersSub, 'recent', function ($join) {
                    $join->on('recent.sku', '=', 'unit_economics.sku');
                })
                ->select('unit_economics.*')
                ->orderByRaw('COALESCE(recent.recent_orders_28d, 0) DESC')
                ->orderByRaw('COALESCE(unit_economics.sales_count, 0) DESC')
                ->orderBy('unit_economics.sku', 'asc');
        } else {
            $query->orderByRaw('COALESCE(sales_count, 0) DESC, sku ASC');
        }

        $limit = $validated['limit'] ?? 50;
        $page = $validated['page'] ?? 1;

        $items = $query->paginate($limit, ['*'], 'page', $page);

        // Считаем статистику по той же выборке (integration_id + fulfillment_type)
        $statsQuery = UnitEconomics::marketplace($marketplace);
        if ($integrationId) {
            $statsQuery->where('integration_id', $integrationId);
        }
        $effectiveFulfillmentType = $fulfillmentType ?? $actualScheme;
        if ($effectiveFulfillmentType) {
            $statsQuery->where('fulfillment_type', strtoupper($effectiveFulfillmentType));
        } else {
            $hasActualStats = (clone $statsQuery)->where('is_actual_scheme', true)->exists();
            if ($hasActualStats) {
                $statsQuery->where('is_actual_scheme', true);
            }
        }

        $stats = [
            'total_revenue'        => round($statsQuery->sum('revenue'), 2),
            'total_costs'          => round($statsQuery->sum('total_costs'), 2),
            'total_profit'         => round($statsQuery->sum('net_profit'), 2),
            'average_margin'       => round($statsQuery->avg('margin_percent'), 2),
            'average_roi'          => round($statsQuery->avg('roi_percent'), 2),
            'total_sales'          => $statsQuery->sum('sales_count'),
            'profitable_products'  => (clone $statsQuery)->where('net_profit', '>', 0)->count(),
            'unprofitable_products'=> (clone $statsQuery)->where('net_profit', '<=', 0)->count(),
        ];

        return response()->json([
            'data' => [
                'items' => $items->items(),
                'total' => $items->total(),
                'scheme_counts' => $schemeCounts,
                'actual_scheme' => $actualScheme,
            ],
            'stats' => $stats,
        ]);
    }

    public function show(string $marketplace, string $sku): JsonResponse
    {
        $marketplace = $this->normalizeMarketplace($marketplace);
        $unitEconomics = UnitEconomics::where('marketplace', $marketplace)
            ->where('sku', $sku)
            ->latest()
            ->firstOrFail();

        return response()->json([
            'data' => $unitEconomics,
        ]);
    }

    public function store(StoreUnitEconomicsRequest $request, string $marketplace): JsonResponse
    {
        $marketplace = $this->normalizeMarketplace($marketplace);
        $validated = $request->validated();
        $validated['marketplace'] = $marketplace;

        $unitEconomics = $this->unitEconomicsService->createOrUpdate($validated);

        return response()->json([
            'data' => $unitEconomics,
            'message' => 'Unit economics created successfully',
        ], 201);
    }

    public function update(UpdateUnitEconomicsRequest $request, string $marketplace, int $id): JsonResponse
    {
        $marketplace = $this->normalizeMarketplace($marketplace);
        $unitEconomics = UnitEconomics::where('marketplace', $marketplace)
            ->findOrFail($id);

        $unitEconomics->update($request->validated());

        return response()->json([
            'data' => $unitEconomics->fresh(),
            'message' => 'Unit economics updated successfully',
        ]);
    }

    public function destroy(string $marketplace, int $id): JsonResponse
    {
        $marketplace = $this->normalizeMarketplace($marketplace);
        $unitEconomics = UnitEconomics::where('marketplace', $marketplace)
            ->findOrFail($id);

        $unitEconomics->delete();

        return response()->json([
            'message' => 'Unit economics deleted successfully',
        ]);
    }

    public function calculate(CalculateRequest $request, string $marketplace): JsonResponse
    {
        $marketplace = $this->normalizeMarketplace($marketplace);
        $validated = $request->validated();
        $validated['marketplace'] = $marketplace;

        $result = $this->unitEconomicsService->calculate($marketplace, $validated);

        return response()->json([
            'data' => $result,
        ]);
    }

    public function comparison(): JsonResponse
    {
        $comparison = $this->unitEconomicsService->getMarketplaceComparison();

        return response()->json([
            'data' => $comparison,
        ]);
    }

    public function stats(): JsonResponse
    {
        $stats = $this->unitEconomicsService->getOverallStats();

        return response()->json([
            'data' => $stats,
        ]);
    }

    public function statsByMarketplace(string $marketplace): JsonResponse
    {
        $marketplace = $this->normalizeMarketplace($marketplace);
        $stats = $this->unitEconomicsService->getStatsByMarketplace($marketplace);

        return response()->json([
            'data' => $stats,
        ]);
    }

    public function commissions(string $marketplace): JsonResponse
    {
        $marketplace = $this->normalizeMarketplace($marketplace);
        $commissions = $this->unitEconomicsService->getCommissions($marketplace);

        return response()->json([
            'data' => $commissions,
        ]);
    }

    public function tariffs(string $marketplace): JsonResponse
    {
        $marketplace = $this->normalizeMarketplace($marketplace);
        $tariffs = $this->unitEconomicsService->getTariffs($marketplace);

        return response()->json([
            'data' => $tariffs,
        ]);
    }

    private function normalizeMarketplace(string $marketplace): string
    {
        return match (strtolower($marketplace)) {
            'yandex', 'yandex_market' => 'yandex_market',
            default => strtolower($marketplace),
        };
    }
}
