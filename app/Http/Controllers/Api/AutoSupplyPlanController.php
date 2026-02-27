<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AutoSupplyPlan\StoreAutoSupplyPlanRequest;
use App\Jobs\CalculateAutoSupplyPlanJob;
use App\Models\AutoSupplyPlan;
use App\Models\AutoSupplyPlanLine;
use App\Models\Integration;
use App\Models\OzonWarehouseCluster;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AutoSupplyPlanController extends Controller
{
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
            $query->where('integration_id', $request->input('integration_id'));
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
        $integration = Integration::findOrFail($request->input('integration_id'));

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
                SUM(qty_rounded) as qty_rounded,
                SUM(qty_recommended) as qty_recommended,
                SUM(current_stock) as current_stock,
                SUM(in_transit) as in_transit,
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

        if (!$line) {
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

        $perPage = $request->input('per_page', 50);
        // Агрегируем по SKU: одна строка на товар, qty суммируется по всем складам
        $lines = $plan->lines()
            ->selectRaw('
                sku,
                MAX(offer_id) as offer_id,
                MAX(product_name) as product_name,
                MAX(barcode) as barcode,
                SUM(qty_rounded) as qty_rounded,
                SUM(qty_recommended) as qty_recommended,
                SUM(current_stock) as current_stock,
                SUM(in_transit) as in_transit,
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
                if (!isset($clusters[$cid])) {
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
                if ($line->warehouse_name && !in_array($line->warehouse_name, $clusters[$cid]['warehouses'])) {
                    $clusters[$cid]['warehouses'][] = $line->warehouse_name;
                }
            } else {
                $unclustered['total_qty'] += $line->qty_rounded;
                $unclustered['total_supply_cost'] += (float) ($line->supply_cost_estimate ?? 0);
                $unclustered['skus'][$line->sku] = true;
                if ($line->warehouse_name && !in_array($line->warehouse_name, $unclustered['warehouses'])) {
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
        usort($result, fn($a, $b) => $b['total_qty'] <=> $a['total_qty']);

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
            'integration_id' => 'required|integer|exists:integrations,id',
        ]);

        $integrationId = $request->input('integration_id');
        $integration = Integration::findOrFail($integrationId);

        $warehouses = \App\Models\InventoryWarehouse::where('integration_id', $integrationId)
            ->where('marketplace', $integration->marketplace)
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

            if (!empty($clusterMapping) && $wh->warehouse_name) {
                try {
                    $normalizedName = OzonWarehouseCluster::normalizeWarehouseName($wh->warehouse_name);
                    if (isset($clusterMapping[$normalizedName])) {
                        $clusterId = $clusterMapping[$normalizedName]['cluster_id'];
                        $clusterName = $clusterMapping[$normalizedName]['cluster_name'];
                        $region = $clusterMapping[$normalizedName]['region'];
                    }
                } catch (\Exception $e) {}
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
            if (!isset($grouped[$offerId])) {
                $grouped[$offerId] = [
                    'offer_id' => $offerId,
                    'name' => $line->product_name,
                    'qty' => 0,
                ];
            }
            $grouped[$offerId]['qty'] += $line->qty_rounded;
        }

        // Убираем строки с итоговым qty < 1
        $grouped = array_filter($grouped, fn($item) => $item['qty'] >= 1);

        $spreadsheet = new Spreadsheet();
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

            if (!isset($grouped[$barcode])) {
                $grouped[$barcode] = [
                    'barcode' => $barcode,
                    'qty' => 0,
                ];
            }
            $grouped[$barcode]['qty'] += $line->qty_rounded;
        }

        // Убираем строки с итоговым qty < 1
        $grouped = array_filter($grouped, fn($item) => $item['qty'] >= 1);

        // Сохраняем ошибки в план
        if (!empty($errors)) {
            $plan->update(['export_errors' => $errors]);
        }

        if (empty($grouped)) {
            return response()->json([
                'message' => 'Нет данных для экспорта WB',
                'errors' => $errors,
            ], 422);
        }

        $spreadsheet = new Spreadsheet();
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
            if (!isset($warehouseMap[$whName])) {
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

            if (!isset($matrix[$offerId])) {
                $matrix[$offerId] = [];
                $offerMeta[$offerId] = $line->product_name;
            }
            $matrix[$offerId][$whName] = ($matrix[$offerId][$whName] ?? 0) + $line->qty_rounded;
        }

        // Сортируем артикулы
        ksort($matrix);

        $spreadsheet = new Spreadsheet();
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
            if (!isset($byWarehouse[$whName])) {
                $byWarehouse[$whName] = [];
            }
            $offerId = $line->offer_id ?? $line->sku;
            if (!isset($byWarehouse[$whName][$offerId])) {
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
        $zip = new \ZipArchive();
        $zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($byWarehouse as $whName => $items) {
            // Убираем строки с qty < 1
            $items = array_filter($items, fn($item) => $item['qty'] >= 1);
            if (empty($items)) continue;

            $spreadsheet = new Spreadsheet();
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
        foreach (glob(sys_get_temp_dir() . '/xlsx_*') as $f) {
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
