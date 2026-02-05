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
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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
        $meta = $shipment->meta ?? [];
        $gtdPath = $meta['gtd_file_path'] ?? null;
        $gtdDownloadUrl = $gtdPath && \Route::has('api.shipments.gtd.download')
            ? route('api.shipments.gtd.download', ['id' => $shipment->id])
            : null;
        $gtdTemplateUrl = \Route::has('api.shipments.gtd.template')
            ? route('api.shipments.gtd.template', ['id' => $shipment->id])
            : null;
        
        return [
            'id' => $shipment->id,
            'integration_id' => $shipment->integration_id,
            'marketplace' => $shipment->marketplace,
            'warehouse_id' => $shipment->warehouse_id,
            'warehouse_name' => $warehouseName,
            'status' => $shipment->status,
            'external_supply_id' => $shipment->external_supply_id,
            'external_status' => $shipment->external_status,
            'status_label' => Shipment::getStatuses()[$shipment->status] ?? $shipment->status,
            'status_color' => $this->getStatusColor($shipment->status),
            'status_group' => $shipment->getStatusGroup(),
            'slot_id' => $slot['id'] ?? null,
            'slot_date' => $slot['date'] ?? null,
            'slot_time_from' => $slot['time_from'] ?? null,
            'slot_time_to' => $slot['time_to'] ?? null,
            'items_count' => $shipment->total_items ?: $shipment->items->count(),
            'total_quantity' => $shipment->total_quantity ?: $shipment->items->sum('quantity'),
            'items' => $shipment->items->map(fn ($item) => [
                'sku' => $item->sku,
                'name' => $item->product_name ?? $item->sku,
                'quantity' => $item->quantity ?? 0,
            ])->toArray(),
            'meta' => array_merge($meta, [
                'external_supply_id' => $shipment->external_supply_id,
                'gtd_file_name' => $meta['gtd_file_name'] ?? null,
                'gtd_uploaded_at' => $meta['gtd_uploaded_at'] ?? null,
                'gtd_download_url' => $gtdDownloadUrl,
                'gtd_template_url' => $gtdTemplateUrl,
            ]),
            'created_at' => $shipment->created_at?->toIso8601String(),
        ];
    }

    /**
     * Загрузить ГТД для поставки
     * POST /api/shipments/{id}/gtd
     */
    public function uploadGtd(Request $request, string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        $request->validate([
            'file' => 'required|file|mimes:xls,xlsx,csv|max:10240',
        ]);

        $file = $request->file('file');
        $directory = "shipments/{$shipment->id}/gtd";
        $filename = 'gtd_' . now()->format('Ymd_His') . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs($directory, $filename);

        $meta = $shipment->meta ?? [];
        $meta['gtd_file_path'] = $path;
        $meta['gtd_file_name'] = $file->getClientOriginalName();
        $meta['gtd_uploaded_at'] = now()->toIso8601String();

        $shipment->update([
            'meta' => $meta,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'file_name' => $meta['gtd_file_name'],
                'download_url' => route('api.shipments.gtd.download', ['id' => $shipment->id]),
                'uploaded_at' => $meta['gtd_uploaded_at'],
            ],
            'message' => 'ГТД загружен',
        ]);
    }

    /**
     * Скачать ГТД
     * GET /api/shipments/{id}/gtd/download
     */
    public function downloadGtd(string $id)
    {
        $shipment = Shipment::findOrFail($id);
        $meta = $shipment->meta ?? [];
        $path = $meta['gtd_file_path'] ?? null;

        if (!$path || !Storage::exists($path)) {
            return response()->json([
                'success' => false,
                'message' => 'Файл ГТД не найден',
            ], 404);
        }

        $filename = $meta['gtd_file_name'] ?? basename($path);

        return Storage::download($path, $filename);
    }

    /**
     * Скачать шаблон ГТД
     * GET /api/shipments/{id}/gtd/template
     */
    public function downloadGtdTemplate(string $id)
    {
        $shipment = Shipment::with('items')->findOrFail($id);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('GTD');

        $sheet->setCellValue('A1', 'SKU');
        $sheet->setCellValue('B1', 'Название');
        $sheet->setCellValue('C1', 'Номер ГТД');

        $row = 2;
        foreach ($shipment->items as $item) {
            $sheet->setCellValue("A{$row}", $item->sku);
            $sheet->setCellValue("B{$row}", $item->product_name ?? $item->sku);
            $sheet->setCellValue("C{$row}", '');
            $row++;
        }

        $writer = new Xlsx($spreadsheet);
        $filename = "gtd_template_{$shipment->id}.xlsx";

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Скачать шаблон для массового добавления товаров
     * GET /api/shipments/items/template
     */
    public function downloadItemsTemplate()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Items');

        $sheet->setCellValue('A1', 'SKU');
        $sheet->setCellValue('B1', 'Количество');

        $sheet->setCellValue('A2', 'SKU-123');
        $sheet->setCellValue('B2', 10);

        $writer = new Xlsx($spreadsheet);
        $filename = 'shipment_items_template.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Загрузить файл с товарами для массового добавления
     * POST /api/shipments/items/upload
     */
    public function uploadItemsFile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:xls,xlsx,csv|max:10240',
            'integration_id' => 'nullable|integer|exists:integrations,id',
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'csv') {
            $reader = new Csv();
            $reader->setDelimiter(';');
            $reader->setEnclosure('"');
            $spreadsheet = $reader->load($file->getRealPath());
            $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);

            $firstRow = $rows[1] ?? [];
            $nonEmpty = array_filter($firstRow, fn ($value) => $value !== null && $value !== '');
            if (count($nonEmpty) <= 1) {
                $reader->setDelimiter(',');
                $spreadsheet = $reader->load($file->getRealPath());
            }
        } else {
            $spreadsheet = IOFactory::load($file->getRealPath());
        }

        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        $headerRow = $rows[1] ?? [];
        $skuColumn = 'A';
        $qtyColumn = 'B';

        foreach ($headerRow as $col => $value) {
            $label = mb_strtolower(trim((string) $value));
            if (in_array($label, ['sku', 'артикул', 'seller sku', 'seller_sku'], true)) {
                $skuColumn = $col;
            }
            if (str_contains($label, 'кол') || str_contains($label, 'quantity')) {
                $qtyColumn = $col;
            }
        }

        $itemsBySku = [];
        $errors = [];
        $skipped = 0;

        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex === 1) {
                continue;
            }

            $sku = trim((string) ($row[$skuColumn] ?? ''));
            $qtyRaw = $row[$qtyColumn] ?? null;

            if ($sku === '') {
                $skipped++;
                continue;
            }

            $quantity = (int) $qtyRaw;
            if ($quantity <= 0) {
                $errors[] = [
                    'row' => $rowIndex,
                    'sku' => $sku,
                    'message' => 'Количество должно быть больше 0',
                ];
                continue;
            }

            $itemsBySku[$sku] = ($itemsBySku[$sku] ?? 0) + $quantity;
        }

        if (empty($itemsBySku)) {
            return response()->json([
                'success' => false,
                'message' => 'Файл не содержит товаров для добавления',
            ], 422);
        }

        $items = [];
        $integrationId = $validated['integration_id'] ?? null;

        $productsQuery = \App\Models\Product::query()->whereIn('sku', array_keys($itemsBySku));
        if ($integrationId) {
            $productsQuery->where('integration_id', $integrationId);
        }
        $products = $productsQuery->get()->keyBy('sku');

        foreach ($itemsBySku as $sku => $quantity) {
            $product = $products->get($sku);
            $items[] = [
                'sku' => $sku,
                'quantity' => $quantity,
                'name' => $product?->name,
                'barcode' => $product?->barcode ?? null,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $items,
                'errors' => $errors,
                'skipped' => $skipped,
            ],
        ]);
    }

    /**
     * Прогноз стоимости поставки (Ozon)
     * POST /api/shipments/{id}/cost-forecast
     */
    public function getCostForecast(string $id): JsonResponse
    {
        $shipment = Shipment::with('items')->findOrFail($id);

        if ($shipment->marketplace !== 'ozon') {
            return response()->json([
                'success' => false,
                'message' => 'Прогноз стоимости доступен только для Ozon',
            ], 422);
        }

        $integration = \App\Models\Integration::find($shipment->integration_id);
        if (!$integration) {
            return response()->json([
                'success' => false,
                'message' => 'Интеграция не найдена',
            ], 422);
        }

        $warehouseId = $shipment->warehouse_id
            ?? ($shipment->meta['warehouse_id'] ?? null);

        if (!$warehouseId) {
            return response()->json([
                'success' => false,
                'message' => 'Не указан склад для прогноза стоимости',
            ], 422);
        }

        try {
            $ozon = \App\Domains\Ozon\OzonMarketplace::fromIntegration($integration);
            $suppliesApi = $ozon->supplies();

            $ozonItems = [];
            $missingSkus = [];

            foreach ($shipment->items as $item) {
                $product = \App\Models\Product::where('sku', $item->sku)
                    ->where('integration_id', $integration->id)
                    ->first();

                $ozonSku = $product?->ozon_data['sku'] ?? null;

                if (!empty($ozonSku)) {
                    $ozonItems[] = [
                        'sku' => (int) $ozonSku,
                        'quantity' => (int) ($item->quantity ?? 0),
                    ];
                } else {
                    $missingSkus[] = $item->sku;
                }
            }

            if (!empty($missingSkus)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нет Ozon SKU для товаров: ' . implode(', ', $missingSkus),
                ], 422);
            }

            if (empty($ozonItems)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нет товаров для расчёта',
                ], 422);
            }

            $clusterId = $shipment->meta['cluster_id'] ?? null;

            if (!$clusterId && method_exists($suppliesApi, 'getFboWarehouses')) {
                $fboWarehouses = $suppliesApi->getFboWarehouses();
                foreach ($fboWarehouses as $wh) {
                    if ((string) ($wh['id'] ?? '') === (string) $warehouseId) {
                        $clusterId = (string) ($wh['cluster_id'] ?? $clusterId);
                        break;
                    }
                }
            }

            if (!$clusterId && method_exists($suppliesApi, 'getClusters')) {
                $clusters = $suppliesApi->getClusters();
                foreach ($clusters as $cluster) {
                    $warehouseIds = $cluster['warehouse_ids'] ?? $cluster['all_warehouse_ids'] ?? [];
                    $warehouseIds = array_map('strval', $warehouseIds);

                    if (in_array((string) $warehouseId, $warehouseIds, true)) {
                        $clusterId = (string) ($cluster['id'] ?? null);
                        break;
                    }
                }
            }

            if (!$clusterId) {
                $clusterId = (string) $warehouseId;
            }

            $draft = $suppliesApi->createDirectDraft([
                'cluster_id' => $clusterId,
                'items' => $ozonItems,
            ]);

            $draftId = $draft['draft_id'] ?? null;
            if (!$draftId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось создать черновик для расчёта стоимости',
                ], 422);
            }

            $draftInfo = $suppliesApi->getDraftInfo((string) $draftId);
            $warehouses = $draftInfo['warehouses'] ?? [];
            $warehouseInfo = null;

            foreach ($warehouses as $wh) {
                if ((string) ($wh['warehouse_id'] ?? '') === (string) $warehouseId) {
                    $warehouseInfo = $wh;
                    break;
                }
            }

            if (!$warehouseInfo && !empty($warehouses)) {
                $warehouseInfo = $warehouses[0];
            }

            $estimatedCost = $warehouseInfo['estimated_cost'] ?? null;

            return response()->json([
                'success' => true,
                'data' => [
                    'draft_id' => $draftId,
                    'warehouse_id' => $warehouseInfo['warehouse_id'] ?? (string) $warehouseId,
                    'warehouse_name' => $warehouseInfo['warehouse_name'] ?? null,
                    'estimated_cost' => $estimatedCost,
                    'items_count' => $warehouseInfo['items_count'] ?? null,
                    'total_quantity' => $warehouseInfo['total_quantity'] ?? null,
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
        // Формат Ozon: timeslot.timeslot.from/to
        if (isset($timeslot['timeslot']['from'])) {
            $slot = $timeslot['timeslot'];
        } elseif (isset($timeslot['value']['timeslot'][0])) {
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

    /**
     * Попытаться найти supply_order_id по supply_id (длинный ID)
     */
    private function resolveSupplyOrderIdBySupplyId(\App\Domains\Ozon\OzonMarketplace $ozon, string $supplyId): ?string
    {
        try {
            $suppliesApi = $ozon->fboSupplyOrders();
            $lastId = null;
            $maxIterations = 20;

            for ($page = 0; $page < $maxIterations; $page++) {
                $listResponse = $suppliesApi->list([], 50, $lastId);
                $orderIds = $listResponse['order_ids'] ?? [];
                $lastId = $listResponse['last_id'] ?? null;

                if (empty($orderIds)) {
                    break;
                }

                foreach (array_chunk($orderIds, 50) as $chunk) {
                    $details = $suppliesApi->get($chunk);
                    foreach ($details['orders'] ?? [] as $order) {
                        $orderId = (string) ($order['order_id'] ?? $order['id'] ?? null);
                        foreach ($order['supplies'] ?? [] as $supply) {
                            if ((string) ($supply['supply_id'] ?? '') === (string) $supplyId) {
                                return $orderId ?: null;
                            }
                        }
                    }
                }

                if (empty($lastId)) {
                    break;
                }
            }
        } catch (\Exception $e) {
            \Log::warning('resolveSupplyOrderIdBySupplyId failed', [
                'supply_id' => $supplyId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
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
                            // Извлекаем warehouse_name из drop_off_warehouse
                            $dropOffWarehouse = $order['drop_off_warehouse'] ?? [];
                            $warehouseName = $dropOffWarehouse['name'] 
                                ?? $order['dropoff_warehouse_name'] 
                                ?? $order['warehouse_name'] 
                                ?? $order['dropoff_point_name']
                                ?? null;
                            
                            // Извлекаем warehouse_id из drop_off_warehouse
                            $warehouseId = $dropOffWarehouse['warehouse_id'] 
                                ?? $order['dropoff_warehouse_id'] 
                                ?? $order['warehouse_id'] 
                                ?? $order['dropoff_point_id']
                                ?? null;
                            
                            \Illuminate\Support\Facades\Log::info('show: drop_off_warehouse extracted', [
                                'warehouse_name' => $warehouseName,
                                'warehouse_id' => $warehouseId,
                                'drop_off_warehouse' => $dropOffWarehouse,
                            ]);
                            
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
                                    // Ответ содержит items напрямую
                                    $items = $bundleResponse['items'] ?? [];
                                    \Illuminate\Support\Facades\Log::info('show: fetched bundle from Ozon', [
                                        'external_supply_id' => $externalSupplyId,
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
            'warehouse_id' => 'required',
            'cluster_id' => 'nullable',
            'items' => 'required|array|min:1',
            'items.*.sku' => 'required|string',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.barcode' => 'nullable|string',
            'timeslot_from' => 'nullable',
            'timeslot_to' => 'nullable',
        ]);

        // Приводим integration_id к строке для совместимости
        $integrationId = (string) $request->input('integration_id');
        $integration = \App\Models\Integration::findOrFail($integrationId);
        $warehouseId = $request->input('warehouse_id');
        $clusterId = $request->input('cluster_id');
        $items = $request->input('items');
        $deliveryMethod = $request->input('delivery_method', 'direct');
        $dropOffPointId = $request->input('drop_off_point_id');
        $dropOffPointType = $request->input('drop_off_point_type');
        $deliveryScheme = $request->input('delivery_scheme', 'drop_off');
        $userTimeslotFrom = $request->input('timeslot_from');
        $userTimeslotTo = $request->input('timeslot_to');

        $externalSupplyId = null;
        $syncError = null;
        $availableClusters = [];

        // Пытаемся создать черновик в Ozon API
        if ($integration->marketplace === 'ozon') {
            try {
                // Для /v1/draft/create нужен числовой SKU Ozon из ozon_data.sku
                $ozonItems = [];
                $missingSkus = [];
                foreach ($items as $item) {
                    $product = \App\Models\Product::where('sku', $item['sku'])
                        ->where('integration_id', $integrationId)
                        ->first();

                    $ozonSku = $product?->ozon_data['sku'] ?? null;

                    if (!empty($ozonSku)) {
                        $ozonItems[] = [
                            'sku' => (int) $ozonSku,
                            'quantity' => (int) $item['quantity'],
                        ];
                    } else {
                        $missingSkus[] = $item['sku'];
                    }
                }

                if (!empty($missingSkus)) {
                    throw new \RuntimeException('Нет Ozon SKU для товаров: ' . implode(', ', $missingSkus));
                }

                if (empty($ozonItems)) {
                    throw new \RuntimeException('Не найдены товары с Ozon SKU для синхронизации');
                }
                
                $marketplace = \App\Domains\Ozon\OzonMarketplace::fromIntegration($integration);
                $suppliesApi = $marketplace->fboSupplyOrders();
                $suppliesPublicApi = $marketplace->supplies();

                $requestedWarehouseId = null;
                $clusterIdForDraft = $clusterId ?: null;

                if (!$clusterId && method_exists($suppliesPublicApi, 'getFboWarehouses')) {
                    $fboWarehouses = $suppliesPublicApi->getFboWarehouses();
                    foreach ($fboWarehouses as $wh) {
                        if ((string) ($wh['id'] ?? '') === (string) $warehouseId) {
                            $requestedWarehouseId = (string) $wh['id'];
                            $clusterIdForDraft = (string) ($wh['cluster_id'] ?? $clusterIdForDraft);
                            break;
                        }
                    }
                }

                if (!$clusterIdForDraft && method_exists($suppliesPublicApi, 'getClusters')) {
                    $clusters = $suppliesPublicApi->getClusters();
                    foreach ($clusters as $cluster) {
                        $warehouseIds = $cluster['warehouse_ids'] ?? $cluster['all_warehouse_ids'] ?? [];
                        $warehouseIds = array_map('strval', $warehouseIds);

                        if (in_array((string) $warehouseId, $warehouseIds, true)) {
                            $clusterIdForDraft = (string) ($cluster['id'] ?? null);
                            break;
                        }
                    }
                }

                if (!$clusterIdForDraft) {
                    if ($requestedWarehouseId) {
                        throw new \RuntimeException('Не удалось определить кластер для выбранного склада');
                    }
                    $clusterIdForDraft = $warehouseId;
                }
                
                // Создаём черновик: items, cluster_ids (опционально), type
                $ozonDraft = $suppliesApi->createDirectDraft(
                    $ozonItems,
                    !empty($clusterIdForDraft) ? [(string) $clusterIdForDraft] : [],
                    'CREATE_TYPE_DIRECT',
                    [
                        'drop_off' => [
                            'point_id' => $dropOffPointId,
                            'point_type' => $dropOffPointType,
                        ],
                    ]
                );
                
                $operationId = $ozonDraft['operation_id'] ?? null;
                
                \Illuminate\Support\Facades\Log::info('Ozon draft created', [
                    'operation_id' => $operationId,
                    'cluster_id' => $clusterIdForDraft,
                    'items_count' => count($ozonItems),
                    'response' => $ozonDraft,
                ]);

                if (empty($operationId)) {
                    $ozonError = $ozonDraft['error'] ?? null;
                    $ozonResponse = $ozonDraft['response'] ?? null;
                    $details = $ozonError ?: $ozonResponse;
                    $errorSuffix = $details ? ' (' . json_encode($details, JSON_UNESCAPED_UNICODE) . ')' : '';
                    throw new \RuntimeException('Не удалось создать черновик в Ozon: operation_id не получен' . $errorSuffix);
                }

                // Ожидаем готовности черновика (polling)
                $draftId = null;
                $draftInfo = null;
                $maxAttempts = 15;
                $attempt = 0;
                
                while ($attempt < $maxAttempts) {
                    $attempt++;
                    $draftInfo = $suppliesApi->getDraftInfo($operationId);
                    if (empty($draftInfo)) {
                        \Illuminate\Support\Facades\Log::warning('Ozon draft status empty (rate limit?)', [
                            'attempt' => $attempt,
                            'operation_id' => $operationId,
                        ]);
                        usleep(2000000);
                        continue;
                    }
                    $status = $draftInfo['status'] ?? '';
                    $draftId = $draftInfo['draft_id'] ?? null;
                    
                    \Illuminate\Support\Facades\Log::info('Ozon draft status check', [
                        'attempt' => $attempt,
                        'status' => $status,
                        'draft_id' => $draftId,
                    ]);
                    
                    // Если статус завершён или есть draft_id > 0
                    if ($status === 'CALCULATION_STATUS_SUCCESS' || ($draftId && $draftId > 0)) {
                        break;
                    }
                    
                    // Если ошибка
                    if ($status === 'CALCULATION_STATUS_ERROR' || $status === 'CALCULATION_STATUS_FAILED') {
                        $errors = $draftInfo['errors'] ?? [];
                        throw new \RuntimeException('Ошибка создания черновика в Ozon: ' . json_encode($errors));
                    }
                    
                    // Ждём 1 секунду перед следующей попыткой
                    usleep(2000000);
                }
                
                if (empty($draftId) || $draftId == 0) {
                    throw new \RuntimeException('Черновик не был создан в Ozon после ' . $maxAttempts . ' попыток (возможно лимит запросов)');
                }

                $availableWarehouse = null;
                $requestedWarehouse = null;

                if (!empty($draftInfo['clusters'])) {
                    foreach ($draftInfo['clusters'] as $cluster) {
                        $clusterData = [
                            'cluster_id' => $cluster['cluster_id'] ?? null,
                            'cluster_name' => $cluster['cluster_name'] ?? null,
                            'warehouses' => [],
                        ];

                        if (!empty($cluster['warehouses'])) {
                            foreach ($cluster['warehouses'] as $wh) {
                                $warehouseData = [
                                    'warehouse_id' => $wh['supply_warehouse']['warehouse_id'] ?? null,
                                    'name' => $wh['supply_warehouse']['name'] ?? null,
                                    'address' => $wh['supply_warehouse']['address'] ?? null,
                                    'is_available' => $wh['status']['is_available'] ?? false,
                                    'state' => $wh['status']['state'] ?? null,
                                    'invalid_reason' => $wh['status']['invalid_reason'] ?? null,
                                    'bundle_ids' => $wh['bundle_ids'] ?? [],
                                ];

                                $clusterData['warehouses'][] = $warehouseData;

                                $isAvailable = ($wh['status']['is_available'] ?? false) === true;
                                $warehouseIdValue = (string) ($wh['supply_warehouse']['warehouse_id'] ?? '');

                                if ($isAvailable && !$availableWarehouse) {
                                    $availableWarehouse = $wh;
                                }

                                if ($requestedWarehouseId && $isAvailable && $warehouseIdValue === (string) $requestedWarehouseId) {
                                    $requestedWarehouse = $wh;
                                }
                            }
                        }

                        if (!empty($clusterData['warehouses'])) {
                            $availableClusters[] = $clusterData;
                        }
                    }
                }

                // Если есть доступный склад — получаем таймслоты и создаём заявку
                if ($requestedWarehouseId && !$requestedWarehouse) {
                    throw new \RuntimeException('Выбранный склад недоступен для поставки. Выберите другой склад.');
                }

                $warehouseForSupply = $requestedWarehouse ?: $availableWarehouse;

                if ($warehouseForSupply && $draftId && is_numeric($draftId)) {
                    $warehouseIdForSupply = $warehouseForSupply['supply_warehouse']['warehouse_id'] ?? null;
                    
                    if ($warehouseIdForSupply) {
                        $timeslots = $suppliesApi->getDraftTimeslots((int) $draftId, (int) $warehouseIdForSupply);
                        
                        // Извлекаем слоты из ответа - проверяем разные структуры
                        $allSlots = [];
                        
                        // Структура 1: warehouses[].days[].timeslots[]
                        $warehouses = $timeslots['warehouses'] ?? [];
                        foreach ($warehouses as $wh) {
                            foreach ($wh['days'] ?? [] as $day) {
                                foreach ($day['timeslots'] ?? [] as $slot) {
                                    $allSlots[] = $slot;
                                }
                            }
                        }
                        
                        // Структура 2: drop_off_warehouse_timeslots[].days[].timeslots[]
                        if (empty($allSlots)) {
                            $dropOffTimeslots = $timeslots['drop_off_warehouse_timeslots'] ?? [];
                            foreach ($dropOffTimeslots as $whTimeslots) {
                                foreach ($whTimeslots['days'] ?? [] as $day) {
                                    foreach ($day['timeslots'] ?? [] as $slot) {
                                        $allSlots[] = $slot;
                                    }
                                }
                            }
                        }
                        
                        // Структура 3: days[].timeslots[] напрямую
                        if (empty($allSlots)) {
                            foreach ($timeslots['days'] ?? [] as $day) {
                                foreach ($day['timeslots'] ?? [] as $slot) {
                                    $allSlots[] = $slot;
                                }
                            }
                        }
                        
                        \Illuminate\Support\Facades\Log::info('Ozon timeslots parsed', [
                            'draft_id' => $draftId,
                            'warehouse_id' => $warehouseIdForSupply,
                            'timeslots_count' => count($allSlots),
                            'first_slot' => $allSlots[0] ?? null,
                        ]);

                        // Используем выбранный пользователем таймслот или первый доступный
                        $timeslotFrom = $userTimeslotFrom;
                        $timeslotTo = $userTimeslotTo;

                        if (empty($timeslotFrom) || empty($timeslotTo)) {
                            $firstSlot = !empty($allSlots) ? $allSlots[0] : null;

                            if (!$firstSlot) {
                                throw new \RuntimeException('Не удалось подобрать таймслот для поставки в Ozon');
                            }

                            $timeslotFrom = $firstSlot['from_in_timezone'] ?? $firstSlot['from'] ?? '';
                            $timeslotTo = $firstSlot['to_in_timezone'] ?? $firstSlot['to'] ?? '';
                        }

                        if (empty($timeslotFrom) || empty($timeslotTo)) {
                            throw new \RuntimeException('Неверные данные таймслота для поставки в Ozon');
                        }

                        $supplyResult = $suppliesApi->createSupplyFromDraft(
                            (int) $draftId,
                            (int) $warehouseIdForSupply,
                            $timeslotFrom,
                            $timeslotTo
                        );

                        \Illuminate\Support\Facades\Log::info('Ozon supply create requested', [
                            'draft_id' => $draftId,
                            'supply_result' => $supplyResult,
                        ]);

                        $supplyOperationId = $supplyResult['operation_id'] ?? null;
                        if (empty($supplyOperationId)) {
                            throw new \RuntimeException('Не удалось создать поставку в Ozon: operation_id не получен');
                        }

                        // Ждём, пока Ozon создаст заявку на поставку и вернёт supply_order_id
                        $supplyOrderId = null;
                        $statusAttempts = 10;
                        for ($i = 1; $i <= $statusAttempts; $i++) {
                            $statusResponse = $suppliesApi->getSupplyCreateStatus($supplyOperationId);
                            $status = $statusResponse['status'] ?? $statusResponse['state'] ?? '';
                            $supplyOrderId = $statusResponse['supply_order_id']
                                ?? ($statusResponse['supply_order_ids'][0] ?? null)
                                ?? ($statusResponse['result']['supply_order_id'] ?? null)
                                ?? ($statusResponse['result']['order_ids'][0] ?? null);

                            \Illuminate\Support\Facades\Log::info('Ozon supply status check', [
                                'attempt' => $i,
                                'operation_id' => $supplyOperationId,
                                'status' => $status,
                                'supply_order_id' => $supplyOrderId,
                                'response' => $statusResponse,
                            ]);

                            if ($supplyOrderId) {
                                break;
                            }

                            if (in_array($status, ['ERROR', 'FAILED', 'REJECTED'], true)) {
                                throw new \RuntimeException('Ozon не создал поставку: ' . json_encode($statusResponse));
                            }

                            usleep(1000000);
                        }

                        if (empty($supplyOrderId)) {
                            throw new \RuntimeException('Ozon не вернул supply_order_id после ожидания');
                        }

                        $externalSupplyId = (string) $supplyOrderId;
                        $warehouseId = (string) $warehouseIdForSupply;
                    } else {
                        throw new \RuntimeException('Не удалось определить склад для поставки в Ozon');
                    }
                } else {
                    throw new \RuntimeException('Черновик не содержит доступных складов для поставки');
                }
            } catch (\Exception $e) {
                $syncError = $e->getMessage();
                \Illuminate\Support\Facades\Log::warning('Failed to create Ozon draft', [
                    'error' => $syncError,
                    'macrolocal_cluster_id' => $warehouseId,
                    'available_clusters_count' => count($availableClusters),
                ]);
            }
        }

        if (empty($externalSupplyId)) {
            return response()->json([
                'message' => 'Не удалось создать поставку в Ozon',
                'sync_error' => $syncError,
                'available_clusters' => $availableClusters,
            ], 422);
        }

        $shipmentData = [
            'name' => 'Поставка ' . now()->format('d.m.Y H:i'),
            'integration_id' => $integration->id,
            'marketplace' => $integration->marketplace,
            'warehouse_id' => $warehouseId,
            'shipment_type' => 'fbo',
            'status' => 'submitted',
            'items' => $items,
            'external_supply_id' => $externalSupplyId,
            'meta' => [
                'external_supply_id' => $externalSupplyId,
            ],
        ];

        $shipment = $this->shipmentService->create($shipmentData);

        return response()->json([
            'data' => $this->formatShipmentResponse($shipment->load(['items'])),
            'message' => 'Поставка создана и синхронизирована с Ozon',
            'ozon_supply_id' => $externalSupplyId,
        ], 201);
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

        $fetchBundleItems = $request->boolean('with_bundle');

        try {
            $marketplace = \App\Domains\Ozon\OzonMarketplace::fromIntegration($integration);
            $suppliesApi = $marketplace->fboSupplyOrders();
            $suppliesPublicApi = $marketplace->supplies();

            // Получаем список заявок из Ozon (с пагинацией)
            $orderIds = [];
            $lastId = null;
            $maxIterations = 20;
            for ($page = 0; $page < $maxIterations; $page++) {
                $listResponse = $suppliesApi->list([], 50, $lastId);
                $batchIds = $listResponse['order_ids'] ?? [];
                $orderIds = array_merge($orderIds, $batchIds);
                $lastId = $listResponse['last_id'] ?? null;

                if (empty($batchIds) || empty($lastId)) {
                    break;
                }
            }

            $ozonOrderIds = array_values(array_unique(array_map('strval', $orderIds)));
            $deleted = 0;
            $localShipments = Shipment::where('integration_id', $integrationId)
                ->where('marketplace', 'ozon')
                ->get();

            foreach ($localShipments as $shipment) {
                $externalId = $shipment->external_supply_id
                    ?? ($shipment->meta['external_supply_id'] ?? null)
                    ?? ($shipment->meta['ozon_order_number'] ?? null);

                if (empty($externalId)) {
                    continue;
                }

                if (!in_array((string) $externalId, $ozonOrderIds, true)) {
                    $shipment->items()->delete();
                    $shipment->delete();
                    $deleted++;
                    continue;
                }

                if (empty($shipment->external_supply_id)) {
                    $shipment->update(['external_supply_id' => (string) $externalId]);
                }
            }

            if (empty($orderIds)) {
                return response()->json([
                    'message' => $deleted > 0
                        ? "Синхронизация завершена: удалено {$deleted}"
                        : 'Нет заявок в Ozon',
                    'synced' => 0,
                    'updated' => 0,
                    'deleted' => $deleted,
                    'total_from_ozon' => 0,
                ]);
            }

            // Получаем детали заявок (Ozon ограничивает размер списка)
            $orders = [];
            $warehouses = collect();
            foreach (array_chunk($orderIds, 50) as $orderChunk) {
                $detailsResponse = $suppliesApi->get($orderChunk);
                $orders = array_merge($orders, $detailsResponse['orders'] ?? []);
                if (!empty($detailsResponse['warehouses'] ?? null)) {
                    $warehouses = $warehouses->merge($detailsResponse['warehouses']);
                }
            }
            $warehouses = $warehouses->keyBy('warehouse_id');

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

                // Получаем информацию о складе из drop_off_warehouse
                $dropOffWarehouse = $order['drop_off_warehouse'] ?? [];
                $dropoffWarehouseId = $dropOffWarehouse['warehouse_id'] 
                    ?? $order['dropoff_warehouse_id'] 
                    ?? $order['warehouse_id'] 
                    ?? $order['dropoff_point_id'] 
                    ?? null;
                $warehouseInfo = $dropoffWarehouseId ? ($warehouses[$dropoffWarehouseId] ?? null) : null;
                $warehouseName = $dropOffWarehouse['name']
                    ?? $order['dropoff_warehouse_name']
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
                if ($fetchBundleItems && empty($items)) {
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
                'message' => "Синхронизация завершена: создано {$synced}, обновлено {$updated}, удалено {$deleted}",
                'synced' => $synced,
                'updated' => $updated,
                'deleted' => $deleted,
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
            'ACCEPTED_AT_SUPPLY_WAREHOUSE' => Shipment::STATUS_SENT,
            'IN_TRANSIT' => Shipment::STATUS_IN_TRANSIT,
            'AT_WAREHOUSE' => Shipment::STATUS_ARRIVED,
            'ACCEPTING' => Shipment::STATUS_PROCESSING,
            'ACCEPTANCE' => Shipment::STATUS_PROCESSING,
            'ACCEPTANCE_AT_STORAGE_WAREHOUSE' => Shipment::STATUS_PROCESSING,
            'REPORTS_CONFIRMATION_AWAITING' => Shipment::STATUS_PARTIALLY_ACCEPTED,
            'REPORT_REJECTED' => Shipment::STATUS_PARTIALLY_ACCEPTED,
            'ACCEPTED' => Shipment::STATUS_DELIVERED,
            'COMPLETED' => Shipment::STATUS_DELIVERED,
            'PARTIALLY_ACCEPTED' => Shipment::STATUS_PARTIALLY_ACCEPTED,
            'REJECTED_AT_SUPPLY_WAREHOUSE' => Shipment::STATUS_REJECTED,
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

        $externalSupplyId = $shipment->external_supply_id
            ?? ($shipment->meta['external_supply_id'] ?? null)
            ?? ($shipment->meta['ozon_order_number'] ?? null);

        if ($shipment->marketplace === 'ozon' && $externalSupplyId) {
            $integration = \App\Models\Integration::find($shipment->integration_id);

            if (!$integration) {
                return response()->json([
                    'message' => 'Интеграция не найдена',
                ], 422);
            }

            try {
                if (!is_numeric($externalSupplyId) || (int) $externalSupplyId <= 0) {
                    return response()->json([
                        'message' => 'Некорректный ID заявки для отмены',
                    ], 422);
                }

                $ozon = \App\Domains\Ozon\OzonMarketplace::fromIntegration($integration);
                $supplyOrderId = (string) $externalSupplyId;

                // Если передали supply_id (длинный), пытаемся найти supply_order_id
                if (strlen($supplyOrderId) > 10) {
                    $resolvedOrderId = $this->resolveSupplyOrderIdBySupplyId($ozon, $supplyOrderId);
                    if (!$resolvedOrderId) {
                        return response()->json([
                            'message' => 'Не удалось определить supply_order_id для отмены',
                        ], 422);
                    }

                    $supplyOrderId = $resolvedOrderId;
                    $meta = $shipment->meta ?? [];
                    $meta['external_supply_id'] = $resolvedOrderId;
                    $shipment->update([
                        'external_supply_id' => $resolvedOrderId,
                        'meta' => $meta,
                    ]);
                }

                $cancelResult = $ozon->fboSupplyOrders()->cancel((int) $supplyOrderId);

                if (empty($cancelResult['success'])) {
                    return response()->json([
                        'message' => $cancelResult['error'] ?? 'Не удалось отменить поставку в Ozon',
                        'debug' => $cancelResult['response'] ?? null,
                    ], 422);
                }

                $operationId = $cancelResult['operation_id'] ?? null;
                if ($operationId) {
                    for ($i = 1; $i <= 5; $i++) {
                        $cancelStatus = $ozon->fboSupplyOrders()->getCancelStatus($operationId);
                        $status = $cancelStatus['status']
                            ?? $cancelStatus['state']
                            ?? ($cancelStatus['result']['status'] ?? null);

                        if (in_array($status, ['CANCELLED', 'CANCELED', 'SUCCESS', 'DONE', 'OK'], true)) {
                            break;
                        }

                        if (in_array($status, ['FAILED', 'ERROR', 'REJECTED'], true)) {
                            return response()->json([
                                'message' => 'Ozon не отменил поставку: ' . json_encode($cancelStatus, JSON_UNESCAPED_UNICODE),
                            ], 422);
                        }

                        usleep(1000000);
                    }
                }
            } catch (\Exception $e) {
                return response()->json([
                    'message' => 'Ошибка отмены в Ozon: ' . $e->getMessage(),
                ], 422);
            }
        }

        $shipment->update([
            'status' => Shipment::STATUS_CANCELLED,
            'external_status' => $shipment->marketplace === 'ozon' ? 'CANCELLED' : $shipment->external_status,
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

            $warehouses = [];

            if ($marketplace === 'ozon') {
                if (method_exists($suppliesApi, 'getClusters')) {
                    $clusters = $suppliesApi->getClusters();
                    foreach ($clusters as $cluster) {
                        // Показываем только кластеры с доступными складами
                        $clusterWarehouses = $cluster['warehouses'] ?? [];
                        if (empty($clusterWarehouses)) {
                            continue;
                        }
                        
                        foreach ($clusterWarehouses as $warehouse) {
                            $warehouses[] = [
                                'id' => (string) ($warehouse['id'] ?? $warehouse['warehouse_id'] ?? null),
                                'name' => $warehouse['name'] ?? null,
                                'type' => $warehouse['type'] ?? null,
                                'cluster_id' => (string) ($cluster['id'] ?? null),
                                'cluster_name' => $cluster['name'] ?? null,
                                'warehouses_count' => $cluster['warehouses_count'] ?? count($clusterWarehouses),
                            ];
                        }
                    }
                }

                if (empty($warehouses)) {
                    $warehouses = $suppliesApi->getFboWarehouses() ?? [];
                }
            } else {
                $warehouses = $suppliesApi->getAvailableWarehouses() ?? [];
            }

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
     * 
     * Возвращает локальные грузоместа из БД + данные из Ozon API
     */
    public function getCargoes(Request $request, string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);
        
        // Получаем локальные грузоместа из meta
        $localCargoes = $shipment->meta['cargoes'] ?? [];
        
        // Пробуем получить из Ozon API если есть интеграция
        $ozonCargoes = [];
        $integration = \App\Models\Integration::find($shipment->integration_id);
        
        if ($integration && $integration->marketplace === 'ozon' && $shipment->external_supply_id) {
            try {
                $marketplace = \App\Domains\Ozon\OzonMarketplace::fromIntegration($integration);
                $supplyOrderId = (int) $shipment->external_supply_id;
                $ozonCargoes = $marketplace->fboCargoes()->get($supplyOrderId);
            } catch (\Exception $e) {
                // Игнорируем ошибку Ozon API, возвращаем локальные данные
                \Log::warning('Failed to get Ozon cargoes', ['error' => $e->getMessage()]);
            }
        }

        // Форматируем локальные грузоместа в стандартный формат
        $formattedCargoes = collect($localCargoes)->map(function ($cargo, $index) {
            return [
                'id' => $cargo['id'] ?? $index + 1,
                'supply_id' => $cargo['supply_id'] ?? null,
                'barcode' => $cargo['barcode'] ?? 'PKG-' . str_pad($index + 1, 4, '0', STR_PAD_LEFT),
                'package_type' => $cargo['package_type'] ?? $cargo['type'] ?? 'box',
                'sequence_number' => $cargo['sequence_number'] ?? $index + 1,
                'status' => $cargo['status'] ?? 'draft',
                'weight' => $cargo['weight'] ?? null,
                'length' => $cargo['length'] ?? null,
                'width' => $cargo['width'] ?? null,
                'height' => $cargo['height'] ?? null,
                'items_count' => $cargo['items_count'] ?? 0,
                'total_quantity' => $cargo['total_quantity'] ?? 0,
                'items' => $cargo['items'] ?? [],
                'created_at' => $cargo['created_at'] ?? now()->toIso8601String(),
            ];
        })->values()->toArray();

        return response()->json([
            'success' => true,
            'data' => $formattedCargoes,
            'ozon_cargoes' => $ozonCargoes,
            'summary' => [
                'total_packages' => count($formattedCargoes),
                'total_items' => collect($formattedCargoes)->sum('items_count'),
                'total_quantity' => collect($formattedCargoes)->sum('total_quantity'),
                'by_status' => collect($formattedCargoes)->groupBy('status')->map->count(),
            ],
        ]);
    }

    /**
     * Создать грузоместо для заявки
     * POST /api/shipments/{id}/cargoes
     * 
     * Поддерживает два формата:
     * 1. Простой: { package_type: 'box' } - создаёт пустое грузоместо
     * 2. Полный: { cargoes: [...] } - создаёт грузоместа с товарами (для Ozon API)
     */
    public function createCargoes(Request $request, string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);
        
        // Простой формат - создание одного грузоместа
        if ($request->has('package_type')) {
            $request->validate([
                'package_type' => 'required|in:box,pallet,mono_pallet',
                'weight' => 'nullable|numeric|min:0',
                'length' => 'nullable|numeric|min:0',
                'width' => 'nullable|numeric|min:0',
                'height' => 'nullable|numeric|min:0',
            ]);
            
            $meta = $shipment->meta ?? [];
            $cargoes = $meta['cargoes'] ?? [];
            
            $newCargo = [
                'id' => count($cargoes) + 1,
                'package_type' => $request->input('package_type'),
                'sequence_number' => count($cargoes) + 1,
                'barcode' => 'PKG-' . $shipment->id . '-' . str_pad(count($cargoes) + 1, 3, '0', STR_PAD_LEFT),
                'status' => 'draft',
                'weight' => $request->input('weight'),
                'length' => $request->input('length'),
                'width' => $request->input('width'),
                'height' => $request->input('height'),
                'items_count' => 0,
                'total_quantity' => 0,
                'items' => [],
                'created_at' => now()->toIso8601String(),
            ];
            
            $cargoes[] = $newCargo;
            $meta['cargoes'] = $cargoes;
            $shipment->update(['meta' => $meta]);
            
            return response()->json([
                'success' => true,
                'data' => $newCargo,
                'message' => 'Грузоместо создано',
            ], 201);
        }
        
        // Полный формат - для Ozon API
        $request->validate([
            'cargoes' => 'required|array|min:1',
            'cargoes.*.items' => 'required|array',
        ]);

        $integration = \App\Models\Integration::find($shipment->integration_id);

        if (!$integration || $integration->marketplace !== 'ozon') {
            return response()->json(['message' => 'Только для Ozon'], 422);
        }

        try {
            $marketplace = \App\Domains\Ozon\OzonMarketplace::fromIntegration($integration);
            $supplyOrderId = (int) $shipment->external_supply_id;
            
            $result = $marketplace->fboCargoes()->create($supplyOrderId, $request->input('cargoes'));

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'Грузоместа созданы в Ozon',
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Обновить товары в грузоместе
     * PUT /api/shipments/{id}/cargoes/{cargoId}/items
     */
    public function updateCargoItems(Request $request, string $id, int $cargoId): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);
        
        $request->validate([
            'items' => 'required|array',
            'items.*.sku' => 'required|string',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.offer_id' => 'nullable|string',
            'items.*.barcode' => 'nullable|string',
        ]);
        
        $meta = $shipment->meta ?? [];
        $cargoes = $meta['cargoes'] ?? [];
        
        $cargoIndex = collect($cargoes)->search(fn($c) => ($c['id'] ?? 0) == $cargoId);
        
        if ($cargoIndex === false) {
            return response()->json([
                'success' => false,
                'message' => 'Грузоместо не найдено',
            ], 404);
        }
        
        $items = $request->input('items');
        $cargoes[$cargoIndex]['items'] = $items;
        $cargoes[$cargoIndex]['items_count'] = count($items);
        $cargoes[$cargoIndex]['total_quantity'] = collect($items)->sum('quantity');
        
        $meta['cargoes'] = $cargoes;
        $shipment->update(['meta' => $meta]);
        
        return response()->json([
            'success' => true,
            'data' => $cargoes[$cargoIndex],
            'message' => 'Товары обновлены',
        ]);
    }

    /**
     * Синхронизировать грузоместа из Ozon в локальную поставку
     * POST /api/shipments/{id}/cargoes/sync-ozon
     */
    public function syncCargoesFromOzon(Request $request, string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);
        $integration = \App\Models\Integration::find($shipment->integration_id);

        if (!$integration || $integration->marketplace !== 'ozon') {
            return response()->json(['message' => 'Только для Ozon'], 422);
        }

        if (!$shipment->external_supply_id) {
            return response()->json(['message' => 'Нет external_supply_id'], 422);
        }

        try {
            $marketplace = \App\Domains\Ozon\OzonMarketplace::fromIntegration($integration);
            $supplyOrderId = (int) $shipment->external_supply_id;

            // Получаем supply_id
            $orderDetails = $marketplace->supplies()->getSupplyOrdersDetails([$supplyOrderId]);
            $supplyId = $orderDetails['orders'][0]['supplies'][0]['supply_id'] ?? null;

            if (!$supplyId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось найти supply_id для заявки',
                ], 422);
            }

            // Получаем грузоместа через /v1/cargoes/get
            $client = $marketplace->getClient();
            $cargoResponse = $client->post('/v1/cargoes/get', [
                'supply_ids' => [(int) $supplyId],
            ]);

            $ozonCargoes = $cargoResponse['supply'][0]['cargoes'] ?? [];
            $mappedCargoes = collect($ozonCargoes)->values()->map(function ($cargo, $index) {
                $type = strtolower($cargo['type'] ?? 'box');
                if (!in_array($type, ['box', 'pallet'], true)) {
                    $type = 'box';
                }

                $ozonCargoId = $cargo['cargo_id'] ?? null;
                $localId = $index + 1;

                return [
                    'id' => $localId,
                    'ozon_cargo_id' => $ozonCargoId,
                    'package_type' => $type,
                    'sequence_number' => $localId,
                    'barcode' => (string) ($ozonCargoId ?? ('OZON-' . $localId)),
                    'status' => $cargo['tracking_info']['status'] ?? 'ozon',
                    'weight' => $cargo['weight'] ?? null,
                    'length' => $cargo['length'] ?? null,
                    'width' => $cargo['width'] ?? null,
                    'height' => $cargo['height'] ?? null,
                    'items_count' => count($cargo['items'] ?? []),
                    'total_quantity' => collect($cargo['items'] ?? [])->sum('quantity'),
                    'items' => $cargo['items'] ?? [],
                    'created_at' => now()->toIso8601String(),
                    'source' => 'ozon',
                ];
            })->values()->toArray();

            $meta = $shipment->meta ?? [];
            $meta['cargoes'] = $mappedCargoes;
            $shipment->update(['meta' => $meta]);

            return response()->json([
                'success' => true,
                'data' => $mappedCargoes,
                'ozon_cargoes' => $ozonCargoes,
                'message' => 'Грузоместа синхронизированы с Ozon',
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to sync cargoes from Ozon', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
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
                try {
                    $bundleResponse = $marketplace->fboSupplyOrders()->getBundle($supplyOrderId);
                    // Ответ содержит items напрямую
                    $items = $bundleResponse['items'] ?? [];
                    \Illuminate\Support\Facades\Log::info('getBundle: response', [
                        'supply_order_id' => $supplyOrderId,
                        'items_count' => count($items),
                    ]);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('getBundle: failed', [
                        'supply_order_id' => $supplyOrderId,
                        'error' => $e->getMessage(),
                    ]);
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
                    try {
                        $bundleResponse = $marketplace->fboSupplyOrders()->getBundle((int) $resolvedSupplyOrderId);
                        $items = $bundleResponse['items'] ?? [];
                    } catch (\Exception $e) {
                        \Illuminate\Support\Facades\Log::warning('getBundle: resolved failed', [
                            'resolved_supply_order_id' => $resolvedSupplyOrderId,
                            'error' => $e->getMessage(),
                        ]);
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

    /**
     * Создать черновик в Ozon (без создания заявки на поставку)
     * 
     * POST /api/shipments/create-draft
     */
    public function createDraft(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required',
            'warehouse_id' => 'nullable',
            'cluster_id' => 'nullable',
            'delivery_method' => 'nullable|in:direct,crossdock',
            'drop_off_point_id' => 'nullable|string',
            'drop_off_point_type' => 'nullable|string',
            'delivery_scheme' => 'nullable|in:drop_off,pick_up',
            'items' => 'required|array|min:1',
            'items.*.sku' => 'required|string',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        $integrationId = (string) $request->input('integration_id');
        $integration = \App\Models\Integration::findOrFail($integrationId);
        $warehouseId = $request->input('warehouse_id');
        $clusterId = $request->input('cluster_id');
        $items = $request->input('items');
        $deliveryMethod = $request->input('delivery_method', 'direct');
        $dropOffPointId = $request->input('drop_off_point_id');
        $dropOffPointType = $request->input('drop_off_point_type');
        $deliveryScheme = $request->input('delivery_scheme', 'drop_off');

        if ($integration->marketplace !== 'ozon') {
            return response()->json([
                'message' => 'Черновики поддерживаются только для Ozon',
            ], 422);
        }

        try {
            $ozonItems = [];
            $missingSkus = [];
            foreach ($items as $item) {
                $product = \App\Models\Product::where('sku', $item['sku'])
                    ->where('integration_id', $integrationId)
                    ->first();

                $ozonSku = $product?->ozon_data['sku'] ?? null;

                if (!empty($ozonSku)) {
                    $ozonItems[] = [
                        'sku' => (int) $ozonSku,
                        'quantity' => (int) $item['quantity'],
                    ];
                } else {
                    $missingSkus[] = $item['sku'];
                }
            }

            if (!empty($missingSkus)) {
                return response()->json([
                    'message' => 'Нет Ozon SKU для товаров: ' . implode(', ', $missingSkus),
                ], 422);
            }

            if (empty($ozonItems)) {
                return response()->json([
                    'message' => 'Не найдены товары с Ozon SKU для синхронизации',
                ], 422);
            }
            
            $marketplace = \App\Domains\Ozon\OzonMarketplace::fromIntegration($integration);
            $suppliesApi = $marketplace->fboSupplyOrders();
            $suppliesPublicApi = $marketplace->supplies();

            // ВАЖНО: Всегда создаём черновик БЕЗ кластера (пустой массив),
            // чтобы получить мультикластерный черновик и узнать доступность складов
            // для конкретных товаров. Ozon определяет доступность по категориям товаров.
            // Кластер будет выбран позже при бронировании слота.

            // Создаём черновик (без кластера — Ozon вернёт все доступные склады)
            // Обрабатываем rate limit (429) с небольшим ретраем
            \Log::info('[FBO Draft] Начинаем создание черновика', [
                'integration_id' => $integrationId,
                'items_count' => count($ozonItems),
                'items' => $ozonItems,
            ]);
            
            $ozonDraft = null;
            $maxCreateAttempts = 3;
            for ($attempt = 1; $attempt <= $maxCreateAttempts; $attempt++) {
                \Log::info("[FBO Draft] Попытка создания черновика #{$attempt}");

                $clusterIds = !empty($clusterId) ? [(string) $clusterId] : [];
                $isCrossdock = $deliveryMethod === 'crossdock'
                    || !empty($dropOffPointId)
                    || !empty($dropOffPointType)
                    || (!empty($deliveryScheme) && $deliveryScheme !== 'drop_off');
                if ($isCrossdock) {
                    $deliveryMethod = 'crossdock';
                }
                $draftType = $isCrossdock ? 'CREATE_TYPE_CROSSDOCK' : 'CREATE_TYPE_DIRECT';

                if ($draftType === 'CREATE_TYPE_CROSSDOCK' && empty($dropOffPointId)) {
                    return response()->json([
                        'message' => 'Не указана точка отгрузки для кросс-докинга',
                    ], 422);
                }

                if ($draftType === 'CREATE_TYPE_CROSSDOCK' && empty($dropOffPointType)) {
                    return response()->json([
                        'message' => 'Не указан тип точки отгрузки для кросс-докинга',
                    ], 422);
                }

                if ($draftType === 'CREATE_TYPE_CROSSDOCK' && empty($clusterId)) {
                    return response()->json([
                        'message' => 'Не указан кластер для кросс-докинга',
                    ], 422);
                }

                if ($draftType === 'CREATE_TYPE_CROSSDOCK') {
                    $crossdockDraft = $suppliesPublicApi->createCrossdockDraft([
                        'macrolocal_cluster_id' => (string) $clusterId,
                        'delivery_scheme' => $deliveryScheme,
                        'point_id' => (string) $dropOffPointId,
                        'point_type' => (string) $dropOffPointType,
                        'items' => $ozonItems,
                    ]);

                    $draftId = $crossdockDraft['draft_id'] ?? null;
                    if (empty($draftId)) {
                        return response()->json([
                            'message' => 'Не удалось создать черновик кросс-докинга',
                            'debug' => [
                                'response' => $crossdockDraft,
                            ],
                        ], 422);
                    }

                    $draftInfo = $suppliesPublicApi->getDraftInfo((string) $draftId);
                    $warehouses = $draftInfo['warehouses'] ?? [];
                    $clustersMap = [];
                    $availableWarehouseId = null;

                    foreach ($warehouses as $warehouse) {
                        $warehouseClusterId = (string) ($warehouse['cluster_id'] ?? '');
                        if (!empty($clusterId) && $warehouseClusterId !== (string) $clusterId) {
                            continue;
                        }
                        if (empty($clustersMap[$warehouseClusterId])) {
                            $clustersMap[$warehouseClusterId] = [
                                'cluster_id' => $warehouse['cluster_id'] ?? $clusterId,
                                'cluster_name' => $warehouse['cluster_name'] ?? null,
                                'warehouses' => [],
                            ];
                        }

                        $isAvailable = $warehouse['is_available'] ?? true;
                        $warehouseIdValue = $warehouse['warehouse_id'] ?? null;
                        $clustersMap[$warehouseClusterId]['warehouses'][] = [
                            'warehouse_id' => $warehouseIdValue,
                            'name' => $warehouse['warehouse_name'] ?? null,
                            'address' => $warehouse['address'] ?? null,
                            'is_available' => $isAvailable,
                            'invalid_reason' => $warehouse['invalid_reason'] ?? null,
                            'bundle_ids' => [],
                        ];

                        if ($isAvailable && !$availableWarehouseId) {
                            $availableWarehouseId = $warehouseIdValue;
                        }
                    }

                    $availableClusters = array_values(array_filter($clustersMap, function ($cluster) {
                        return !empty($cluster['warehouses']);
                    }));

                    return response()->json([
                        'success' => true,
                        'draft_id' => $draftId,
                        'warehouse_id' => $availableWarehouseId,
                        'available_clusters' => $availableClusters,
                    ]);
                }

                $ozonDraft = $suppliesApi->createDirectDraft(
                    $ozonItems,
                    $clusterIds,
                    $draftType,
                    $draftType === 'CREATE_TYPE_CROSSDOCK' ? (int) $dropOffPointId : null
                );

                \Log::info("[FBO Draft] Ответ createDirectDraft", [
                    'attempt' => $attempt,
                    'response' => $ozonDraft,
                ]);

                $httpStatus = $ozonDraft['_http_status'] ?? null;
                $errorCode = $ozonDraft['error']['code'] ?? $ozonDraft['code'] ?? null;
                $isRateLimit = $httpStatus === 429 || (int) $errorCode === 8;

                if (!$isRateLimit) {
                    break;
                }

                \Log::warning("[FBO Draft] Rate limit, ждём перед повтором", ['attempt' => $attempt]);
                usleep(1000000 * $attempt);
            }
            
            $operationId = $ozonDraft['operation_id'] ?? null;
            
            if (empty($operationId)) {
                $ozonError = $ozonDraft['error'] ?? null;
                $ozonResponse = $ozonDraft['response'] ?? null;
                $ozonMessage = $ozonDraft['message'] ?? null;
                $details = $ozonError ?: $ozonResponse ?: $ozonMessage;
                $errorSuffix = $details ? ' (' . json_encode($details, JSON_UNESCAPED_UNICODE) . ')' : '';
                $httpStatus = $ozonDraft['_http_status'] ?? null;
                $errorCode = $ozonDraft['error']['code'] ?? $ozonDraft['code'] ?? null;

                \Log::error('[FBO Draft] operation_id не получен', [
                    'ozon_draft' => $ozonDraft,
                    'http_status' => $httpStatus,
                    'error_code' => $errorCode,
                ]);

                if ($httpStatus === 429 || (int) $errorCode === 8) {
                    return response()->json([
                        'message' => 'Превышен лимит запросов к Ozon. Подождите 1–2 минуты и попробуйте снова.',
                    ], 429);
                }

                return response()->json([
                    'message' => 'Не удалось создать черновик в Ozon: operation_id не получен' . $errorSuffix,
                    'debug' => [
                        'ozon_response' => $ozonDraft,
                    ],
                ], 422);
            }

            \Log::info('[FBO Draft] operation_id получен', ['operation_id' => $operationId]);

            // Шаг 1: Ожидаем готовности черновика через v1 API (получаем draft_id)
            // ВАЖНО: Ozon имеет строгий rate limit, поэтому используем экспоненциальную задержку
            $draftId = null;
            $draftStatus = null;
            $lastCreateStatus = null;
            $maxAttempts = 10;
            $baseDelay = 3; // секунды
            $rateLimitHits = 0;
            
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                // Экспоненциальная задержка: 3, 4, 5, 6... секунд + дополнительно при rate limit
                $delay = $baseDelay + ($attempt - 1) + ($rateLimitHits * 5);
                if ($attempt > 1) {
                    \Log::info("[FBO Draft] Ждём {$delay} сек перед попыткой #{$attempt}");
                    sleep($delay);
                }
                
                $createStatus = $suppliesApi->getDraftCreateStatus($operationId);
                $lastCreateStatus = $createStatus;
                
                // Проверяем rate limit
                $httpStatus = $createStatus['_http_status'] ?? null;
                $errorCode = $createStatus['code'] ?? null;
                $isRateLimit = $httpStatus === 429 || (int) $errorCode === 8;
                
                if ($isRateLimit) {
                    $rateLimitHits++;
                    \Log::warning("[FBO Draft] Rate limit при проверке статуса, попытка #{$attempt}, всего rate limit: {$rateLimitHits}");
                    
                    if ($rateLimitHits >= 3) {
                        // После 3 rate limit — ждём дольше
                        \Log::info("[FBO Draft] Много rate limit, ждём 30 секунд");
                        sleep(30);
                    }
                    continue;
                }
                
                \Log::info("[FBO Draft] Проверка статуса черновика #{$attempt}", [
                    'operation_id' => $operationId,
                    'response' => $createStatus,
                ]);
                
                if (empty($createStatus)) {
                    continue;
                }
                
                $status = $createStatus['status'] ?? '';
                $draftId = $createStatus['draft_id'] ?? null;
                
                if ($status === 'CALCULATION_STATUS_SUCCESS' || ($draftId && $draftId > 0)) {
                    $draftStatus = $status;
                    \Log::info('[FBO Draft] Черновик готов', ['draft_id' => $draftId, 'status' => $status]);
                    break;
                }
                
                if ($status === 'CALCULATION_STATUS_ERROR' || $status === 'CALCULATION_STATUS_FAILED') {
                    $errors = $createStatus['errors'] ?? [];
                    \Log::error('[FBO Draft] Ошибка создания черновика', ['status' => $status, 'errors' => $errors]);
                    return response()->json([
                        'message' => 'Ошибка создания черновика в Ozon: ' . json_encode($errors, JSON_UNESCAPED_UNICODE),
                        'debug' => [
                            'status' => $status,
                            'errors' => $errors,
                            'full_response' => $createStatus,
                        ],
                    ], 422);
                }
            }
            
            if (empty($draftId) || $draftId == 0) {
                \Log::error('[FBO Draft] draft_id не получен после ожидания', [
                    'operation_id' => $operationId,
                    'last_status' => $lastCreateStatus,
                    'attempts' => $maxAttempts,
                    'rate_limit_hits' => $rateLimitHits,
                ]);
                
                $errorMessage = 'Черновик не был создан в Ozon после ожидания';
                if ($rateLimitHits > 0) {
                    $errorMessage = "Превышен лимит запросов к Ozon (rate limit: {$rateLimitHits} раз). Подождите 2-3 минуты и попробуйте снова.";
                }
                
                return response()->json([
                    'message' => $errorMessage,
                    'debug' => [
                        'operation_id' => $operationId,
                        'last_status_response' => $lastCreateStatus,
                        'attempts_made' => $maxAttempts,
                    ],
                ], 422);
            }

            // Шаг 2: Извлекаем информацию о кластерах и складах из v1 API ответа
            // v1 API уже возвращает clusters с warehouses и их доступностью
            $availableClusters = [];
            $availableWarehouseId = null;
            
            $clustersFromV1 = $lastCreateStatus['clusters'] ?? [];
            
            \Log::info('[FBO Draft] Обработка кластеров из v1 API', [
                'clusters_count' => count($clustersFromV1),
            ]);

            if (!empty($clustersFromV1)) {
                foreach ($clustersFromV1 as $cluster) {
                    $clusterData = [
                        'cluster_id' => $cluster['cluster_id'] ?? null,
                        'cluster_name' => $cluster['cluster_name'] ?? null,
                        'warehouses' => [],
                    ];

                    foreach ($cluster['warehouses'] ?? [] as $wh) {
                        // v1 API: данные в supply_warehouse и status
                        $supplyWarehouse = $wh['supply_warehouse'] ?? [];
                        $status = $wh['status'] ?? [];
                        $isAvailable = $status['is_available'] ?? false;
                        
                        $warehouseId = $supplyWarehouse['warehouse_id'] ?? $wh['warehouse_id'] ?? null;
                        
                        $clusterData['warehouses'][] = [
                            'warehouse_id' => $warehouseId,
                            'name' => $supplyWarehouse['name'] ?? $wh['name'] ?? null,
                            'address' => $supplyWarehouse['address'] ?? $wh['address'] ?? null,
                            'is_available' => $isAvailable,
                            'invalid_reason' => $status['invalid_reason'] ?? null,
                            'bundle_ids' => $wh['bundle_ids'] ?? [],
                        ];

                        if ($isAvailable && !$availableWarehouseId) {
                            $availableWarehouseId = $warehouseId;
                        }
                    }

                    if (!empty($clusterData['warehouses'])) {
                        $availableClusters[] = $clusterData;
                    }
                }
            }
            
            if ($draftType === 'CREATE_TYPE_CROSSDOCK' && !empty($clusterId)) {
                $availableClusters = array_values(array_filter($availableClusters, function ($cluster) use ($clusterId) {
                    return (string) ($cluster['cluster_id'] ?? '') === (string) $clusterId;
                }));

                $availableWarehouseId = null;
                foreach ($availableClusters as $cluster) {
                    foreach ($cluster['warehouses'] ?? [] as $warehouse) {
                        if (!empty($warehouse['is_available'])) {
                            $availableWarehouseId = $warehouse['warehouse_id'] ?? null;
                            break 2;
                        }
                    }
                }
            }

            \Log::info('[FBO Draft] Результат', [
                'draft_id' => $draftId,
                'available_clusters_count' => count($availableClusters),
                'first_available_warehouse' => $availableWarehouseId,
            ]);

            return response()->json([
                'success' => true,
                'draft_id' => $draftId,
                'warehouse_id' => $availableWarehouseId,
                'available_clusters' => $availableClusters,
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to create draft', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'message' => 'Ошибка создания черновика: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Получить реальные таймслоты от Ozon для черновика
     * 
     * POST /api/shipments/draft-timeslots
     */
    public function getDraftTimeslots(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required',
            'draft_id' => 'required',
            'warehouse_id' => 'required',
            'cluster_id' => 'nullable',
            'warehouse_name' => 'nullable|string',
        ]);

        $integrationId = (string) $request->input('integration_id');
        $integration = \App\Models\Integration::findOrFail($integrationId);
        $draftId = (int) $request->input('draft_id');
        $warehouseId = (int) $request->input('warehouse_id');
        $clusterId = $request->input('cluster_id');
        $warehouseName = $request->input('warehouse_name');

        if ($integration->marketplace !== 'ozon') {
            return response()->json([
                'message' => 'Таймслоты поддерживаются только для Ozon',
            ], 422);
        }

        try {
            $marketplace = \App\Domains\Ozon\OzonMarketplace::fromIntegration($integration);
            // Используем supplies API, который уже парсит слоты и делает fallback на v1 API
            $suppliesApi = $marketplace->supplies();

            // SuppliesApi.getDraftTimeslots возвращает уже нормализованный массив слотов
            $slots = $suppliesApi->getDraftTimeslots(
                $draftId,
                $warehouseId,
                $clusterId ? (int) $clusterId : null,
                $warehouseName
            );

            \Illuminate\Support\Facades\Log::info('Draft timeslots parsed', [
                'draft_id' => $draftId,
                'warehouse_id' => $warehouseId,
                'slots_count' => count($slots),
                'first_slot' => $slots[0] ?? null,
            ]);

            return response()->json([
                'success' => true,
                'data' => $slots,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ошибка получения таймслотов: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Подтвердить черновик и создать заявку в Ozon
     *
     * POST /api/shipments/confirm-draft
     */
    public function confirmDraft(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required',
            'draft_id' => 'required',
            'warehouse_id' => 'required',
            'items' => 'required|array|min:1',
            'items.*.sku' => 'required|string',
            'items.*.quantity' => 'required|integer|min:1',
            'timeslot_from' => 'required',
            'timeslot_to' => 'required',
            'cluster_id' => 'nullable',
        ]);

        $integrationId = (string) $request->input('integration_id');
        $integration = \App\Models\Integration::findOrFail($integrationId);
        $draftId = $request->input('draft_id');
        $warehouseId = $request->input('warehouse_id');
        $items = $request->input('items');
        $timeslotFrom = $request->input('timeslot_from');
        $timeslotTo = $request->input('timeslot_to');
        
        // Ozon требует формат ISO 8601 с Z на конце
        if ($timeslotFrom && !str_ends_with($timeslotFrom, 'Z')) {
            $timeslotFrom = rtrim($timeslotFrom, 'Z') . 'Z';
        }
        if ($timeslotTo && !str_ends_with($timeslotTo, 'Z')) {
            $timeslotTo = rtrim($timeslotTo, 'Z') . 'Z';
        }

        if ($integration->marketplace !== 'ozon') {
            return response()->json([
                'message' => 'Подтверждение черновика поддерживается только для Ozon',
            ], 422);
        }

        try {
            $marketplace = \App\Domains\Ozon\OzonMarketplace::fromIntegration($integration);
            $suppliesApi = $marketplace->fboSupplyOrders();

            // Обрабатываем rate limit с ретраями
            $supplyResult = null;
            $maxAttempts = 3;
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                if ($attempt > 1) {
                    $delay = 3 * $attempt; // 6, 9 секунд
                    \Log::info("[Confirm Draft] Rate limit, ждём {$delay} сек перед попыткой #{$attempt}");
                    sleep($delay);
                }
                
                $supplyResult = $suppliesApi->createSupplyFromDraft(
                    (int) $draftId,
                    (int) $warehouseId,
                    (string) $timeslotFrom,
                    (string) $timeslotTo
                );
                
                $httpStatus = $supplyResult['_http_status'] ?? null;
                $errorCode = $supplyResult['code'] ?? $supplyResult['error']['code'] ?? null;
                $isRateLimit = $httpStatus === 429 || (int) $errorCode === 8;
                
                if (!$isRateLimit) {
                    break;
                }
                
                \Log::warning("[Confirm Draft] Rate limit при создании поставки, попытка #{$attempt}");
            }

            $supplyOperationId = $supplyResult['operation_id'] ?? null;
            if (empty($supplyOperationId)) {
                $ozonError = $supplyResult['error'] ?? null;
                $ozonResponse = $supplyResult['response'] ?? null;
                $ozonMessage = $supplyResult['message'] ?? null;
                $httpStatus = $supplyResult['_http_status'] ?? null;
                $errorCode = $supplyResult['code'] ?? $supplyResult['error']['code'] ?? null;
                
                // Проверяем rate limit
                if ($httpStatus === 429 || (int) $errorCode === 8) {
                    return response()->json([
                        'message' => 'Превышен лимит запросов к Ozon. Подождите 2-3 минуты и попробуйте снова.',
                    ], 429);
                }
                
                $details = $ozonError ?: $ozonResponse ?: $ozonMessage;
                $errorSuffix = $details ? ' (' . json_encode($details, JSON_UNESCAPED_UNICODE) . ')' : '';
                throw new \RuntimeException('Не удалось создать заявку в Ozon: operation_id не получен' . $errorSuffix);
            }

            $supplyOrderId = null;
            $statusAttempts = 10;
            for ($i = 1; $i <= $statusAttempts; $i++) {
                $statusResponse = $suppliesApi->getSupplyCreateStatus($supplyOperationId);
                $status = $statusResponse['status'] ?? $statusResponse['state'] ?? '';
                $supplyOrderId = $statusResponse['supply_order_id']
                    ?? ($statusResponse['supply_order_ids'][0] ?? null)
                    ?? ($statusResponse['result']['supply_order_id'] ?? null)
                    ?? ($statusResponse['result']['order_ids'][0] ?? null);

                \Illuminate\Support\Facades\Log::info('Ozon supply status check (confirmDraft)', [
                    'attempt' => $i,
                    'operation_id' => $supplyOperationId,
                    'status' => $status,
                    'supply_order_id' => $supplyOrderId,
                    'response' => $statusResponse,
                ]);

                if ($supplyOrderId) {
                    break;
                }

                if (in_array($status, ['ERROR', 'FAILED', 'REJECTED'], true)) {
                    throw new \RuntimeException('Ozon не создал поставку: ' . json_encode($statusResponse));
                }

                usleep(1000000);
            }

            if (empty($supplyOrderId)) {
                throw new \RuntimeException('Ozon не вернул supply_order_id после ожидания');
            }

            $shipmentData = [
                'name' => 'Поставка ' . now()->format('d.m.Y H:i'),
                'integration_id' => $integration->id,
                'marketplace' => $integration->marketplace,
                'warehouse_id' => (string) $warehouseId,
                'shipment_type' => 'fbo',
                'status' => 'submitted',
                'items' => $items,
                'external_supply_id' => (string) $supplyOrderId,
                'meta' => [
                    'external_supply_id' => (string) $supplyOrderId,
                ],
            ];

            $shipment = $this->shipmentService->create($shipmentData);

            return response()->json([
                'data' => $this->formatShipmentResponse($shipment->load(['items'])),
                'message' => 'Поставка создана и синхронизирована с Ozon',
                'ozon_supply_id' => (string) $supplyOrderId,
            ], 201);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to confirm Ozon draft', [
                'error' => $e->getMessage(),
                'draft_id' => $draftId,
                'integration_id' => $integrationId,
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
