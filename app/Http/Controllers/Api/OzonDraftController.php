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
 * Проксирует запросы к Ozon Seller API:
 * - POST /api/ozon/draft/create - создание черновика
 * - POST /api/ozon/draft/info - информация о черновике
 * - POST /api/ozon/draft/timeslots - получение таймслотов
 * - POST /api/ozon/draft/supply/create - создание поставки из черновика
 * - POST /api/ozon/supply/create/status - статус создания поставки
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
}
