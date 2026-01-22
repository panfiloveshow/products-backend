<?php

namespace App\Http\Controllers\Api;

use App\Domains\Ozon\OzonMarketplace;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Контроллер для работы с черновиками поставок Ozon
 * 
 * Legacy endpoints (до 16.02.2026):
 * - POST /api/ozon/draft/create - создание черновика (warehouse_id)
 * - POST /api/ozon/draft/info - информация о черновике
 * - POST /api/ozon/draft/timeslots - получение таймслотов
 * - POST /api/ozon/draft/supply/create - создание поставки из черновика
 * - POST /api/ozon/supply/create/status - статус создания поставки
 * 
 * New endpoints (с 16.02.2026 - кластерная модель):
 * - POST /api/ozon/clusters - список макролокальных кластеров
 * - POST /api/ozon/draft/direct/create - прямая поставка
 * - POST /api/ozon/draft/crossdock/create - кросс-док поставка
 * - POST /api/ozon/draft/multi-cluster/create - мультикластерная поставка
 * - POST /api/ozon/draft/v2/info - статус и расчёты черновика
 * - POST /api/ozon/draft/v2/timeslots - таймслоты для черновика
 * - POST /api/ozon/draft/v2/supply/create - создание поставки (новый API)
 * - POST /api/ozon/warehouses/fbo - список складов FBO
 * - POST /api/ozon/warehouses/seller - список складов продавца
 * - POST /api/ozon/cargoes - грузоместа в поставке
 * 
 * @see https://dev.ozon.ru/news/647-Izmeneniia-v-metodakh-Seller-API-pri-rabote-s-postavkami-FBO/
 */
