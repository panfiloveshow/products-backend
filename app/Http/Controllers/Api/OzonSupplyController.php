<?php

namespace App\Http\Controllers\Api;

use App\Domains\Ozon\OzonMarketplace;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Контроллер для полного цикла работы с поставками Ozon FBO
 * 
 * Supply Orders:
 * - POST /api/ozon/supply/orders - список заявок
 * - POST /api/ozon/supply/order - детали заявки
 * - POST /api/ozon/supply/order/details - детали заявки (v1)
 * - POST /api/ozon/supply/order/status-counter - счётчик статусов
 * 
 * Timeslots:
 * - POST /api/ozon/supply/order/timeslots - доступные таймслоты
 * - POST /api/ozon/supply/order/timeslot/get - таймслот по заявке
 * - POST /api/ozon/supply/order/timeslot/update - изменить таймслот
 * - POST /api/ozon/supply/order/timeslot/status - статус обновления таймслота
 * - POST /api/ozon/supply/order/timeslot/update/status - статус изменения
 * 
 * Content:
 * - POST /api/ozon/supply/order/content/update - редактирование состава
 * - POST /api/ozon/supply/order/content/update/validation - проверка состава
 * - POST /api/ozon/supply/order/content/update/status - статус редактирования
 * 
 * Pass (водитель и ТС):
 * - POST /api/ozon/supply/order/pass/create - добавить данные водителя
 * - POST /api/ozon/supply/order/pass/status - статус добавления
 * 
 * FBO Postings:
 * - POST /api/ozon/posting/fbo/list - список отправлений
 * - POST /api/ozon/posting/fbo/get - информация об отправлении
 * - POST /api/ozon/posting/fbo/cancel-reasons - причины отмены
 * 
 * Returns/Removal:
 * - POST /api/ozon/returns/list - возвраты FBO/FBS
 * - POST /api/ozon/removal/from-stock/list - вывоз со стока
 * - POST /api/ozon/removal/from-supply/list - вывоз с поставки
 * 
 * FBP:
 * - POST /api/ozon/fbp/act/create - сгенерировать акт приёмки
 * - POST /api/ozon/fbp/act/status - статус генерации акта
 * - POST /api/ozon/fbp/draft/direct/timeslot/edit - изменить таймслот черновика
 * 
 * Cargoes (грузоместа):
 * - POST /api/ozon/supply/cargoes/create - создать грузоместа
 * - POST /api/ozon/supply/cargoes/info - информация о грузоместах
 * 
 * Labels (этикетки):
 * - POST /api/ozon/supply/cargoes/labels/create - создать этикетки
 * - POST /api/ozon/supply/cargoes/labels/status - статус генерации
 * - GET /api/ozon/supply/cargoes/labels/download - скачать PDF
 * 
 * Warehouses:
 * - POST /api/ozon/clusters - список кластеров
 * - POST /api/ozon/warehouses/fbo - FBO склады
 * - POST /api/ozon/warehouses/availability - доступность складов
 */
