<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AutoSupplyPlan\StoreAutoSupplyPlanRequest;
use App\Jobs\CalculateAutoSupplyPlanJob;
use App\Models\AutoSupplyPlan;
use App\Models\Integration;
use App\Models\OzonWarehouseCluster;
use App\Models\Product;
use App\Services\IntegrationAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AutoSupplyPlanController extends Controller
{
    public function __construct(
        private IntegrationAccessService $integrationAccessService
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
            ]),
        ]);

        CalculateAutoSupplyPlanJob::dispatch($plan->id);

        return response()->json([
            'message' => 'План создан, расчёт запущен',
            'data' => $plan->load('integration'),
        ], 201);
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

        // Агрегируем по SKU: суммируем qty_rounded, берём MAX для финансовых полей
        $aggregated = $query
            ->selectRaw('
                sku,
                MAX(offer_id) as offer_id,
                MAX(product_name) as product_name,
                MAX(barcode) as barcode,
                MAX(warehouse_id) as warehouse_id,
                MAX(warehouse_name) as warehouse_name,
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
            ')
            ->groupBy('sku')
            ->orderByRaw("CASE MAX(risk_level) WHEN 'high' THEN 0 WHEN 'med' THEN 1 ELSE 2 END")
            ->orderByRaw('SUM(qty_rounded) DESC');

        $perPage = $request->input('per_page', 50);
        $lines = $aggregated->paginate($perPage);

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
        // Агрегируем по SKU: одна строка на товар, qty суммируется по всем складам
        $lines = $plan->lines()
            ->selectRaw('
                sku,
                MAX(offer_id) as offer_id,
                MAX(product_name) as product_name,
                MAX(barcode) as barcode,
                MAX(warehouse_id) as warehouse_id,
                MAX(warehouse_name) as warehouse_name,
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
            ')
            ->groupBy('sku')
            ->orderByRaw("CASE MAX(risk_level) WHEN 'high' THEN 0 WHEN 'med' THEN 1 ELSE 2 END")
            ->orderByRaw('SUM(qty_rounded) DESC')
            ->paginate($perPage);

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

        $groups = $plan->lines()
            ->whereNotNull('cluster_id')
            ->where('is_cluster_split', true)
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

        $groups = $plan->lines()
            ->whereNotNull('cluster_id')
            ->where('is_cluster_split', true)
            ->get()
            ->groupBy('cluster_id');

        if ($groups->isEmpty()) {
            return response()->json([
                'message' => 'У плана нет split-строк с привязкой к кластерам',
                'error' => 'no_cluster_split',
            ], 422);
        }

        $applier = app(\App\Domains\Locality\Recommendation\LocalityDraftApplier::class);
        $results = ['drafts' => [], 'errors' => []];

        foreach ($groups as $clusterId => $lines) {
            $items = [];
            foreach ($lines as $line) {
                $product = \App\Models\Product::query()
                    ->where('integration_id', $plan->integration_id)
                    ->where('sku', $line->sku)
                    ->first();
                $ozonSku = $this->resolveOzonSku($product);
                if ($ozonSku <= 0) {
                    continue;
                }
                $items[] = ['sku' => $ozonSku, 'quantity' => (int) $line->qty_rounded];
            }

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
     * Создаёт новый план, seeded конкретным набором LocalityRecommendation.
     * Body: { integration_id, recommendation_ids[], base_params?: {mode, horizon_days, ...} }
     */
    public function createFromLocalityRecommendations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|integer|exists:integrations,id',
            'recommendation_ids' => 'required|array|min:1',
            'recommendation_ids.*' => 'integer',
            'base_params' => 'nullable|array',
        ]);

        $access = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $validated['integration_id']);
        if (! ($access['success'] ?? false)) {
            return response()->json(['message' => $access['message'] ?? 'Доступ запрещён'], $access['status'] ?? 403);
        }

        /** @var Integration $integration */
        $integration = $access['integration'];
        if ($integration->marketplace !== 'ozon') {
            return response()->json(['message' => 'Только Ozon', 'error' => 'not_ozon'], 422);
        }

        // Валидируем что все рекомендации принадлежат этой интеграции
        $recs = \App\Models\LocalityRecommendation::query()
            ->where('integration_id', $integration->id)
            ->whereIn('id', $validated['recommendation_ids'])
            ->get();
        if ($recs->count() !== count($validated['recommendation_ids'])) {
            return response()->json([
                'message' => 'Некоторые recommendation_id не принадлежат этой интеграции',
                'error' => 'invalid_recommendation_ids',
            ], 422);
        }

        $seedSkus = $recs->pluck('sku')->unique()->values()->all();
        $baseParams = array_merge([
            'target_days' => 28,
            'safety_days' => 5,
            'lead_time_days' => 7,
            'ewma_alpha' => 0.35,
            'split_by_cluster' => true,
            'locality_distribution_strategy' => \App\Domains\Locality\Integration\LocalityEnrichmentService::STRATEGY_RECOMMENDATIONS,
        ], $request->input('base_params', []));

        $baseParams['source'] = 'locality_recommendations';
        $baseParams['seed_recommendation_ids'] = $recs->pluck('id')->map(fn ($i) => (int) $i)->all();
        $baseParams['seed_skus'] = $seedSkus;

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => $integration->marketplace,
            'status' => AutoSupplyPlan::STATUS_PENDING,
            'mode' => $request->input('base_params.mode', 'balanced'),
            'horizon_days' => $baseParams['target_days'] ?? 28,
            'min_cover_days' => $request->input('base_params.min_cover_days', 7),
            'target_cover_days' => $baseParams['target_days'] ?? 28,
            'max_cover_days' => $request->input('base_params.max_cover_days', 42),
            'safety_stock_days' => $baseParams['safety_days'] ?? 5,
            'forecast_model' => 'EWMA_0.35',
            'algorithm_version' => 'asp-1.0.0',
            'params' => $baseParams,
        ]);

        CalculateAutoSupplyPlanJob::dispatch($plan->id);

        return response()->json([
            'message' => sprintf(
                'План создан на основе %d рекомендаций Locality (%d SKU). Расчёт запущен.',
                $recs->count(),
                count($seedSkus)
            ),
            'data' => $plan->load('integration'),
        ], 201);
    }

    /**
     * POST /api/auto-supply-plans/preview-split-by-cluster
     * Live-предпросмотр для CreatePlanDialog: сколько будет split-групп, ожидаемая локальность, экономия.
     * Body: { integration_id, min_confidence? }
     */
    public function previewSplitByCluster(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|integer|exists:integrations,id',
            'min_confidence' => 'nullable|string|in:low,medium,high',
        ]);

        $access = $this->integrationAccessService
            ->ensureAccessibleIntegration($request, (int) $validated['integration_id']);
        if (! ($access['success'] ?? false)) {
            return response()->json(['message' => $access['message'] ?? 'Доступ запрещён'], $access['status'] ?? 403);
        }

        $minConfidence = (string) ($validated['min_confidence'] ?? 'medium');
        $allowed = match ($minConfidence) {
            'low' => ['low', 'medium', 'high'],
            'high' => ['high'],
            default => ['medium', 'high'],
        };

        $recs = \App\Models\LocalityRecommendation::query()
            ->where('integration_id', $validated['integration_id'])
            ->where('state', \App\Models\LocalityRecommendation::STATE_NEW)
            ->whereIn('confidence', $allowed)
            ->get();

        $skuCount = $recs->pluck('sku')->unique()->count();
        $clustersCount = $recs->pluck('target_cluster_id')->filter()->unique()->count();
        $totalSavings = round((float) $recs->sum('expected_savings_rub'), 2);
        $avgUpliftPp = $recs->isNotEmpty()
            ? round((float) $recs->avg('expected_local_share_uplift_pp'), 2)
            : 0.0;

        return response()->json([
            'message' => 'Success',
            'data' => [
                'recommendations_count' => $recs->count(),
                'skus_count' => $skuCount,
                'clusters_count' => $clustersCount,
                'expected_savings_rub' => $totalSavings,
                'avg_local_share_uplift_pp' => $avgUpliftPp,
                'min_confidence_used' => $minConfidence,
            ],
        ]);
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
