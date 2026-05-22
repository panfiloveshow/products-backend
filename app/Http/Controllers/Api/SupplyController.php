<?php

namespace App\Http\Controllers\Api;

use App\Domains\Ozon\OzonMarketplace;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\Supply;
use App\Models\SupplyAnalytics;
use App\Models\SupplyRecommendation;
use App\Models\SupplySettings;
use App\Models\WarehouseSlot;
use App\Services\Supply\LegacySupplyRecommendationService;
use App\Services\Supply\SupplyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Контроллер модуля «Поставки Ozon»
 * 
 * Endpoints:
 * - Рекомендации: список, принять, отклонить, отложить
 * - Поставки: создать, список, детали, действия
 * - Слоты: список, бронирование
 * - Настройки: получить, обновить
 */
class SupplyController extends Controller
{
    public function __construct(
        protected LegacySupplyRecommendationService $recommendationService,
        protected SupplyService $supplyService
    ) {}

    // ========================================================================
    // РЕКОМЕНДАЦИИ
    // ========================================================================

    /**
     * Получить список рекомендаций
     * 
     * GET /api/supplies/recommendations
     */
    public function getRecommendations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'state' => 'nullable|in:new,accepted,rejected,postponed,in_plan,in_supply,completed,expired',
            'priority' => 'nullable|in:A,B,C',
            'oos_risk' => 'nullable|boolean',
            'cluster_id' => 'nullable|string',
            'warehouse_id' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = SupplyRecommendation::where('integration_id', $validated['integration_id']);

        if (isset($validated['state'])) {
            $query->where('state', $validated['state']);
        } else {
            $query->active();
        }

        if (isset($validated['priority'])) {
            $query->where('priority', $validated['priority']);
        }

        if (isset($validated['oos_risk']) && $validated['oos_risk']) {
            $query->oosRisk();
        }

        if (isset($validated['cluster_id'])) {
            $query->forCluster($validated['cluster_id']);
        }

        if (isset($validated['warehouse_id'])) {
            $query->forWarehouse($validated['warehouse_id']);
        }

