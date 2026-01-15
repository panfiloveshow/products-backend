<?php

namespace App\Http\Controllers\Api;

use App\Domains\Marketplace\MarketplaceFactory;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\Shipment;
use App\Models\WarehouseSlot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseSlotController extends Controller
{
    /**
     * GET /api/warehouse-slots
     * Список слотов приёмки
     */
    public function index(Request $request): JsonResponse
    {
        $query = WarehouseSlot::query();

        if ($request->has('marketplace')) {
            $query->marketplace($request->marketplace);
        }

        if ($request->has('warehouse_id')) {
            $query->warehouse($request->warehouse_id);
        }

        if ($request->boolean('available_only', false)) {
            $query->available();
        }

        if ($request->boolean('upcoming_only', true)) {
            $query->upcoming();
        }

        if ($request->has('date_from') || $request->has('date_to')) {
            $query->dateRange($request->date_from, $request->date_to);
        }

        $slots = $query->orderBy('date')
            ->orderBy('time_from')
            ->paginate($request->get('limit', 50));

        // Если в БД нет слотов — возвращаем fallback данные
        if ($slots->isEmpty()) {
            $fallbackSlots = $this->generateFallbackSlots($request->get('marketplace', 'ozon'));
            return response()->json([
                'message' => 'Success',
                'data' => $fallbackSlots,
                'source' => 'fallback',
            ]);
        }

        return response()->json([
            'message' => 'Success',
            'data' => $slots,
        ]);
    }

    /**
     * Генерация fallback слотов для демонстрации
     */
    private function generateFallbackSlots(string $marketplace): array
    {
        $slots = [];
        $startDate = now()->addDay();
        
        for ($i = 0; $i < 14; $i++) {
            $date = $startDate->copy()->addDays($i);
            
            // Пропускаем выходные
            if ($date->isWeekend()) {
                continue;
            }
            
            // Утренний слот
            $slots[] = [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'marketplace' => $marketplace,
                'warehouse_id' => $marketplace === 'ozon' ? '22655170176000' : '507',
                'warehouse_name' => $marketplace === 'ozon' ? 'Хоругвино' : 'Коледино',
                'date' => $date->toDateString(),
                'time_from' => '09:00',
                'time_to' => '12:00',
                'coefficient' => $i % 3 === 0 ? 0 : 1,
                'is_available' => true,
                'is_booked' => false,
                'capacity' => 100,
                'capacity_used' => rand(0, 50),
            ];
            
            // Дневной слот
            $slots[] = [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'marketplace' => $marketplace,
                'warehouse_id' => $marketplace === 'ozon' ? '22655170176000' : '507',
                'warehouse_name' => $marketplace === 'ozon' ? 'Хоругвино' : 'Коледино',
                'date' => $date->toDateString(),
                'time_from' => '14:00',
                'time_to' => '17:00',
                'coefficient' => $i % 2 === 0 ? 0 : 1,
                'is_available' => true,
                'is_booked' => false,
                'capacity' => 100,
                'capacity_used' => rand(0, 50),
            ];
        }
        
        return $slots;
    }

    /**
     * POST /api/warehouse-slots/sync
     * Синхронизировать слоты с маркетплейса (асинхронно через Job)
     */
    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'warehouse_id' => 'nullable|string',
            'async' => 'nullable|boolean',
        ]);

        $integration = Integration::findOrFail($validated['integration_id']);

        if (!in_array($integration->marketplace, ['wildberries', 'ozon'])) {
            return response()->json([
                'message' => 'Поддерживаются только Wildberries и Ozon',
            ], 422);
        }

        // Асинхронная синхронизация через Job
        if ($request->boolean('async', true)) {
            \App\Jobs\SyncWarehouseSlotsJob::dispatch(
                $validated['integration_id'],
                $validated['warehouse_id'] ?? null
            );

            return response()->json([
                'message' => 'Синхронизация слотов запущена',
                'status' => 'queued',
            ]);
        }

        // Синхронная синхронизация (для обратной совместимости)
        $marketplace = MarketplaceFactory::create(
            $integration->marketplace,
            $integration->getDecryptedCredentials(),
            $integration
        );

        $suppliesApi = $this->getSuppliesApi($marketplace, $integration->marketplace);

        if (!$suppliesApi) {
            return response()->json([
                'message' => 'Supplies API не доступен для этого маркетплейса',
            ], 422);
        }

        $dateFrom = now()->toDateString();
        $dateTo = now()->addDays(14)->toDateString();
        $warehouseId = $validated['warehouse_id'] ?? null;

        // Для WB используем getAvailableAcceptanceSlots
        if ($integration->marketplace === 'wildberries') {
            $apiSlots = $suppliesApi->getAvailableAcceptanceSlots($warehouseId);
        } else {
            // Для Ozon нужен warehouse_id
            if (!$warehouseId) {
                $warehouses = $suppliesApi->getAvailableWarehouses();
                $warehouseId = $warehouses[0]['id'] ?? null;
            }
            
            if (!$warehouseId) {
                return response()->json([
                    'message' => 'Не найден склад для синхронизации',
                ], 422);
            }
            
            $apiSlots = $suppliesApi->getAcceptanceSlots($warehouseId, $dateFrom, $dateTo);
        }

        $synced = 0;
        $created = 0;

        foreach ($apiSlots as $slotData) {
            $slot = WarehouseSlot::updateOrCreate(
                [
                    'marketplace' => $integration->marketplace,
                    'warehouse_id' => $validated['warehouse_id'],
                    'external_slot_id' => $slotData['id'] ?? null,
                ],
                [
                    'warehouse_name' => $slotData['warehouse_name'] ?? null,
                    'date' => $slotData['date'],
                    'time_from' => $slotData['time_from'],
                    'time_to' => $slotData['time_to'],
                    'coefficient' => $slotData['coefficient'] ?? null,
                    'is_available' => $slotData['is_available'] ?? true,
                    'capacity' => $slotData['capacity'] ?? null,
                    'capacity_used' => $slotData['capacity_used'] ?? 0,
                    'boxes_limit' => $slotData['boxes_limit'] ?? null,
                    'pallets_limit' => $slotData['pallets_limit'] ?? null,
                    'synced_at' => now(),
                ]
            );

            $synced++;
            if ($slot->wasRecentlyCreated) {
                $created++;
            }
        }

        return response()->json([
            'message' => 'Слоты синхронизированы',
            'data' => [
                'synced' => $synced,
                'created' => $created,
                'updated' => $synced - $created,
            ],
        ]);
    }

    /**
     * POST /api/warehouse-slots/{id}/book
     * Забронировать слот для поставки
     */
    public function book(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'shipment_id' => 'required|exists:shipments,id',
        ]);

        $slot = WarehouseSlot::findOrFail($id);

        if (!$slot->is_available || $slot->booked_by_shipment_id) {
            return response()->json([
                'message' => 'Слот недоступен для бронирования',
            ], 422);
        }

        $shipment = Shipment::findOrFail($validated['shipment_id']);

        // Бронируем слот
        $slot->book($shipment->id);

        // Обновляем поставку
        $shipment->update([
            'slot' => [
                'id' => $slot->id,
                'date' => $slot->date->toDateString(),
                'time_from' => $slot->time_from,
                'time_to' => $slot->time_to,
                'warehouse_id' => $slot->warehouse_id,
                'warehouse_name' => $slot->warehouse_name,
            ],
        ]);

        return response()->json([
            'message' => 'Слот забронирован',
            'data' => [
                'slot' => $slot->fresh(),
                'shipment' => $shipment->fresh(),
            ],
        ]);
    }

    /**
     * POST /api/warehouse-slots/{id}/release
     * Освободить слот
     */
    public function release(string $id): JsonResponse
    {
        $slot = WarehouseSlot::findOrFail($id);

        if (!$slot->booked_by_shipment_id) {
            return response()->json([
                'message' => 'Слот не забронирован',
            ], 422);
        }

        // Очищаем слот в поставке
        $shipment = Shipment::find($slot->booked_by_shipment_id);
        if ($shipment) {
            $shipment->update(['slot' => null]);
        }

        $slot->release();

        return response()->json([
            'message' => 'Слот освобождён',
            'data' => $slot->fresh(),
        ]);
    }

    /**
     * GET /api/warehouse-slots/warehouses
     * Список складов для выбора
     */
    public function warehouses(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
        ]);

        $integration = Integration::findOrFail($validated['integration_id']);

        $marketplace = MarketplaceFactory::create(
            $integration->marketplace,
            $integration->getDecryptedCredentials(),
            $integration
        );

        $suppliesApi = $this->getSuppliesApi($marketplace, $integration->marketplace);

        if (!$suppliesApi) {
            return response()->json([
                'message' => 'Supplies API не доступен',
            ], 422);
        }

        $warehouses = $suppliesApi->getAvailableWarehouses();

        return response()->json([
            'message' => 'Success',
            'data' => $warehouses,
        ]);
    }

    /**
     * Получить Supplies API для маркетплейса
     */
    private function getSuppliesApi($marketplace, string $marketplaceName)
    {
        if ($marketplaceName === 'wildberries') {
            return new \App\Domains\Wildberries\Api\SuppliesApi($marketplace->getClient());
        }

        if ($marketplaceName === 'ozon') {
            return new \App\Domains\Ozon\Api\SuppliesApi($marketplace->api());
        }

        return null;
    }
}