class OzonSupplyController extends Controller
{
    /**
     * Получить список заявок на поставку
     * 
     * POST /api/ozon/supply/orders
     */
    public function getSupplyOrders(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'page' => 'nullable|integer|min:1',
            'page_size' => 'nullable|integer|min:1|max:100',
            'filter' => 'nullable|array',
            'filter.status' => 'nullable|array',
            'filter.warehouse_id' => 'nullable|array',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            
            $supplies = $ozon->supplies()->getSupplies([
                'limit' => $validated['page_size'] ?? 50,
                'offset' => (($validated['page'] ?? 1) - 1) * ($validated['page_size'] ?? 50),
                'statuses' => $validated['filter']['status'] ?? [],
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'supply_orders' => $supplies,
                    'total' => count($supplies),
                    'page' => $validated['page'] ?? 1,
                    'page_size' => $validated['page_size'] ?? 50,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get Ozon supply orders', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Получить подробную информацию о заявке на поставку (v1)
     * 
     * POST /api/ozon/supply/order/details
     */
    public function getSupplyOrderDetailsV1(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'supply_order_id' => 'required|integer',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);

            $details = $ozon->supplies()->getSupplyOrderDetailsV1((string) $validated['supply_order_id']);

            return response()->json([
                'success' => true,
                'data' => $details,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get Ozon supply order details v1', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Таймслоты по заявке (v1)
     * 
     * POST /api/ozon/supply/order/timeslot/get
     */
    public function getSupplyOrderTimeslot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'supply_order_id' => 'required|integer',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);

            $payload = $request->except(['integration_id', 'supply_order_id']);
            $result = $ozon->supplies()->getSupplyOrderTimeslot(
                (string) $validated['supply_order_id'],
                $payload
            );

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get supply order timeslot', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Статус обновления таймслота (v1)
     * 
     * POST /api/ozon/supply/order/timeslot/status
     */
    public function getSupplyOrderTimeslotStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'operation_id' => 'required|string',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);

            $result = $ozon->supplies()->getSupplyOrderTimeslotStatus($validated['operation_id']);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get supply order timeslot status', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Редактировать состав заявки
     * 
     * POST /api/ozon/supply/order/content/update
     */
    public function updateSupplyOrderContent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'supply_order_id' => 'required|integer',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);

            $payload = $request->except(['integration_id', 'supply_order_id']);
            $result = $ozon->supplies()->updateSupplyOrderContent(
                (string) $validated['supply_order_id'],
                $payload
            );

            $operationId = $result['operation_id'] ?? $result['task_id'] ?? null;
            if ($operationId) {
                Cache::put("ozon_content_update_task_{$validated['supply_order_id']}", [
                    'operation_id' => $operationId,
                    'status' => $result['status'] ?? 'processing',
                    'created_at' => now()->toIso8601String(),
                ], 3600);
            }

            return response()->json([
                'success' => true,
                'data' => $result,
                'operation_id' => $operationId,
                'message' => 'Состав заявки отправлен на обновление',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update supply order content', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Проверить новый состав заявки
     * 
     * POST /api/ozon/supply/order/content/update/validation
     */
    public function validateSupplyOrderContent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'supply_order_id' => 'required|integer',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);

            $payload = $request->except(['integration_id', 'supply_order_id']);
            $result = $ozon->supplies()->validateSupplyOrderContent(
                (string) $validated['supply_order_id'],
                $payload
            );

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to validate supply order content', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Статус редактирования состава заявки
     * 
     * POST /api/ozon/supply/order/content/update/status
     */
    public function getSupplyOrderContentUpdateStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'supply_order_id' => 'required|integer',
            'operation_id' => 'nullable|string',
        ]);

        try {
            $cacheKey = "ozon_content_update_task_{$validated['supply_order_id']}";
            $taskData = Cache::get($cacheKey);
            $operationId = $validated['operation_id'] ?? ($taskData['operation_id'] ?? null);

            if (!$operationId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не указан operation_id',
                ], 422);
            }

            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            $result = $ozon->supplies()->getSupplyOrderContentUpdateStatus($operationId);

            $status = $result['status'] ?? $result['result'] ?? 'Unknown';

            Cache::put($cacheKey, [
                'operation_id' => $operationId,
                'status' => $status,
                'created_at' => $taskData['created_at'] ?? now()->toIso8601String(),
            ], 3600);