class OzonDraftController extends Controller
{
    /**
     * Создать черновик поставки
     * 
     * POST /api/ozon/draft/create
     * 
     * Request body:
     * {
     *   "integration_id": 1,
     *   "warehouse_id": 22655170176000,
     *   "items": [
     *     {"offer_id": "SKU-001", "quantity": 10},
     *     {"offer_id": "SKU-002", "quantity": 5}
     *   ]
     * }
     */
    public function createDraft(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'warehouse_id' => 'required|integer',
            'items' => 'nullable|array',
            'items.*.offer_id' => 'required_with:items|string',
            'items.*.quantity' => 'required_with:items|integer|min:1',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            
            $result = $ozon->supplies()->createSupplyDraft([
                'warehouse_id' => $validated['warehouse_id'],
                'items' => $validated['items'] ?? [],
            ]);

            Log::info('Ozon draft created', [
                'integration_id' => $validated['integration_id'],
                'draft_id' => $result['id'] ?? null,
            ]);

            return response()->json([
                'success' => true,
                'data' => $result,
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create Ozon draft', [
                'error' => $e->getMessage(),
                'integration_id' => $validated['integration_id'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Получить информацию о черновике/поставке
     * 
     * POST /api/ozon/draft/info
     * 
     * Request body:
     * {
     *   "integration_id": 1,
     *   "supply_order_id": 123456789
     * }
     */
    public function getDraftInfo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'supply_order_id' => 'required|integer',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            
            $supply = $ozon->supplies()->getSupplyDetails((string) $validated['supply_order_id']);
            
            if (!$supply) {
                return response()->json([
                    'success' => false,
                    'message' => 'Поставка не найдена',
                ], 404);
            }

            $items = $ozon->supplies()->getSupplyProducts((string) $validated['supply_order_id']);

            return response()->json([
                'success' => true,
                'data' => [
                    'supply' => $supply,
                    'items' => $items,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get Ozon draft info', [
                'error' => $e->getMessage(),
                'supply_order_id' => $validated['supply_order_id'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Получить доступные таймслоты для поставки
     * 
     * POST /api/ozon/draft/timeslots
     * 
     * Request body:
     * {
     *   "integration_id": 1,
     *   "warehouse_id": 22655170176000,
     *   "date_from": "2026-01-15",
     *   "date_to": "2026-01-30"
     * }
     */
    public function getTimeslots(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'warehouse_id' => 'required|integer',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            
            $slots = $ozon->supplies()->getAcceptanceSlots(
                (string) $validated['warehouse_id'],
                $validated['date_from'] ?? null,
                $validated['date_to'] ?? null
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'timeslots' => $slots,
                    'warehouse_id' => $validated['warehouse_id'],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get Ozon timeslots', [
                'error' => $e->getMessage(),
                'warehouse_id' => $validated['warehouse_id'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Создать поставку из черновика (забронировать слот)
     * 
     * POST /api/ozon/draft/supply/create
     * 
     * Request body:
     * {
     *   "integration_id": 1,
     *   "supply_order_id": 123456789,
     *   "timeslot_id": 987654321
     * }
     */
    public function createSupplyFromDraft(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'supply_order_id' => 'required|integer',
            'timeslot_id' => 'required|integer',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            
            $result = $ozon->supplies()->bookAcceptanceSlot(
                (string) $validated['supply_order_id'],
                (string) $validated['timeslot_id']
            );

            Log::info('Ozon supply created from draft', [
                'integration_id' => $validated['integration_id'],
                'supply_order_id' => $validated['supply_order_id'],
                'timeslot_id' => $validated['timeslot_id'],
            ]);

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'Поставка создана, слот забронирован',
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create Ozon supply from draft', [
                'error' => $e->getMessage(),
                'supply_order_id' => $validated['supply_order_id'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Получить статус создания поставки
     * 
     * POST /api/ozon/supply/create/status
     * 
     * Request body:
     * {
     *   "integration_id": 1,
     *   "supply_order_id": 123456789
     * }
     */
    public function getSupplyCreateStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'supply_order_id' => 'required|integer',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            
            $supply = $ozon->supplies()->getSupplyDetails((string) $validated['supply_order_id']);
            
            if (!$supply) {
                return response()->json([
                    'success' => false,
                    'message' => 'Поставка не найдена',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'supply_order_id' => $validated['supply_order_id'],
                    'status' => $supply['status'] ?? 'unknown',
                    'status_code' => $supply['status_code'] ?? null,
                    'warehouse_id' => $supply['warehouse_id'] ?? null,
                    'warehouse_name' => $supply['warehouse_name'] ?? null,
                    'timeslot_from' => $supply['timeslot_from'] ?? null,
                    'timeslot_to' => $supply['timeslot_to'] ?? null,
                    'items_count' => $supply['items_count'] ?? 0,
                    'created_at' => $supply['created_at'] ?? null,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get Ozon supply status', [
                'error' => $e->getMessage(),
                'supply_order_id' => $validated['supply_order_id'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Получить список складов Ozon
     * 
     * POST /api/ozon/warehouses
     * 
     * Request body:
     * {
     *   "integration_id": 1
     * }
     */
    public function getWarehouses(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            
            $warehouses = $ozon->supplies()->getAvailableWarehouses();

            return response()->json([
                'success' => true,
                'data' => [
                    'warehouses' => $warehouses,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get Ozon warehouses', [
                'error' => $e->getMessage(),
                'integration_id' => $validated['integration_id'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Добавить товары в поставку
     * 
     * POST /api/ozon/draft/items/add
     * 
     * Request body:
     * {
     *   "integration_id": 1,
     *   "supply_order_id": 123456789,
     *   "items": [
     *     {"offer_id": "SKU-001", "quantity": 10}
     *   ]
     * }
     */
    public function addItems(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'supply_order_id' => 'required|integer',
            'items' => 'required|array|min:1',
            'items.*.offer_id' => 'required|string',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            
            $success = $ozon->supplies()->addItemsToSupply(
                (string) $validated['supply_order_id'],
                $validated['items']
            );

            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось добавить товары',
                ], 422);
            }

            Log::info('Items added to Ozon supply', [
                'integration_id' => $validated['integration_id'],
                'supply_order_id' => $validated['supply_order_id'],
                'items_count' => count($validated['items']),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Товары добавлены',
                'data' => [
                    'supply_order_id' => $validated['supply_order_id'],
                    'items_added' => count($validated['items']),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to add items to Ozon supply', [
                'error' => $e->getMessage(),
                'supply_order_id' => $validated['supply_order_id'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    // ========================================================================
    // НОВЫЕ МЕТОДЫ ДЛЯ КЛАСТЕРНОЙ МОДЕЛИ (с 16.02.2026)
    // ========================================================================

    /**
     * Получить список макролокальных кластеров
     * 
     * POST /api/ozon/clusters
     * 
     * Request body:
     * {
     *   "integration_id": 1
     * }
     */
    public function getClusters(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            
            $clusters = $ozon->supplies()->getClusters();

            return response()->json([
                'success' => true,
                'data' => [
                    'clusters' => $clusters,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get Ozon clusters', [
                'error' => $e->getMessage(),
                'integration_id' => $validated['integration_id'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Создать черновик прямой поставки
     * 
     * POST /api/ozon/draft/direct/create
     * 
     * Request body:
     * {
     *   "integration_id": 1,
     *   "macrolocal_cluster_id": "cluster_123",
     *   "items": [
     *     {"sku": "SKU-001", "quantity": 10}
     *   ]
     * }
     */
    public function createDirectDraft(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'macrolocal_cluster_id' => 'required|string',
            'items' => 'nullable|array',
            'items.*.sku' => 'required_with:items|string',
            'items.*.quantity' => 'required_with:items|integer|min:1',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            
            $result = $ozon->supplies()->createDirectDraft([
                'macrolocal_cluster_id' => $validated['macrolocal_cluster_id'],
                'items' => $validated['items'] ?? [],
            ]);

            Log::info('Ozon direct draft created', [
                'integration_id' => $validated['integration_id'],
                'draft_id' => $result['draft_id'] ?? null,
                'cluster_id' => $validated['macrolocal_cluster_id'],
            ]);

            return response()->json([
                'success' => true,
                'data' => $result,
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create Ozon direct draft', [
                'error' => $e->getMessage(),
                'integration_id' => $validated['integration_id'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Создать черновик кросс-док поставки
     * 
     * POST /api/ozon/draft/crossdock/create
     * 
     * Request body:
     * {
     *   "integration_id": 1,
     *   "macrolocal_cluster_id": "cluster_123",
     *   "delivery_scheme": "drop_off",
     *   "point_id": "point_456",
     *   "point_type": "PVZ",
     *   "items": [...]
     * }
     * 
     * или для Pick Up:
     * {
     *   "integration_id": 1,
     *   "macrolocal_cluster_id": "cluster_123",
     *   "delivery_scheme": "pick_up",
     *   "seller_warehouse_id": "seller_wh_789",
     *   "items": [...]
     * }
     */
    public function createCrossdockDraft(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'macrolocal_cluster_id' => 'required|string',
            'delivery_scheme' => 'required|in:drop_off,pick_up',
            'point_id' => 'required_if:delivery_scheme,drop_off|string|nullable',
            'point_type' => 'required_if:delivery_scheme,drop_off|string|nullable',
            'seller_warehouse_id' => 'required_if:delivery_scheme,pick_up|string|nullable',
            'items' => 'nullable|array',
            'items.*.sku' => 'required_with:items|string',
            'items.*.quantity' => 'required_with:items|integer|min:1',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            
            $result = $ozon->supplies()->createCrossdockDraft($validated);

            Log::info('Ozon crossdock draft created', [
                'integration_id' => $validated['integration_id'],
                'draft_id' => $result['draft_id'] ?? null,
                'cluster_id' => $validated['macrolocal_cluster_id'],
                'delivery_scheme' => $validated['delivery_scheme'],
            ]);

            return response()->json([
                'success' => true,
                'data' => $result,
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create Ozon crossdock draft', [
                'error' => $e->getMessage(),
                'integration_id' => $validated['integration_id'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Создать черновик мультикластерной поставки
     * 
     * POST /api/ozon/draft/multi-cluster/create
     * 
     * Request body:
     * {
     *   "integration_id": 1,
     *   "cluster_ids": ["cluster_1", "cluster_2"],
     *   "delivery_scheme": "drop_off",
     *   "point_id": "point_456",
     *   "point_type": "PVZ",
     *   "items": [...]
     * }
     */
    public function createMultiClusterDraft(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'cluster_ids' => 'required|array|min:1',
            'cluster_ids.*' => 'string',
            'delivery_scheme' => 'required|in:drop_off,pick_up',
            'point_id' => 'required_if:delivery_scheme,drop_off|string|nullable',
            'point_type' => 'required_if:delivery_scheme,drop_off|string|nullable',
            'seller_warehouse_id' => 'required_if:delivery_scheme,pick_up|string|nullable',
            'items' => 'nullable|array',
            'items.*.sku' => 'required_with:items|string',
            'items.*.quantity' => 'required_with:items|integer|min:1',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            
            $result = $ozon->supplies()->createMultiClusterDraft($validated);

            Log::info('Ozon multi-cluster draft created', [
                'integration_id' => $validated['integration_id'],
                'draft_id' => $result['draft_id'] ?? null,
                'cluster_ids' => $validated['cluster_ids'],
                'delivery_scheme' => $validated['delivery_scheme'],
            ]);

            return response()->json([
                'success' => true,
                'data' => $result,
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create Ozon multi-cluster draft', [
                'error' => $e->getMessage(),
                'integration_id' => $validated['integration_id'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Получить статус и расчёты черновика (новый API v2)
     * 
     * POST /api/ozon/draft/v2/info
     * 
     * Request body:
     * {
     *   "integration_id": 1,
     *   "draft_id": "draft_123"
     * }
     */
    public function getDraftInfoV2(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'draft_id' => 'required|string',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            
            $info = $ozon->supplies()->getDraftInfo($validated['draft_id']);

            return response()->json([
                'success' => true,
                'data' => $info,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get Ozon draft info v2', [
                'error' => $e->getMessage(),
                'draft_id' => $validated['draft_id'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Получить таймслоты для черновика (новый API v2)
     * 
     * POST /api/ozon/draft/v2/timeslots
     * 
     * Request body:
     * {
     *   "integration_id": 1,
     *   "draft_id": "draft_123",
     *   "warehouse_id": 22655170176000
     * }
     */
    public function getDraftTimeslotsV2(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'draft_id' => 'required|string',
            'warehouse_id' => 'required|string',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            
            $slots = $ozon->supplies()->getDraftTimeslots(
                $validated['draft_id'],
                $validated['warehouse_id']
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'timeslots' => $slots,
                    'draft_id' => $validated['draft_id'],
                    'warehouse_id' => $validated['warehouse_id'],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get Ozon draft timeslots v2', [
                'error' => $e->getMessage(),
                'draft_id' => $validated['draft_id'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Создать поставку из черновика (новый API v2)
     * 
     * POST /api/ozon/draft/v2/supply/create
     * 
     * Request body:
     * {
     *   "integration_id": 1,
     *   "draft_id": "draft_123",
     *   "warehouse_id": "22655170176000",
     *   "timeslot_id": "987654321"
     * }
     */
    public function createSupplyFromDraftV2(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'draft_id' => 'required|string',
            'warehouse_id' => 'required|string',
            'timeslot_id' => 'required|string',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            
            $result = $ozon->supplies()->createSupplyFromDraft(
                $validated['draft_id'],
                $validated['warehouse_id'],
                $validated['timeslot_id']
            );

            Log::info('Ozon supply created from draft v2', [
                'integration_id' => $validated['integration_id'],
                'draft_id' => $validated['draft_id'],
                'warehouse_id' => $validated['warehouse_id'],
                'timeslot_id' => $validated['timeslot_id'],
                'supply_order_id' => $result['supply_order_id'] ?? null,
            ]);

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'Поставка создана',
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create Ozon supply from draft v2', [
                'error' => $e->getMessage(),
                'draft_id' => $validated['draft_id'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Получить статус создания поставки (новый API v2)
     * 
     * POST /api/ozon/draft/v2/supply/status
     * 
     * Request body:
     * {
     *   "integration_id": 1,
     *   "draft_id": "draft_123"
     * }
     */
    public function getSupplyCreateStatusV2(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'draft_id' => 'required|string',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            
            $status = $ozon->supplies()->getSupplyCreateStatus($validated['draft_id']);

            return response()->json([
                'success' => true,
                'data' => $status,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get Ozon supply create status v2', [
                'error' => $e->getMessage(),
                'draft_id' => $validated['draft_id'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Получить список складов FBO
     * 
     * POST /api/ozon/warehouses/fbo
     * 
     * Request body:
     * {
     *   "integration_id": 1
     * }
     */
    public function getFboWarehouses(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            
            $warehouses = $ozon->supplies()->getFboWarehouses();

            return response()->json([
                'success' => true,
                'data' => [
                    'warehouses' => $warehouses,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get Ozon FBO warehouses', [
                'error' => $e->getMessage(),
                'integration_id' => $validated['integration_id'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Получить список складов продавца (для Pick Up)
     * 
     * POST /api/ozon/warehouses/seller
     * 
     * Request body:
     * {
     *   "integration_id": 1
     * }
     */
    public function getSellerWarehouses(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            
            $warehouses = $ozon->supplies()->getSellerWarehouses();

            return response()->json([
                'success' => true,
                'data' => [
                    'warehouses' => $warehouses,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get Ozon seller warehouses', [
                'error' => $e->getMessage(),
                'integration_id' => $validated['integration_id'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Получить грузоместа в поставке (бета)
     * 
     * POST /api/ozon/cargoes
     * 
     * Request body:
     * {
     *   "integration_id": 1,
     *   "supply_order_id": 123456789
     * }
     */
    public function getCargoes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'supply_order_id' => 'required|integer',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            
            $cargoes = $ozon->supplies()->getCargoes((string) $validated['supply_order_id']);

            return response()->json([
                'success' => true,
                'data' => [
                    'cargoes' => $cargoes,
                    'supply_order_id' => $validated['supply_order_id'],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get Ozon cargoes', [
                'error' => $e->getMessage(),
                'supply_order_id' => $validated['supply_order_id'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
