<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shipment\IndexShipmentRequest;
use App\Http\Requests\Shipment\StoreShipmentRequest;
use App\Http\Requests\Shipment\UpdateShipmentRequest;
use App\Http\Requests\Shipment\AddItemRequest;
use App\Http\Requests\Shipment\UpdateItemRequest;
use App\Http\Requests\Shipment\BookSlotRequest;
use App\Exceptions\BusinessLogicException;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShipmentRecommendation;
use App\Services\ShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShipmentController extends Controller
{
    public function __construct(
        private ShipmentService $shipmentService
    ) {}

    /**
     * Список поставок FBO
     * GET /api/shipments?integration_id={id}&status={status}&page=1&per_page=20
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'nullable|string',
            'status' => 'nullable|string',
            'marketplace' => 'nullable|string',
            'supplier_id' => 'nullable|string',
            'search' => 'nullable|string',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = Shipment::with(['items', 'supplier']);

        // Фильтр по integration_id (обязательный для нового API)
        if ($request->filled('integration_id')) {
            $query->where('integration_id', $request->input('integration_id'));
        }

        if ($request->filled('status')) {
            $query->status($request->input('status'));
        }

        // Фильтрация по группе статусов (как в Ozon Seller)
        if ($request->filled('status_group')) {
            $groups = Shipment::getStatusGroups();
            $groupKey = $request->input('status_group');
            if (isset($groups[$groupKey])) {
                $query->whereIn('status', $groups[$groupKey]['statuses']);
            }
        }

        if ($request->filled('supplier_id')) {
            $query->supplier($request->input('supplier_id'));
        }

        if ($request->filled('marketplace')) {
            $query->marketplace($request->input('marketplace'));
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->input('search')}%");
        }

        $query->dateRange($request->input('date_from'), $request->input('date_to'));

        $query->orderBy('created_at', 'desc');

        $perPage = $request->input('per_page', 20);
        $page = $request->input('page', 1);

        $shipments = $query->paginate($perPage, ['*'], 'page', $page);

        // Форматируем данные согласно требуемому API
        $formattedShipments = collect($shipments->items())->map(function ($shipment) {
            return $this->formatShipmentResponse($shipment);
        });

        // Получаем статистику по группам для текущей интеграции
        $statsQuery = Shipment::query();
        if ($request->filled('integration_id')) {
            $statsQuery->where('integration_id', $request->input('integration_id'));
        }
        
        $statusCounts = $statsQuery->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $groupStats = [];
        foreach (Shipment::getStatusGroups() as $groupKey => $groupData) {
            $count = 0;
            foreach ($groupData['statuses'] as $status) {
                $count += $statusCounts[$status] ?? 0;
            }
            $groupStats[$groupKey] = [
                'key' => $groupKey,
                'label' => $groupData['label'],
                'count' => $count,
            ];
        }

        return response()->json([
            'data' => $formattedShipments,
            'meta' => [
                'current_page' => $shipments->currentPage(),
                'total' => $shipments->total(),
                'per_page' => $shipments->perPage(),
                'last_page' => $shipments->lastPage(),
            ],
            'status_groups' => array_values($groupStats),
        ]);
    }

    /**
     * Форматировать ответ поставки согласно API спецификации
     */
    private function formatShipmentResponse(Shipment $shipment): array
    {
        $slot = $shipment->slot ?? [];
        $warehouseName = $shipment->warehouse_name
            ?? ($shipment->meta['warehouse_name'] ?? null)
            ?? ($shipment->meta['cluster_name'] ?? null);
        
        return [
            'id' => $shipment->id,
            'integration_id' => $shipment->integration_id,
            'marketplace' => $shipment->marketplace,
            'warehouse_id' => $shipment->warehouse_id,
            'warehouse_name' => $warehouseName,
            'status' => $shipment->status,
            'external_status' => $shipment->external_status,
            'status_label' => Shipment::getStatuses()[$shipment->status] ?? $shipment->status,
            'status_color' => $this->getStatusColor($shipment->status),
            'status_group' => $shipment->getStatusGroup(),
            'slot_id' => $slot['id'] ?? null,
            'slot_date' => $slot['date'] ?? null,
            'slot_time_from' => $slot['time_from'] ?? null,
            'slot_time_to' => $slot['time_to'] ?? null,
            'items_count' => $shipment->total_items ?? $shipment->items->count(),
            'total_quantity' => $shipment->total_quantity ?? $shipment->items->sum('quantity'),
            'items' => $shipment->items->map(fn ($item) => [
                'sku' => $item->sku,
                'name' => $item->product_name ?? $item->sku,
                'quantity' => $item->quantity ?? 0,
            ])->toArray(),
            'meta' => array_merge($shipment->meta ?? [], [
                'external_supply_id' => $shipment->external_supply_id,
            ]),
            'created_at' => $shipment->created_at?->toIso8601String(),
        ];
    }

    /**
     * Получить цвет статуса
     */
    private function getStatusColor(string $status): string
    {
        return match ($status) {
            Shipment::STATUS_DRAFT => 'gray',
            Shipment::STATUS_PENDING_LOGISTICS, Shipment::STATUS_SUBMITTED => 'yellow',
            Shipment::STATUS_PENDING_CONFIRMATION => 'blue',
            Shipment::STATUS_CONFIRMED, Shipment::STATUS_APPROVED => 'green',
            Shipment::STATUS_SENT, Shipment::STATUS_IN_TRANSIT => 'indigo',
            Shipment::STATUS_ARRIVED, Shipment::STATUS_PROCESSING => 'purple',
            Shipment::STATUS_DELIVERED => 'green',
            Shipment::STATUS_PARTIALLY_ACCEPTED => 'orange',
            Shipment::STATUS_REJECTED, Shipment::STATUS_CANCELLED => 'red',
            default => 'gray',
        };
    }

    /**
     * Извлечь таймслот из ответа Ozon
     */
    private function extractTimeslot(?array $timeslot): array
    {
        if (!$timeslot) {
            return [null, null, null];
        }

        $slot = $timeslot;
        if (isset($timeslot['value']['timeslot'][0])) {
            $slot = $timeslot['value']['timeslot'][0];
        } elseif (isset($timeslot['timeslot'][0])) {
            $slot = $timeslot['timeslot'][0];
        }

        $from = $slot['from'] ?? $timeslot['from'] ?? $slot['time_from'] ?? null;
        $to = $slot['to'] ?? $timeslot['to'] ?? $slot['time_to'] ?? null;

        $date = $slot['date'] ?? ($from ? substr($from, 0, 10) : null);

        $timeFrom = $from;
        if (is_string($from) && strlen($from) > 5) {
            $timeFrom = substr($from, 11, 5);
        }

        $timeTo = $to;
        if (is_string($to) && strlen($to) > 5) {
            $timeTo = substr($to, 11, 5);
        }

        return [$date, $timeFrom, $timeTo];
    }

    public function show(string $id, Request $request): JsonResponse
    {
        $shipment = Shipment::with(['items', 'supplier'])->findOrFail($id);
        $forceRefresh = $request->boolean('refresh', false);

        \Illuminate\Support\Facades\Log::info('show: starting', [
            'shipment_id' => $id,
            'marketplace' => $shipment->marketplace,
            'external_supply_id' => $shipment->external_supply_id,
            'items_count' => $shipment->items->count(),
            'warehouse_name' => $shipment->warehouse_name,
            'force_refresh' => $forceRefresh,
        ]);

        // Подтягиваем данные из Ozon если склад или товары пустые, или принудительное обновление
        $needsRefresh = $forceRefresh || !$shipment->warehouse_name || $shipment->items->isEmpty();
        if ($shipment->marketplace === 'ozon' && $needsRefresh) {
            $integration = \App\Models\Integration::find($shipment->integration_id);
            if ($integration) {
                $externalSupplyId = $shipment->external_supply_id
                    ?? ($shipment->meta['external_supply_id'] ?? null)
                    ?? ($shipment->meta['ozon_order_number'] ?? null);

                if ($externalSupplyId && is_numeric($externalSupplyId)) {
                    try {
                        $marketplace = \App\Domains\Ozon\OzonMarketplace::fromIntegration($integration);
                        
                        // Используем /v3/supply-order/get для получения деталей
                        $detailsResponse = $marketplace->fboSupplyOrders()->get([(int) $externalSupplyId]);
                        $orders = $detailsResponse['orders'] ?? [];
                        $order = $orders[0] ?? null;
                        
                        \Illuminate\Support\Facades\Log::info('show: fetched order from Ozon', [
                            'shipment_id' => $id,
                            'external_supply_id' => $externalSupplyId,
                            'order_found' => !empty($order),
                            'order_keys' => $order ? array_keys($order) : [],
                        ]);

                        if ($order) {
                            // Извлекаем warehouse_name
                            $warehouseName = $order['dropoff_warehouse_name'] 
                                ?? $order['warehouse_name'] 
                                ?? $order['dropoff_point_name']
                                ?? null;
                            
                            // Извлекаем warehouse_id
                            $warehouseId = $order['dropoff_warehouse_id'] 
                                ?? $order['warehouse_id'] 
                                ?? $order['dropoff_point_id']
                                ?? null;
                            
                            // Извлекаем таймслот
                            [$slotDate, $slotTimeFrom, $slotTimeTo] = $this->extractTimeslot($order['timeslot'] ?? null);
                            
                            // Извлекаем товары из order
                            $items = $order['items'] ?? $order['products'] ?? [];
                            if (empty($items) && !empty($order['supplies']) && is_array($order['supplies'])) {
                                foreach ($order['supplies'] as $supply) {
                                    if (!empty($supply['items']) && is_array($supply['items'])) {
                                        $items = array_merge($items, $supply['items']);
                                    }
                                }
                            }
                            
                            // Если items пустые — получаем через /v1/supply-order/bundle
                            if (empty($items)) {
                                try {
                                    $bundleResponse = $marketplace->fboSupplyOrders()->getBundle((int) $externalSupplyId);
                                    // Новый формат: bundles[].items[]
                                    $bundles = $bundleResponse['bundles'] ?? [];
                                    foreach ($bundles as $bundle) {
                                        $bundleItems = $bundle['items'] ?? [];
                                        $items = array_merge($items, $bundleItems);
                                    }
                                    // Fallback на старый формат
                                    if (empty($items)) {
                                        $items = $bundleResponse['result']['items']
                                            ?? $bundleResponse['items']
                                            ?? $bundleResponse['result']['products']
                                            ?? $bundleResponse['products']
                                            ?? [];
                                    }
                                    \Illuminate\Support\Facades\Log::info('show: fetched bundle from Ozon', [
                                        'external_supply_id' => $externalSupplyId,
                                        'bundles_count' => count($bundles),
                                        'items_count' => count($items),
                                    ]);
                                } catch (\Exception $bundleError) {
                                    \Illuminate\Support\Facades\Log::warning('show: bundle fetch failed', [
                                        'external_supply_id' => $externalSupplyId,
                                        'error' => $bundleError->getMessage(),
                                    ]);
                                }
                            }
                            
                            \Illuminate\Support\Facades\Log::info('show: extracted data from order', [
                                'warehouse_name' => $warehouseName,
                                'warehouse_id' => $warehouseId,
                                'slot_date' => $slotDate,
                                'items_count' => count($items),
                            ]);
                            
                            // Обновляем shipment
                            $updateData = [];
                            if ($warehouseId && !$shipment->warehouse_id) {
                                $updateData['warehouse_id'] = (string) $warehouseId;
                            }
                            if ($warehouseName && !$shipment->warehouse_name) {
                                $updateData['warehouse_name'] = $warehouseName;
                            }
                            if ($slotDate && empty($shipment->slot)) {
                                $updateData['slot'] = [
                                    'date' => $slotDate,
                                    'time_from' => $slotTimeFrom,
                                    'time_to' => $slotTimeTo,
                                ];
                            }
                            if (!empty($items)) {
                                $updateData['total_items'] = count($items);
                                $updateData['total_quantity'] = array_sum(array_map(
                                    fn($item) => (int) ($item['quantity'] ?? $item['qty'] ?? 0), 
                                    $items
                                ));
                            }
                            
                            if (!empty($updateData)) {
                                $shipment->update($updateData);
                            }
                            
                            // Сохраняем товары в БД если их нет
                            if (!empty($items) && $shipment->items()->count() === 0) {
                                foreach ($items as $ozonItem) {
                                    ShipmentItem::create([
                                        'shipment_id' => $shipment->id,
                                        'sku' => $ozonItem['sku'] ?? $ozonItem['offer_id'] ?? $ozonItem['product_id'] ?? 'unknown',
                                        'product_name' => $ozonItem['name'] ?? $ozonItem['offer_id'] ?? null,
                                        'quantity' => (int) ($ozonItem['quantity'] ?? $ozonItem['qty'] ?? 0),
                                    ]);
                                }
                                \Illuminate\Support\Facades\Log::info('show: saved items to DB', [
                                    'shipment_id' => $shipment->id,
                                    'items_count' => count($items),
                                ]);
                            }
                            
                            // Перезагружаем shipment с items
                            $shipment = Shipment::with(['items', 'supplier'])->find($shipment->id);
                        }
                    } catch (\Exception $e) {
                        \Illuminate\Support\Facades\Log::warning('Failed to hydrate shipment from Ozon', [
                            'shipment_id' => $shipment->id,
                            'external_supply_id' => $externalSupplyId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        return response()->json([
            'data' => $this->formatShipmentResponse($shipment),
        ]);
    }

    /**
     * Создать поставку FBO
     * POST /api/shipments
     * 
     * Создаёт черновик локально и синхронизирует с Ozon API
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required',
            'warehouse_id' => 'required|string',
            'items' => 'required|array|min:1',
            'items.*.sku' => 'required|string',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.barcode' => 'nullable|string',
        ]);

        // Приводим integration_id к строке для совместимости
        $integrationId = (string) $request->input('integration_id');
        $integration = \App\Models\Integration::findOrFail($integrationId);
        $warehouseId = $request->input('warehouse_id');
        $items = $request->input('items');

        $externalSupplyId = null;
        $syncError = null;

        // Пытаемся создать черновик в Ozon API
        if ($integration->marketplace === 'ozon') {
            try {
                // Получаем SKU из ozon_data для каждого товара
                // Важно: для /v1/draft/create нужен ozon_data.sku, а не product_id (marketplace_id)
                $ozonItems = [];
                foreach ($items as $item) {
                    $product = \App\Models\Product::where('sku', $item['sku'])
                        ->where('integration_id', $integrationId)
                        ->first();
                    
                    if ($product) {
                        // Приоритет: ozon_data.sku > marketplace_id
                        $ozonSku = null;
                        $ozonData = $product->ozon_data ?? [];
                        if (!empty($ozonData['sku'])) {
                            $ozonSku = (int) $ozonData['sku'];
                        } elseif ($product->marketplace_id) {
                            // Fallback на marketplace_id (product_id)
                            $ozonSku = (int) $product->marketplace_id;
                        }
                        
                        if ($ozonSku) {
                            $ozonItems[] = [
                                'sku' => $ozonSku,
                                'quantity' => (int) $item['quantity'],
                            ];
                        }
                    }
                }
                
                if (empty($ozonItems)) {
                    throw new \RuntimeException('Не найдены товары с SKU для синхронизации с Ozon');
                }
                
                $marketplace = \App\Domains\Ozon\OzonMarketplace::fromIntegration($integration);
                $suppliesApi = $marketplace->fboSupplyOrders();
                
                // Используем новый API с macrolocal_cluster_id (warehouse_id = cluster_id)
                $ozonDraft = $suppliesApi->createDirectDraft([
                    'macrolocal_cluster_id' => $warehouseId,
                    'items' => $ozonItems,
                ]);
                
                $draftId = $ozonDraft['draft_id'] ?? null;
                
                \Illuminate\Support\Facades\Log::info('Ozon draft created', [
                    'draft_id' => $draftId,
                    'macrolocal_cluster_id' => $warehouseId,
                    'items_count' => count($ozonItems),
                ]);

                // Получаем информацию о доступных складах из черновика
                $draftInfo = $suppliesApi->getDraftCreateInfo($ozonDraft['operation_id']);
                $availableWarehouse = null;
                
                if (!empty($draftInfo['clusters'])) {
                    foreach ($draftInfo['clusters'] as $cluster) {
                        if (!empty($cluster['warehouses'])) {
                            foreach ($cluster['warehouses'] as $wh) {
                                if (($wh['status']['is_available'] ?? false) === true) {
                                    $availableWarehouse = $wh;
                                    break 2;
                                }
                            }
                        }
                    }
                }

                // Если есть доступный склад — получаем таймслоты и создаём заявку
                if ($availableWarehouse && $draftId && is_numeric($draftId)) {
                    $warehouseIdForSupply = $availableWarehouse['supply_warehouse']['warehouse_id'] ?? null;
                    
                    if ($warehouseIdForSupply) {
                        $timeslots = $suppliesApi->getDraftTimeslots((int) $draftId, (int) $warehouseIdForSupply);
                        
                        // Извлекаем слоты из ответа
                        $allSlots = [];
                        $dropOffTimeslots = $timeslots['drop_off_warehouse_timeslots'] ?? [];
                        foreach ($dropOffTimeslots as $whTimeslots) {
                            foreach ($whTimeslots['days'] ?? [] as $day) {
                                foreach ($day['timeslots'] ?? [] as $slot) {
                                    $allSlots[] = $slot;
                                }
                            }
                        }
                        
                        \Illuminate\Support\Facades\Log::info('Ozon timeslots parsed', [
                            'draft_id' => $draftId,
                            'warehouse_id' => $warehouseIdForSupply,
                            'timeslots_count' => count($allSlots),
                        ]);

                        // Берём первый доступный таймслот
                        $firstSlot = !empty($allSlots) ? $allSlots[0] : null;

                        if ($firstSlot) {
                            $supplyResult = $suppliesApi->createSupplyFromDraft(
                                (int) $draftId,
                                (int) $warehouseIdForSupply,
                                [
                                    'from' => $firstSlot['from'] ?? $firstSlot['from_in_timezone'] ?? '',
                                    'to' => $firstSlot['to'] ?? $firstSlot['to_in_timezone'] ?? '',
                                ]
                            );

                            \Illuminate\Support\Facades\Log::info('Ozon supply created', [
                                'draft_id' => $draftId,
                                'supply_result' => $supplyResult,
                            ]);

                            // Используем operation_id от создания заявки
                            $externalSupplyId = $supplyResult['operation_id'] ?? $draftId;
                        } else {
                            $externalSupplyId = $draftId;
                        }
                    } else {
                        $externalSupplyId = $draftId;
                    }
                } else {
                    $externalSupplyId = $draftId;
                }
            } catch (\Exception $e) {
                $syncError = $e->getMessage();
                \Illuminate\Support\Facades\Log::warning('Failed to create Ozon draft', [
                    'error' => $syncError,
                    'macrolocal_cluster_id' => $warehouseId,
                ]);
            }
        }

        // Определяем статус: если заявка создана в Ozon — submitted, иначе draft
        $status = $externalSupplyId ? 'submitted' : 'draft';

        $shipmentData = [
            'name' => 'Поставка ' . now()->format('d.m.Y H:i'),
            'integration_id' => $integration->id,
            'marketplace' => $integration->marketplace,
            'warehouse_id' => $warehouseId,
            'shipment_type' => 'fbo',
            'status' => $status,
            'items' => $items,
            'external_supply_id' => $externalSupplyId,
        ];

        $shipment = $this->shipmentService->create($shipmentData);

        $response = [
            'data' => $this->formatShipmentResponse($shipment->load(['items'])),
            'message' => 'Поставка создана',
        ];

        if ($externalSupplyId) {
            $response['ozon_draft_id'] = $externalSupplyId;
            $response['message'] = 'Поставка создана и синхронизирована с Ozon';
        } elseif ($syncError) {
            $response['sync_error'] = $syncError;
            $response['message'] = 'Поставка создана локально (ошибка синхронизации с Ozon)';
        }

        return response()->json($response, 201);
    }

    public function update(UpdateShipmentRequest $request, string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        if (!$shipment->canBeEdited()) {
            return response()->json([
                'message' => 'Shipment cannot be edited in current status',
            ], 422);
        }

        $shipment->update($request->validated());

        return response()->json([
            'data' => $shipment->fresh(['items', 'supplier']),
            'message' => 'Shipment updated successfully',
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        // Разрешаем удалять черновики и отменённые поставки
        $deletableStatuses = ['draft', 'cancelled', 'rejected'];
        if (!in_array($shipment->status, $deletableStatuses)) {
            return response()->json([
                'message' => 'Можно удалить только черновики, отменённые или отклонённые поставки',
            ], 422);
        }

        // Удаляем связанные items
        $shipment->items()->delete();
        $shipment->delete();

        return response()->json([
            'message' => 'Поставка удалена',
        ]);
    }

    /**
     * Синхронизация заявок из Ozon в нашу систему
     */
    public function syncFromOzon(Request $request): JsonResponse
    {
        $integrationId = $request->input('integration_id');
        if (!$integrationId) {
            return response()->json(['message' => 'integration_id обязателен'], 422);
        }

        $integration = \App\Models\Integration::find($integrationId);
        if (!$integration || $integration->marketplace !== 'ozon') {
            return response()->json(['message' => 'Интеграция не найдена или не Ozon'], 404);
        }

        try {
            $marketplace = \App\Domains\Ozon\OzonMarketplace::fromIntegration($integration);
            $suppliesApi = $marketplace->fboSupplyOrders();

            // Получаем список заявок из Ozon
            $listResponse = $suppliesApi->list([], 50);
            $orderIds = $listResponse['order_ids'] ?? [];

            if (empty($orderIds)) {
                return response()->json([
                    'message' => 'Нет заявок в Ozon',
                    'synced' => 0,
                ]);
            }

            // Получаем детали заявок
            $detailsResponse = $suppliesApi->get($orderIds);
            $orders = $detailsResponse['orders'] ?? [];
            $warehouses = collect($detailsResponse['warehouses'] ?? [])->keyBy('warehouse_id');

            $synced = 0;
            $updated = 0;

            foreach ($orders as $order) {
                $supplyOrderId = (string) ($order['order_id'] ?? $order['supply_order_id'] ?? $order['id'] ?? 0);
                $supplyOrderNumber = $order['order_number'] ?? $order['supply_order_number'] ?? $supplyOrderId;

                // Проверяем, есть ли уже такая поставка
                $existingShipment = Shipment::where('integration_id', $integrationId)
                    ->where('external_supply_id', $supplyOrderId)
                    ->first();

                // Маппинг статусов Ozon -> наши
                $ozonState = $order['state'] ?? $order['status'] ?? $order['order_state'] ?? '';
                $status = $this->mapOzonStateToStatus($ozonState);

                // Получаем информацию о складе
                $dropoffWarehouseId = $order['dropoff_warehouse_id'] ?? $order['warehouse_id'] ?? $order['dropoff_point_id'] ?? null;
                $warehouseInfo = $dropoffWarehouseId ? ($warehouses[$dropoffWarehouseId] ?? null) : null;
                $warehouseName = $order['dropoff_warehouse_name']
                    ?? $order['dropoff_point_name']
                    ?? $order['warehouse_name']
                    ?? ($warehouseInfo['name'] ?? null);

                // Таймслот
                [$slotDate, $slotTimeFrom, $slotTimeTo] = $this->extractTimeslot($order['timeslot'] ?? null);

                // Товары и количество
                $items = $order['items'] ?? $order['products'] ?? [];
                if (empty($items) && !empty($order['supplies']) && is_array($order['supplies'])) {
                    foreach ($order['supplies'] as $supply) {
                        if (!empty($supply['items']) && is_array($supply['items'])) {
                            $items = array_merge($items, $supply['items']);
                        }
                    }
                }

                $bundleItems = [];
                if (empty($items)) {
                    try {
                        \Illuminate\Support\Facades\Log::info('syncFromOzon: fetching bundle', [
                            'supply_order_id' => $supplyOrderId,
                        ]);
                        $bundleResponse = $suppliesApi->getBundle((int) $supplyOrderId);
                        $bundleItems = $bundleResponse['result']['items']
                            ?? $bundleResponse['items']
                            ?? $bundleResponse['result']['products']
                            ?? $bundleResponse['products']
                            ?? [];
                        \Illuminate\Support\Facades\Log::info('syncFromOzon: bundle fetched', [
                            'supply_order_id' => $supplyOrderId,
                            'bundle_items_count' => count($bundleItems),
                            'first_item' => $bundleItems[0] ?? null,
                        ]);
                    } catch (\Exception $e) {
                        \Illuminate\Support\Facades\Log::warning('Ozon bundle fetch failed', [
                            'supply_order_id' => $supplyOrderId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $totalItems = $order['sku_count'] ?? $order['items_count'] ?? count($items);
                if (!$totalItems && !empty($bundleItems)) {
                    $totalItems = count($bundleItems);
                }

                $totalQuantity = $order['quantity'] ?? $order['total_quantity']
                    ?? array_sum(array_map(fn ($item) => (int) ($item['quantity'] ?? 0), $items));
                if (!$totalQuantity && !empty($bundleItems)) {
                    $totalQuantity = array_sum(array_map(
                        fn ($item) => (int) ($item['quantity'] ?? $item['qty'] ?? 0),
                        $bundleItems
                    ));
                }
                $suppliesCount = $order['supplies_count']
                    ?? (is_array($order['supplies'] ?? null) ? count($order['supplies']) : null)
                    ?? 1;

                $vehicle = $order['vehicle'] ?? [];
                $driverName = $vehicle['driver_name'] ?? $vehicle['driver'] ?? null;
                $carNumber = $vehicle['car_number'] ?? $vehicle['number'] ?? null;
                $clusterName = $order['cluster_name'] ?? ($order['cluster']['name'] ?? null);
                $clusterId = $order['cluster_id'] ?? ($order['cluster']['id'] ?? null);

                $shipmentData = [
                    'name' => 'Заявка ' . $supplyOrderNumber,
                    'status' => $status,
                    'external_supply_id' => $supplyOrderId,
                    'external_status' => $ozonState,
                    'warehouse_id' => (string) $dropoffWarehouseId,
                    'warehouse_name' => $warehouseName,
                    'slot' => $slotDate ? [
                        'date' => $slotDate,
                        'time_from' => $slotTimeFrom,
                        'time_to' => $slotTimeTo,
                    ] : null,
                    'total_items' => $totalItems,
                    'total_quantity' => $totalQuantity,
                    'meta' => [
                        'ozon_order_number' => $supplyOrderNumber,
                        'ozon_state' => $ozonState,
                        'is_econom' => $order['is_econom'] ?? false,
                        'is_super_fbo' => $order['is_super_fbo'] ?? false,
                        'creation_date' => $order['creation_date'] ?? $order['created_at'] ?? null,
                        'data_filling_deadline' => $order['data_filling_deadline_utc'] ?? $order['data_filling_deadline'] ?? null,
                        'cluster_id' => $clusterId,
                        'cluster_name' => $clusterName,
                        'supplies_count' => $suppliesCount,
                        'driver_name' => $driverName,
                        'car_number' => $carNumber,
                    ],
                ];

                // Объединяем items и bundleItems для сохранения
                $allItems = !empty($items) ? $items : $bundleItems;
                
                if ($existingShipment) {
                    $existingShipment->update($shipmentData);
                    
                    // Сохраняем товары если их нет
                    if (!empty($allItems) && $existingShipment->items()->count() === 0) {
                        foreach ($allItems as $ozonItem) {
                            ShipmentItem::create([
                                'shipment_id' => $existingShipment->id,
                                'sku' => $ozonItem['sku'] ?? $ozonItem['offer_id'] ?? $ozonItem['product_id'] ?? 'unknown',
                                'product_name' => $ozonItem['name'] ?? $ozonItem['offer_id'] ?? null,
                                'quantity' => (int) ($ozonItem['quantity'] ?? $ozonItem['qty'] ?? 0),
                            ]);
                        }
                    }
                    $updated++;
                } else {
                    $shipmentData['integration_id'] = $integrationId;
                    $shipmentData['marketplace'] = 'ozon';
                    $shipmentData['shipment_type'] = 'fbo';
                    $shipmentData['created_by'] = auth()->id() ?? \Illuminate\Support\Str::uuid();
                    $shipmentData['created_by_name'] = auth()->user()?->name ?? 'Ozon Sync';

                    $newShipment = Shipment::create($shipmentData);
                    
                    // Сохраняем товары
                    if (!empty($allItems)) {
                        foreach ($allItems as $ozonItem) {
                            ShipmentItem::create([
                                'shipment_id' => $newShipment->id,
                                'sku' => $ozonItem['sku'] ?? $ozonItem['offer_id'] ?? $ozonItem['product_id'] ?? 'unknown',
                                'product_name' => $ozonItem['name'] ?? $ozonItem['offer_id'] ?? null,
                                'quantity' => (int) ($ozonItem['quantity'] ?? $ozonItem['qty'] ?? 0),
                            ]);
                        }
                    }
                    $synced++;
                }
            }

            return response()->json([
                'message' => "Синхронизация завершена: создано {$synced}, обновлено {$updated}",
                'synced' => $synced,
                'updated' => $updated,
                'total_from_ozon' => count($orders),
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Ozon sync error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Ошибка синхронизации: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Маппинг статусов Ozon на наши статусы
     * Ozon API возвращает статусы в формате DATA_FILLING, READY_TO_SUPPLY и т.д.
     */
    private function mapOzonStateToStatus(string $ozonState): string
    {
        // Убираем префикс ORDER_STATE_ если есть
        $state = str_replace('ORDER_STATE_', '', $ozonState);
        
        return match ($state) {
            'DATA_FILLING' => Shipment::STATUS_SUBMITTED,
            'READY_TO_SUPPLY' => Shipment::STATUS_APPROVED,
            'IN_TRANSIT' => Shipment::STATUS_IN_TRANSIT,
            'AT_WAREHOUSE' => Shipment::STATUS_ARRIVED,
            'ACCEPTING' => Shipment::STATUS_PROCESSING,
            'ACCEPTANCE' => Shipment::STATUS_PROCESSING,
            'ACCEPTED' => Shipment::STATUS_DELIVERED,
            'PARTIALLY_ACCEPTED' => Shipment::STATUS_PARTIALLY_ACCEPTED,
            'CANCELLED' => Shipment::STATUS_CANCELLED,
            default => Shipment::STATUS_SUBMITTED,
        };
    }

    public function addItem(AddItemRequest $request, string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        if (!$shipment->canBeEdited()) {
            return response()->json([
                'message' => 'Cannot add items to shipment in current status',
            ], 422);
        }

        $item = $this->shipmentService->addItem($shipment, $request->validated());

        return response()->json([
            'data' => $item,
            'message' => 'Item added successfully',
        ], 201);
    }

    public function updateItem(UpdateItemRequest $request, string $id, string $itemId): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        if (!$shipment->canBeEdited()) {
            return response()->json([
                'message' => 'Cannot update items in current status',
            ], 422);
        }

        $item = ShipmentItem::where('shipment_id', $id)->findOrFail($itemId);
        $item->update($request->validated());

        return response()->json([
            'data' => $item->fresh(),
            'message' => 'Item updated successfully',
        ]);
    }

    public function removeItem(string $id, string $itemId): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        if (!$shipment->canBeEdited()) {
            return response()->json([
                'message' => 'Cannot remove items in current status',
            ], 422);
        }

        $item = ShipmentItem::where('shipment_id', $id)->findOrFail($itemId);
        $item->delete();

        return response()->json([
            'message' => 'Item removed successfully',
        ]);
    }

    public function submit(string $id): JsonResponse
    {
        try {
            $shipment = $this->shipmentService->submit($id);

            return response()->json([
                'data' => $shipment,
                'message' => 'Shipment submitted for approval',
            ]);
        } catch (BusinessLogicException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->getErrors(),
            ], 422);
        }
    }

    public function approve(string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        if (!$shipment->canBeApproved()) {
            return response()->json([
                'message' => 'Shipment cannot be approved',
            ], 422);
        }

        $shipment->update([
            'status' => Shipment::STATUS_APPROVED,
            'logistics_approval' => [
                'approved' => true,
                'approved_by' => auth()->id(),
                'approved_by_name' => auth()->user()?->name ?? 'System',
                'approved_at' => now()->toISOString(),
            ],
        ]);

        return response()->json([
            'data' => $shipment->fresh(),
            'message' => 'Shipment approved',
        ]);
    }

    public function reject(string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        if (!$shipment->canBeApproved()) {
            return response()->json([
                'message' => 'Shipment cannot be rejected',
            ], 422);
        }

        $shipment->update([
            'status' => Shipment::STATUS_REJECTED,
            'logistics_approval' => [
                'approved' => false,
                'approved_by' => auth()->id(),
                'approved_by_name' => auth()->user()?->name ?? 'System',
                'approved_at' => now()->toISOString(),
                'comment' => request('comment'),
            ],
        ]);

        return response()->json([
            'data' => $shipment->fresh(),
            'message' => 'Shipment rejected',
        ]);
    }

    public function send(string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        if (!$shipment->canBeSent()) {
            return response()->json([
                'message' => 'Shipment cannot be sent',
            ], 422);
        }

        $shipment->update([
            'status' => Shipment::STATUS_SENT,
            'sent_at' => now(),
        ]);

        return response()->json([
            'data' => $shipment->fresh(),
            'message' => 'Shipment marked as sent',
        ]);
    }

    public function deliver(string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        if (!in_array($shipment->status, [Shipment::STATUS_SENT, Shipment::STATUS_IN_TRANSIT])) {
            return response()->json([
                'message' => 'Shipment cannot be marked as delivered',
            ], 422);
        }

        $shipment->update([
            'status' => Shipment::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);

        return response()->json([
            'data' => $shipment->fresh(),
            'message' => 'Shipment marked as delivered',
        ]);
    }

    /**
     * Отменить поставку
     * POST /api/shipments/{id}/cancel
     */
    public function cancel(string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        if (!in_array($shipment->status, [
            Shipment::STATUS_DRAFT,
            Shipment::STATUS_PENDING_LOGISTICS,
            Shipment::STATUS_SUBMITTED,
            Shipment::STATUS_PENDING_CONFIRMATION,
        ])) {
            return response()->json([
                'message' => 'Поставка не может быть отменена в текущем статусе',
            ], 422);
        }

        $shipment->update([
            'status' => Shipment::STATUS_CANCELLED,
        ]);

        return response()->json([
            'data' => $shipment->fresh(),
            'message' => 'Поставка отменена',
        ]);
    }

    /**
     * Синхронизировать статус поставки с маркетплейсом
     * POST /api/shipments/{id}/sync-status
     */
    public function syncStatus(string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        if (!$shipment->external_supply_id) {
            return response()->json([
                'message' => 'Поставка не отправлена в маркетплейс',
            ], 422);
        }

        try {
            $integration = \App\Models\Integration::find($shipment->integration_id);
            
            if (!$integration) {
                return response()->json([
                    'message' => 'Интеграция не найдена',
                ], 422);
            }

            // Получаем статус из маркетплейса
            $suppliesApi = match ($shipment->marketplace) {
                'ozon' => \App\Domains\Ozon\OzonMarketplace::fromIntegration($integration)->supplies(),
                'wildberries' => \App\Domains\Wildberries\WildberriesMarketplace::fromIntegration($integration)->supplies(),
                default => null,
            };

            if ($suppliesApi && method_exists($suppliesApi, 'getSupplyStatus')) {
                $status = $suppliesApi->getSupplyStatus($shipment->external_supply_id);
                
                if ($status) {
                    $shipment->update([
                        'external_status' => $status['status'] ?? null,
                        'synced_at' => now(),
                    ]);
                }
            }

            return response()->json([
                'data' => $shipment->fresh(),
                'message' => 'Статус синхронизирован',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ошибка синхронизации: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function slots(): JsonResponse
    {
        $slots = $this->shipmentService->getAvailableSlots();

        return response()->json([
            'data' => $slots,
        ]);
    }

    public function bookSlot(BookSlotRequest $request, string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);
        $slot = $this->shipmentService->bookSlot($shipment, $request->validated());

        return response()->json([
            'data' => $slot,
            'message' => 'Slot booked successfully',
        ]);
    }

    public function recommendations(): JsonResponse
    {
        $recommendations = ShipmentRecommendation::active()
            ->orderByRaw("FIELD(priority, 'urgent', 'high', 'medium')")
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $recommendations,
        ]);
    }

    public function createFromRecommendation(string $recommendationId): JsonResponse
    {
        $recommendation = ShipmentRecommendation::findOrFail($recommendationId);

        if ($recommendation->is_used) {
            return response()->json([
                'message' => 'Recommendation already used',
            ], 422);
        }

        $shipment = $this->shipmentService->createFromRecommendation($recommendation);

        return response()->json([
            'data' => $shipment->load(['items', 'supplier']),
            'message' => 'Shipment created from recommendation',
        ], 201);
    }

    public function exportPdf(string $id): JsonResponse
    {
        $shipment = Shipment::with(['items', 'supplier'])->findOrFail($id);
        $url = $this->shipmentService->exportToPdf($shipment);

        return response()->json([
            'data' => ['url' => $url],
        ]);
    }

    public function exportCsv(string $id): JsonResponse
    {
        $shipment = Shipment::with(['items', 'supplier'])->findOrFail($id);
        $url = $this->shipmentService->exportToCsv($shipment);

        return response()->json([
            'data' => ['url' => $url],
        ]);
    }

    /**
     * Статистика поставок FBO
     * GET /api/shipments/statistics?integration_id={id}
     */
    public function statistics(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'nullable|string',
        ]);

        $integrationId = $request->input('integration_id');
        
        $query = Shipment::query();
        
        if ($integrationId) {
            $query->where('integration_id', $integrationId);
        }

        $total = (clone $query)->count();
        
        $byStatus = (clone $query)
            ->select('status')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $totalItems = (clone $query)->sum('total_items');
        $totalQuantity = (clone $query)->sum('total_quantity');

        return response()->json([
            'data' => [
                'total' => $total,
                'by_status' => $byStatus,
                'total_items' => (int) $totalItems,
                'total_quantity' => (int) $totalQuantity,
            ],
        ]);
    }

    public function stats(): JsonResponse
    {
        $stats = $this->shipmentService->getStats();

        return response()->json([
            'data' => $stats,
        ]);
    }

    /**
     * Создать поставку из рекомендаций по пополнению
     * 
     * POST /api/shipments/from-inventory-recommendations
     */
    public function createFromInventoryRecommendations(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required|string|exists:integrations,id',
            'warehouse_id' => 'required|string',
        ]);

        try {
            $shipment = $this->shipmentService->createFromInventoryRecommendations(
                $request->input('integration_id'),
                $request->input('warehouse_id')
            );

            return response()->json([
                'data' => $shipment->load(['items', 'supplier']),
                'message' => 'Shipment created from inventory recommendations',
            ], 201);
        } catch (BusinessLogicException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Получить слоты из API маркетплейса
     * 
     * GET /api/shipments/marketplace-slots
     */
    public function marketplaceSlots(Request $request): JsonResponse
    {
        $request->validate([
            'marketplace' => 'required|string|in:wildberries,ozon',
            'warehouse_id' => 'nullable|string',
            'integration_id' => 'nullable|string|exists:integrations,id',
        ]);

        $slots = $this->shipmentService->getMarketplaceSlots(
            $request->input('marketplace'),
            $request->input('warehouse_id'),
            $request->input('integration_id')
        );

        return response()->json([
            'data' => $slots,
        ]);
    }

    /**
     * Генерация этикеток для поставки
     * 
     * POST /api/shipments/{id}/labels
     */
    public function generateLabels(string $id): JsonResponse
    {
        $shipment = Shipment::with('items')->findOrFail($id);

        // Проверяем, что поставка отправлена в маркетплейс
        if (!$shipment->external_supply_id) {
            return response()->json([
                'message' => 'Поставка ещё не отправлена в маркетплейс. Сначала выполните submit.',
            ], 422);
        }

        try {
            $integration = $shipment->integration_id 
                ? \App\Models\Integration::find($shipment->integration_id)
                : \App\Models\Integration::where('marketplace', $shipment->marketplace)
                    ->where('is_active', true)
                    ->first();

            if (!$integration) {
                return response()->json([
                    'message' => 'Интеграция не найдена',
                ], 422);
            }

            // Генерация этикеток зависит от маркетплейса
            $labels = match ($shipment->marketplace) {
                'ozon' => $this->generateOzonLabels($shipment, $integration),
                'wildberries' => $this->generateWildberriesLabels($shipment, $integration),
                default => throw new \RuntimeException("Unsupported marketplace: {$shipment->marketplace}"),
            };

            return response()->json([
                'data' => $labels,
                'message' => 'Labels generated successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ошибка генерации этикеток: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Генерация этикеток Ozon
     */
    private function generateOzonLabels(Shipment $shipment, \App\Models\Integration $integration): array
    {
        $marketplace = \App\Domains\Ozon\OzonMarketplace::fromIntegration($integration);
        $client = $marketplace->getClient();

        // Ozon: POST /v2/posting/fbs/package-label
        $response = $client->post('/v2/posting/fbs/package-label', [
            'posting_number' => [$shipment->external_supply_id],
        ]);

        if (!$response) {
            throw new \RuntimeException('Не удалось получить этикетки от Ozon');
        }

        return [
            'type' => 'pdf',
            'content_base64' => $response['content'] ?? null,
            'url' => $response['url'] ?? null,
            'marketplace' => 'ozon',
        ];
    }

    /**
     * Генерация этикеток Wildberries
     */
    private function generateWildberriesLabels(Shipment $shipment, \App\Models\Integration $integration): array
    {
        $marketplace = \App\Domains\Wildberries\WildberriesMarketplace::fromIntegration($integration);
        $client = $marketplace->getClient();

        // WB: GET /api/v3/supplies/{supplyId}/barcodes
        $response = $client->get("/api/v3/supplies/{$shipment->external_supply_id}/barcodes");

        if (!$response) {
            throw new \RuntimeException('Не удалось получить этикетки от Wildberries');
        }

        return [
            'type' => 'pdf',
            'content_base64' => $response['file'] ?? null,
            'barcodes' => $response['barcodes'] ?? [],
            'marketplace' => 'wildberries',
        ];
    }

    /**
     * Получить склады маркетплейса
     * 
     * GET /api/shipments/warehouses?integration_id={id}
     */
    public function warehouses(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required|string',
            'marketplace' => 'nullable|string|in:wildberries,ozon',
        ]);

        try {
            $integrationId = $request->input('integration_id');
            $integration = \App\Models\Integration::find($integrationId);

            if (!$integration) {
                return response()->json([
                    'data' => [],
                    'message' => 'Интеграция не найдена',
                ], 404);
            }

            $marketplace = $request->input('marketplace') ?? $integration->marketplace;

            $suppliesApi = match ($marketplace) {
                'ozon' => \App\Domains\Ozon\OzonMarketplace::fromIntegration($integration)->supplies(),
                'wildberries' => \App\Domains\Wildberries\WildberriesMarketplace::fromIntegration($integration)->supplies(),
                default => null,
            };

            if (!$suppliesApi) {
                return response()->json([
                    'data' => [],
                    'message' => "Маркетплейс {$marketplace} не поддерживается",
                ]);
            }

            $warehouses = $suppliesApi->getAvailableWarehouses() ?? [];

            return response()->json([
                'data' => $warehouses,
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to fetch warehouses', [
                'integration_id' => $request->input('integration_id'),
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'data' => [],
                'message' => 'Ошибка получения складов: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Поиск товаров для добавления в поставку
     * 
     * GET /api/shipments/products?integration_id={id}&search={query}
     */
    public function products(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required|string',
            'search' => 'nullable|string|max:255',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $integrationId = $request->input('integration_id');
        $search = $request->input('search');
        $perPage = $request->input('per_page', 50);
        $page = $request->input('page', 1);

        $query = \App\Models\Product::where('integration_id', $integrationId)
            ->select([
                'id',
                'sku',
                'marketplace_id',
                'name',
                'barcode',
                'price',
                'stock',
                'images',
                'category',
                'brand',
            ]);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('sku', 'ilike', "%{$search}%")
                  ->orWhere('barcode', 'ilike', "%{$search}%")
                  ->orWhere('marketplace_id', 'ilike', "%{$search}%");
            });
        }

        $query->orderBy('name', 'asc');

        $products = $query->paginate($perPage, ['*'], 'page', $page);

        // Форматируем для удобства фронтенда
        $formattedProducts = collect($products->items())->map(function ($product) {
            $images = $product->images;
            $firstImage = null;
            
            if (is_array($images) && !empty($images)) {
                $firstImage = $images[0];
            } elseif (is_string($images)) {
                $decoded = json_decode($images, true);
                $firstImage = is_array($decoded) && !empty($decoded) ? $decoded[0] : null;
            }

            return [
                'id' => $product->id,
                'sku' => $product->sku,
                'marketplace_id' => $product->marketplace_id,
                'name' => $product->name,
                'barcode' => $product->barcode,
                'price' => $product->price,
                'stock' => $product->stock,
                'image' => $firstImage,
                'category' => $product->category,
                'brand' => $product->brand,
            ];
        });

        return response()->json([
            'data' => $formattedProducts,
            'meta' => [
                'current_page' => $products->currentPage(),
                'total' => $products->total(),
                'per_page' => $products->perPage(),
                'last_page' => $products->lastPage(),
            ],
        ]);
    }

    // ==================== FBO: Грузоместа и этикетки ====================

    /**
     * Получить грузоместа заявки
     * GET /api/shipments/{id}/cargoes
     */
    public function getCargoes(Request $request, string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);
        $integration = \App\Models\Integration::find($shipment->integration_id);

        if (!$integration || $integration->marketplace !== 'ozon') {
            return response()->json(['message' => 'Только для Ozon'], 422);
        }

        try {
            $marketplace = \App\Domains\Ozon\OzonMarketplace::fromIntegration($integration);
            $supplyOrderId = (int) $shipment->external_supply_id;
            
            $cargoes = $marketplace->fboCargoes()->get($supplyOrderId);

            return response()->json(['data' => $cargoes]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Создать грузоместа для заявки
     * POST /api/shipments/{id}/cargoes
     */
    public function createCargoes(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'cargoes' => 'required|array|min:1',
            'cargoes.*.items' => 'required|array',
        ]);

        $shipment = Shipment::findOrFail($id);
        $integration = \App\Models\Integration::find($shipment->integration_id);

        if (!$integration || $integration->marketplace !== 'ozon') {
            return response()->json(['message' => 'Только для Ozon'], 422);
        }

        try {
            $marketplace = \App\Domains\Ozon\OzonMarketplace::fromIntegration($integration);
            $supplyOrderId = (int) $shipment->external_supply_id;
            
            $result = $marketplace->fboCargoes()->create($supplyOrderId, $request->input('cargoes'));

            return response()->json([
                'data' => $result,
                'message' => 'Грузоместа созданы',
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Создать этикетки для грузомест
     * POST /api/shipments/{id}/cargoes/labels
     */
    public function createCargoLabels(Request $request, string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);
        $integration = \App\Models\Integration::find($shipment->integration_id);

        if (!$integration || $integration->marketplace !== 'ozon') {
            return response()->json(['message' => 'Только для Ozon'], 422);
        }

        try {
            $marketplace = \App\Domains\Ozon\OzonMarketplace::fromIntegration($integration);
            $supplyOrderId = (int) $shipment->external_supply_id;
            
            $result = $marketplace->fboCargoes()->createLabels($supplyOrderId);

            return response()->json([
                'data' => $result,
                'message' => 'Задача на генерацию этикеток создана',
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Получить статус этикеток
     * GET /api/shipments/{id}/cargoes/labels/{taskId}
     */
    public function getCargoLabelsStatus(Request $request, string $id, string $taskId): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);
        $integration = \App\Models\Integration::find($shipment->integration_id);

        if (!$integration || $integration->marketplace !== 'ozon') {
            return response()->json(['message' => 'Только для Ozon'], 422);
        }

        try {
            $marketplace = \App\Domains\Ozon\OzonMarketplace::fromIntegration($integration);
            $result = $marketplace->fboCargoes()->getLabelsStatus($taskId);

            return response()->json(['data' => $result]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Скачать PDF этикеток
     * GET /api/shipments/{id}/cargoes/labels/{taskId}/download
     */
    public function downloadCargoLabels(Request $request, string $id, string $fileGuid): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);
        $integration = \App\Models\Integration::find($shipment->integration_id);

        if (!$integration || $integration->marketplace !== 'ozon') {
            return response()->json(['message' => 'Только для Ozon'], 422);
        }

        try {
            $marketplace = \App\Domains\Ozon\OzonMarketplace::fromIntegration($integration);
            $content = $marketplace->fboCargoes()->downloadLabels($fileGuid);

            return response()->json([
                'data' => [
                    'content' => base64_encode($content),
                    'content_type' => 'application/pdf',
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }


    /**
     * Получить счётчики по статусам из Ozon
     * GET /api/shipments/ozon-counters
     */
    public function ozonCounters(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required|integer',
        ]);

        $integration = \App\Models\Integration::find($request->input('integration_id'));

        if (!$integration || $integration->marketplace !== 'ozon') {
            return response()->json(['message' => 'Интеграция не найдена или не Ozon'], 404);
        }

        try {
            $marketplace = \App\Domains\Ozon\OzonMarketplace::fromIntegration($integration);
            $counters = $marketplace->fboSupplyOrders()->getStatusCounters();

            return response()->json(['data' => $counters]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Получить состав заявки (товары)
     * GET /api/shipments/{id}/bundle
     */
    public function getBundle(Request $request, string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);
        $integration = \App\Models\Integration::find($shipment->integration_id);

        if (!$integration || $integration->marketplace !== 'ozon') {
            return response()->json(['message' => 'Только для Ozon'], 422);
        }

        if ($shipment->items()->exists()) {
            $normalizedItems = $shipment->items->map(fn ($item) => [
                'sku' => $item->sku,
                'name' => $item->product_name ?? $item->sku,
                'quantity' => $item->quantity ?? 0,
            ])->toArray();

            return response()->json(['data' => $normalizedItems]);
        }

        $externalSupplyId = $shipment->external_supply_id
            ?? ($shipment->meta['external_supply_id'] ?? null)
            ?? ($shipment->meta['ozon_order_number'] ?? null);

        if (!$externalSupplyId) {
            return response()->json(['data' => []]);
        }

        try {
            $marketplace = \App\Domains\Ozon\OzonMarketplace::fromIntegration($integration);
            $externalSupplyId = (string) $externalSupplyId;
            $supplyOrderId = is_numeric($externalSupplyId) ? (int) $externalSupplyId : null;
            $items = [];

            if ($supplyOrderId) {
                \Illuminate\Support\Facades\Log::info('getBundle: calling Ozon API', [
                    'supply_order_id' => $supplyOrderId,
                ]);
                $bundle = $marketplace->fboSupplyOrders()->getBundle($supplyOrderId);
                \Illuminate\Support\Facades\Log::info('getBundle: Ozon API response', [
                    'supply_order_id' => $supplyOrderId,
                    'bundle_keys' => array_keys($bundle ?? []),
                    'bundles_count' => count($bundle['bundles'] ?? []),
                ]);
                // Новый формат: bundles[].items[]
                $bundles = $bundle['bundles'] ?? [];
                foreach ($bundles as $b) {
                    $bundleItems = $b['items'] ?? [];
                    $items = array_merge($items, $bundleItems);
                }
                // Fallback на старый формат
                if (empty($items)) {
                    $items = $bundle['result']['items']
                        ?? $bundle['items']
                        ?? $bundle['result']['products']
                        ?? $bundle['products']
                        ?? [];
                }
            }

            $resolvedSupplyOrderId = null;
            if (empty($items)) {
                $status = $marketplace->supplies()->getSupplyCreateStatus($externalSupplyId);
                $resolvedSupplyOrderId = $status['result']['supply_order_id']
                    ?? $status['supply_order_id']
                    ?? $status['result']['order_id']
                    ?? $status['order_id']
                    ?? $status['result']['id']
                    ?? null;

                if ($resolvedSupplyOrderId) {
                    $bundle = $marketplace->fboSupplyOrders()->getBundle((int) $resolvedSupplyOrderId);
                    // Новый формат: bundles[].items[]
                    $bundles = $bundle['bundles'] ?? [];
                    foreach ($bundles as $b) {
                        $bundleItems = $b['items'] ?? [];
                        $items = array_merge($items, $bundleItems);
                    }
                    // Fallback на старый формат
                    if (empty($items)) {
                        $items = $bundle['result']['items']
                            ?? $bundle['items']
                            ?? $bundle['result']['products']
                            ?? $bundle['products']
                            ?? [];
                    }

                    if (!empty($items)) {
                        $shipment->update([
                            'external_supply_id' => (string) $resolvedSupplyOrderId,
                        ]);
                    }
                }
            }

            $finalSupplyOrderId = $resolvedSupplyOrderId ?? $supplyOrderId;
            
            \Illuminate\Support\Facades\Log::info('getBundle: trying to fetch items', [
                'shipment_id' => $id,
                'external_supply_id' => $externalSupplyId,
                'supply_order_id' => $supplyOrderId,
                'resolved_supply_order_id' => $resolvedSupplyOrderId,
                'final_supply_order_id' => $finalSupplyOrderId,
                'items_count_so_far' => count($items),
            ]);
            
            // Fallback 1: /v3/supply-order/get
            if (empty($items) && $finalSupplyOrderId) {
                $detailsResponse = $marketplace->fboSupplyOrders()->get([(int) $finalSupplyOrderId]);
                $orders = $detailsResponse['orders'] ?? [];
                $order = $orders[0] ?? null;

                \Illuminate\Support\Facades\Log::info('getBundle: v3/supply-order/get response', [
                    'orders_count' => count($orders),
                    'order_keys' => $order ? array_keys($order) : [],
                ]);

                if ($order) {
                    $items = $order['items'] ?? $order['products'] ?? [];
                    if (empty($items) && !empty($order['supplies']) && is_array($order['supplies'])) {
                        foreach ($order['supplies'] as $supply) {
                            if (!empty($supply['items']) && is_array($supply['items'])) {
                                $items = array_merge($items, $supply['items']);
                            }
                        }
                    }
                    
                    // Сохраняем warehouse_name если есть
                    $warehouseName = $order['dropoff_warehouse_name'] 
                        ?? $order['warehouse_name'] 
                        ?? $order['dropoff_point_name'] 
                        ?? null;
                    if ($warehouseName && !$shipment->warehouse_name) {
                        $shipment->update(['warehouse_name' => $warehouseName]);
                    }
                }
            }
            
            // Fallback 2: /v1/supply/order/items
            if (empty($items) && $finalSupplyOrderId) {
                \Illuminate\Support\Facades\Log::info('getBundle: trying v1/supply/order/items', [
                    'supply_order_id' => $finalSupplyOrderId,
                ]);
                $items = $marketplace->supplies()->getSupplyProducts((string) $finalSupplyOrderId);
            }

            if (!is_array($items)) {
                $items = [];
            }
            
            \Illuminate\Support\Facades\Log::info('getBundle: final result', [
                'items_count' => count($items),
                'first_item' => $items[0] ?? null,
            ]);

            $normalizedItems = array_map(fn ($item) => [
                'sku' => $item['sku'] ?? $item['offer_id'] ?? $item['product_id'] ?? null,
                'name' => $item['name'] ?? $item['offer_id'] ?? null,
                'quantity' => $item['quantity'] ?? $item['qty'] ?? $item['count'] ?? 0,
            ], $items);
            
            // Сохраняем товары в БД если их нет
            if (!empty($normalizedItems) && $shipment->items()->count() === 0) {
                foreach ($normalizedItems as $normalizedItem) {
                    ShipmentItem::create([
                        'shipment_id' => $shipment->id,
                        'sku' => $normalizedItem['sku'] ?? 'unknown',
                        'product_name' => $normalizedItem['name'],
                        'quantity' => (int) ($normalizedItem['quantity'] ?? 0),
                    ]);
                }
                \Illuminate\Support\Facades\Log::info('getBundle: saved items to DB', [
                    'shipment_id' => $id,
                    'items_count' => count($normalizedItems),
                ]);
            }

            return response()->json(['data' => $normalizedItems]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