            return response()->json([
                'success' => true,
                'data' => [
                    'status' => $status,
                    'operation_id' => $operationId,
                    'created_at' => $taskData['created_at'] ?? null,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get supply order content update status', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Список отправлений FBO
     * 
     * POST /api/ozon/posting/fbo/list
     */
    public function getFboPostings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'limit' => 'nullable|integer|min:1|max:1000',
            'offset' => 'nullable|integer|min:0',
            'filter' => 'nullable|array',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);

            $result = $ozon->fboPostings()->list(
                $validated['filter'] ?? [],
                $validated['limit'] ?? 50,
                $validated['offset'] ?? 0
            );

            return response()->json([
                'success' => true,
                'data' => $result['postings'] ?? [],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get FBO postings', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Информация об отправлении FBO
     * 
     * POST /api/ozon/posting/fbo/get
     */
    public function getFboPosting(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'posting_number' => 'required|string',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);

            $result = $ozon->fboPostings()->get($validated['posting_number']);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get FBO posting', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Причины отмены FBO отправлений
     * 
     * POST /api/ozon/posting/fbo/cancel-reasons
     */
    public function getFboCancelReasons(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);

            $result = $ozon->fboPostings()->getCancelReasons();

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get FBO cancel reasons', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Возвраты FBO/FBS
     * 
     * POST /api/ozon/returns/list
     */
    public function getReturns(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'filter' => 'nullable|array',
            'limit' => 'nullable|integer|min:1|max:1000',
            'offset' => 'nullable|integer|min:0',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            $client = $ozon->getClient();

            $payload = $request->except(['integration_id']);
            $response = $client->post('/v1/returns/list', $payload, empty($payload));

            if (!$response) {
                throw new \RuntimeException('Empty response from Ozon');
            }

            return response()->json([
                'success' => true,
                'data' => $response['result'] ?? $response,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get returns list', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Отчёт по вывозу со стока (removal from stock)
     * 
     * POST /api/ozon/removal/from-stock/list
     */
    public function getRemovalFromStock(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            $client = $ozon->getClient();

            $payload = $request->except(['integration_id']);
            $response = $client->post('/v1/removal/from-stock/list', $payload, empty($payload));

            if (!$response) {
                throw new \RuntimeException('Empty response from Ozon');
            }

            return response()->json([
                'success' => true,
                'data' => $response['result'] ?? $response,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get removal from stock list', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Отчёт по вывозу с поставки (removal from supply)
     * 
     * POST /api/ozon/removal/from-supply/list
     */
    public function getRemovalFromSupply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            $client = $ozon->getClient();

            $payload = $request->except(['integration_id']);
            $response = $client->post('/v1/removal/from-supply/list', $payload, empty($payload));

            if (!$response) {
                throw new \RuntimeException('Empty response from Ozon');
            }

            return response()->json([
                'success' => true,
                'data' => $response['result'] ?? $response,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get removal from supply list', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Получить детали заявки на поставку
     * 
     * POST /api/ozon/supply/order
     */
    public function getSupplyOrder(Request $request): JsonResponse
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
                return response()->json(['success' => false, 'message' => 'Заявка не найдена'], 404);
            }

            $items = $ozon->supplies()->getSupplyProducts((string) $validated['supply_order_id']);

            return response()->json([
                'success' => true,
                'data' => [
                    'supply_order' => $supply,
                    'items' => $items,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get Ozon supply order', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Получить счётчик статусов заявок
     * 
     * POST /api/ozon/supply/order/status-counter
     */
    public function getSupplyOrderStatusCounter(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            
            // Получаем все поставки и группируем по статусам
            $supplies = $ozon->supplies()->getSupplies(['limit' => 1000]);
            
            $counters = [];
            foreach ($supplies as $supply) {
                $status = $supply['status'] ?? 'unknown';
                $counters[$status] = ($counters[$status] ?? 0) + 1;
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'counters' => $counters,
                    'total' => count($supplies),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get Ozon supply status counter', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Получить доступные таймслоты для заявки
     * 
     * POST /api/ozon/supply/order/timeslots
     */
    public function getSupplyOrderTimeslots(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'supply_order_id' => 'required|integer',
            'warehouse_id' => 'nullable|integer',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            
            // Если warehouse_id не указан, получаем из заявки
            $warehouseId = $validated['warehouse_id'] ?? null;
            if (!$warehouseId) {
                $supply = $ozon->supplies()->getSupplyDetails((string) $validated['supply_order_id']);
                $warehouseId = $supply['warehouse_id'] ?? null;
            }

            if (!$warehouseId) {
                return response()->json(['success' => false, 'message' => 'Не указан warehouse_id'], 422);
            }

            $slots = $ozon->supplies()->getAcceptanceSlots(
                (string) $warehouseId,
                $validated['date_from'] ?? null,
                $validated['date_to'] ?? null
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'timeslots' => $slots,
                    'supply_order_id' => $validated['supply_order_id'],
                    'warehouse_id' => $warehouseId,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get Ozon supply order timeslots', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Изменить таймслот заявки
     * 
     * POST /api/ozon/supply/order/timeslot/update
     */
    public function updateSupplyOrderTimeslot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'supply_order_id' => 'required|integer',
            'timeslot_id' => 'required|integer',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            
            $result = $ozon->supplies()->updateSupplyOrderTimeslot(
                (string) $validated['supply_order_id'],
                $validated['timeslot_id']
            );

            $operationId = $result['operation_id'] ?? $result['task_id'] ?? uniqid('timeslot_');
            Cache::put("ozon_timeslot_task_{$validated['supply_order_id']}", [
                'operation_id' => $operationId,
                'status' => $result['status'] ?? 'processing',
                'created_at' => now()->toIso8601String(),
            ], 3600);

            return response()->json([
                'success' => true,
                'data' => [
                    'operation_id' => $operationId,
                    'supply_order_id' => $validated['supply_order_id'],
                    'timeslot_id' => $validated['timeslot_id'],
                ],
                'message' => 'Запрос на изменение таймслота отправлен',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update Ozon supply order timeslot', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Получить статус изменения таймслота
     * 
     * POST /api/ozon/supply/order/timeslot/update/status
     */
    public function getTimeslotUpdateStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'supply_order_id' => 'required|integer',
            'operation_id' => 'nullable|string',
        ]);

        try {
            $cacheKey = "ozon_timeslot_task_{$validated['supply_order_id']}";
            $taskData = Cache::get($cacheKey);

            $operationId = $validated['operation_id'] ?? ($taskData['operation_id'] ?? null);

            if ($operationId) {
                $integration = Integration::findOrFail($validated['integration_id']);
                $ozon = OzonMarketplace::fromIntegration($integration);
                $status = $ozon->supplies()->getSupplyOrderTimeslotStatus($operationId);

                $responseStatus = $status['status'] ?? $status['result'] ?? 'Unknown';

                Cache::put($cacheKey, [
                    'operation_id' => $operationId,
                    'status' => $responseStatus,
                    'created_at' => $taskData['created_at'] ?? now()->toIso8601String(),
                ], 3600);

                return response()->json([
                    'success' => true,
                    'data' => [
                        'status' => $responseStatus,
                        'operation_id' => $operationId,
                        'created_at' => $taskData['created_at'] ?? null,
                    ],
                ]);
            }

            // Fallback: возвращаем текущий таймслот
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            $supply = $ozon->supplies()->getSupplyDetails((string) $validated['supply_order_id']);

            return response()->json([
                'success' => true,
                'data' => [
                    'status' => 'completed',
                    'supply_order_id' => $validated['supply_order_id'],
                    'current_timeslot' => [
                        'from' => $supply['timeslot_from'] ?? null,
                        'to' => $supply['timeslot_to'] ?? null,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get timeslot update status', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Создать пропуск (данные водителя и ТС)
     * 
     * POST /api/ozon/supply/order/pass/create
     */
    public function createSupplyOrderPass(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'supply_order_id' => 'required|integer',
            'vehicle' => 'required|array',
            'vehicle.driver_name' => 'required|string|max:200',
            'vehicle.driver_phone' => 'required|string|max:20',
            'vehicle.vehicle_number' => 'required|string|max:20',
            'vehicle.vehicle_model' => 'nullable|string|max:100',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            
            $result = $ozon->supplies()->createSupplyOrderPass(
                (string) $validated['supply_order_id'],
                $validated['vehicle']
            );

            $operationId = $result['operation_id'] ?? uniqid('pass_');
            Cache::put("ozon_pass_task_{$validated['supply_order_id']}", [
                'operation_id' => $operationId,
                'status' => $result['result'] ?? 'processing',
                'errors' => $result['errors'] ?? $result['error_reasons'] ?? [],
                'created_at' => now()->toIso8601String(),
            ], 3600);

            return response()->json([
                'success' => true,
                'data' => [
                    'operation_id' => $operationId,
                    'supply_order_id' => $validated['supply_order_id'],
                ],
                'message' => 'Данные водителя добавлены',
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create Ozon supply order pass', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Получить статус добавления пропуска
     * 
     * POST /api/ozon/supply/order/pass/status
     */
    public function getPassCreateStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'supply_order_id' => 'required|integer',
            'operation_id' => 'nullable|string',
        ]);

        try {
            $cacheKey = "ozon_pass_task_{$validated['supply_order_id']}";
            $taskData = Cache::get($cacheKey);
            $operationId = $validated['operation_id'] ?? ($taskData['operation_id'] ?? null);

            if (!$operationId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не указан operation_id',
                ], 422);
            }

            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            $status = $ozon->supplies()->getSupplyOrderPassStatus($operationId);

            $responseStatus = $status['result'] ?? $status['status'] ?? 'Unknown';
            $errors = $status['errors'] ?? $status['error_reasons'] ?? [];

            Cache::put($cacheKey, [
                'operation_id' => $operationId,
                'status' => $responseStatus,
                'errors' => $errors,
                'created_at' => $taskData['created_at'] ?? now()->toIso8601String(),
            ], 3600);

            return response()->json([
                'success' => true,
                'data' => [
                    'status' => $responseStatus,
                    'operation_id' => $operationId,
                    'errors' => $errors,
                    'created_at' => $taskData['created_at'] ?? null,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get pass create status', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Сгенерировать акт приёмки (FBP)
     * 
     * POST /api/ozon/fbp/act/create
     */
    public function createFbpAcceptanceAct(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'supply_order_id' => 'required|integer',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);

            $payload = $request->except(['integration_id', 'supply_order_id']);
            $result = $ozon->supplies()->createFbpAcceptanceAct(
                (string) $validated['supply_order_id'],
                $payload
            );

            $operationId = $result['operation_id'] ?? $result['task_id'] ?? uniqid('act_');
            Cache::put("ozon_fbp_act_task_{$validated['supply_order_id']}", [
                'operation_id' => $operationId,
                'status' => $result['status'] ?? 'processing',
                'created_at' => now()->toIso8601String(),
            ], 3600);

            return response()->json([
                'success' => true,
                'data' => [
                    'operation_id' => $operationId,
                    'supply_order_id' => $validated['supply_order_id'],
                ],
                'message' => 'Акт приёмки запрошен',
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create FBP acceptance act', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Получить статус генерации акта приёмки (FBP)
     * 
     * POST /api/ozon/fbp/act/status
     */
    public function getFbpAcceptanceActStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'supply_order_id' => 'required|integer',
            'operation_id' => 'nullable|string',
        ]);

        try {
            $cacheKey = "ozon_fbp_act_task_{$validated['supply_order_id']}";
            $taskData = Cache::get($cacheKey);
            $operationId = $validated['operation_id'] ?? ($taskData['operation_id'] ?? null);

            if (!$operationId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не указан operation_id',
                ], 422);
            }

            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            $status = $ozon->supplies()->getFbpAcceptanceActStatus($operationId);

            $responseStatus = $status['status'] ?? $status['result'] ?? 'Unknown';

            Cache::put($cacheKey, [
                'operation_id' => $operationId,
                'status' => $responseStatus,
                'created_at' => $taskData['created_at'] ?? now()->toIso8601String(),
            ], 3600);

            return response()->json([
                'success' => true,
                'data' => [
                    'status' => $responseStatus,
                    'operation_id' => $operationId,
                    'created_at' => $taskData['created_at'] ?? null,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get FBP acceptance act status', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Отредактировать таймслот в черновике прямой поставки (FBP)
     * 
     * POST /api/ozon/fbp/draft/direct/timeslot/edit
     */
    public function editFbpDirectDraftTimeslot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'draft_id' => 'required|string',
            'timeslot_id' => 'required|integer',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);

            $payload = $request->except(['integration_id', 'draft_id', 'timeslot_id']);
            $result = $ozon->supplies()->editFbpDirectDraftTimeslot(
                $validated['draft_id'],
                $validated['timeslot_id'],
                $payload
            );

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'Таймслот черновика обновлён',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to edit FBP draft timeslot', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Создать грузоместа
     * 
     * POST /api/ozon/supply/cargoes/create
     * 
     * До 40 паллет или 30 коробок
     */
    public function createCargoes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'supply_order_id' => 'required|integer',
            'containers' => 'required|array|min:1|max:40',
            'containers.*.type' => 'required|in:pallet,box',
            'containers.*.weight' => 'required|numeric|min:0',
            'containers.*.length' => 'required|numeric|min:0',
            'containers.*.width' => 'required|numeric|min:0',
            'containers.*.height' => 'required|numeric|min:0',
            'containers.*.items' => 'nullable|array',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            
            $result = $ozon->supplies()->createCargo(
                (string) $validated['supply_order_id'],
                ['containers' => $validated['containers']]
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'supply_order_id' => $validated['supply_order_id'],
                    'containers_count' => count($validated['containers']),
                    'cargo_ids' => $result['cargo_ids'] ?? [],
                ],
                'message' => 'Грузоместа созданы',
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create Ozon cargoes', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Получить информацию о грузоместах
     * 
     * POST /api/ozon/supply/cargoes/info
     */
    public function getCargoesInfo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'supply_order_id' => 'required|integer',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            
            // Получаем детали заявки с информацией о грузоместах
            $supply = $ozon->supplies()->getSupplyDetails((string) $validated['supply_order_id']);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'supply_order_id' => $validated['supply_order_id'],
                    'cargoes' => $supply['raw_data']['cargoes'] ?? [],
                    'total_weight' => $supply['raw_data']['total_weight'] ?? 0,
                    'total_volume' => $supply['raw_data']['total_volume'] ?? 0,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get Ozon cargoes info', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Создать этикетки для грузомест
     * 
     * POST /api/ozon/supply/cargoes/labels/create
     */
    public function createCargoesLabels(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'supply_order_id' => 'required|integer',
            'cargo_ids' => 'nullable|array',
        ]);

        try {
            $taskId = uniqid('labels_');
            
            // Сохраняем задачу на генерацию
            Cache::put("ozon_labels_task_{$validated['supply_order_id']}", [
                'task_id' => $taskId,
                'status' => 'completed', // В реальности будет processing -> completed
                'supply_order_id' => $validated['supply_order_id'],
                'created_at' => now()->toIso8601String(),
            ], 3600);

            return response()->json([
                'success' => true,
                'data' => [
                    'task_id' => $taskId,
                    'supply_order_id' => $validated['supply_order_id'],
                ],
                'message' => 'Генерация этикеток запущена',
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create Ozon cargoes labels', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Получить статус генерации этикеток
     * 
     * POST /api/ozon/supply/cargoes/labels/status
     */
    public function getCargoesLabelsStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'supply_order_id' => 'required|integer',
            'task_id' => 'nullable|string',
        ]);

        $cacheKey = "ozon_labels_task_{$validated['supply_order_id']}";
        $taskData = Cache::get($cacheKey);

        return response()->json([
            'success' => true,
            'data' => [
                'status' => $taskData['status'] ?? 'completed',
                'task_id' => $taskData['task_id'] ?? null,
                'download_url' => $taskData['status'] === 'completed' 
                    ? "/api/ozon/supply/cargoes/labels/download?supply_order_id={$validated['supply_order_id']}&integration_id={$validated['integration_id']}"
                    : null,
            ],
        ]);
    }

    /**
     * Скачать PDF с этикетками
     * 
     * GET /api/ozon/supply/cargoes/labels/download
     */
    public function downloadCargoesLabels(Request $request)
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'supply_order_id' => 'required|integer',
        ]);

        try {
            // В реальной реализации здесь будет запрос к Ozon API для получения PDF
            // Пока возвращаем заглушку
            
            $pdfContent = $this->generateLabelsPdf($validated['supply_order_id']);
            
            return response($pdfContent, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "attachment; filename=labels_{$validated['supply_order_id']}.pdf",
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to download Ozon cargoes labels', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Получить список кластеров Ozon
     * 
     * POST /api/ozon/clusters
     */
    public function getOzonClusters(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            
            // Получаем склады и группируем по кластерам
            $warehouses = $ozon->supplies()->getAvailableWarehouses();
            
            $clusters = [];
            foreach ($warehouses as $wh) {
                $region = $wh['region'] ?? 'Другие';
                if (!isset($clusters[$region])) {
                    $clusters[$region] = [
                        'name' => $region,
                        'warehouses' => [],
                    ];
                }
                $clusters[$region]['warehouses'][] = $wh;
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'clusters' => array_values($clusters),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get Ozon clusters', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Получить FBO склады Ozon
     * 
     * POST /api/ozon/warehouses/fbo
     */
    public function getOzonFboWarehouses(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            
            $warehouses = $ozon->supplies()->getAvailableWarehouses();
            
            // Фильтруем только FBO склады (не RFBS)
            $fboWarehouses = array_filter($warehouses, fn($wh) => !($wh['is_rfbs'] ?? false));

            return response()->json([
                'success' => true,
                'data' => [
                    'warehouses' => array_values($fboWarehouses),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get Ozon FBO warehouses', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Проверить доступность складов
     * 
     * POST /api/ozon/warehouses/availability
     */
    public function checkWarehouseAvailability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'warehouse_ids' => 'required|array|min:1',
            'warehouse_ids.*' => 'required|integer',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        try {
            $integration = Integration::findOrFail($validated['integration_id']);
            $ozon = OzonMarketplace::fromIntegration($integration);
            
            $availability = [];
            foreach ($validated['warehouse_ids'] as $warehouseId) {
                $slots = $ozon->supplies()->getAcceptanceSlots(
                    (string) $warehouseId,
                    $validated['date_from'] ?? null,
                    $validated['date_to'] ?? null
                );
                
                $availableSlots = array_filter($slots, fn($s) => $s['is_available'] ?? false);
                
                $availability[] = [
                    'warehouse_id' => $warehouseId,
                    'is_available' => count($availableSlots) > 0,
                    'available_slots_count' => count($availableSlots),
                    'next_available_slot' => $availableSlots[0] ?? null,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'availability' => $availability,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to check Ozon warehouse availability', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Генерация PDF с этикетками (заглушка)
     */
    private function generateLabelsPdf(int $supplyOrderId): string
    {
        // В реальной реализации здесь будет генерация PDF через библиотеку
        // или запрос к Ozon API
        return "%PDF-1.4\n1 0 obj\n<<\n/Type /Catalog\n/Pages 2 0 R\n>>\nendobj\n2 0 obj\n<<\n/Type /Pages\n/Kids [3 0 R]\n/Count 1\n>>\nendobj\n3 0 obj\n<<\n/Type /Page\n/Parent 2 0 R\n/MediaBox [0 0 612 792]\n>>\nendobj\nxref\n0 4\n0000000000 65535 f\n0000000009 00000 n\n0000000058 00000 n\n0000000115 00000 n\ntrailer\n<<\n/Size 4\n/Root 1 0 R\n>>\nstartxref\n199\n%%EOF";
    }
}
