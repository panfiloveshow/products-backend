<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\Supply;
use App\Models\SupplyAnalytics;
use App\Models\SupplyRecommendation;
use App\Models\SupplySettings;
use App\Services\Supply\SupplyRecommendationService;
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
        protected SupplyRecommendationService $recommendationService,
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
    public function show(int $id): JsonResponse
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
    public function getEvents(int $id): JsonResponse
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

        // Fallback: генерируем демо-кластеры
        $fallbackClusters = $this->generateFallbackClusters($integration);

        return response()->json([
            'success' => true,
            'data' => $fallbackClusters,
            'source' => 'fallback',
            'period_days' => $periodDays,
            'message' => 'Используются демо-данные. Синхронизируйте кластеры с Ozon.',
        ]);
    }

    /**
     * Получить рекомендации товаров для кластера
     * 
     * GET /api/supplies/clusters/{clusterId}/products
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

        try {
            $ozon = \App\Domains\Ozon\OzonMarketplace::fromIntegration($integration);
            
            if ($tab === 'recommendations') {
                // Получаем рекомендации из Ozon API
                $products = $ozon->supplies()->getClusterRecommendations($clusterId, $periodDays);
                
                if (!empty($products)) {
                    // Обогащаем данными из нашей БД
                    $products = $this->enrichProductsWithLocalData($integration, $products);
                    
                    // Считаем статистику
                    $totalSku = count($products);
                    $totalUnits = array_sum(array_column($products, 'recommended_qty'));
                    $totalVolume = array_sum(array_column($products, 'volume'));
                    
                    return response()->json([
                        'success' => true,
                        'data' => [
                            'products' => $products,
                            'summary' => [
                                'total_sku' => $totalSku,
                                'total_units' => $totalUnits,
                                'total_volume' => round($totalVolume, 2),
                            ],
                        ],
                        'cluster_id' => $clusterId,
                        'period_days' => $periodDays,
                        'tab' => $tab,
                        'source' => 'ozon_api',
                    ]);
                }
            } else {
                // Товары в заявке — пока пустой список (заполняется пользователем)
                return response()->json([
                    'success' => true,
                    'data' => [
                        'products' => [],
                        'summary' => [
                            'total_sku' => 0,
                            'total_units' => 0,
                            'total_volume' => 0,
                        ],
                    ],
                    'cluster_id' => $clusterId,
                    'tab' => $tab,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to get cluster products from Ozon API', [
                'integration_id' => $integration->id,
                'cluster_id' => $clusterId,
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback: генерируем демо-данные на основе наших товаров
        $fallbackProducts = $this->generateFallbackClusterProducts($integration, $clusterId, $periodDays);

        return response()->json([
            'success' => true,
            'data' => $fallbackProducts,
            'cluster_id' => $clusterId,
            'period_days' => $periodDays,
            'tab' => $tab,
            'source' => 'fallback',
        ]);
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
        $cacheKey = "supply_draft_{$integration->id}_{$clusterId}";
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
     * Обогатить кластеры реальными данными из Ozon API
     * 
     * Логика расчёта рекомендаций по кластерам:
     * 1. Получаем общие рекомендации из Ozon API (товары с низким запасом)
     * 2. Для каждого кластера считаем, какие товары нужно поставить на его склады
     *    на основе текущих остатков и средних продаж
     */
    private function enrichClustersWithRealData(Integration $integration, $ozon, array $apiClusters, int $periodDays): array
    {
        $result = [];
        
        // Получаем общие рекомендации один раз
        $allRecommendations = [];
        try {
            $allRecommendations = $ozon->supplies()->getSupplyRecommendations(1000, 0);
        } catch (\Exception $e) {
            Log::warning('Failed to get supply recommendations', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);
        }
        
        // Получаем остатки по складам из нашей БД
        // В нашей БД warehouse_id — это название склада (ПЕТРОВСКОЕ_РФЦ)
        $warehouseStocks = \App\Models\InventoryWarehouse::where('integration_id', $integration->id)
            ->where('marketplace', 'ozon')
            ->where('quantity', '>', 0)
            ->select('warehouse_id', 'warehouse_name', 'sku', 'quantity', 'average_daily_sales', 'days_of_stock')
            ->get()
            ->groupBy('warehouse_name'); // Группируем по названию склада
        
        // Создаём маппинг warehouse_name -> cluster_id
        // Ozon API возвращает warehouse_ids как числовые ID, но также есть названия в logistic_clusters
        $warehouseNameToCluster = [];
        foreach ($apiClusters as $cluster) {
            $clusterId = $cluster['id'];
            $clusterName = $cluster['name'] ?? '';
            
            // Определяем кластер по ключевым словам в названии склада
            // Москва: ДОМОДЕДОВО, ХОРУГВИНО, ПЕТРОВСКОЕ, СОФЬИНО, ТВЕРЬ
            // СПб: СПБ_, ШУШАРЫ, БУГРЫ, КОЛПИНО, ПОРОШКИНО
            // Екатеринбург: ЕКБ_, ЕКАТЕРИНБУРГ
            // и т.д.
            $clusterKeywords = $this->getClusterKeywords($clusterName);
            foreach ($clusterKeywords as $keyword) {
                $warehouseNameToCluster[strtoupper($keyword)] = $clusterId;
            }
        }
        
        // Группируем остатки по кластерам
        $clusterStocks = [];
        foreach ($warehouseStocks as $warehouseName => $stocks) {
            // Ищем кластер по названию склада
            $clusterId = null;
            $upperName = strtoupper($warehouseName);
            foreach ($warehouseNameToCluster as $keyword => $cId) {
                if (strpos($upperName, $keyword) !== false) {
                    $clusterId = $cId;
                    break;
                }
            }
            if (!$clusterId) continue;
            
            if (!isset($clusterStocks[$clusterId])) {
                $clusterStocks[$clusterId] = [];
            }
            
            foreach ($stocks as $stock) {
                $sku = $stock->sku;
                if (!isset($clusterStocks[$clusterId][$sku])) {
                    $clusterStocks[$clusterId][$sku] = [
                        'quantity' => 0,
                        'avg_sales' => $stock->average_daily_sales ?? 0,
                        'days_of_stock' => $stock->days_of_stock ?? 0,
                    ];
                }
                $clusterStocks[$clusterId][$sku]['quantity'] += $stock->quantity;
            }
        }
        
        foreach ($apiClusters as $cluster) {
            $clusterId = $cluster['id'];
            $warehouseIds = $cluster['warehouse_ids'] ?? [];
            $warehousesCount = $cluster['warehouses_count'] ?? count($warehouseIds);
            
            // Считаем рекомендации для этого кластера
            $clusterSkuData = $clusterStocks[$clusterId] ?? [];
            
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
            
            // Фильтруем товары, которым нужна поставка в этот кластер
            // Только товары, которые УЖЕ есть на складах этого кластера
            $recommendedSkus = [];
            $totalUnits = 0;
            
            foreach ($clusterSkuData as $sku => $stockData) {
                $currentStock = $stockData['quantity'] ?? 0;
                $avgSales = $stockData['avg_sales'] ?? 0;
                $daysOfStock = $stockData['days_of_stock'] ?? 0;
                
                // Рекомендуемое количество: на 28 дней минус текущий запас
                $neededQty = max(0, ceil($avgSales * $periodDays) - $currentStock);
                
                // Добавляем в рекомендации если нужна поставка (запас < 28 дней)
                if ($neededQty > 0 || $daysOfStock < $periodDays) {
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
                'units_count' => $totalUnits,
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
     * Генерация fallback кластеров для демонстрации
     */
    private function generateFallbackClusters(Integration $integration): array
    {
        // Получаем реальную статистику по остаткам
        $totalStats = \App\Models\InventoryWarehouse::where('integration_id', $integration->id)
            ->where('marketplace', 'ozon')
            ->selectRaw('COUNT(DISTINCT sku) as sku_count, SUM(quantity) as units_count, AVG(days_of_stock) as avg_days')
            ->first();

        $skuCount = $totalStats->sku_count ?? 0;
        $unitsCount = $totalStats->units_count ?? 0;
        $avgDays = $totalStats->avg_days ?? 14;

        // Распределяем по демо-кластерам
        return [
            [
                'id' => 'cluster_msk',
                'name' => 'Москва и МО',
                'warehouses_count' => 5,
                'sku_count' => (int) round($skuCount * 0.4),
                'units_count' => (int) round($unitsCount * 0.4),
                'days_of_stock' => max(3, (int) round($avgDays * 0.8)),
            ],
            [
                'id' => 'cluster_spb',
                'name' => 'Санкт-Петербург',
                'warehouses_count' => 3,
                'sku_count' => (int) round($skuCount * 0.2),
                'units_count' => (int) round($unitsCount * 0.2),
                'days_of_stock' => max(5, (int) round($avgDays * 1.0)),
            ],
            [
                'id' => 'cluster_ekb',
                'name' => 'Екатеринбург',
                'warehouses_count' => 2,
                'sku_count' => (int) round($skuCount * 0.15),
                'units_count' => (int) round($unitsCount * 0.15),
                'days_of_stock' => max(7, (int) round($avgDays * 1.2)),
            ],
            [
                'id' => 'cluster_nsk',
                'name' => 'Новосибирск',
                'warehouses_count' => 2,
                'sku_count' => (int) round($skuCount * 0.1),
                'units_count' => (int) round($unitsCount * 0.1),
                'days_of_stock' => max(10, (int) round($avgDays * 1.5)),
            ],
            [
                'id' => 'cluster_krd',
                'name' => 'Краснодар',
                'warehouses_count' => 2,
                'sku_count' => (int) round($skuCount * 0.1),
                'units_count' => (int) round($unitsCount * 0.1),
                'days_of_stock' => max(12, (int) round($avgDays * 1.3)),
            ],
            [
                'id' => 'cluster_kzn',
                'name' => 'Казань',
                'warehouses_count' => 1,
                'sku_count' => (int) round($skuCount * 0.05),
                'units_count' => (int) round($unitsCount * 0.05),
                'days_of_stock' => max(14, (int) round($avgDays * 1.4)),
            ],
        ];
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

        // Если слотов нет — пробуем синхронизировать или возвращаем fallback
        if ($slots->isEmpty()) {
            // Запускаем синхронизацию в фоне
            \App\Jobs\SyncWarehouseSlotsJob::dispatch($integration->id);

            // Возвращаем fallback-слоты для демонстрации
            $fallbackSlots = $this->generateFallbackSlots($dateFrom, $dateTo, $clusterIds);
            
            return response()->json([
                'success' => true,
                'data' => $fallbackSlots,
                'meta' => [
                    'synced_at' => null,
                    'total' => count($fallbackSlots),
                    'source' => 'fallback',
                    'cluster_ids' => $clusterIds,
                    'message' => 'Синхронизация слотов запущена. Обновите страницу через минуту.',
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
            'slot_id' => 'required|string',
            'items' => 'required|array|min:1',
            'items.*.sku' => 'required|string',
            'items.*.qty' => 'required|integer|min:1',
            'name' => 'nullable|string|max:255',
            'comment' => 'nullable|string|max:1000',
        ]);

        $integration = Integration::findOrFail($validated['integration_id']);

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

        if ($slot->date->isPast()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SLOT_EXPIRED',
                    'message' => 'Слот в прошлом',
                ],
            ], 422);
        }

        // 2. Валидация товаров
        $invalidItems = [];
        $validItems = [];

        foreach ($validated['items'] as $item) {
            $product = \App\Models\Product::where('integration_id', $integration->id)
                ->where('sku', $item['sku'])
                ->first();

            if (!$product) {
                $invalidItems[] = [
                    'sku' => $item['sku'],
                    'reason' => 'Товар не найден',
                ];
                continue;
            }

            // Проверка кратности
            $packMultiple = $product->pack_multiple ?? 1;
            if ($packMultiple > 1 && $item['qty'] % $packMultiple !== 0) {
                $invalidItems[] = [
                    'sku' => $item['sku'],
                    'reason' => "Количество должно быть кратно {$packMultiple}",
                ];
                continue;
            }

            $validItems[] = [
                'product' => $product,
                'sku' => $item['sku'],
                'quantity' => $item['qty'],
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
        try {
            $supplyName = $validated['name'] 
                ?? "Поставка {$slot->date->format('d M')} {$slot->time_from}";

            $supply = \DB::transaction(function () use ($integration, $slot, $validItems, $supplyName, $validated) {
                // Создаём поставку
                $supply = Supply::create([
                    'integration_id' => $integration->id,
                    'supply_type' => Supply::TYPE_FBO,
                    'supply_method' => Supply::METHOD_DIRECT,
                    'warehouse_id' => $slot->warehouse_id,
                    'warehouse_name' => $slot->warehouse_name,
                    'timeslot_id' => $slot->external_slot_id ?? $slot->id,
                    'timeslot_from' => $slot->from_datetime ?? $slot->date->setTimeFromTimeString($slot->time_from),
                    'timeslot_to' => $slot->to_datetime ?? $slot->date->setTimeFromTimeString($slot->time_to),
                    'planned_delivery_date' => $slot->date,
                    'status' => Supply::STATUS_DRAFT,
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

                // Бронируем слот
                $slot->update([
                    'booked_by_supply_id' => $supply->id,
                    'booked_at' => now(),
                    'is_available' => false,
                ]);

                // Логируем событие
                $supply->logEvent(\App\Models\SupplyEvent::TYPE_CREATED, [
                    'title' => 'Поставка создана со слотом',
                    'description' => "Слот: {$slot->date->format('d.m.Y')} {$slot->time_from}-{$slot->time_to}",
                ]);

                return $supply;
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $supply->id,
                    'name' => $supplyName,
                    'status' => $supply->status,
                    'ozon_supply_id' => $supply->ozon_supply_id,
                    'slot' => [
                        'id' => $slot->external_slot_id ?? $slot->id,
                        'date' => $slot->date->toDateString(),
                        'time_from' => substr($slot->time_from, 0, 5),
                        'time_to' => substr($slot->time_to, 0, 5),
                        'warehouse_name' => $slot->warehouse_name,
                        'is_booked' => true,
                    ],
                    'items_count' => count($validItems),
                    'total_qty' => collect($validItems)->sum('quantity'),
                    'created_at' => $supply->created_at->toIso8601String(),
                ],
                'message' => 'Поставка создана. Слот забронирован.',
            ], 201);

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
     * Генерация fallback слотов для демонстрации
     */
    private function generateFallbackSlots(string $dateFrom, string $dateTo, array $clusterIds = []): array
    {
        $slots = [];
        $startDate = \Carbon\Carbon::parse($dateFrom);
        $endDate = \Carbon\Carbon::parse($dateTo);
        
        // Все склады по кластерам
        $allWarehouses = [
            'cluster_msk' => [
                ['id' => '22655170176000', 'name' => 'Хоругвино'],
                ['id' => '22655170177000', 'name' => 'Коледино'],
                ['id' => '22655170178000', 'name' => 'Подольск'],
            ],
            'cluster_spb' => [
                ['id' => '22655170179000', 'name' => 'Шушары'],
                ['id' => '22655170180000', 'name' => 'Бугры'],
            ],
            'cluster_ekb' => [
                ['id' => '22655170181000', 'name' => 'Екатеринбург'],
            ],
            'cluster_nsk' => [
                ['id' => '22655170183000', 'name' => 'Новосибирск'],
            ],
            'cluster_krd' => [
                ['id' => '22655170184000', 'name' => 'Краснодар'],
            ],
            'cluster_kzn' => [
                ['id' => '22655170185000', 'name' => 'Казань'],
            ],
        ];

        // Фильтруем склады по выбранным кластерам
        $warehouses = [];
        if (empty($clusterIds)) {
            // Если кластеры не выбраны — показываем все
            foreach ($allWarehouses as $clusterId => $clusterWarehouses) {
                foreach ($clusterWarehouses as $wh) {
                    $wh['cluster_id'] = $clusterId;
                    $warehouses[] = $wh;
                }
            }
        } else {
            foreach ($clusterIds as $clusterId) {
                if (isset($allWarehouses[$clusterId])) {
                    foreach ($allWarehouses[$clusterId] as $wh) {
                        $wh['cluster_id'] = $clusterId;
                        $warehouses[] = $wh;
                    }
                }
            }
        }

        while ($startDate <= $endDate) {
            // Пропускаем выходные
            if (!$startDate->isWeekend()) {
                foreach ($warehouses as $warehouse) {
                    // Утренний слот
                    $slots[] = [
                        'id' => 'demo_' . $startDate->format('Ymd') . '_' . $warehouse['id'] . '_am',
                        'date' => $startDate->toDateString(),
                        'time_from' => '09:00',
                        'time_to' => '12:00',
                        'warehouse_id' => $warehouse['id'],
                        'warehouse_name' => $warehouse['name'],
                        'cluster_id' => $warehouse['cluster_id'],
                        'is_available' => true,
                        'capacity' => 100,
                        'capacity_used' => rand(20, 60),
                        'coefficient' => rand(0, 1) ? 1.0 : 1.5,
                        'is_demo' => true,
                    ];

                    // Дневной слот
                    $slots[] = [
                        'id' => 'demo_' . $startDate->format('Ymd') . '_' . $warehouse['id'] . '_pm',
                        'date' => $startDate->toDateString(),
                        'time_from' => '14:00',
                        'time_to' => '17:00',
                        'warehouse_id' => $warehouse['id'],
                        'warehouse_name' => $warehouse['name'],
                        'cluster_id' => $warehouse['cluster_id'],
                        'is_available' => true,
                        'capacity' => 100,
                        'capacity_used' => rand(30, 80),
                        'coefficient' => rand(0, 1) ? 1.0 : 2.0,
                        'is_demo' => true,
                    ];
                }
            }

            $startDate->addDay();
        }

        return $slots;
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
}
