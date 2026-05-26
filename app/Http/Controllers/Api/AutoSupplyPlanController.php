<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AutoSupplyPlan\StoreAutoSupplyPlanRequest;
use App\Jobs\CalculateAutoSupplyPlanJob;
use App\Models\AutoSupplyPlan;
use App\Models\AutoSupplyPlanLine;
use App\Models\InventoryWarehouse;
use App\Models\Integration;
use App\Models\LocalityRecommendation;
use App\Models\OzonWarehouseCluster;
use App\Models\Product;
use App\Services\IntegrationAccessService;
use App\Services\LimitsSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AutoSupplyPlanController extends Controller
{
    public function __construct(
        private IntegrationAccessService $integrationAccessService,
        private LimitsSyncService $limitsSync
    ) {}

    /**
     * GET /api/auto-supply-plans
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'nullable|integer',
            'status' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = AutoSupplyPlan::with('integration')
            ->orderByDesc('created_at');

        if ($request->filled('integration_id')) {
            $integrationAccess = $this->integrationAccessService
                ->ensureAccessibleIntegration($request, (int) $request->input('integration_id'));

            if (! ($integrationAccess['success'] ?? false)) {
                return response()->json([
                    'message' => $integrationAccess['message'] ?? 'Интеграция не найдена',
                ], $integrationAccess['status'] ?? 404);
            }

            $query->where('integration_id', $integrationAccess['integration']->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $perPage = $request->input('per_page', 20);
        $plans = $query->paginate($perPage);

        return response()->json([
            'message' => 'OK',
            'data' => $plans,
        ]);
    }

    /**
     * POST /api/auto-supply-plans
     */
    public function store(StoreAutoSupplyPlanRequest $request): JsonResponse
    {
        $integrationAccess = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $request->input('integration_id'));

        if (! ($integrationAccess['success'] ?? false)) {
            return response()->json([
                'message' => $integrationAccess['message'] ?? 'Интеграция не найдена',
            ], $integrationAccess['status'] ?? 404);
        }

        /** @var Integration $integration */
        $integration = $integrationAccess['integration'];

        if ($integration->marketplace === 'ozon') {
            $clusterIds = array_values(array_filter(
                array_map('intval', (array) $request->input('cluster_ids', []))
            ));

            if ($clusterIds === []) {
                return response()->json([
                    'message' => 'Для Ozon-плана нужно выбрать хотя бы один кластер поставки',
                    'error' => 'ozon_cluster_required',
                ], 422);
            }
        }

        return $this->createPlanFromRequest($request, $integration);
    }

    private function createPlanFromRequest(Request $request, Integration $integration): JsonResponse
    {
        $limitResponse = $this->ensureAutoplanningLimitAvailable($integration);
        if ($limitResponse !== null) {
            return $limitResponse;
        }

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => $integration->marketplace,
            'status' => AutoSupplyPlan::STATUS_PENDING,
            'mode' => $request->input('mode', 'balanced'),
            'horizon_days' => $request->input('horizon_days', 28),
            'min_cover_days' => $request->input('min_cover_days', 7),
            'target_cover_days' => $request->input('target_cover_days', 21),
            'max_cover_days' => $request->input('max_cover_days', 42),
            'safety_stock_days' => $request->input('safety_stock_days', 5),
            'turnover_limit_days' => $request->input('turnover_limit_days'),
            'budget_limit' => $request->input('budget_limit'),
            'forecast_model' => 'EWMA_0.35',
            'algorithm_version' => 'asp-1.0.0',
            'params' => array_filter([
                'target_days' => $request->input('target_cover_days', 21),
                'safety_days' => $request->input('safety_stock_days', 5),
                'lead_time_days' => $request->input('lead_time_days', 7),
                'ewma_alpha' => 0.35,
                'warehouse_ids' => $request->input('warehouse_ids'),
                'cluster_ids' => $request->input('cluster_ids'),

                // Advanced (используются алгоритмом расчёта — см. CalculateAutoSupplyPlanJob)
                'ozon_qty_anchor' => $request->input('ozon_qty_anchor'),
                'demand_seasonality_multiplier' => $request->input('demand_seasonality_multiplier'),
                'skip_negative_profit' => $request->input('skip_negative_profit'),
                'include_wb_supplies_api_in_transit' => $request->input('include_wb_supplies_api_in_transit'),

                // Locality integration (читаются в CalculateAutoSupplyPlanJob и LocalityEnrichmentService)
                'split_by_cluster' => $request->input('split_by_cluster'),
                'minimum_locality_confidence' => $request->input('minimum_locality_confidence'),
                'include_locality_recommendations' => $request->input('include_locality_recommendations'),
                'locality_distribution_strategy' => $request->input('locality_distribution_strategy'),
                'locality_recommendation_ids' => $request->input('locality_recommendation_ids'),
            ], static fn ($v) => $v !== null),
        ]);

        if ((int) ($integration->work_space_id ?? 0) > 0) {
            $this->limitsSync->syncWorkspaceAutoplanningLimit((int) $integration->work_space_id);
        }

        CalculateAutoSupplyPlanJob::dispatch($plan->id);

        return response()->json([
            'message' => 'План создан, расчёт запущен',
            'data' => $plan->load('integration'),
        ], 201);
    }

    private function ensureAutoplanningLimitAvailable(Integration $integration): ?JsonResponse
    {
        $workspaceId = (int) ($integration->work_space_id ?? 0);
        if ($workspaceId <= 0) {
            return null;
        }

        $limitCheck = $this->limitsSync->ensureLimitAvailable($workspaceId, 'autoplanning', 1);
        if ($limitCheck['success'] ?? false) {
            return null;
        }

        return response()->json(
            $this->limitsSync->limitResponsePayload($limitCheck),
            (int) ($limitCheck['status'] ?? 403)
        );
    }

    /**
     * POST /api/auto-supply-plans/{id}/calculate
     */
    public function calculate(string $id): JsonResponse
    {
        $plan = AutoSupplyPlan::findOrFail($id);

        // Удаляем старые строки
        $plan->lines()->delete();
        $plan->update([
            'status' => AutoSupplyPlan::STATUS_PENDING,
            'error_message' => null,
            'data_quality_score' => null,
            'data_quality_json' => null,
            'total_lines' => 0,
            'total_qty' => 0,
        ]);

        CalculateAutoSupplyPlanJob::dispatch($plan->id);

        return response()->json([
            'message' => 'Пересчёт запущен',
            'data' => $plan->fresh()->load('integration'),
        ]);
    }

    /**
     * GET /api/auto-supply-plans/{id}/lines
     * Агрегирует строки по SKU — одна строка на товар, qty суммируется по всем складам
     */
    public function lines(Request $request, string $id): JsonResponse
    {
        $plan = AutoSupplyPlan::findOrFail($id);

        $query = $plan->lines();

        if ($request->filled('risk_level')) {
            $query->where('risk_level', $request->input('risk_level'));
        }

        if ($request->filled('offer_id')) {
            $query->where('offer_id', $request->input('offer_id'));
        }

        $perPage = $request->input('per_page', 50);
        $lines = $this->paginateAggregatedPlanLines($plan, $query, (int) $perPage);

        return response()->json([
            'message' => 'OK',
            'data' => $lines,
        ]);
    }

    /**
     * GET /api/auto-supply-plans/{id}/simulate?offer_id=...&destination_id=...
     */
    public function simulate(Request $request, string $id): JsonResponse
    {
        $plan = AutoSupplyPlan::findOrFail($id);

        $request->validate([
            'offer_id' => 'required|string',
            'destination_id' => 'nullable|string',
        ]);

        $query = $plan->lines()->where('offer_id', $request->input('offer_id'));

        if ($request->filled('destination_id')) {
            $query->where('destination_id', $request->input('destination_id'));
        }

        $line = $query->first();

        if (! $line) {
            return response()->json(['message' => 'Строка не найдена'], 404);
        }

        return response()->json([
            'message' => 'OK',
            'data' => [
                'line' => $line,
                'simulation' => $line->simulation_json,
                'explain' => $line->explain_json,
            ],
        ]);
    }

    /**
     * GET /api/auto-supply-plans/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $plan = AutoSupplyPlan::with('integration')->findOrFail($id);

        $integrationAccess = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $plan->integration_id);

        if (! ($integrationAccess['success'] ?? false)) {
            return response()->json([
                'message' => $integrationAccess['message'] ?? 'Доступ запрещён',
            ], $integrationAccess['status'] ?? 403);
        }

        $perPage = $request->input('per_page', 50);
        $lines = $this->paginateAggregatedPlanLines($plan, $plan->lines(), (int) $perPage);

        // Финансовые агрегаты
        $allLines = $plan->lines();
        $totalSupplyCost = (float) $allLines->sum('supply_cost_estimate');
        $totalExpectedRevenue = (float) $plan->lines()->sum('expected_revenue');
        $totalExpectedProfit = (float) $plan->lines()->sum('expected_profit');
        $totalLostRevenueDaily = (float) $plan->lines()->sum('lost_revenue_daily');
        $avgRoi = (float) $plan->lines()->whereNotNull('roi_percent')->avg('roi_percent');
        $avgTurnover = (float) $plan->lines()->whereNotNull('turnover_days')->avg('turnover_days');

        // Приоритет breakdown
        $priorityBreakdown = [
            'critical' => $plan->lines()->where('priority', 'critical')->count(),
            'high' => $plan->lines()->where('priority', 'high')->count(),
            'medium' => $plan->lines()->where('priority', 'medium')->count(),
            'low' => $plan->lines()->where('priority', 'low')->count(),
        ];

        // Тренд breakdown
        $trendBreakdown = [
            'growing' => $plan->lines()->where('sales_trend', 'growing')->count(),
            'stable' => $plan->lines()->where('sales_trend', 'stable')->count(),
            'declining' => $plan->lines()->where('sales_trend', 'declining')->count(),
        ];

        return response()->json([
            'message' => 'OK',
            'data' => [
                'plan' => $plan,
                'lines' => $lines,
                'summary' => [
                    'total_lines' => $plan->total_lines,
                    'total_qty' => $plan->total_qty,
                    'data_quality_score' => $plan->data_quality_score,
                    'data_quality_json' => $plan->data_quality_json,
                    'risk_breakdown' => [
                        'high' => $plan->lines()->where('risk_level', 'high')->count(),
                        'med' => $plan->lines()->where('risk_level', 'med')->count(),
                        'low' => $plan->lines()->where('risk_level', 'low')->count(),
                    ],
                    'priority_breakdown' => $priorityBreakdown,
                    'trend_breakdown' => $trendBreakdown,
                    'financials' => [
                        'total_supply_cost' => round($totalSupplyCost, 2),
                        'total_expected_revenue' => round($totalExpectedRevenue, 2),
                        'total_expected_profit' => round($totalExpectedProfit, 2),
                        'total_lost_revenue_daily' => round($totalLostRevenueDaily, 2),
                        'avg_roi_percent' => round($avgRoi, 2),
                        'avg_turnover_days' => round($avgTurnover, 1),
                    ],
                    'redistribution' => $plan->result_json['redistribution'] ?? [],
                ],
            ],
        ]);
    }

    private function paginateAggregatedPlanLines(AutoSupplyPlan $plan, $query, int $perPage)
    {
        $warehouseBreakdownMap = $this->buildWarehouseBreakdownMap($plan);
        $paginator = $this->aggregatedPlanLinesQuery($plan, $query)->paginate($perPage);
        $paginator->getCollection()->transform(function (AutoSupplyPlanLine $line) use ($plan, $warehouseBreakdownMap) {
            return $this->normalizeAggregatedPlanLine($plan, $line, $warehouseBreakdownMap);
        });

        return $paginator;
    }

    private function aggregatedPlanLinesQuery(AutoSupplyPlan $plan, $query)
    {
        $isOzon = $plan->marketplace === 'ozon';
        $clusterSelect = $isOzon ? 'cluster_id' : 'MAX(cluster_id) as cluster_id';
        $driver = \DB::connection()->getDriverName();
        $idSelect = 'MIN(id) as id';
        $planIdSelect = $driver === 'pgsql'
            ? 'MIN(auto_supply_plan_id::text) as auto_supply_plan_id'
            : 'MIN(auto_supply_plan_id) as auto_supply_plan_id';

        return $query
            ->selectRaw("
                {$idSelect},
                {$planIdSelect},
                sku,
                MAX(offer_id) as offer_id,
                MAX(product_name) as product_name,
                MAX(barcode) as barcode,
                MAX(warehouse_id) as warehouse_id,
                MAX(warehouse_name) as warehouse_name,
                {$clusterSelect},
                MAX(cluster_name) as cluster_name,
                MAX(region) as region,
                MAX(destination) as destination,
                MAX(destination_id) as destination_id,
                MAX(destination_type) as destination_type,
                SUM(qty_rounded) as qty_rounded,
                SUM(qty_recommended) as qty_recommended,
                SUM(current_stock) as current_stock,
                SUM(in_transit) as in_transit,
                SUM(sales_7_days) as sales_7_days,
                SUM(sales_14_days) as sales_14_days,
                SUM(sales_30_days) as sales_30_days,
                MAX(avg_daily_sales) as avg_daily_sales,
                MAX(ewma_daily_sales) as ewma_daily_sales,
                MAX(demand_daily) as demand_daily,
                MAX(cover_days_before) as cover_days_before,
                MAX(cover_days_after) as cover_days_after,
                MAX(oos_date) as oos_date,
                MAX(risk_level) as risk_level,
                MAX(priority) as priority,
                MAX(priority_score) as priority_score,
                MAX(sales_trend) as sales_trend,
                MAX(sales_trend_percent) as sales_trend_percent,
                MAX(price) as price,
                MAX(cost_price) as cost_price,
                SUM(supply_cost_estimate) as supply_cost_estimate,
                SUM(expected_revenue) as expected_revenue,
                SUM(expected_profit) as expected_profit,
                MAX(roi_percent) as roi_percent,
                MAX(turnover_days) as turnover_days,
                SUM(storage_cost_daily) as storage_cost_daily,
                SUM(storage_cost_monthly) as storage_cost_monthly,
                SUM(lost_revenue_daily) as lost_revenue_daily
            ")
            ->when($isOzon, fn ($q) => $q->groupBy('sku', 'cluster_id'), fn ($q) => $q->groupBy('sku'))
            ->orderByRaw("CASE MAX(risk_level) WHEN 'high' THEN 0 WHEN 'med' THEN 1 ELSE 2 END")
            ->orderByRaw('SUM(qty_rounded) DESC');
    }

    private function normalizeAggregatedPlanLine(
        AutoSupplyPlan $plan,
        AutoSupplyPlanLine $line,
        array $warehouseBreakdownMap = []
    ): AutoSupplyPlanLine
    {
        if ($plan->marketplace !== 'ozon') {
            return $line;
        }

        $key = $line->sku . '|' . ($line->cluster_id ?? 'no-cluster');
        if (isset($warehouseBreakdownMap[$key])) {
            $line->setAttribute('warehouse_breakdown', $warehouseBreakdownMap[$key]);
        } else {
            $line->setAttribute('warehouse_breakdown', []);
        }

        if ($line->cluster_id === null) {
            return $line;
        }

        $clusterName = $line->cluster_name
            ?: $line->destination
            ?: ($line->cluster_id ? 'Кластер ' . $line->cluster_id : null);

        $line->setAttribute('destination_type', 'cluster');
        $line->setAttribute('destination_id', 'cluster:' . $line->cluster_id);
        $line->setAttribute('destination', $clusterName);

        if ($clusterName) {
            $line->setAttribute('warehouse_id', 'cluster:' . $line->cluster_id);
            $line->setAttribute('warehouse_name', $clusterName);
            $line->setAttribute('cluster_name', $clusterName);
        }

        return $line;
    }

    private function buildWarehouseBreakdownMap(AutoSupplyPlan $plan): array
    {
        if ($plan->marketplace !== 'ozon') {
            return [];
        }

        $planKeys = $plan->lines()
            ->whereNotNull('cluster_id')
            ->get(['sku', 'cluster_id']);

        if ($planKeys->isEmpty()) {
            return [];
        }

        $skus = $planKeys->pluck('sku')->filter()->unique()->values();
        $clusterIds = $planKeys->pluck('cluster_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();

        $inventoryBySkuAndWarehouse = [];
        InventoryWarehouse::query()
            ->where('integration_id', $plan->integration_id)
            ->where('marketplace', 'ozon')
            ->whereIn('sku', $skus)
            ->get(['sku', 'warehouse_id', 'warehouse_name', 'quantity', 'in_transit', 'sales_7_days', 'sales_30_days'])
            ->each(function (InventoryWarehouse $warehouse) use (&$inventoryBySkuAndWarehouse) {
                if (! $warehouse->warehouse_name) {
                    return;
                }

                $key = $warehouse->sku . '|' . OzonWarehouseCluster::normalizeWarehouseName($warehouse->warehouse_name);
                $inventoryBySkuAndWarehouse[$key] ??= [
                    'warehouse_id' => $warehouse->warehouse_id,
                    'warehouse_name' => $warehouse->warehouse_name,
                    'current_stock' => 0,
                    'in_transit' => 0,
                    'sales_7_days' => 0,
                    'sales_30_days' => 0,
                ];

                $inventoryBySkuAndWarehouse[$key]['current_stock'] += (int) $warehouse->quantity;
                $inventoryBySkuAndWarehouse[$key]['in_transit'] += (int) $warehouse->in_transit;
                $inventoryBySkuAndWarehouse[$key]['sales_7_days'] += (int) $warehouse->sales_7_days;
                $inventoryBySkuAndWarehouse[$key]['sales_30_days'] += (int) $warehouse->sales_30_days;
            });

        $clusterWarehouses = [];
        foreach ($clusterIds as $clusterId) {
            $clusterWarehouses[$clusterId] = OzonWarehouseCluster::getWarehousesByCluster($clusterId)
                ->filter(fn (OzonWarehouseCluster $warehouse) => $this->isRegularOzonClusterWarehouse($warehouse))
                ->map(fn (OzonWarehouseCluster $warehouse) => [
                    'warehouse_id' => null,
                    'warehouse_name' => $warehouse->warehouse_name,
                    'warehouse_name_normalized' => $warehouse->warehouse_name_normalized
                        ?: OzonWarehouseCluster::normalizeWarehouseName($warehouse->warehouse_name),
                ])
                ->values()
                ->all();
        }

        $map = [];
        foreach ($planKeys as $line) {
            $clusterId = (int) $line->cluster_id;
            $key = $line->sku . '|' . $clusterId;
            $map[$key] = [];

            foreach ($clusterWarehouses[$clusterId] ?? [] as $warehouse) {
                $inventoryKey = $line->sku . '|' . $warehouse['warehouse_name_normalized'];
                $inventory = $inventoryBySkuAndWarehouse[$inventoryKey] ?? null;

                $map[$key][] = [
                    'warehouse_id' => $inventory['warehouse_id'] ?? $warehouse['warehouse_id'],
                    'warehouse_name' => $inventory['warehouse_name'] ?? $warehouse['warehouse_name'],
                    'current_stock' => (int) ($inventory['current_stock'] ?? 0),
                    'in_transit' => (int) ($inventory['in_transit'] ?? 0),
                    'sales_7_days' => (int) ($inventory['sales_7_days'] ?? 0),
                    'sales_30_days' => (int) ($inventory['sales_30_days'] ?? 0),
                    'has_inventory' => $inventory !== null,
                ];
            }

            usort($map[$key], fn ($a, $b) => ($b['current_stock'] + $b['in_transit']) <=> ($a['current_stock'] + $a['in_transit']));
        }

        return $map;
    }

    private function isRegularOzonClusterWarehouse(OzonWarehouseCluster $warehouse): bool
    {
        if ($warehouse->is_negabarit || $warehouse->is_jewelry) {
            return false;
        }

        $name = OzonWarehouseCluster::normalizeWarehouseName($warehouse->warehouse_name);

        foreach (['АПТЕКА', 'ФОТОСТУДИЯ', 'ВОЗВРАТ', 'КГТ', 'ПАЛЛЕТ'] as $specialMarker) {
            if (str_contains($name, $specialMarker)) {
                return false;
            }
        }

        return true;
    }

    /**
     * GET /api/auto-supply-plans/{id}/clusters
     * Агрегация строк плана по кластерам доставки (гео-распределение)
     */
    public function clusters(string $id): JsonResponse
    {
        $plan = AutoSupplyPlan::findOrFail($id);

        $lines = $plan->lines()->get();

        $clusters = [];
        $unclustered = ['cluster_id' => null, 'cluster_name' => 'Без кластера', 'region' => null, 'warehouses' => [], 'total_qty' => 0, 'total_skus' => 0, 'total_supply_cost' => 0, 'skus' => []];

        foreach ($lines as $line) {
            $cid = $line->cluster_id;
            if ($cid) {
                if (! isset($clusters[$cid])) {
                    $clusters[$cid] = [
                        'cluster_id' => $cid,
                        'cluster_name' => $line->cluster_name,
                        'region' => $line->region,
                        'warehouses' => [],
                        'total_qty' => 0,
                        'total_skus' => 0,
                        'total_supply_cost' => 0,
                        'skus' => [],
                    ];
                }
                $clusters[$cid]['total_qty'] += $line->qty_rounded;
                $clusters[$cid]['total_supply_cost'] += (float) ($line->supply_cost_estimate ?? 0);
                $clusters[$cid]['skus'][$line->sku] = true;
                if ($line->warehouse_name && ! in_array($line->warehouse_name, $clusters[$cid]['warehouses'])) {
                    $clusters[$cid]['warehouses'][] = $line->warehouse_name;
                }
            } else {
                $unclustered['total_qty'] += $line->qty_rounded;
                $unclustered['total_supply_cost'] += (float) ($line->supply_cost_estimate ?? 0);
                $unclustered['skus'][$line->sku] = true;
                if ($line->warehouse_name && ! in_array($line->warehouse_name, $unclustered['warehouses'])) {
                    $unclustered['warehouses'][] = $line->warehouse_name;
                }
            }
        }

        // Finalize SKU counts
        foreach ($clusters as &$c) {
            $c['total_skus'] = count($c['skus']);
            $c['total_supply_cost'] = round($c['total_supply_cost'], 2);
            unset($c['skus']);
        }
        $unclustered['total_skus'] = count($unclustered['skus']);
        $unclustered['total_supply_cost'] = round($unclustered['total_supply_cost'], 2);
        unset($unclustered['skus']);

        // Sort by total_qty desc
        $result = array_values($clusters);
        usort($result, fn ($a, $b) => $b['total_qty'] <=> $a['total_qty']);

        if ($unclustered['total_qty'] > 0) {
            $result[] = $unclustered;
        }

        return response()->json([
            'message' => 'OK',
            'data' => [
                'plan_id' => $plan->id,
                'marketplace' => $plan->marketplace,
                'clusters' => $result,
                'total_clusters' => count($clusters),
            ],
        ]);
    }

    /**
     * GET /api/auto-supply-plans/data-health?integration_id=X
     * Lightweight freshness metadata for the plan details screen.
     */
    public function dataHealth(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required|integer',
        ]);

        $integrationAccess = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $request->input('integration_id'));

        if (! ($integrationAccess['success'] ?? false)) {
            return response()->json([
                'message' => $integrationAccess['message'] ?? 'Интеграция не найдена',
            ], $integrationAccess['status'] ?? 404);
        }

        /** @var Integration $integration */
        $integration = $integrationAccess['integration'];

        $inventoryLastUpdated = InventoryWarehouse::query()
            ->where('integration_id', $integration->id)
            ->where('marketplace', $integration->marketplace)
            ->max('last_updated');

        return response()->json([
            'message' => 'OK',
            'data' => [
                'integration_id' => $integration->id,
                'marketplace' => $integration->marketplace,
                'freshness' => [
                    'inventory_warehouses_last_updated' => $inventoryLastUpdated,
                    'last_inventory_sync_completed_at' => null,
                ],
                'ozon_delivery_analytics_cache_active' => null,
            ],
        ]);
    }

    /**
     * GET /api/auto-supply-plans/warehouses?integration_id=X
     * Список складов интеграции с количеством SKU и остатками
     */
    public function warehouses(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required|integer',
        ]);

        $integrationAccess = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $request->input('integration_id'));

        if (! ($integrationAccess['success'] ?? false)) {
            return response()->json([
                'message' => $integrationAccess['message'] ?? 'Интеграция не найдена',
            ], $integrationAccess['status'] ?? 404);
        }

        /** @var Integration $integration */
        $integration = $integrationAccess['integration'];
        $integrationId = $integration->id;

        $warehouses = \App\Models\InventoryWarehouse::where('integration_id', $integrationId)
            ->when(
                in_array($integration->marketplace, ['yandex', 'yandex_market'], true),
                fn ($q) => $q->whereIn('marketplace', ['yandex', 'yandex_market']),
                fn ($q) => $q->where('marketplace', $integration->marketplace)
            )
            ->selectRaw('warehouse_id, warehouse_name, COUNT(DISTINCT sku) as sku_count, SUM(quantity) as total_stock, SUM(sales_30_days) as total_sales_30d, SUM(sales_7_days) as total_sales_7d, AVG(storage_cost_per_day) as avg_storage_cost_daily, SUM(storage_fee_total) as total_storage_fee')
            ->groupBy('warehouse_id', 'warehouse_name')
            ->orderByDesc(\DB::raw('SUM(quantity)'))
            ->get();

        // Добавляем кластер для Ozon (безопасно — таблица может не существовать)
        $clusterMapping = [];
        if ($integration->marketplace === 'ozon') {
            try {
                $clusterMapping = OzonWarehouseCluster::getAllMapping();
            } catch (\Exception $e) {
                // Таблица ozon_warehouse_clusters может не существовать — не критично
            }
        }

        $result = $warehouses->map(function ($wh) use ($clusterMapping) {
            $clusterId = null;
            $clusterName = null;
            $region = null;

            if (! empty($clusterMapping) && $wh->warehouse_name) {
                try {
                    $normalizedName = OzonWarehouseCluster::normalizeWarehouseName($wh->warehouse_name);
                    if (isset($clusterMapping[$normalizedName])) {
                        $clusterId = $clusterMapping[$normalizedName]['cluster_id'];
                        $clusterName = $clusterMapping[$normalizedName]['cluster_name'];
                        $region = $clusterMapping[$normalizedName]['region'];
                    }
                } catch (\Exception $e) {
                }
            }

            // Рассчитываем оборачиваемость
            $avgDailySales = ($wh->total_sales_30d ?? 0) / 30;
            $turnoverDays = $avgDailySales > 0 ? round(($wh->total_stock ?? 0) / $avgDailySales, 1) : null;
            $storageCostDaily = round((float) ($wh->avg_storage_cost_daily ?? 0), 2);

            return [
                'warehouse_id' => $wh->warehouse_id,
                'warehouse_name' => $wh->warehouse_name,
                'cluster_id' => $clusterId,
                'cluster_name' => $clusterName,
                'region' => $region,
                'sku_count' => (int) $wh->sku_count,
                'total_stock' => (int) $wh->total_stock,
                'total_sales_30d' => (int) ($wh->total_sales_30d ?? 0),
                'total_sales_7d' => (int) ($wh->total_sales_7d ?? 0),
                'avg_daily_sales' => round($avgDailySales, 1),
                'turnover_days' => $turnoverDays,
                'storage_cost_daily' => $storageCostDaily,
                'total_storage_fee' => round((float) ($wh->total_storage_fee ?? 0), 2),
            ];
        });

        if ($integration->marketplace === 'ozon') {
            $result = $result
                ->groupBy(fn (array $row) => $row['cluster_id'] !== null ? 'cluster:' . $row['cluster_id'] : 'warehouse:' . $row['warehouse_id'])
                ->map(function ($rows) {
                    $first = $rows->first();
                    $isCluster = $first['cluster_id'] !== null;

                    return [
                        'warehouse_id' => $isCluster ? 'cluster:' . $first['cluster_id'] : $first['warehouse_id'],
                        'warehouse_name' => $isCluster ? $first['cluster_name'] : $first['warehouse_name'],
                        'cluster_id' => $first['cluster_id'],
                        'cluster_name' => $first['cluster_name'],
                        'region' => $first['region'],
                        'destination_type' => $isCluster ? 'cluster' : 'warehouse',
                        'warehouses' => $rows->pluck('warehouse_name')->filter()->unique()->values()->all(),
                        'sku_count' => (int) $rows->sum('sku_count'),
                        'total_stock' => (int) $rows->sum('total_stock'),
                        'total_sales_30d' => (int) $rows->sum('total_sales_30d'),
                        'total_sales_7d' => (int) $rows->sum('total_sales_7d'),
                        'avg_daily_sales' => round((float) $rows->sum('avg_daily_sales'), 1),
                        'turnover_days' => $rows->sum('avg_daily_sales') > 0
                            ? round($rows->sum('total_stock') / max($rows->sum('avg_daily_sales'), 0.1), 1)
                            : null,
                        'storage_cost_daily' => round((float) $rows->sum('storage_cost_daily'), 2),
                        'total_storage_fee' => round((float) $rows->sum('total_storage_fee'), 2),
                    ];
                })
                ->sortByDesc('total_stock')
                ->values();
        }

        return response()->json([
            'message' => 'OK',
            'data' => $result->values(),
        ]);
    }

    /**
     * GET /api/auto-supply-plans/{id}/locality-impact
     * Влияние плана на локальность: before/after доля, экономия, timeline, топ offender-кластеров.
     */
    public function localityImpact(Request $request, string $id): JsonResponse
    {
        $plan = AutoSupplyPlan::with('integration')->findOrFail($id);

        $access = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $plan->integration_id);
        if (! ($access['success'] ?? false)) {
            return response()->json([
                'message' => $access['message'] ?? 'Доступ запрещён',
            ], $access['status'] ?? 403);
        }

        if ($plan->marketplace !== 'ozon') {
            return response()->json([
                'message' => 'Locality-анализ доступен только для Ozon-планов',
                'error' => 'not_ozon_plan',
            ], 422);
        }

        $summary = $plan->result_json['locality_summary'] ?? null;

        // Fallback: если план не содержит summary (старый план или сбой при расчёте) — строим on-the-fly.
        if ($summary === null) {
            try {
                $enricher = app(\App\Domains\Locality\Integration\LocalityEnrichmentService::class);
                $summary = $enricher->buildPlanSummary($plan);
            } catch (\Throwable $e) {
                return response()->json([
                    'message' => 'Не удалось построить Locality-анализ',
                    'error' => $e->getMessage(),
                ], 500);
            }
        }

        // Timeline 28 дней для графика
        $timeline = \App\Models\LocalityMetricDaily::query()
            ->where('integration_id', $plan->integration_id)
            ->where('period_days', 28)
            ->where('snapshot_date', '>=', now()->subDays(28)->toDateString())
            ->selectRaw('snapshot_date, SUM(orders_count) AS orders, SUM(local_orders_count) AS local_orders,
                         SUM(overpayment_amount) AS overpayment')
            ->groupBy('snapshot_date')
            ->orderBy('snapshot_date')
            ->get()
            ->map(fn ($row) => [
                'date' => $row->snapshot_date instanceof \Carbon\Carbon
                    ? $row->snapshot_date->toDateString()
                    : (string) $row->snapshot_date,
                'orders' => (int) $row->orders,
                'local_orders' => (int) $row->local_orders,
                'overpayment_rub' => (float) $row->overpayment,
                'local_share_percent' => (int) $row->orders > 0
                    ? round(((int) $row->local_orders / (int) $row->orders) * 100, 2)
                    : null,
            ])
            ->values()
            ->all();

        $enricher = app(\App\Domains\Locality\Integration\LocalityEnrichmentService::class);
        $narrative = $enricher->narrate($summary);

        return response()->json([
            'message' => 'Success',
            'data' => [
                'plan_id' => $plan->id,
                'summary' => $summary,
                'narrative' => $narrative,
                'timeline' => $timeline,
            ],
        ]);
    }

    /**
     * GET /api/auto-supply-plans/{id}/cluster-split
     * Группирует split-строки плана по target_cluster_id, выдаёт агрегат per-кластер.
     */
    public function clusterSplit(Request $request, string $id): JsonResponse
    {
        $plan = AutoSupplyPlan::findOrFail($id);
        $access = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $plan->integration_id);
        if (! ($access['success'] ?? false)) {
            return response()->json(['message' => $access['message'] ?? 'Доступ запрещён'], $access['status'] ?? 403);
        }

        $rows = $plan->lines()
            ->whereNotNull('cluster_id')
            ->selectRaw("
                cluster_id,
                MAX(cluster_name) AS cluster_name,
                COUNT(DISTINCT sku) AS sku_count,
                SUM(qty_rounded) AS total_qty,
                SUM(expected_savings_rub) AS total_savings,
                AVG(local_share_percent) AS avg_local_share,
                MIN(locality_confidence) AS min_confidence
            ")
            ->groupBy('cluster_id')
            ->orderByDesc('total_savings')
            ->get()
            ->map(fn ($row) => [
                'cluster_id' => (string) $row->cluster_id,
                'cluster_name' => (string) $row->cluster_name,
                'sku_count' => (int) $row->sku_count,
                'total_qty' => (int) $row->total_qty,
                'expected_savings_rub' => round((float) $row->total_savings, 2),
                'avg_local_share_percent' => $row->avg_local_share !== null
                    ? round((float) $row->avg_local_share, 2)
                    : null,
                'confidence' => (string) ($row->min_confidence ?? 'low'),
            ])
            ->values()
            ->all();

        return response()->json(['message' => 'Success', 'data' => $rows]);
    }

    /**
     * GET /api/auto-supply-plans/{id}/locality-recommendations
     * Список всех активных LocalityRecommendation для SKU из плана, с флагом in_plan.
     */
    public function localityRecommendations(Request $request, string $id): JsonResponse
    {
        $plan = AutoSupplyPlan::findOrFail($id);
        $access = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $plan->integration_id);
        if (! ($access['success'] ?? false)) {
            return response()->json(['message' => $access['message'] ?? 'Доступ запрещён'], $access['status'] ?? 403);
        }

        $skusInPlan = $plan->lines()->pluck('sku')->unique()->values();
        $linkedRecIds = collect($plan->lines()
            ->whereNotNull('linked_locality_recommendation_ids')
            ->pluck('linked_locality_recommendation_ids')
            ->all())
            ->flatMap(function ($raw) {
                $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
                return is_array($decoded) ? $decoded : [];
            })
            ->unique()
            ->values()
            ->all();

        $recs = \App\Models\LocalityRecommendation::query()
            ->where('integration_id', $plan->integration_id)
            ->whereIn('sku', $skusInPlan)
            ->where('state', \App\Models\LocalityRecommendation::STATE_NEW)
            ->orderByDesc('rank_score')
            ->get();

        $data = $recs->map(fn ($r) => [
            'id' => (int) $r->id,
            'sku' => (string) $r->sku,
            'target_cluster_id' => $r->target_cluster_id,
            'target_cluster_name' => (string) $r->target_cluster_name,
            'recommended_qty_units' => (int) $r->recommended_qty_units,
            'expected_savings_rub' => (float) $r->expected_savings_rub,
            'expected_local_share_uplift_pp' => (float) $r->expected_local_share_uplift_pp,
            'confidence' => (string) $r->confidence,
            'in_plan' => in_array((int) $r->id, array_map('intval', $linkedRecIds), true),
        ])->all();

        return response()->json(['message' => 'Success', 'data' => $data]);
    }

    /**
     * GET /api/auto-supply-plans/{id}/cluster-draft-preview
     * Превью: что создастся в Ozon, если нажать «Создать драфты по всем кластерам» (без API-вызова).
     */
    public function clusterDraftPreview(Request $request, string $id): JsonResponse
    {
        $plan = AutoSupplyPlan::findOrFail($id);
        $access = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $plan->integration_id);
        if (! ($access['success'] ?? false)) {
            return response()->json(['message' => $access['message'] ?? 'Доступ запрещён'], $access['status'] ?? 403);
        }

        $groups = $this->clusterDraftLinesQuery($plan)
            ->when($this->selectedPlanClusterIds($plan) !== [], function ($query) use ($plan) {
                $query->whereIn('cluster_id', $this->selectedPlanClusterIds($plan));
            })
            ->orderBy('cluster_id')
            ->get()
            ->groupBy('cluster_id')
            ->map(function ($linesInCluster) {
                $first = $linesInCluster->first();
                return [
                    'cluster_id' => (string) $first->cluster_id,
                    'cluster_name' => (string) $first->cluster_name,
                    'items' => $linesInCluster->map(fn ($l) => [
                        'sku' => (string) $l->sku,
                        'offer_id' => $l->offer_id,
                        'product_name' => $l->product_name,
                        'quantity' => (int) $l->qty_rounded,
                    ])->values()->all(),
                    'total_qty' => (int) $linesInCluster->sum('qty_rounded'),
                    'expected_savings_rub' => round((float) $linesInCluster->sum('expected_savings_rub'), 2),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'message' => 'Success',
            'data' => [
                'clusters' => $groups,
                'total_drafts' => count($groups),
                'total_qty' => array_sum(array_column($groups, 'total_qty')),
            ],
        ]);
    }

    /**
     * POST /api/auto-supply-plans/{id}/create-cluster-drafts
     * Batch: создаёт по одному Ozon FBO-draft на каждый target-кластер плана.
     */
    public function createClusterDrafts(Request $request, string $id): JsonResponse
    {
        $plan = AutoSupplyPlan::with('integration')->findOrFail($id);
        $access = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $plan->integration_id);
        if (! ($access['success'] ?? false)) {
            return response()->json(['message' => $access['message'] ?? 'Доступ запрещён'], $access['status'] ?? 403);
        }

        if ($plan->marketplace !== 'ozon') {
            return response()->json(['message' => 'Только для Ozon-планов', 'error' => 'not_ozon'], 422);
        }

        $groups = $this->clusterDraftLinesQuery($plan)
            ->when($this->selectedPlanClusterIds($plan) !== [], function ($query) use ($plan) {
                $query->whereIn('cluster_id', $this->selectedPlanClusterIds($plan));
            })
            ->get()
            ->groupBy('cluster_id');

        if ($groups->isEmpty()) {
            return response()->json([
                'message' => 'У плана нет строк поставки с привязкой к кластерам',
                'error' => 'no_cluster_lines',
            ], 422);
        }

        $applier = app(\App\Domains\Locality\Recommendation\LocalityDraftApplier::class);
        $results = ['drafts' => [], 'errors' => []];

        foreach ($groups as $clusterId => $lines) {
            $itemsByOzonSku = [];
            foreach ($lines as $line) {
                $product = \App\Models\Product::query()
                    ->where('integration_id', $plan->integration_id)
                    ->where('sku', $line->sku)
                    ->first();
                $ozonSku = $this->resolveOzonSku($product);
                if ($ozonSku <= 0) {
                    continue;
                }
                $qty = (int) $line->qty_rounded;
                if ($qty <= 0) {
                    continue;
                }
                $itemsByOzonSku[$ozonSku] = ($itemsByOzonSku[$ozonSku] ?? 0) + $qty;
            }

            $items = collect($itemsByOzonSku)
                ->map(fn (int $quantity, int $sku) => ['sku' => $sku, 'quantity' => $quantity])
                ->values()
                ->all();

            if (empty($items)) {
                $results['errors'][] = [
                    'cluster_id' => (string) $clusterId,
                    'error' => 'no_items_with_ozon_sku',
                ];
                continue;
            }

            try {
                $result = $applier->applyBatch($plan->integration, $items, (int) $clusterId);
                if (($result['success'] ?? false) && ($result['draft_id'] ?? null)) {
                    $results['drafts'][] = [
                        'cluster_id' => (string) $clusterId,
                        'cluster_name' => (string) $lines->first()->cluster_name,
                        'draft_id' => (string) $result['draft_id'],
                        'items_count' => count($items),
                        'total_qty' => array_sum(array_column($items, 'quantity')),
                    ];
                } else {
                    $results['errors'][] = [
                        'cluster_id' => (string) $clusterId,
                        'error' => $result['error'] ?? 'unknown',
                    ];
                }
            } catch (\Throwable $e) {
                $results['errors'][] = [
                    'cluster_id' => (string) $clusterId,
                    'error' => $e->getMessage(),
                ];
            }

            usleep(1_000_000); // rate-limit между кластерами
        }

        // Сохраним результат в план для истории
        $plan->result_json = array_merge($plan->result_json ?? [], ['cluster_drafts' => $results]);
        $plan->save();

        return response()->json([
            'message' => sprintf(
                'Создано %d draft(ов) из %d кластеров',
                count($results['drafts']),
                $groups->count()
            ),
            'data' => $results,
        ]);
    }

    /**
     * POST /api/auto-supply-plans/from-locality-recommendations
     * Создаёт автоплан с включённым locality split по активным рекомендациям.
     */
    public function createFromLocalityRecommendations(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'integration_id' => 'required|integer',
            'recommendation_ids' => 'nullable|array',
            'recommendation_ids.*' => 'integer',
            'mode' => 'nullable|string|in:anti_oos,balanced,cash_safe',
            'horizon_days' => 'nullable|integer|in:7,14,28,30,56,60,90',
            'min_cover_days' => 'nullable|integer|min:1|max:90',
            'target_cover_days' => 'nullable|integer|min:1|max:120',
            'max_cover_days' => 'nullable|integer|min:1|max:180',
            'safety_stock_days' => 'nullable|integer|min:0|max:30',
            'turnover_limit_days' => 'nullable|integer|min:1|max:365',
            'budget_limit' => 'nullable|numeric|min:0',
            'lead_time_days' => 'nullable|integer|min:0|max:30',
            'cluster_ids' => 'nullable|array',
            'cluster_ids.*' => 'integer',
        ])->validate();

        $integrationAccess = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $validated['integration_id']);

        if (! ($integrationAccess['success'] ?? false)) {
            return response()->json([
                'message' => $integrationAccess['message'] ?? 'Интеграция не найдена',
            ], $integrationAccess['status'] ?? 404);
        }

        /** @var Integration $integration */
        $integration = $integrationAccess['integration'];

        $recommendations = LocalityRecommendation::query()
            ->where('integration_id', $integration->id)
            ->where('state', LocalityRecommendation::STATE_NEW)
            ->when(! empty($validated['recommendation_ids'] ?? []), function ($query) use ($validated) {
                $query->whereIn('id', array_map('intval', $validated['recommendation_ids']));
            })
            ->whereNotNull('target_cluster_id')
            ->get();

        $clusterIds = collect($validated['cluster_ids'] ?? [])
            ->merge($recommendations->pluck('target_cluster_id'))
            ->map(fn ($clusterId) => (int) $clusterId)
            ->filter(fn (int $clusterId) => $clusterId > 0)
            ->unique()
            ->values()
            ->all();

        if ($clusterIds === []) {
            return response()->json([
                'message' => 'Нет активных locality-рекомендаций с целевыми кластерами',
                'error' => 'no_locality_recommendations',
            ], 422);
        }

        $request->merge([
            'cluster_ids' => $clusterIds,
            'split_by_cluster' => true,
            'include_locality_recommendations' => true,
            'locality_distribution_strategy' => 'recommendations',
            'locality_recommendation_ids' => $recommendations->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
        ]);

        return $this->createPlanFromRequest($request, $integration);
    }

    /**
     * POST /api/auto-supply-plans/preview-split-by-cluster
     * Лёгкий preview для UI перед созданием плана из locality recommendations.
     */
    public function previewSplitByCluster(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'integration_id' => 'required|integer',
            'recommendation_ids' => 'nullable|array',
            'recommendation_ids.*' => 'integer',
        ])->validate();

        $integrationAccess = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $validated['integration_id']);

        if (! ($integrationAccess['success'] ?? false)) {
            return response()->json([
                'message' => $integrationAccess['message'] ?? 'Интеграция не найдена',
            ], $integrationAccess['status'] ?? 404);
        }

        /** @var Integration $integration */
        $integration = $integrationAccess['integration'];

        $recommendations = LocalityRecommendation::query()
            ->where('integration_id', $integration->id)
            ->where('state', LocalityRecommendation::STATE_NEW)
            ->when(! empty($validated['recommendation_ids'] ?? []), function ($query) use ($validated) {
                $query->whereIn('id', array_map('intval', $validated['recommendation_ids']));
            })
            ->whereNotNull('target_cluster_id')
            ->orderByDesc('rank_score')
            ->get();

        $clusters = $recommendations
            ->groupBy('target_cluster_id')
            ->map(function ($items, $clusterId) {
                $first = $items->first();

                return [
                    'cluster_id' => (string) $clusterId,
                    'cluster_name' => (string) $first->target_cluster_name,
                    'recommendations_count' => $items->count(),
                    'total_qty' => (int) $items->sum('recommended_qty_units'),
                    'expected_savings_rub' => round((float) $items->sum('expected_savings_rub'), 2),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'message' => 'Success',
            'data' => [
                'clusters' => $clusters,
                'total_clusters' => count($clusters),
                'total_recommendations' => $recommendations->count(),
                'total_qty' => array_sum(array_column($clusters, 'total_qty')),
            ],
        ]);
    }

    /**
     * Строки, из которых можно сформировать Ozon FBO draft по кластерам.
     *
     * Поддерживает оба режима:
     * - старый locality split: is_cluster_split=true;
     * - новый Ozon auto-planning: destination_type=cluster.
     */
    private function clusterDraftLinesQuery(AutoSupplyPlan $plan)
    {
        return $plan->lines()
            ->whereNotNull('cluster_id')
            ->where('qty_rounded', '>', 0)
            ->where(function ($query) {
                $query
                    ->where('is_cluster_split', true)
                    ->orWhere('destination_type', 'cluster');
            });
    }

    /**
     * @return list<int>
     */
    private function selectedPlanClusterIds(AutoSupplyPlan $plan): array
    {
        return array_values(array_filter(
            array_map('intval', (array) ($plan->params['cluster_ids'] ?? [])),
            fn (int $clusterId) => $clusterId > 0
        ));
    }

    /**
     * Извлекает ozon_sku (числовой ID) из `products.ozon_data.sku`.
     */
    private function resolveOzonSku(?Product $product): int
    {
        if ($product === null) {
            return 0;
        }
        $ozonData = is_array($product->ozon_data ?? null) ? $product->ozon_data : [];
        $sku = $ozonData['sku'] ?? ($ozonData['product_id'] ?? null);
        return (int) ($sku ?? 0);
    }

    /**
     * DELETE /api/auto-supply-plans/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $plan = AutoSupplyPlan::findOrFail($id);
        $plan->lines()->delete();
        $plan->delete();

        return response()->json(['message' => 'План удалён']);
    }

    /**
     * PATCH /api/auto-supply-plans/{planId}/lines/{lineId}
     * Ручная корректировка количества в строке плана
     */
    public function updateLine(Request $request, string $planId, int $lineId): JsonResponse
    {
        $plan = AutoSupplyPlan::findOrFail($planId);

        if ($plan->status !== AutoSupplyPlan::STATUS_READY) {
            abort(422, 'План ещё не рассчитан');
        }

        $request->validate([
            'qty_rounded' => 'required|integer|min:0',
        ]);

        $line = $plan->lines()->findOrFail($lineId);
        $oldQty = $line->qty_rounded;
        $newQty = $request->input('qty_rounded');

        $line->update([
            'qty_rounded' => $newQty,
        ]);

        // Пересчитать total_qty плана
        $plan->update([
            'total_qty' => $plan->lines()->sum('qty_rounded'),
        ]);

        return response()->json([
            'message' => 'Количество обновлено',
            'data' => [
                'line' => $line->fresh(),
                'old_qty' => $oldQty,
                'new_qty' => $newQty,
                'plan_total_qty' => $plan->fresh()->total_qty,
            ],
        ]);
    }

    /**
     * GET /api/auto-supply-plans/{id}/export/ozon
     *
     * Колонки: "артикул", "имя (необязательно)", "количество"
     * Группировка: SUM(qty_rounded) по offer_id
     */
    public function exportOzon(string $id): StreamedResponse
    {
        $plan = AutoSupplyPlan::findOrFail($id);

        if ($plan->status !== AutoSupplyPlan::STATUS_READY) {
            abort(422, 'План ещё не рассчитан');
        }

        $lines = $plan->lines()->get();

        // Группируем по offer_id
        $grouped = [];
        foreach ($lines as $line) {
            $offerId = $line->offer_id ?? $line->sku;
            if (empty($offerId) || $line->qty_rounded <= 0) {
                continue;
            }
            if (! isset($grouped[$offerId])) {
                $grouped[$offerId] = [
                    'offer_id' => $offerId,
                    'name' => $line->product_name,
                    'qty' => 0,
                ];
            }
            $grouped[$offerId]['qty'] += $line->qty_rounded;
        }

        // Убираем строки с итоговым qty < 1
        $grouped = array_filter($grouped, fn ($item) => $item['qty'] >= 1);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Ozon Supply');

        // Заголовки строго по шаблону Ozon
        $sheet->setCellValue('A1', 'артикул');
        $sheet->setCellValue('B1', 'имя (необязательно)');
        $sheet->setCellValue('C1', 'количество');

        $row = 2;
        foreach ($grouped as $item) {
            $sheet->setCellValue("A{$row}", $item['offer_id']);
            $sheet->setCellValue("B{$row}", $item['name'] ?? '');
            $sheet->setCellValue("C{$row}", $item['qty']);
            $row++;
        }

        $filename = "ozon_supply_plan_{$plan->id}.xlsx";

        return $this->streamXlsx($spreadsheet, $filename);
    }

    /**
     * GET /api/auto-supply-plans/{id}/export/wb
     *
     * Колонки: "Баркод", "Количество"
     * Группировка: SUM(qty_rounded) по barcode
     * Ошибка если barcode null или дубли
     */
    public function exportWb(string $id): StreamedResponse|JsonResponse
    {
        $plan = AutoSupplyPlan::findOrFail($id);

        if ($plan->status !== AutoSupplyPlan::STATUS_READY) {
            abort(422, 'План ещё не рассчитан');
        }

        $lines = $plan->lines()->get();

        // Собираем все SKU для поиска barcode
        $skus = $lines->pluck('sku')->unique()->toArray();
        $products = Product::where('integration_id', $plan->integration_id)
            ->where('marketplace', 'wildberries')
            ->whereIn('sku', $skus)
            ->get(['sku', 'barcode'])
            ->keyBy('sku');

        $grouped = [];
        $errors = [];

        // Проверяем однозначность offer_id → barcode
        $skuToBarcodes = [];
        foreach ($lines as $line) {
            $product = $products->get($line->sku);
            $barcode = $product?->barcode ?? $line->barcode;
            if ($barcode) {
                $skuToBarcodes[$line->sku][$barcode] = true;
            }
        }

        foreach ($lines as $line) {
            if ($line->qty_rounded <= 0) {
                continue;
            }

            $product = $products->get($line->sku);
            $barcode = $product?->barcode ?? $line->barcode;

            if (empty($barcode)) {
                $errors[] = [
                    'sku' => $line->sku,
                    'product_name' => $line->product_name,
                    'error' => 'Баркод не найден',
                ];

                continue;
            }

            // Один offer_id → несколько баркодов (размеры)
            if (isset($skuToBarcodes[$line->sku]) && count($skuToBarcodes[$line->sku]) > 1) {
                $errors[] = [
                    'sku' => $line->sku,
                    'product_name' => $line->product_name,
                    'barcodes' => array_keys($skuToBarcodes[$line->sku]),
                    'error' => 'Несколько баркодов для одного SKU, нужна детализация',
                ];

                continue;
            }

            if (! isset($grouped[$barcode])) {
                $grouped[$barcode] = [
                    'barcode' => $barcode,
                    'qty' => 0,
                ];
            }
            $grouped[$barcode]['qty'] += $line->qty_rounded;
        }

        // Убираем строки с итоговым qty < 1
        $grouped = array_filter($grouped, fn ($item) => $item['qty'] >= 1);

        // Сохраняем ошибки в план
        if (! empty($errors)) {
            $plan->update(['export_errors' => $errors]);
        }

        if (empty($grouped)) {
            return response()->json([
                'message' => 'Нет данных для экспорта WB',
                'errors' => $errors,
            ], 422);
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('WB Supply');

        // Заголовки строго по шаблону WB
        $sheet->setCellValue('A1', 'Баркод');
        $sheet->setCellValue('B1', 'Количество');

        $row = 2;
        foreach ($grouped as $item) {
            $sheet->setCellValue("A{$row}", $item['barcode']);
            $sheet->setCellValue("B{$row}", $item['qty']);
            $row++;
        }

        $filename = "wb_supply_plan_{$plan->id}.xlsx";

        return $this->streamXlsx($spreadsheet, $filename);
    }

    /**
     * GET /api/auto-supply-plans/{id}/export/ozon-matrix
     *
     * Формат матрицы Ozon FBO: строки = артикулы, столбцы = склады
     * Шаблон для загрузки в ЛК Ozon (Поставки → Создать поставку)
     */
    public function exportOzonMatrix(string $id): StreamedResponse
    {
        $plan = AutoSupplyPlan::findOrFail($id);

        if ($plan->status !== AutoSupplyPlan::STATUS_READY) {
            abort(422, 'План ещё не рассчитан');
        }

        $lines = $plan->lines()
            ->where('qty_rounded', '>', 0)
            ->get();

        if ($lines->isEmpty()) {
            abort(422, 'Нет данных для экспорта');
        }

        // Собираем уникальные склады (сортируем по имени)
        $warehouseMap = [];
        foreach ($lines as $line) {
            $whName = $line->warehouse_name ?: $line->warehouse_id ?: 'Неизвестный';
            if (! isset($warehouseMap[$whName])) {
                $warehouseMap[$whName] = $whName;
            }
        }
        ksort($warehouseMap);
        $warehouseNames = array_values($warehouseMap);

        // Собираем матрицу: offer_id → warehouse_name → qty
        $matrix = [];
        $offerMeta = [];
        foreach ($lines as $line) {
            $offerId = $line->offer_id ?? $line->sku;
            $whName = $line->warehouse_name ?: $line->warehouse_id ?: 'Неизвестный';

            if (! isset($matrix[$offerId])) {
                $matrix[$offerId] = [];
                $offerMeta[$offerId] = $line->product_name;
            }
            $matrix[$offerId][$whName] = ($matrix[$offerId][$whName] ?? 0) + $line->qty_rounded;
        }

        // Сортируем артикулы
        ksort($matrix);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Поставка Ozon');

        // === Заголовки ===
        $sheet->setCellValue('A1', 'артикул');

        $colIndex = 2; // B = 2
        foreach ($warehouseNames as $whName) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex);
            $sheet->setCellValue("{$colLetter}1", $whName);
            // Автоширина
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
            $colIndex++;
        }

        // Стиль заголовков
        $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex - 1);
        $headerRange = "A1:{$lastColLetter}1";
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'size' => 10],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E8F5E9'],
            ],
            'borders' => [
                'bottom' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN],
            ],
        ]);
        $sheet->getColumnDimension('A')->setAutoSize(true);

        // === Данные ===
        $row = 2;
        foreach ($matrix as $offerId => $warehouseQty) {
            $sheet->setCellValue("A{$row}", $offerId);

            $colIndex = 2;
            foreach ($warehouseNames as $whName) {
                $qty = $warehouseQty[$whName] ?? 0;
                if ($qty > 0) {
                    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex);
                    $sheet->setCellValue("{$colLetter}{$row}", $qty);
                }
                $colIndex++;
            }
            $row++;
        }

        // Freeze header
        $sheet->freezePane('B2');

        $filename = "ozon_supply_matrix_{$plan->id}.xlsx";

        return $this->streamXlsx($spreadsheet, $filename);
    }

    /**
     * GET /api/auto-supply-plans/{id}/export/ozon-by-warehouse
     *
     * ZIP-архив с отдельными XLSX шаблонами по каждому складу (городу)
     * Каждый файл — шаблон Ozon FBO: "артикул", "имя (необязательно)", "количество"
     */
    public function exportOzonByWarehouse(string $id): StreamedResponse|JsonResponse
    {
        $plan = AutoSupplyPlan::findOrFail($id);

        if ($plan->status !== AutoSupplyPlan::STATUS_READY) {
            abort(422, 'План ещё не рассчитан');
        }

        $lines = $plan->lines()
            ->where('qty_rounded', '>', 0)
            ->get();

        if ($lines->isEmpty()) {
            return response()->json(['message' => 'Нет данных для экспорта'], 422);
        }

        // Группируем по складам
        $byWarehouse = [];
        foreach ($lines as $line) {
            $whName = $line->warehouse_name ?: $line->warehouse_id ?: 'Неизвестный';
            if (! isset($byWarehouse[$whName])) {
                $byWarehouse[$whName] = [];
            }
            $offerId = $line->offer_id ?? $line->sku;
            if (! isset($byWarehouse[$whName][$offerId])) {
                $byWarehouse[$whName][$offerId] = [
                    'offer_id' => $offerId,
                    'name' => $line->product_name,
                    'qty' => 0,
                ];
            }
            $byWarehouse[$whName][$offerId]['qty'] += $line->qty_rounded;
        }

        ksort($byWarehouse);

        // Создаём ZIP
        $tempFile = tempnam(sys_get_temp_dir(), 'supply_zip_');
        $zip = new \ZipArchive;
        $zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($byWarehouse as $whName => $items) {
            // Убираем строки с qty < 1
            $items = array_filter($items, fn ($item) => $item['qty'] >= 1);
            if (empty($items)) {
                continue;
            }

            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Поставка');

            $sheet->setCellValue('A1', 'артикул');
            $sheet->setCellValue('B1', 'имя (необязательно)');
            $sheet->setCellValue('C1', 'количество');

            // Стиль заголовков
            $sheet->getStyle('A1:C1')->applyFromArray([
                'font' => ['bold' => true, 'size' => 10],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E8F5E9'],
                ],
            ]);
            $sheet->getColumnDimension('A')->setAutoSize(true);
            $sheet->getColumnDimension('B')->setAutoSize(true);

            $row = 2;
            foreach ($items as $item) {
                $sheet->setCellValue("A{$row}", $item['offer_id']);
                $sheet->setCellValue("B{$row}", $item['name'] ?? '');
                $sheet->setCellValue("C{$row}", $item['qty']);
                $row++;
            }

            // Записываем XLSX во временный файл
            $tmpXlsx = tempnam(sys_get_temp_dir(), 'xlsx_');
            $writer = new Xlsx($spreadsheet);
            $writer->save($tmpXlsx);
            $spreadsheet->disconnectWorksheets();

            // Безопасное имя файла
            $safeName = preg_replace('/[^\p{L}\p{N}_\-\s]/u', '', $whName);
            $safeName = trim($safeName) ?: 'warehouse';
            $zip->addFile($tmpXlsx, "{$safeName}.xlsx");
        }

        $zip->close();

        $zipContent = file_get_contents($tempFile);
        @unlink($tempFile);

        // Удаляем временные xlsx файлы
        foreach (glob(sys_get_temp_dir().'/xlsx_*') as $f) {
            @unlink($f);
        }

        $filename = "ozon_supply_by_warehouse_{$plan->id}.zip";

        return new StreamedResponse(function () use ($zipContent) {
            echo $zipContent;
        }, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Content-Length' => strlen($zipContent),
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /**
     * Стрим XLSX файла
     */
    private function streamXlsx(Spreadsheet $spreadsheet, string $filename): StreamedResponse
    {
        return new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'max-age=0',
        ]);
    }
}
