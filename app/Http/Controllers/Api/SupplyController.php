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
