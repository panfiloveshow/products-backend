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
    // СЛОТЫ И СОЗДАНИЕ ПОСТАВКИ СО СЛОТОМ (новый flow фронтенда)
    // ========================================================================

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

        $slots = $query->get();

        // Если слотов нет — пробуем синхронизировать или возвращаем fallback
        if ($slots->isEmpty()) {
            // Запускаем синхронизацию в фоне
            \App\Jobs\SyncWarehouseSlotsJob::dispatch($integration->id);

            // Возвращаем fallback-слоты для демонстрации
            $fallbackSlots = $this->generateFallbackSlots($dateFrom, $dateTo);
            
            return response()->json([
                'success' => true,
                'data' => $fallbackSlots,
                'meta' => [
                    'synced_at' => null,
                    'total' => count($fallbackSlots),
                    'source' => 'fallback',
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
            ],
        ]);
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
    private function generateFallbackSlots(string $dateFrom, string $dateTo): array
    {
        $slots = [];
        $startDate = \Carbon\Carbon::parse($dateFrom);
        $endDate = \Carbon\Carbon::parse($dateTo);
        
        $warehouses = [
            ['id' => '22655170176000', 'name' => 'Хоругвино'],
            ['id' => '22655170177000', 'name' => 'Коледино'],
        ];

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