        $recommendations = $query
            ->orderByDesc('priority_score')
            ->orderBy('days_of_stock')
            ->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'success' => true,
            'data' => $recommendations,
        ]);
    }

    /**
     * Рассчитать рекомендации
     * 
     * POST /api/supplies/recommendations/calculate
     */
    public function calculateRecommendations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'cluster_id' => 'nullable|string',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);

            if ($integration->marketplace !== 'ozon') {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'MARKETPLACE_NOT_SUPPORTED',
                        'message' => 'Доступно только для Ozon',
                    ],
                ], 422);
            }

            $recommendations = $this->recommendationService->calculateRecommendations(
                $integration,
                $validated['cluster_id'] ?? null
            );

            $saved = $this->recommendationService->saveRecommendations($recommendations);

            Log::info('Supply recommendations calculated', [
                'integration_id' => $integration->id,
                'calculated' => $recommendations->count(),
                'saved' => $saved,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'calculated' => $recommendations->count(),
                    'saved' => $saved,
                ],
                'message' => "Рассчитано {$recommendations->count()} рекомендаций",
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to calculate recommendations', [
                'error' => $e->getMessage(),
                'integration_id' => $validated['integration_id'],
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Получить все склады для SKU
     * 
     * GET /api/supplies/recommendations/by-sku/{sku}
     * 
     * Возвращает ВСЕ склады для указанного SKU, включая те где не нужна поставка.
     * Это позволяет видеть полную картину распределения товара по складам.
     */
    public function getRecommendationsBySku(Request $request, string $sku): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
        ]);

        $recommendations = SupplyRecommendation::where('integration_id', $validated['integration_id'])
            ->where('sku', $sku)
            ->orderByDesc('current_stock')
            ->get();

        // Агрегированные данные по SKU
        $summary = [
            'sku' => $sku,
            'total_warehouses' => $recommendations->count(),
            'total_stock' => $recommendations->sum('current_stock'),
            'total_recommended_qty' => $recommendations->sum('recommended_qty'),
            'warehouses_need_supply' => $recommendations->where('recommended_qty', '>', 0)->count(),
            'warehouses_ok' => $recommendations->where('recommended_qty', 0)->count(),
            'avg_days_of_stock' => round($recommendations->avg('days_of_stock'), 1),
            'min_days_of_stock' => $recommendations->min('days_of_stock'),
            'max_days_of_stock' => $recommendations->max('days_of_stock'),
        ];

        return response()->json([
            'success' => true,
            'data' => $recommendations,
            'summary' => $summary,
        ]);
    }

    /**
     * Получить сводку по всем SKU
     * 
     * GET /api/supplies/recommendations/summary
     * 
     * Возвращает агрегированные данные по каждому SKU:
     * - Общий остаток по ВСЕМ складам
     * - Минимальные дни до OOS
     * - Количество складов
     * - Сколько нужно к поставке
     */
    public function getRecommendationsSummary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
        ]);

        // Получаем все рекомендации и группируем по SKU
        $recommendations = SupplyRecommendation::where('integration_id', $validated['integration_id'])
            ->whereIn('state', ['new', 'accepted', 'postponed'])
            ->get();

        $summary = [];
        $grouped = $recommendations->groupBy('sku');

        foreach ($grouped as $sku => $recs) {
            $summary[$sku] = [
                'sku' => $sku,
                'product_name' => $recs->first()->product_name,
                'total_warehouses' => $recs->count(),
                'total_stock' => $recs->sum('current_stock'),
                'total_in_transit' => $recs->sum('in_transit'),
                'total_recommended_qty' => $recs->sum('recommended_qty'),
                'warehouses_need_supply' => $recs->where('recommended_qty', '>', 0)->count(),
                'warehouses_ok' => $recs->where('recommended_qty', 0)->count(),
                'min_days_of_stock' => $recs->min('days_of_stock'),
                'max_days_of_stock' => $recs->max('days_of_stock'),
                'avg_days_of_stock' => round($recs->avg('days_of_stock'), 1),
                'has_oos_risk' => $recs->where('oos_risk', true)->count() > 0,
                'max_priority' => $recs->sortBy(function($r) {
                    return ['A' => 1, 'B' => 2, 'C' => 3][$r->priority] ?? 4;
                })->first()->priority ?? 'C',
            ];
        }

        return response()->json([
            'success' => true,
            'data' => array_values($summary),
            'total_skus' => count($summary),
        ]);
    }

    /**
     * Получить данные для карты кластеров
     * 
     * GET /api/supplies/recommendations/map
     * 
     * Возвращает кластеры с координатами и агрегированными данными по складам
     */
    public function getRecommendationsMap(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'sku' => 'nullable|string', // Фильтр по конкретному SKU
        ]);

        // Получаем все кластеры с координатами
        $clusters = \App\Models\OzonWarehouseCluster::select(
            'cluster_id',
            'cluster_name',
            'region',
            'latitude',
            'longitude'
        )
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->groupBy('cluster_id', 'cluster_name', 'region', 'latitude', 'longitude')
            ->get()
            ->keyBy('cluster_id');

        // Получаем рекомендации
        $query = SupplyRecommendation::where('integration_id', $validated['integration_id'])
            ->whereIn('state', ['new', 'accepted', 'postponed']);

        if (!empty($validated['sku'])) {
            $query->where('sku', $validated['sku']);
        }

        $recommendations = $query->get();

        // Группируем по кластерам
        $clusterData = [];
        foreach ($recommendations as $rec) {
            $clusterId = $rec->delivery_cluster_id;
            if (!$clusterId) continue;

            if (!isset($clusterData[$clusterId])) {
                $cluster = $clusters->get($clusterId);
                $clusterData[$clusterId] = [
                    'cluster_id' => $clusterId,
                    'cluster_name' => $rec->delivery_cluster_name ?? $cluster?->cluster_name ?? "Кластер {$clusterId}",
                    'region' => $cluster?->region,
                    'latitude' => $cluster?->latitude,
                    'longitude' => $cluster?->longitude,
                    'total_stock' => 0,
                    'total_recommended_qty' => 0,
                    'total_lost_revenue' => 0,
                    'total_ozon_lost_profit' => 0,
                    'warehouses_count' => 0,
                    'warehouses_need_supply' => 0,
                    'has_oos_risk' => false,
                    'skus_count' => 0,
                    'warehouses' => [],
                ];
            }

            $clusterData[$clusterId]['total_stock'] += $rec->current_stock ?? 0;
            $clusterData[$clusterId]['total_recommended_qty'] += $rec->recommended_qty ?? 0;
            $clusterData[$clusterId]['total_lost_revenue'] += $rec->lost_revenue_potential ?? 0;
            $clusterData[$clusterId]['total_ozon_lost_profit'] += $rec->ozon_lost_profit ?? 0;
            $clusterData[$clusterId]['warehouses_count']++;
            if (($rec->recommended_qty ?? 0) > 0) {
                $clusterData[$clusterId]['warehouses_need_supply']++;
            }
            if ($rec->oos_risk) {
                $clusterData[$clusterId]['has_oos_risk'] = true;
            }

            // Добавляем склад в список
            $clusterData[$clusterId]['warehouses'][] = [
                'warehouse_name' => $rec->warehouse_name,
                'sku' => $rec->sku,
                'current_stock' => $rec->current_stock,
                'days_of_stock' => $rec->days_of_stock,
                'recommended_qty' => $rec->recommended_qty,
                'ozon_recommended_supply' => $rec->ozon_recommended_supply,
                'ozon_lost_profit' => $rec->ozon_lost_profit,
                'lost_revenue_potential' => $rec->lost_revenue_potential,
                'priority' => $rec->priority,
                'oos_risk' => $rec->oos_risk,
            ];
        }

        // Подсчитываем уникальные SKU в каждом кластере
        foreach ($clusterData as $clusterId => &$data) {
            $data['skus_count'] = collect($data['warehouses'])->pluck('sku')->unique()->count();
        }

        // Определяем статус кластера
        foreach ($clusterData as $clusterId => &$data) {
            if ($data['has_oos_risk']) {
                $data['status'] = 'critical'; // Красный - есть риск OOS
            } elseif ($data['warehouses_need_supply'] > 0) {
                $data['status'] = 'warning'; // Оранжевый - нужна поставка
            } else {
                $data['status'] = 'ok'; // Зеленый - всё в порядке
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'clusters' => array_values($clusterData),
                'total_clusters' => count($clusterData),
                'summary' => [
                    'total_stock' => collect($clusterData)->sum('total_stock'),
                    'total_recommended_qty' => collect($clusterData)->sum('total_recommended_qty'),
                    'total_lost_revenue' => collect($clusterData)->sum('total_lost_revenue'),
                    'clusters_with_oos_risk' => collect($clusterData)->where('has_oos_risk', true)->count(),
                    'clusters_need_supply' => collect($clusterData)->where('warehouses_need_supply', '>', 0)->count(),
                ],
            ],
        ]);
    }

    /**
     * Получить список складов для карты с полными данными
     * 
     * GET /api/supplies/recommendations/map-warehouses
     *
     * Возвращает склады с координатами, агрегированными метриками и списком рекомендаций
     */
    public function getRecommendationsMapWarehouses(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'sku' => 'nullable|string',
            'state' => 'nullable|string',
            'priority' => 'nullable|string',
            'oos_risk' => 'nullable|boolean',
        ]);

        $query = SupplyRecommendation::where('integration_id', $validated['integration_id'])
            ->whereIn('state', ['new', 'accepted', 'postponed']);

        if (!empty($validated['sku'])) {
            $query->where('sku', $validated['sku']);
        }
        if (!empty($validated['state']) && $validated['state'] !== 'all') {
            $query->where('state', $validated['state']);
        }
        if (!empty($validated['priority']) && $validated['priority'] !== 'all') {
            $query->where('priority', $validated['priority']);
        }
        if (isset($validated['oos_risk']) && $validated['oos_risk']) {
            $query->where('oos_risk', true);
        }

        $recommendations = $query->get();
        $mapping = \App\Models\OzonWarehouseCluster::getAllMapping();

        // Группируем рекомендации по складам
        $warehouseGroups = $recommendations->groupBy(function ($rec) {
            return ($rec->warehouse_id ?? '') . '|' . ($rec->warehouse_name ?? '');
        });

        $warehouses = $warehouseGroups->map(function ($recs, $key) use ($mapping) {
            $first = $recs->first();
            $normalized = \App\Models\OzonWarehouseCluster::normalizeWarehouseName($first->warehouse_name ?? '');
            $mapItem = $mapping[$normalized] ?? null;

            // Агрегируем метрики по складу
            $totalStock = $recs->sum('current_stock');
            $totalInTransit = $recs->sum('in_transit');
            $minDaysOfStock = $recs->min('days_of_stock') ?? 0;
            $maxDaysOfStock = $recs->max('days_of_stock') ?? 0;
            $totalRecommendedQty = $recs->sum('recommended_qty');
            $hasOosRisk = $recs->contains('oos_risk', true);
            $criticalCount = $recs->where('priority', 'A')->count();

            // Формируем список рекомендаций для склада
            $recList = $recs->map(function ($rec) {
                return [
                    'id' => $rec->id,
                    'sku' => $rec->sku,
                    'product_name' => $rec->product_name,
                    'current_stock' => $rec->current_stock,
                    'in_transit' => $rec->in_transit,
                    'recommended_qty' => $rec->recommended_qty,
                    'days_of_stock' => $rec->days_of_stock,
                    'oos_risk' => $rec->oos_risk,
                    'priority' => $rec->priority,
                    'state' => $rec->state,
                    'avg_daily_sales' => $rec->avg_sales_used,
                    'price' => $rec->price,
                ];
            })->values();

            return [
                'warehouse_id' => $first->warehouse_id,
                'warehouse_name' => $first->warehouse_name,
                'cluster_name' => $first->cluster_name ?? $first->delivery_cluster_name ?? $mapItem['cluster_name'] ?? null,
                'lat' => $mapItem['lat'] ?? null,
                'lng' => $mapItem['lng'] ?? null,
                'is_hub' => $mapItem['is_hub'] ?? false,
                'total_stock' => $totalStock,
                'total_in_transit' => $totalInTransit,
                'min_days_of_stock' => $minDaysOfStock,
                'max_days_of_stock' => $maxDaysOfStock,
                'total_recommended_qty' => $totalRecommendedQty,
                'has_oos_risk' => $hasOosRisk,
                'critical_count' => $criticalCount,
                'recommendations_count' => $recs->count(),
                'recommendations' => $recList,
            ];
        })->values();

        // Общая статистика
        $stats = [
            'total_warehouses' => $warehouses->count(),
            'total_recommendations' => $recommendations->count(),
            'total_to_supply' => $recommendations->sum('recommended_qty'),
            'critical_count' => $recommendations->where('priority', 'A')->count(),
            'oos_risk_count' => $recommendations->where('oos_risk', true)->count(),
            'unique_skus' => $recommendations->pluck('sku')->unique()->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $warehouses,
            'stats' => $stats,
        ]);
    }

    /**
     * Принять рекомендацию
     * 
     * POST /api/supplies/recommendations/{id}/accept
     */
    public function acceptRecommendation(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'qty' => 'nullable|integer|min:1',
        ]);

        $recommendation = SupplyRecommendation::findOrFail($id);
        $recommendation->accept($validated['qty'] ?? null, $request->user()?->id);

        return response()->json([
            'success' => true,
            'data' => $recommendation->fresh(),
            'message' => 'Рекомендация принята',
        ]);
    }

    /**
     * Отклонить рекомендацию
     * 
     * POST /api/supplies/recommendations/{id}/reject
     */
    public function rejectRecommendation(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'comment' => 'nullable|string|max:500',
        ]);

        $recommendation = SupplyRecommendation::findOrFail($id);
        $recommendation->reject($validated['comment'] ?? null, $request->user()?->id);

        return response()->json([
            'success' => true,
            'message' => 'Рекомендация отклонена',
        ]);
    }

    /**
     * Отложить рекомендацию
     * 
     * POST /api/supplies/recommendations/{id}/postpone
     */
    public function postponeRecommendation(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'comment' => 'nullable|string|max:500',
        ]);

        $recommendation = SupplyRecommendation::findOrFail($id);
        $recommendation->postpone($validated['comment'] ?? null, $request->user()?->id);

        return response()->json([
            'success' => true,
            'message' => 'Рекомендация отложена',
        ]);
    }

    // ========================================================================
    // ПОСТАВКИ
    // ========================================================================

    /**
     * Получить список поставок
     * 
     * GET /api/supplies
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'status' => 'nullable|string',
            'cluster_id' => 'nullable|string',
            'warehouse_id' => 'nullable|string',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = Supply::where('integration_id', $validated['integration_id'])
            ->with(['items', 'createdBy', 'responsible']);

        if (isset($validated['status'])) {
            $statuses = explode(',', $validated['status']);
            $query->whereIn('status', $statuses);
        }

        if (isset($validated['cluster_id'])) {
            $query->forCluster($validated['cluster_id']);
        }

        if (isset($validated['warehouse_id'])) {
            $query->forWarehouse($validated['warehouse_id']);
        }

        if (isset($validated['date_from'])) {
            $query->where('created_at', '>=', $validated['date_from']);
        }

        if (isset($validated['date_to'])) {
            $query->where('created_at', '<=', $validated['date_to']);
        }

        $supplies = $query
            ->orderByDesc('created_at')
            ->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'success' => true,
            'data' => $supplies,
        ]);
    }

    /**
     * Получить детали поставки
     * 
     * GET /api/supplies/{id}
     */
    public function show(string $id): JsonResponse
    {
        $supply = Supply::with(['items', 'events', 'createdBy', 'responsible', 'supplyPlan'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $supply,
        ]);
    }

    /**
     * Создать поставку из рекомендаций
     * 
     * POST /api/supplies
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'recommendation_ids' => 'required|array|min:1',
            'recommendation_ids.*' => 'exists:supply_recommendations,id',
            'supply_method' => 'nullable|in:direct,crossdock,multi_cluster',
            'delivery_scheme' => 'nullable|in:drop_off,pick_up',
            'cluster_id' => 'nullable|string',
            'warehouse_id' => 'nullable|string',
            'comment' => 'nullable|string|max:1000',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);

            $supply = $this->supplyService->createFromRecommendations(
                $integration,
                $validated['recommendation_ids'],
                [
                    'supply_method' => $validated['supply_method'] ?? Supply::METHOD_DIRECT,
                    'delivery_scheme' => $validated['delivery_scheme'] ?? null,
                    'cluster_id' => $validated['cluster_id'] ?? null,
                    'warehouse_id' => $validated['warehouse_id'] ?? null,
                    'comment' => $validated['comment'] ?? null,
                    'user_id' => $request->user()?->id,
                ]
            );

            return response()->json([
                'success' => true,
                'data' => $supply->load('items'),
                'message' => 'Поставка создана',
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create supply', [
                'error' => $e->getMessage(),
                'integration_id' => $validated['integration_id'],
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Создать поставку вручную (без рекомендаций)
     * 
     * POST /api/supplies/manual
     */
    public function storeManual(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'warehouse_id' => 'required|string',
            'warehouse_name' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.sku' => 'required|string',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.product_name' => 'nullable|string',
            'supply_method' => 'nullable|in:direct,crossdock,multi_cluster',
            'delivery_scheme' => 'nullable|in:drop_off,pick_up',
            'comment' => 'nullable|string|max:1000',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);

            $supply = $this->supplyService->createManual(
                $integration,
                $validated['items'],
                [
                    'warehouse_id' => $validated['warehouse_id'],
                    'warehouse_name' => $validated['warehouse_name'] ?? null,
                    'supply_method' => $validated['supply_method'] ?? Supply::METHOD_DIRECT,
                    'delivery_scheme' => $validated['delivery_scheme'] ?? null,
                    'comment' => $validated['comment'] ?? null,
                    'user_id' => $request->user()?->id,
                ]
            );

            return response()->json([
                'success' => true,
                'data' => $supply->load('items'),
                'message' => 'Поставка создана',
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create manual supply', [
                'error' => $e->getMessage(),
                'integration_id' => $validated['integration_id'],
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Создать черновик в Ozon
     * 
     * POST /api/supplies/{id}/create-draft
     */
    public function createDraft(int $id): JsonResponse
    {
        $supply = Supply::findOrFail($id);

        if ($supply->status !== Supply::STATUS_DRAFT) {
            return response()->json([
                'success' => false,
                'message' => 'Черновик уже создан или поставка в неподходящем статусе',
            ], 422);
        }

        try {
            $result = $this->supplyService->createOzonDraft($supply);

            return response()->json([
                'success' => true,
                'data' => [
                    'draft_id' => $result['draft_id'],
                    'supply' => $supply->fresh(),
                ],
                'message' => 'Черновик создан в Ozon',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Получить доступные слоты
     * 
     * GET /api/supplies/{id}/timeslots
     */
    public function getTimeslots(Request $request, int $id): JsonResponse
    {
        $supply = Supply::findOrFail($id);

        if (!$supply->ozon_draft_id) {
            return response()->json([
                'success' => false,
                'message' => 'Сначала создайте черновик в Ozon',
            ], 422);
        }

        try {
            $useCache = $request->boolean('use_cache', true);
            $slots = $this->supplyService->getAvailableTimeslots($supply, $useCache);

            // Получаем лучший слот
            $bestSlot = $this->supplyService->selectBestTimeslot($supply);

            return response()->json([
                'success' => true,
                'data' => [
                    'timeslots' => $slots,
                    'best_slot' => $bestSlot,
                    'total' => count($slots),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Забронировать слот
     * 
     * POST /api/supplies/{id}/book-slot
     */
    public function bookSlot(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'timeslot_id' => 'required|string',
        ]);

        $supply = Supply::findOrFail($id);

        if (!$supply->can_book_slot) {
            return response()->json([
                'success' => false,
                'message' => 'Бронирование слота недоступно для текущего статуса',
            ], 422);
        }

        try {
            $result = $this->supplyService->bookTimeslot($supply, $validated['timeslot_id']);

            return response()->json([
                'success' => true,
                'data' => $supply->fresh(),
                'message' => 'Слот забронирован',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Начать сборку
     * 
     * POST /api/supplies/{id}/start-preparing
     */
    public function startPreparing(Request $request, int $id): JsonResponse
    {
        $supply = Supply::findOrFail($id);

        try {
            $this->supplyService->startPreparing($supply, $request->user()?->id);

            return response()->json([
                'success' => true,
                'data' => $supply->fresh(),
                'message' => 'Сборка начата',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Отметить готовность к отгрузке
     * 
     * POST /api/supplies/{id}/ready-to-ship
     */
    public function markReadyToShip(Request $request, int $id): JsonResponse
    {
        $supply = Supply::findOrFail($id);

        try {
            $this->supplyService->markReadyToShip($supply, $request->user()?->id);

            return response()->json([
                'success' => true,
                'data' => $supply->fresh(),
                'message' => 'Готово к отгрузке',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Отметить отгрузку
     * 
     * POST /api/supplies/{id}/ship
     */
    public function markShipped(Request $request, int $id): JsonResponse
    {
        $supply = Supply::findOrFail($id);

        try {
            $this->supplyService->markShipped($supply, $request->user()?->id);

            return response()->json([
                'success' => true,
                'data' => $supply->fresh(),
                'message' => 'Отгружено',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Отменить поставку
     * 
     * POST /api/supplies/{id}/cancel
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $supply = Supply::findOrFail($id);

        try {
            $this->supplyService->cancel($supply, $validated['reason'] ?? null, $request->user()?->id);

            return response()->json([
                'success' => true,
                'message' => 'Поставка отменена',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Синхронизировать статус из Ozon
     * 
     * POST /api/supplies/{id}/sync-status
     */
    public function syncStatus(int $id): JsonResponse
    {
        $supply = Supply::findOrFail($id);

        $this->supplyService->syncStatus($supply);

        return response()->json([
            'success' => true,
            'data' => $supply->fresh(),
            'message' => 'Статус синхронизирован',
        ]);
    }

    /**
     * Получить события поставки
     * 
     * GET /api/supplies/{id}/events
     */
    public function getEvents(string $id): JsonResponse
    {
        $supply = Supply::findOrFail($id);

        $events = $supply->events()
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $events,
        ]);
    }

    // ========================================================================
    // СТАТИСТИКА
    // ========================================================================

    /**
     * Получить статистику поставок
     * 
     * GET /api/supplies/stats
     */
    public function getStats(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'period' => 'nullable|in:7d,14d,30d,90d',
        ]);

        $integration = Integration::findOrFail($validated['integration_id']);
        $stats = $this->supplyService->getStats($integration, $validated['period'] ?? '30d');

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    // ========================================================================
    // НАСТРОЙКИ
    // ========================================================================

    /**
     * Получить настройки поставок
     * 
     * GET /api/supplies/settings
     */
    public function getSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
        ]);

        $settings = SupplySettings::getOrCreate($validated['integration_id']);

        return response()->json([
            'success' => true,
            'data' => $settings,
        ]);
    }

    /**
     * Обновить настройки поставок
     * 
     * PUT /api/supplies/settings
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'default_sales_window' => 'nullable|in:7d,14d,28d',
            'target_days_a' => 'nullable|integer|min:1|max:90',
            'target_days_b' => 'nullable|integer|min:1|max:90',
            'target_days_c' => 'nullable|integer|min:1|max:90',
            'safety_stock_days' => 'nullable|integer|min:0|max:30',
            'safety_stock_percent' => 'nullable|numeric|min:0|max:100',
            'safety_stock_mode' => 'nullable|in:days,percent,max',
            'default_lead_time_days' => 'nullable|integer|min:1|max:30',
            'min_order_qty' => 'nullable|integer|min:1',
            'default_pack_multiple' => 'nullable|integer|min:1',
            'oos_risk_days' => 'nullable|integer|min:1|max:14',
            'overstock_days' => 'nullable|integer|min:14|max:180',
            'preferred_weekdays' => 'nullable|array',
            'preferred_weekdays.*' => 'integer|min:1|max:7',
            'max_supplies_per_day' => 'nullable|integer|min:1|max:20',
            'max_items_per_supply' => 'nullable|integer|min:1|max:500',
            'auto_book_slot' => 'nullable|boolean',
            'notify_no_slots' => 'nullable|boolean',
            'notify_oos_risk' => 'nullable|boolean',
            'notify_stuck_supply' => 'nullable|boolean',
            'notify_api_errors' => 'nullable|boolean',
            'excluded_skus' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);

        $settings = SupplySettings::getOrCreate($validated['integration_id']);
        
        unset($validated['integration_id']);
        $settings->update($validated);

        return response()->json([
            'success' => true,
            'data' => $settings->fresh(),
            'message' => 'Настройки сохранены',
        ]);
    }

    // ========================================================================
    // АНАЛИТИКА
    // ========================================================================

    /**
     * Получить аналитику поставок
     * 
     * GET /api/supplies/analytics
     */
    public function getAnalytics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'period' => 'nullable|in:7d,14d,30d,90d',
        ]);

        $periodDays = match ($validated['period'] ?? '30d') {
            '7d' => 7,
            '14d' => 14,
            '30d' => 30,
            '90d' => 90,
        };

        $startDate = now()->subDays($periodDays)->startOfDay();
        $endDate = now()->endOfDay();

        // Получаем последнюю аналитику
        $analytics = SupplyAnalytics::where('integration_id', $validated['integration_id'])
            ->where('period_start', '>=', $startDate->subDays(1))
            ->orderByDesc('calculated_at')
            ->first();

        // Если нет данных или устарели — возвращаем базовую статистику
        if (!$analytics) {
            $analytics = $this->calculateBasicAnalytics($validated['integration_id'], $startDate, $endDate);
        }

        return response()->json([
            'success' => true,
            'data' => $analytics,
        ]);
    }

    // ========================================================================
    // КЛАСТЕРЫ, СЛОТЫ И СОЗДАНИЕ ПОСТАВКИ СО СЛОТОМ (новый flow фронтенда)
    // ========================================================================

    /**
     * Получить список кластеров Ozon с агрегированной статистикой
     * 
     * GET /api/supplies/clusters
     */
    public function getClusters(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'period_days' => 'nullable|integer|min:7|max:90',
        ]);

        $integration = Integration::findOrFail($validated['integration_id']);
        $periodDays = $validated['period_days'] ?? 28;

        if ($integration->marketplace !== 'ozon') {
            return response()->json([
                'success' => false,
                'message' => 'Кластеры доступны только для Ozon',
            ], 422);
        }

        // Пробуем получить кластеры из Ozon API
        try {
            $ozon = \App\Domains\Ozon\OzonMarketplace::fromIntegration($integration);
            $apiClusters = $ozon->supplies()->getClusters();
            
            if (!empty($apiClusters)) {
                // Получаем склады для каждого кластера и обогащаем данными
                $clusters = $this->enrichClustersWithRealData($integration, $ozon, $apiClusters, $periodDays);
                
                return response()->json([
                    'success' => true,
                    'data' => $clusters,
                    'source' => 'ozon_api',
                    'period_days' => $periodDays,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to get clusters from Ozon API', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [],
            'source' => 'empty',
            'period_days' => $periodDays,
            'message' => 'Кластеры не найдены. Проверьте интеграцию Ozon и повторите синхронизацию.',
        ]);
    }

    /**
     * Получить рекомендации товаров для кластера
     * 
     * GET /api/supplies/clusters/{clusterId}/products
     * 
     * Использует внутренние данные из InventoryWarehouse:
     * - Фильтрует товары по складам кластера
     * - Рассчитывает рекомендуемое количество на основе продаж
     */
    public function getClusterProducts(Request $request, string $clusterId): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'period_days' => 'nullable|integer|min:7|max:90',
            'tab' => 'nullable|in:recommendations,in_supply',
        ]);

        $integration = Integration::findOrFail($validated['integration_id']);
        $periodDays = $validated['period_days'] ?? 28;
        $tab = $validated['tab'] ?? 'recommendations';

        if ($integration->marketplace !== 'ozon') {
            return response()->json([
                'success' => false,
                'message' => 'Доступно только для Ozon',
            ], 422);
        }

        if ($tab === 'in_supply') {
            // Товары в заявке — из кэша
            $cacheKey = $this->getSupplyDraftCacheKey($integration->id, $clusterId);
            $draftProducts = cache()->get($cacheKey, []);
            $draftMeta = cache()->get($this->getSupplyDraftMetaKey($integration->id, $clusterId), []);

            return response()->json([
                'success' => true,
                'data' => [
                    'products' => array_values($draftProducts),
                    'summary' => [
                        'total_sku' => count($draftProducts),
                        'total_units' => array_sum(array_column($draftProducts, 'quantity')),
                        'total_volume' => 0,
                    ],
                    'draft_meta' => $draftMeta,
                ],
                'cluster_id' => $clusterId,
                'tab' => $tab,
            ]);
        }

        // Получаем рекомендации на основе внутренних данных
        $products = $this->getClusterProductsFromLocalData($integration, $clusterId, $periodDays);

        return response()->json([
            'success' => true,
            'data' => $products,
            'cluster_id' => $clusterId,
            'period_days' => $periodDays,
            'tab' => $tab,
            'source' => 'internal_data',
        ]);
    }

    /**
     * Получить товары кластера из внутренних данных (InventoryWarehouse)
     */
    private function getClusterProductsFromLocalData(Integration $integration, string $clusterId, int $periodDays): array
    {
        // Получаем название кластера для определения ключевых слов
        $ozon = \App\Domains\Ozon\OzonMarketplace::fromIntegration($integration);
        $clusters = [];
        try {
            $clusters = $ozon->supplies()->getClusters();
        } catch (\Exception $e) {
            Log::warning('Failed to get clusters', ['error' => $e->getMessage()]);
        }
        
        $clusterName = '';
        foreach ($clusters as $cluster) {
            if ($cluster['id'] === $clusterId) {
                $clusterName = $cluster['name'] ?? '';
                break;
            }
        }
        
        $clusterKeywords = $this->getClusterKeywords($clusterName);
        
        // Получаем все данные по складам из БД
        $warehouseData = \App\Models\InventoryWarehouse::where('integration_id', $integration->id)
            ->where('marketplace', 'ozon')
            ->select(
                'sku',
                'warehouse_name',
                'quantity',
                'reserved',
                'in_transit',
                'sales_7_days',
                'sales_14_days',
                'sales_30_days',
                'average_daily_sales',
                'days_of_stock',
                'turnover_days'
            )
            ->get();
        
        // Фильтруем по складам кластера
        $clusterProducts = [];
        foreach ($warehouseData as $item) {
            $warehouseName = strtoupper($item->warehouse_name ?? '');
            $matchesCluster = false;
            
            foreach ($clusterKeywords as $keyword) {
                if (strpos($warehouseName, strtoupper($keyword)) !== false) {
                    $matchesCluster = true;
                    break;
                }
            }
            
            if (!$matchesCluster) continue;
            
            $sku = $item->sku;
            $avgSales = (float) ($item->average_daily_sales ?? 0);
            $quantity = (int) ($item->quantity ?? 0);
            $daysOfStock = (int) ($item->days_of_stock ?? 0);
            $sales30 = (int) ($item->sales_30_days ?? 0);
            $inTransit = (int) ($item->in_transit ?? 0);
            
            // Если нет средних продаж — рассчитываем из sales_30_days
            if ($avgSales <= 0 && $sales30 > 0) {
                $avgSales = $sales30 / 30;
            }
            
            if (!isset($clusterProducts[$sku])) {
                $clusterProducts[$sku] = [
                    'sku' => $sku,
                    'quantity' => 0,
                    'in_transit' => 0,
                    'avg_daily_sales' => $avgSales,
                    'days_of_stock' => $daysOfStock,
                    'sales_30_days' => $sales30,
                ];
            }
            
            // Суммируем остатки по всем складам кластера
            $clusterProducts[$sku]['quantity'] += $quantity;
            $clusterProducts[$sku]['in_transit'] += $inTransit;
            // Берём максимальные продажи
            if ($avgSales > $clusterProducts[$sku]['avg_daily_sales']) {
                $clusterProducts[$sku]['avg_daily_sales'] = $avgSales;
            }
        }
        
        // Получаем информацию о товарах из Product
        $skus = array_keys($clusterProducts);
        $products = \App\Models\Product::where('integration_id', $integration->id)
            ->whereIn('sku', $skus)
            ->get()
            ->keyBy('sku');
        
        // Формируем результат с рекомендациями
        $result = [];
        foreach ($clusterProducts as $sku => $data) {
            $product = $products[$sku] ?? null;
            $currentStock = $data['quantity'];
            $avgSales = $data['avg_daily_sales'];
            $daysOfStock = $data['days_of_stock'];
            $inTransit = $data['in_transit'];
            
            // Рекомендуемое количество: на periodDays дней минус текущий запас
            $neededForPeriod = ceil($avgSales * $periodDays);
            $recommendedQty = max(0, (int) ($neededForPeriod - $currentStock));
            
            // Определяем приоритет
            $priority = 'normal';
            if ($daysOfStock <= 7 || ($avgSales > 0 && $currentStock <= 0)) {
                $priority = 'critical';
            } elseif ($daysOfStock <= 14) {
                $priority = 'high';
            } elseif ($daysOfStock <= 21) {
                $priority = 'medium';
            }
            
            // Добавляем только товары, которым нужна поставка
            $needsSupply = $recommendedQty > 0 || ($daysOfStock < $periodDays && $avgSales > 0);
            
            if ($needsSupply) {
                $result[] = [
                    'sku' => $sku,
                    'product_id' => $product->marketplace_id ?? null,
                    'name' => $product->name ?? $sku,
                    'barcode' => $product->barcode ?? null,
                    'image' => $product->images[0] ?? null,
                    'current_stock' => $currentStock,
                    'in_transit' => $inTransit,
                    'avg_daily_sales' => round($avgSales, 2),
                    'days_of_stock' => $daysOfStock,
                    'recommended_qty' => $recommendedQty,
                    'volume' => round($recommendedQty * 0.5, 2), // примерный объём
                    'priority' => $priority,
                    'priority_label' => match($priority) {
                        'critical' => 'Критично',
                        'high' => 'Высокий',
                        'medium' => 'Средний',
                        default => 'Нормальный',
                    },
                ];
            }
        }
        
        // Сортируем по приоритету (критичные первыми)
        usort($result, function ($a, $b) {
            $priorityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'normal' => 3];
            $orderA = $priorityOrder[$a['priority']] ?? 4;
            $orderB = $priorityOrder[$b['priority']] ?? 4;
            if ($orderA !== $orderB) {
                return $orderA <=> $orderB;
            }
            return $a['days_of_stock'] <=> $b['days_of_stock'];
        });
        
        $totalSku = count($result);
        $totalUnits = array_sum(array_column($result, 'recommended_qty'));
        $totalVolume = array_sum(array_column($result, 'volume'));
        
        return [
            'products' => $result,
            'summary' => [
                'total_sku' => $totalSku,
                'total_units' => $totalUnits,
                'total_volume' => round($totalVolume, 2),
            ],
        ];
    }

    /**
     * Добавить товары в заявку кластера
     * 
     * POST /api/supplies/clusters/{clusterId}/add-products
     */
    public function addClusterProducts(Request $request, string $clusterId): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'products' => 'required|array|min:1',
            'products.*.sku' => 'required|string',
            'products.*.quantity' => 'required|integer|min:1',
        ]);

        $integration = Integration::findOrFail($validated['integration_id']);

        if ($integration->marketplace !== 'ozon') {
            return response()->json([
                'success' => false,
                'message' => 'Доступно только для Ozon',
            ], 422);
        }

        // Сохраняем товары в сессию/кэш для последующего создания поставки
        $cacheKey = $this->getSupplyDraftCacheKey($integration->id, $clusterId);
        $existingProducts = cache()->get($cacheKey, []);
        
        foreach ($validated['products'] as $product) {
            $existingProducts[$product['sku']] = [
                'sku' => $product['sku'],
                'quantity' => $product['quantity'],
                'added_at' => now()->toIso8601String(),
            ];
        }
        
        cache()->put($cacheKey, $existingProducts, now()->addHours(24));

        return response()->json([
            'success' => true,
            'message' => 'Товары добавлены в заявку',
            'data' => [
                'cluster_id' => $clusterId,
                'products_count' => count($existingProducts),
                'products' => array_values($existingProducts),
            ],
        ]);
    }

    /**
     * Указать способ доставки для кластера (ПВЗ/СЦ)
     * 
     * POST /api/supplies/clusters/{clusterId}/delivery
     */
    public function setClusterDeliveryMethod(Request $request, string $clusterId): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'delivery_type' => 'required|in:pvz,sc',
        ]);

        $integration = Integration::findOrFail($validated['integration_id']);

        if ($integration->marketplace !== 'ozon') {
            return response()->json([
                'success' => false,
                'message' => 'Доступно только для Ozon',
            ], 422);
        }

        $metaKey = $this->getSupplyDraftMetaKey($integration->id, $clusterId);
        $meta = cache()->get($metaKey, []);

        $meta['delivery_type'] = $validated['delivery_type'];
        $meta['delivery_type_label'] = $validated['delivery_type'] === 'pvz' ? 'ПВЗ' : 'СЦ';
        $meta['delivery_selected_at'] = now()->toIso8601String();

        cache()->put($metaKey, $meta, now()->addHours(24));

        return response()->json([
            'success' => true,
            'message' => 'Способ доставки сохранён',
            'data' => [
                'cluster_id' => $clusterId,
                'delivery_type' => $meta['delivery_type'],
                'delivery_type_label' => $meta['delivery_type_label'],
            ],
        ]);
    }

    /**
     * Указать склад внутри кластера
     * 
     * POST /api/supplies/clusters/{clusterId}/warehouse
     */
    public function setClusterWarehouse(Request $request, string $clusterId): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'warehouse_id' => 'required|string',
            'warehouse_name' => 'nullable|string',
        ]);

        $integration = Integration::findOrFail($validated['integration_id']);

        if ($integration->marketplace !== 'ozon') {
            return response()->json([
                'success' => false,
                'message' => 'Доступно только для Ozon',
            ], 422);
        }

        $metaKey = $this->getSupplyDraftMetaKey($integration->id, $clusterId);
        $meta = cache()->get($metaKey, []);

        if (empty($meta['delivery_type'])) {
            return response()->json([
                'success' => false,
                'message' => 'Сначала выберите способ доставки (ПВЗ или СЦ)',
            ], 422);
        }

        $warehouseId = (string) $validated['warehouse_id'];
        $warehouseName = $validated['warehouse_name'] ?? null;
        $resolvedClusterId = null;

        try {
            $ozon = OzonMarketplace::fromIntegration($integration);
            $clusters = $ozon->supplies()->getClusters();

            foreach ($clusters as $cluster) {
                $warehouseIds = $cluster['warehouse_ids'] ?? $cluster['all_warehouse_ids'] ?? [];
                $warehouseIds = array_map('strval', $warehouseIds);

                if (in_array($warehouseId, $warehouseIds, true)) {
                    $resolvedClusterId = (string) ($cluster['id'] ?? null);
                    if (!$warehouseName) {
                        foreach ($cluster['warehouses'] ?? [] as $wh) {
                            if ((string) ($wh['id'] ?? '') === $warehouseId) {
                                $warehouseName = $wh['name'] ?? null;
                                break;
                            }
                        }
                    }
                    break;
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to resolve Ozon warehouse info', [
                'integration_id' => $integration->id,
                'warehouse_id' => $warehouseId,
                'error' => $e->getMessage(),
            ]);
        }

        if ($resolvedClusterId && (string) $resolvedClusterId !== (string) $clusterId) {
            return response()->json([
                'success' => false,
                'message' => 'Выбранный склад не относится к указанному кластеру',
            ], 422);
        }

        $meta['warehouse_id'] = $warehouseId;
        $meta['warehouse_name'] = $warehouseName;
        $meta['warehouse_selected_at'] = now()->toIso8601String();

        cache()->put($metaKey, $meta, now()->addHours(24));

        return response()->json([
            'success' => true,
            'message' => 'Склад сохранён',
            'data' => [
                'cluster_id' => $clusterId,
                'warehouse_id' => $warehouseId,
                'warehouse_name' => $warehouseName,
            ],
        ]);
    }

    /**
     * Обогатить кластеры реальными данными из внутренней БД
     * 
     * Логика расчёта рекомендаций по кластерам:
     * 1. Берём данные из InventoryWarehouse (продажи, оборачиваемость, остатки)
     * 2. Маппим склады к кластерам по ключевым словам в названиях
     * 3. Для каждого кластера считаем товары с низким запасом (< periodDays)
     * 4. Рекомендуемое количество = (avg_daily_sales * periodDays) - current_stock
     */
    private function enrichClustersWithRealData(Integration $integration, $ozon, array $apiClusters, int $periodDays): array
    {
        $result = [];
        
        // Получаем ВСЕ данные по складам из нашей БД (включая товары с 0 остатком но с продажами)
        $warehouseData = \App\Models\InventoryWarehouse::where('integration_id', $integration->id)
            ->where('marketplace', 'ozon')
            ->select(
                'sku', 
                'warehouse_name', 
                'quantity', 
                'reserved',
                'sales_7_days',
                'sales_14_days', 
                'sales_30_days',
                'average_daily_sales', 
                'days_of_stock',
                'turnover_days'
            )
            ->get()
            ->groupBy('warehouse_name');
        
        // Создаём маппинг warehouse_name -> cluster_id
        $warehouseNameToCluster = [];
        foreach ($apiClusters as $cluster) {
            $clusterId = $cluster['id'];
            $clusterName = $cluster['name'] ?? '';
            $clusterKeywords = $this->getClusterKeywords($clusterName);
            foreach ($clusterKeywords as $keyword) {
                $warehouseNameToCluster[strtoupper($keyword)] = $clusterId;
            }
        }
        
        // Группируем данные по кластерам
        $clusterData = [];
        foreach ($warehouseData as $warehouseName => $stocks) {
            $clusterId = null;
            $upperName = strtoupper($warehouseName);
            foreach ($warehouseNameToCluster as $keyword => $cId) {
                if (strpos($upperName, $keyword) !== false) {
                    $clusterId = $cId;
                    break;
                }
            }
            if (!$clusterId) continue;
            
            if (!isset($clusterData[$clusterId])) {
                $clusterData[$clusterId] = [];
            }
            
            foreach ($stocks as $stock) {
                $sku = $stock->sku;
                $avgSales = (float) ($stock->average_daily_sales ?? 0);
                $quantity = (int) ($stock->quantity ?? 0);
                $daysOfStock = (int) ($stock->days_of_stock ?? 0);
                $sales30 = (int) ($stock->sales_30_days ?? 0);
                
                if (!isset($clusterData[$clusterId][$sku])) {
                    $clusterData[$clusterId][$sku] = [
                        'quantity' => 0,
                        'avg_daily_sales' => $avgSales,
                        'days_of_stock' => $daysOfStock,
                        'sales_30_days' => $sales30,
                    ];
                }
                
                // Суммируем остатки по всем складам кластера
                $clusterData[$clusterId][$sku]['quantity'] += $quantity;
                // Берём максимальные продажи (товар мог продаваться на разных складах)
                if ($avgSales > $clusterData[$clusterId][$sku]['avg_daily_sales']) {
                    $clusterData[$clusterId][$sku]['avg_daily_sales'] = $avgSales;
                }
                if ($sales30 > $clusterData[$clusterId][$sku]['sales_30_days']) {
                    $clusterData[$clusterId][$sku]['sales_30_days'] = $sales30;
                }
            }
        }
        
        foreach ($apiClusters as $cluster) {
            $clusterId = $cluster['id'];
            $warehouseIds = $cluster['warehouse_ids'] ?? [];
            $warehousesCount = $cluster['warehouses_count'] ?? count($warehouseIds);
            
            $clusterSkuData = $clusterData[$clusterId] ?? [];
            
            // Если нет данных по кластеру — показываем 0
            if (empty($clusterSkuData)) {
                $result[] = [
                    'id' => $clusterId,
                    'name' => $cluster['name'],
                    'type' => $cluster['type'] ?? null,
                    'warehouses_count' => $warehousesCount,
                    'warehouse_ids' => $warehouseIds,
                    'sku_count' => 0,
                    'units_count' => 0,
                    'days_of_stock' => $periodDays,
                ];
                continue;
            }
            
            // Считаем рекомендации: товары с запасом < periodDays
            $recommendedSkus = [];
            $totalUnits = 0;
            
            foreach ($clusterSkuData as $sku => $data) {
                $currentStock = $data['quantity'];
                $avgSales = $data['avg_daily_sales'];
                $daysOfStock = $data['days_of_stock'];
                $sales30 = $data['sales_30_days'];
                
                // Если нет средних продаж — рассчитываем из sales_30_days
                if ($avgSales <= 0 && $sales30 > 0) {
                    $avgSales = $sales30 / 30;
                }
                
                // Рекомендуемое количество: на periodDays дней минус текущий запас
                $neededForPeriod = ceil($avgSales * $periodDays);
                $neededQty = max(0, $neededForPeriod - $currentStock);
                
                // Добавляем в рекомендации если:
                // 1. Нужна поставка (neededQty > 0)
                // 2. ИЛИ запас меньше периода И есть продажи
                $needsSupply = $neededQty > 0 || ($daysOfStock < $periodDays && $avgSales > 0);
                
                if ($needsSupply) {
                    $recommendedSkus[] = $sku;
                    $totalUnits += $neededQty;
                }
            }
            
            $result[] = [
                'id' => $clusterId,
                'name' => $cluster['name'],
                'type' => $cluster['type'] ?? null,
                'warehouses_count' => $warehousesCount,
                'warehouse_ids' => $warehouseIds,
                'sku_count' => count(array_unique($recommendedSkus)),
                'units_count' => (int) $totalUnits,
                'days_of_stock' => $periodDays,
            ];
        }
        
        return $result;
    }

    /**
     * Получить ключевые слова для определения кластера по названию склада
     */
    private function getClusterKeywords(string $clusterName): array
    {
        // Маппинг кластеров к ключевым словам в названиях складов
        // Основано на реальных названиях складов из InventoryWarehouse
        $keywords = [
            'Москва' => ['ДОМОДЕДОВО', 'ХОРУГВИНО', 'ПЕТРОВСКОЕ', 'СОФЬИНО', 'ПОДОЛЬСК', 'КОЛЕДИНО', 'ЭЛЕКТРОСТАЛЬ', 'ПУШКИНО', 'НОГИНСК', 'ГРИВНО'],
            'Санкт-Петербург' => ['СПБ_', 'ШУШАРЫ', 'БУГРЫ', 'КОЛПИНО', 'ПОРОШКИНО', 'ВОЛХОНКА', 'САНКТ_ПЕТЕРБУРГ', 'САНКТ-ПЕТЕРБУРГ'],
            'Екатеринбург' => ['ЕКАТЕРИНБУРГ'],
            'Новосибирск' => ['НОВОСИБИРСК'],
            'Казань' => ['КАЗАНЬ'],
            'Краснодар' => ['КРАСНОДАР', 'АДЫГЕЙСК', 'НОВОРОССИЙСК'],
            'Ростов' => ['РОСТОВ'],
            'Самара' => ['САМАРА'],
            'Воронеж' => ['ВОРОНЕЖ'],
            'Нижний Новгород' => ['НИЖНИЙ_НОВГОРОД'],
            'Красноярск' => ['КРАСНОЯРСК'],
            'Пермь' => ['ПЕРМЬ'],
            'Уфа' => ['УФА'],
            'Челябинск' => ['ЧЕЛЯБИНСК'],
            'Омск' => ['ОМСК'],
            'Волгоград' => ['ВОЛГОГРАД'],
            'Тюмень' => ['ТЮМЕНЬ'],
            'Оренбург' => ['ОРЕНБУРГ'],
            'Тверь' => ['ТВЕРЬ'],
            'Ярославль' => ['ЯРОСЛАВЛЬ'],
            'Хабаровск' => ['ХАБАРОВСК'],
            'Владивосток' => ['ВЛАДИВОСТОК', 'АРТЕМ'],
            'Иркутск' => ['ИРКУТСК'],
            'Барнаул' => ['БАРНАУЛ'],
            'Невинномысск' => ['НЕВИННОМЫССК'],
            'Дальний' => ['ДАЛЬНИЙ'],
            'Астана' => ['АСТАНА'],
            'Алматы' => ['АЛМАТЫ'],
            'Минск' => ['МИНСК'],
            'Ереван' => ['ЕРЕВАН'],
            'Калининград' => ['КАЛИНИНГРАД'],
            'Саратов' => ['САРАТОВ'],
            'Махачкала' => ['МАХАЧКАЛА'],
        ];
        
        foreach ($keywords as $key => $values) {
            if (stripos($clusterName, $key) !== false) {
                return $values;
            }
        }
        
        // Если не нашли — возвращаем первое слово названия кластера
        $firstWord = explode(' ', $clusterName)[0] ?? '';
        $firstWord = explode(',', $firstWord)[0] ?? '';
        return $firstWord ? [strtoupper($firstWord)] : [];
    }

    /**
     * Получить локальную статистику по складам
     */
    private function getLocalClusterStatsByWarehouses(Integration $integration, array $warehouseIds): array
    {
        if (empty($warehouseIds)) {
            // Если нет warehouse_ids — берём все данные по интеграции
            $stats = \App\Models\InventoryWarehouse::where('integration_id', $integration->id)
                ->where('marketplace', 'ozon')
                ->selectRaw('COUNT(DISTINCT sku) as sku_count, SUM(quantity) as units_count, AVG(days_of_stock) as avg_days')
                ->first();
        } else {
            $stats = \App\Models\InventoryWarehouse::where('integration_id', $integration->id)
                ->where('marketplace', 'ozon')
                ->whereIn('warehouse_id', $warehouseIds)
                ->selectRaw('COUNT(DISTINCT sku) as sku_count, SUM(quantity) as units_count, AVG(days_of_stock) as avg_days')
                ->first();
        }
        
        return [
            'sku_count' => $stats->sku_count ?? 0,
            'units_count' => (int) ($stats->units_count ?? 0),
            'days_of_stock' => (int) round($stats->avg_days ?? 28),
        ];
    }

    /**
     * Получить локальную статистику по кластеру
     */
    private function getLocalClusterStats(Integration $integration, string $clusterId): array
    {
        $clusterMapping = $this->getClusterWarehouseMapping();
        $warehouseIds = $clusterMapping[$clusterId] ?? [];
        
        if (empty($warehouseIds)) {
            return ['sku_count' => 0, 'units_count' => 0, 'days_of_stock' => 28];
        }
        
        $stats = \App\Models\InventoryWarehouse::where('integration_id', $integration->id)
            ->where('marketplace', 'ozon')
            ->whereIn('warehouse_id', $warehouseIds)
            ->selectRaw('COUNT(DISTINCT sku) as sku_count, SUM(quantity) as units_count, AVG(days_of_stock) as avg_days')
            ->first();
        
        return [
            'sku_count' => $stats->sku_count ?? 0,
            'units_count' => (int) ($stats->units_count ?? 0),
            'days_of_stock' => (int) round($stats->avg_days ?? 28),
        ];
    }

    /**
     * Обогатить товары локальными данными
     */
    private function enrichProductsWithLocalData(Integration $integration, array $products): array
    {
        $skus = array_column($products, 'sku');
        
        $localProducts = \App\Models\Product::where('integration_id', $integration->id)
            ->whereIn('sku', $skus)
            ->get()
            ->keyBy('sku');
        
        return array_map(function ($product) use ($localProducts) {
            $local = $localProducts[$product['sku']] ?? null;
            
            if ($local) {
                $product['name'] = $product['name'] ?? $local->name;
                $product['barcode'] = $product['barcode'] ?? $local->barcode;
                $product['image'] = $local->images[0] ?? null;
                $product['price'] = $local->price;
            }
            
            return $product;
        }, $products);
    }

    /**
     * Генерация fallback товаров для кластера
     */
    private function generateFallbackClusterProducts(Integration $integration, string $clusterId, int $periodDays): array
    {
        // Получаем товары из нашей БД с остатками
        $products = \App\Models\Product::where('integration_id', $integration->id)
            ->with(['inventoryWarehouses' => fn($q) => $q->where('marketplace', 'ozon')])
            ->limit(50)
            ->get();
        
        $result = [];
        foreach ($products as $product) {
            $inventory = $product->inventoryWarehouses->first();
            $currentStock = $inventory->quantity ?? 0;
            $avgSales = $inventory->average_daily_sales ?? 0;
            $daysOfStock = $avgSales > 0 ? (int) round($currentStock / $avgSales) : 999;
            
            // Рекомендуемое количество на период
            $recommendedQty = max(0, (int) ceil($avgSales * $periodDays - $currentStock));
            
            if ($recommendedQty > 0) {
                $result[] = [
                    'sku' => $product->sku,
                    'product_id' => $product->marketplace_id,
                    'name' => $product->name,
                    'barcode' => $product->barcode,
                    'image' => $product->images[0] ?? null,
                    'recommended_qty' => $recommendedQty,
                    'volume' => round($recommendedQty * 0.5, 2), // примерный объём
                    'current_stock' => $currentStock,
                    'in_transit' => $inventory->in_transit ?? 0,
                    'avg_sales_28d' => round($avgSales, 2),
                    'days_of_stock' => $daysOfStock,
                    'priority' => $daysOfStock <= 7 ? 'urgent' : ($daysOfStock <= 14 ? 'high' : 'normal'),
                    'is_sortable' => true,
                ];
            }
        }
        
        // Сортируем по приоритету (дни запаса)
        usort($result, fn($a, $b) => $a['days_of_stock'] <=> $b['days_of_stock']);
        
        $totalSku = count($result);
        $totalUnits = array_sum(array_column($result, 'recommended_qty'));
        $totalVolume = array_sum(array_column($result, 'volume'));
        
        return [
            'products' => $result,
            'summary' => [
                'total_sku' => $totalSku,
                'total_units' => $totalUnits,
                'total_volume' => round($totalVolume, 2),
            ],
        ];
    }

    /**
     * Обогатить кластеры статистикой по товарам (legacy)
     */
    private function enrichClustersWithStats(Integration $integration, array $apiClusters): array
    {
        // Получаем остатки по складам
        $inventoryByWarehouse = \App\Models\InventoryWarehouse::where('integration_id', $integration->id)
            ->where('marketplace', 'ozon')
            ->selectRaw('warehouse_id, COUNT(DISTINCT sku) as sku_count, SUM(quantity) as units_count, AVG(days_of_stock) as avg_days_of_stock')
            ->groupBy('warehouse_id')
            ->get()
            ->keyBy('warehouse_id');

        return array_map(function ($cluster) use ($inventoryByWarehouse) {
            // Суммируем по всем складам кластера (пока упрощённо — по warehouse_id)
            $warehouseIds = $cluster['warehouse_ids'] ?? [];
            $skuCount = 0;
            $unitsCount = 0;
            $daysOfStock = 30;

            foreach ($warehouseIds as $whId) {
                if (isset($inventoryByWarehouse[$whId])) {
                    $inv = $inventoryByWarehouse[$whId];
                    $skuCount += $inv->sku_count;
                    $unitsCount += $inv->units_count;
                    $daysOfStock = min($daysOfStock, $inv->avg_days_of_stock ?? 30);
                }
            }

            return [
                'id' => $cluster['id'],
                'name' => $cluster['name'],
                'warehouses_count' => $cluster['warehouses_count'] ?? count($warehouseIds),
                'sku_count' => $skuCount,
                'units_count' => (int) $unitsCount,
                'days_of_stock' => (int) round($daysOfStock),
            ];
        }, $apiClusters);
    }


    /**
     * Забронировать складской слот для legacy-страницы поставок.
     *
     * POST /api/warehouse-slots/{slotId}/book
     */
    public function bookWarehouseSlot(Request $request, string $slotId): JsonResponse
    {
        $validated = $request->validate([
            'shipment_id' => 'required|string',
        ]);

        $slot = WarehouseSlot::query()
            ->where('id', $slotId)
            ->orWhere('external_slot_id', $slotId)
            ->firstOrFail();

        if (! $slot->book((string) $validated['shipment_id'])) {
            return response()->json([
                'message' => 'Слот недоступен для бронирования',
            ], 422);
        }

        return response()->json([
            'message' => 'Слот забронирован',
            'data' => $slot->fresh(),
        ]);
    }

    /**
     * Освободить складской слот для legacy-страницы поставок.
     *
     * POST /api/warehouse-slots/{slotId}/release
     */
    public function releaseWarehouseSlot(string $slotId): JsonResponse
    {
        $slot = WarehouseSlot::query()
            ->where('id', $slotId)
            ->orWhere('external_slot_id', $slotId)
            ->firstOrFail();

        $slot->release();

        return response()->json([
            'message' => 'Слот освобождён',
            'data' => $slot->fresh(),
        ]);
    }

    /**
     * Получить доступные слоты приёмки
     * 
     * GET /api/supplies/slots
     */
    public function getSlots(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'warehouse_id' => 'nullable|string',
            'cluster_ids' => 'nullable|array',
            'cluster_ids.*' => 'string',
        ]);

        $integration = Integration::findOrFail($validated['integration_id']);

        // Проверяем что это Ozon
        if ($integration->marketplace !== 'ozon') {
            return response()->json([
                'success' => false,
                'message' => 'Слоты доступны только для Ozon',
            ], 422);
        }

        $dateFrom = $validated['date_from'] ?? now()->toDateString();
        $dateTo = $validated['date_to'] ?? now()->addDays(14)->toDateString();
        $clusterIds = $validated['cluster_ids'] ?? [];

        // Маппинг кластеров к складам (для фильтрации)
        $clusterToWarehouses = $this->getClusterWarehouseMapping();

        // Получаем слоты из БД
        $query = \App\Models\WarehouseSlot::query()
            ->where('marketplace', 'ozon')
            ->where('date', '>=', $dateFrom)
            ->where('date', '<=', $dateTo)
            ->orderBy('date')
            ->orderBy('time_from');

        if (!empty($validated['warehouse_id'])) {
            $query->where('warehouse_id', $validated['warehouse_id']);
        }

        // Фильтрация по кластерам
        if (!empty($clusterIds)) {
            $warehouseIds = [];
            foreach ($clusterIds as $clusterId) {
                if (isset($clusterToWarehouses[$clusterId])) {
                    $warehouseIds = array_merge($warehouseIds, $clusterToWarehouses[$clusterId]);
                }
            }
            if (!empty($warehouseIds)) {
                $query->whereIn('warehouse_id', array_unique($warehouseIds));
            }
        }

        $slots = $query->get();

        // Если слотов нет — пробуем синхронизировать
        if ($slots->isEmpty()) {
            // Запускаем синхронизацию в фоне
            \App\Jobs\SyncWarehouseSlotsJob::dispatch($integration->id);

            return response()->json([
                'success' => true,
                'data' => [],
                'meta' => [
                    'synced_at' => null,
                    'total' => 0,
                    'source' => 'empty',
                    'cluster_ids' => $clusterIds,
                    'message' => 'Слоты не найдены. Синхронизация запущена, обновите страницу через минуту.',
                ],
            ]);
        }


        $data = $slots->map(fn($slot) => [
            'id' => $slot->external_slot_id ?? $slot->id,
            'date' => $slot->date->toDateString(),
            'time_from' => substr($slot->time_from, 0, 5),
            'time_to' => substr($slot->time_to, 0, 5),
            'warehouse_id' => $slot->warehouse_id,
            'warehouse_name' => $slot->warehouse_name,
            'cluster_id' => $slot->cluster_id ?? $this->getClusterIdByWarehouse($slot->warehouse_id),
            'is_available' => $slot->is_available && !$slot->booked_by_supply_id,
            'capacity' => $slot->capacity,
            'capacity_used' => $slot->capacity_used ?? 0,
            'coefficient' => (float) ($slot->coefficient ?? 1.0),
        ]);

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'synced_at' => $slots->first()?->synced_at?->toIso8601String(),
                'total' => $data->count(),
                'cluster_ids' => $clusterIds,
            ],
        ]);
    }

    /**
     * Маппинг кластеров к складам
     */
    private function getClusterWarehouseMapping(): array
    {
        return [
            'cluster_msk' => ['22655170176000', '22655170177000', '22655170178000'],
            'cluster_spb' => ['22655170179000', '22655170180000'],
            'cluster_ekb' => ['22655170181000', '22655170182000'],
            'cluster_nsk' => ['22655170183000'],
            'cluster_krd' => ['22655170184000'],
            'cluster_kzn' => ['22655170185000'],
        ];
    }

    /**
     * Получить ID кластера по ID склада
     */
    private function getClusterIdByWarehouse(string $warehouseId): ?string
    {
        $mapping = $this->getClusterWarehouseMapping();
        foreach ($mapping as $clusterId => $warehouses) {
            if (in_array($warehouseId, $warehouses)) {
                return $clusterId;
            }
        }
        return null;
    }

    /**
     * Получить товары для создания поставки
     * 
     * GET /api/supplies/products-for-supply
     */
    public function getProductsForSupply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'warehouse_id' => 'nullable|string',
        ]);

        $integration = Integration::findOrFail($validated['integration_id']);

        // Получаем товары с остатками и продажами
        $products = \App\Models\Product::where('integration_id', $integration->id)
            ->where('is_active', true)
            ->with(['inventoryWarehouses' => function ($q) use ($validated) {
                $q->where('marketplace', 'ozon');
                if (!empty($validated['warehouse_id'])) {
                    $q->where('warehouse_id', $validated['warehouse_id']);
                }
            }])
            ->get();

        $data = $products->map(function ($product) {
            // Суммируем остатки по всем складам
            $inventory = $product->inventoryWarehouses;
            $currentStock = $inventory->sum('quantity');
            $inTransit = $inventory->sum('in_transit') + $inventory->sum('in_way_to_client');
            
            // Продажи
            $avgSales7d = $inventory->avg('sales_7_days') ?? 0;
            $avgSales14d = $inventory->avg('sales_14_days') ?? 0;
            $avgSales28d = $inventory->avg('sales_30_days') ?? 0;
            
            // Дней запаса
            $daysOfStock = $avgSales7d > 0 
                ? (int) round($currentStock / $avgSales7d) 
                : ($currentStock > 0 ? 999 : 0);

            // Рекомендуемое количество (на 14 дней продаж минус текущий запас)
            $targetDays = 14;
            $recommendedQty = max(0, (int) ceil($avgSales7d * $targetDays - $currentStock - $inTransit));

            // Приоритет
            $priority = match (true) {
                $daysOfStock <= 3 => 'A',
                $daysOfStock <= 7 => 'B',
                default => 'C',
            };

            return [
                'id' => $product->id,
                'sku' => $product->sku,
                'ozon_product_id' => $product->ozon_product_id ?? $product->marketplace_id,
                'name' => $product->name,
                'current_stock' => $currentStock,
                'in_transit' => $inTransit,
                'avg_sales_7d' => round($avgSales7d, 1),
                'avg_sales_14d' => round($avgSales14d, 1),
                'avg_sales_28d' => round($avgSales28d, 1),
                'days_of_stock' => min($daysOfStock, 999),
                'recommended_qty' => $recommendedQty,
                'min_order_qty' => $product->min_order_qty ?? 1,
                'pack_multiple' => $product->pack_multiple ?? 1,
                'priority' => $priority,
                'oos_risk' => $daysOfStock <= 3,
            ];
        })
        ->filter(fn($p) => $p['current_stock'] > 0 || $p['recommended_qty'] > 0 || $p['oos_risk'])
        ->sortBy('days_of_stock')
        ->values();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Создать поставку с привязкой к слоту
     * 
     * POST /api/supplies/create-with-slot
     */
    public function createWithSlot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'cluster_id' => 'required|string',
            'slot_id' => 'required|string',
            'name' => 'nullable|string|max:255',
            'comment' => 'nullable|string|max:1000',
        ]);

        $integration = Integration::findOrFail($validated['integration_id']);

        $clusterId = $validated['cluster_id'];
        $draftProducts = cache()->get($this->getSupplyDraftCacheKey($integration->id, $clusterId), []);
        $draftMeta = cache()->get($this->getSupplyDraftMetaKey($integration->id, $clusterId), []);

        if (empty($draftProducts)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'DRAFT_EMPTY',
                    'message' => 'Сначала добавьте товары в заявку',
                ],
            ], 422);
        }

        if (empty($draftMeta['delivery_type'])) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'DELIVERY_NOT_SELECTED',
                    'message' => 'Сначала выберите способ доставки (ПВЗ или СЦ)',
                ],
            ], 422);
        }

        if (empty($draftMeta['warehouse_id'])) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'WAREHOUSE_NOT_SELECTED',
                    'message' => 'Сначала выберите склад',
                ],
            ], 422);
        }

        // 1. Найти и проверить слот
        $slot = \App\Models\WarehouseSlot::where(function ($q) use ($validated) {
                $q->where('external_slot_id', $validated['slot_id'])
                  ->orWhere('id', $validated['slot_id']);
            })
            ->where('marketplace', 'ozon')
            ->first();

        if (!$slot) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SLOT_NOT_FOUND',
                    'message' => 'Слот не найден',
                ],
            ], 404);
        }

        if (!$slot->is_available || $slot->booked_by_supply_id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SLOT_NOT_AVAILABLE',
                    'message' => 'Выбранный слот уже занят или недоступен',
                ],
            ], 422);
        }

        $slotStart = $slot->from_datetime
            ?? $slot->date->setTimeFromTimeString($slot->time_from);

        if ($slotStart && $slotStart->isPast()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SLOT_EXPIRED',
                    'message' => 'Слот в прошлом',
                ],
            ], 422);
        }

        // Проверка соответствия слота выбранному складу
        if ((string) $slot->warehouse_id !== (string) $draftMeta['warehouse_id']) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'WAREHOUSE_MISMATCH',
                    'message' => 'Выбранный слот не соответствует выбранному складу',
                ],
            ], 422);
        }

        // Проверка соответствия слота кластеру
        $clusterIdFromSlot = \App\Models\OzonWarehouseCluster::getClusterIdByWarehouse($slot->warehouse_name ?? '')
            ?? \App\Models\OzonWarehouseCluster::getClusterIdByWarehouse((string) $slot->warehouse_id);

        if ($clusterIdFromSlot && (string) $clusterIdFromSlot !== (string) $clusterId) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CLUSTER_MISMATCH',
                    'message' => 'Выбранный слот не относится к выбранному кластеру',
                ],
            ], 422);
        }

        // 2. Валидация товаров
        $invalidItems = [];
        $validItems = [];

        foreach ($draftProducts as $draftItem) {
            $qty = (int) ($draftItem['quantity'] ?? 0);
            if ($qty < 1) {
                $invalidItems[] = [
                    'sku' => $draftItem['sku'] ?? null,
                    'reason' => 'Количество должно быть больше 0',
                ];
                continue;
            }

            $sku = $draftItem['sku'] ?? null;
            if (!$sku) {
                $invalidItems[] = [
                    'sku' => null,
                    'reason' => 'SKU не указан',
                ];
                continue;
            }

            $product = \App\Models\Product::where('integration_id', $integration->id)
                ->where('sku', $sku)
                ->first();

            if (!$product) {
                $invalidItems[] = [
                    'sku' => $sku,
                    'reason' => 'Товар не найден',
                ];
                continue;
            }

            // Проверка кратности
            $packMultiple = $product->pack_multiple ?? 1;
            if ($packMultiple > 1 && $qty % $packMultiple !== 0) {
                $invalidItems[] = [
                    'sku' => $sku,
                    'reason' => "Количество должно быть кратно {$packMultiple}",
                ];
                continue;
            }

            $validItems[] = [
                'product' => $product,
                'sku' => $sku,
                'quantity' => $qty,
            ];
        }

        if (!empty($invalidItems)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_ITEMS',
                    'message' => 'Некоторые товары недоступны для поставки',
                    'details' => $invalidItems,
                ],
            ], 422);
        }

        // 3. Создать поставку
        $supplyName = $validated['name']
            ?? "Поставка {$slot->date->format('d M')} {$slot->time_from}";
        $timeslotId = (string) ($slot->external_slot_id ?? $slot->id);
        $timeslotFrom = $slot->from_datetime ?? $slot->date->setTimeFromTimeString($slot->time_from);
        $timeslotTo = $slot->to_datetime ?? $slot->date->setTimeFromTimeString($slot->time_to);
        $clusterId = $clusterIdFromSlot
            ?? \App\Models\OzonWarehouseCluster::getClusterIdByWarehouse($slot->warehouse_name ?? '')
            ?? \App\Models\OzonWarehouseCluster::getClusterIdByWarehouse((string) $slot->warehouse_id)
            ?? $clusterId;
        $clusterName = \App\Models\OzonWarehouseCluster::getClusterNameByWarehouse($slot->warehouse_name ?? '')
            ?? \App\Models\OzonWarehouseCluster::getClusterNameByWarehouse((string) $slot->warehouse_id);

        try {
            $supply = \DB::transaction(function () use ($integration, $slot, $validItems, $supplyName, $validated, $timeslotId, $timeslotFrom, $timeslotTo, $clusterId, $clusterName) {
                // Создаём поставку
                $supply = Supply::create([
                    'integration_id' => $integration->id,
                    'supply_type' => Supply::TYPE_FBO,
                    'supply_method' => Supply::METHOD_DIRECT,
                    'cluster_id' => $clusterId,
                    'cluster_name' => $clusterName,
                    'warehouse_id' => $slot->warehouse_id,
                    'warehouse_name' => $slot->warehouse_name,
                    'timeslot_id' => $timeslotId,
                    'timeslot_from' => $timeslotFrom,
                    'timeslot_to' => $timeslotTo,
                    'planned_delivery_date' => $slot->date,
                    'status' => Supply::STATUS_DRAFT,
                    'meta' => [
                        'delivery_type' => $draftMeta['delivery_type'] ?? null,
                        'delivery_type_label' => $draftMeta['delivery_type_label'] ?? null,
                    ],
                    'comment' => $validated['comment'] ?? null,
                    'created_by' => auth()->id(),
                ]);

                // Добавляем позиции
                foreach ($validItems as $item) {
                    \App\Models\SupplyItem::create([
                        'supply_id' => $supply->id,
                        'product_id' => $item['product']->id,
                        'sku' => $item['sku'],
                        'ozon_product_id' => $item['product']->ozon_product_id ?? $item['product']->marketplace_id,
                        'product_name' => $item['product']->name,
                        'barcode' => $item['product']->barcode,
                        'planned_qty' => $item['quantity'],
                        'pack_multiple' => $item['product']->pack_multiple ?? 1,
                        'status' => \App\Models\SupplyItem::STATUS_PENDING,
                    ]);
                }

                // Пересчитываем итоги
                $supply->recalculateTotals();

                // Логируем событие
                $supply->logEvent(\App\Models\SupplyEvent::TYPE_CREATED, [
                    'title' => 'Поставка создана со слотом',
                    'description' => "Слот: {$slot->date->format('d.m.Y')} {$slot->time_from}-{$slot->time_to}",
                ]);

                return $supply;
            });

        } catch (\Exception $e) {
            Log::error('Failed to create supply with slot', [
                'error' => $e->getMessage(),
                'integration_id' => $integration->id,
                'slot_id' => $validated['slot_id'],
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CREATE_FAILED',
                    'message' => 'Не удалось создать поставку: ' . $e->getMessage(),
                ],
            ], 500);
        }

        try {
            $this->supplyService->createOzonDraft($supply);
        } catch (\Exception $e) {
            Log::error('Failed to create Ozon draft for supply', [
                'error' => $e->getMessage(),
                'supply_id' => $supply->id,
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'OZON_DRAFT_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }

        $warning = null;
        try {
            $this->supplyService->bookTimeslot($supply, $timeslotId);
            $slot->update([
                'booked_by_supply_id' => $supply->id,
                'booked_at' => now(),
                'is_available' => false,
            ]);
        } catch (\Exception $e) {
            $warning = $e->getMessage();
            Log::warning('Failed to book Ozon slot for supply', [
                'error' => $warning,
                'supply_id' => $supply->id,
                'timeslot_id' => $timeslotId,
            ]);
        }

        cache()->forget($this->getSupplyDraftCacheKey($integration->id, $clusterId));
        cache()->forget($this->getSupplyDraftMetaKey($integration->id, $clusterId));

        $supply->refresh();
        $slot->refresh();

        $response = [
            'success' => true,
            'data' => [
                'id' => $supply->id,
                'name' => $supplyName,
                'status' => $supply->status,
                'ozon_draft_id' => $supply->ozon_draft_id,
                'ozon_supply_id' => $supply->ozon_supply_id,
                'slot' => [
                    'id' => $timeslotId,
                    'date' => $slot->date->toDateString(),
                    'time_from' => substr($slot->time_from, 0, 5),
                    'time_to' => substr($slot->time_to, 0, 5),
                    'warehouse_name' => $slot->warehouse_name,
                    'is_booked' => $slot->booked_by_supply_id === $supply->id,
                ],
                'items_count' => count($validItems),
                'total_qty' => collect($validItems)->sum('quantity'),
                'created_at' => $supply->created_at->toIso8601String(),
            ],
            'message' => $warning
                ? 'Черновик создан в Ozon, но слот не забронирован.'
                : 'Поставка создана. Слот забронирован.',
        ];

        if ($warning) {
            $response['warning'] = [
                'code' => 'OZON_SLOT_ERROR',
                'message' => $warning,
            ];
        }

        return response()->json($response, 201);
    }

    /**
     * Синхронизировать слоты с Ozon
     * 
     * POST /api/supplies/sync-slots
     */
    public function syncSlots(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'async' => 'nullable|boolean',
        ]);

        $integration = Integration::findOrFail($validated['integration_id']);

        if ($integration->marketplace !== 'ozon') {
            return response()->json([
                'success' => false,
                'message' => 'Синхронизация слотов доступна только для Ozon',
            ], 422);
        }

        if ($request->boolean('async', true)) {
            \App\Jobs\SyncWarehouseSlotsJob::dispatch($integration->id);

            return response()->json([
                'success' => true,
                'message' => 'Синхронизация запущена',
                'job_id' => 'job_' . uniqid(),
            ]);
        }

        // Синхронная синхронизация
        try {
            $job = new \App\Jobs\SyncWarehouseSlotsJob($integration->id);
            $job->handle();

            $slotsCount = \App\Models\WarehouseSlot::where('marketplace', 'ozon')
                ->where('synced_at', '>=', now()->subMinutes(5))
                ->count();

            return response()->json([
                'success' => true,
                'message' => 'Синхронизация завершена',
                'data' => [
                    'slots_synced' => $slotsCount,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка синхронизации: ' . $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Рассчитать базовую аналитику на лету
     */
    private function calculateBasicAnalytics(int $integrationId, $startDate, $endDate): array
    {
        $supplies = Supply::where('integration_id', $integrationId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        $completed = $supplies->whereIn('status', ['accepted_full', 'accepted_partial', 'closed'])->count();
        $cancelled = $supplies->where('status', 'cancelled')->count();
        $errors = $supplies->where('status', 'error')->count();

        $recommendations = SupplyRecommendation::where('integration_id', $integrationId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        $oosRiskCount = $recommendations->where('oos_risk', true)->count();

        return [
            'period_start' => $startDate->toDateString(),
            'period_end' => $endDate->toDateString(),
            'oos_rate' => $recommendations->count() > 0 
                ? round(($oosRiskCount / $recommendations->count()) * 100, 2) 
                : 0,
            'forecast_accuracy' => null,
            'avg_lead_time_hours' => null,
            'total_supplies' => $supplies->count(),
            'completed_supplies' => $completed,
            'cancelled_supplies' => $cancelled,
            'error_supplies' => $errors,
            'total_items' => $supplies->sum('items_count'),
            'total_quantity' => $supplies->sum('total_quantity'),
            'acceptance_rate' => $completed > 0 ? 100 : null,
            'calculated_at' => now()->toIso8601String(),
            'is_realtime' => true,
        ];
    }

    private function getSupplyDraftCacheKey(int $integrationId, string $clusterId): string
    {
        return "supply_draft_{$integrationId}_{$clusterId}";
    }

    private function getSupplyDraftMetaKey(int $integrationId, string $clusterId): string
    {
        return "supply_draft_meta_{$integrationId}_{$clusterId}";
    }
}
