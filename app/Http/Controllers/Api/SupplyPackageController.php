<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supply;
use App\Models\SupplyPackage;
use App\Models\SupplyPackageItem;
use App\Models\SupplyDocument;
use App\Models\SupplyEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SupplyPackageController extends Controller
{
    /**
     * Получить список грузомест поставки
     * GET /api/supplies/{supplyId}/packages
     */
    public function index(string|int $supplyId): JsonResponse
    {
        $supply = Supply::with(['packages.items'])->findOrFail($supplyId);

        return response()->json([
            'success' => true,
            'data' => $supply->packages->map(fn($pkg) => $this->formatPackage($pkg)),
            'summary' => [
                'total_packages' => $supply->packages->count(),
                'total_items' => $supply->packages->sum('items_count'),
                'total_quantity' => $supply->packages->sum('total_quantity'),
                'by_status' => $supply->packages->groupBy('status')->map->count(),
            ],
        ]);
    }

    /**
     * Создать новое грузоместо
     * POST /api/supplies/{supplyId}/packages
     */
    public function store(Request $request, string|int $supplyId): JsonResponse
    {
        $supply = Supply::findOrFail($supplyId);

        $request->validate([
            'package_type' => 'nullable|in:box,pallet,mono_pallet',
            'weight' => 'nullable|numeric|min:0',
            'length' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
        ]);

        // Определяем следующий порядковый номер
        $nextSequence = $supply->packages()->max('sequence_number') + 1;

        $package = $supply->packages()->create([
            'package_type' => $request->input('package_type', SupplyPackage::TYPE_BOX),
            'sequence_number' => $nextSequence,
            'weight' => $request->input('weight'),
            'length' => $request->input('length'),
            'width' => $request->input('width'),
            'height' => $request->input('height'),
            'status' => SupplyPackage::STATUS_DRAFT,
        ]);

        // Логируем событие
        $this->logEvent($supply, SupplyEvent::TYPE_CREATED, 'Создано грузоместо', [
            'package_id' => $package->id,
            'barcode' => $package->barcode,
            'type' => $package->package_type,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->formatPackage($package),
            'message' => 'Грузоместо создано',
        ], 201);
    }

    /**
     * Получить грузоместо
     * GET /api/supplies/{supplyId}/packages/{packageId}
     */
    public function show(string|int $supplyId, string|int $packageId): JsonResponse
    {
        $package = SupplyPackage::with(['items', 'supply'])
            ->where('supply_id', $supplyId)
            ->findOrFail($packageId);

        return response()->json([
            'success' => true,
            'data' => $this->formatPackage($package, true),
        ]);
    }

    /**
     * Обновить грузоместо
     * PUT /api/supplies/{supplyId}/packages/{packageId}
     */
    public function update(Request $request, string|int $supplyId, string|int $packageId): JsonResponse
    {
        $package = SupplyPackage::where('supply_id', $supplyId)->findOrFail($packageId);

        if (!in_array($package->status, [SupplyPackage::STATUS_DRAFT, SupplyPackage::STATUS_PACKING])) {
            return response()->json([
                'success' => false,
                'message' => 'Нельзя редактировать грузоместо в статусе ' . $package->status,
            ], 422);
        }

        $request->validate([
            'package_type' => 'nullable|in:box,pallet,mono_pallet',
            'weight' => 'nullable|numeric|min:0',
            'length' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
        ]);

        $package->update($request->only(['package_type', 'weight', 'length', 'width', 'height']));

        return response()->json([
            'success' => true,
            'data' => $this->formatPackage($package->fresh(['items'])),
            'message' => 'Грузоместо обновлено',
        ]);
    }

    /**
     * Удалить грузоместо
     * DELETE /api/supplies/{supplyId}/packages/{packageId}
     */
    public function destroy(string|int $supplyId, string|int $packageId): JsonResponse
    {
        $package = SupplyPackage::where('supply_id', $supplyId)->findOrFail($packageId);

        if (!in_array($package->status, [SupplyPackage::STATUS_DRAFT, SupplyPackage::STATUS_PACKING])) {
            return response()->json([
                'success' => false,
                'message' => 'Нельзя удалить грузоместо в статусе ' . $package->status,
            ], 422);
        }

        $barcode = $package->barcode;
        $package->delete();

        $this->logEvent(
            Supply::find($supplyId),
            SupplyEvent::TYPE_UPDATED,
            'Удалено грузоместо',
            ['barcode' => $barcode]
        );

        return response()->json([
            'success' => true,
            'message' => 'Грузоместо удалено',
        ]);
    }

    /**
     * Добавить товар в грузоместо
     * POST /api/supplies/{supplyId}/packages/{packageId}/items
     */
    public function addItem(Request $request, string|int $supplyId, string|int $packageId): JsonResponse
    {
        $package = SupplyPackage::where('supply_id', $supplyId)->findOrFail($packageId);

        if (!$package->canAddItem()) {
            return response()->json([
                'success' => false,
                'message' => 'Нельзя добавить товар в грузоместо в статусе ' . $package->status,
            ], 422);
        }

        $request->validate([
            'supply_item_id' => 'nullable|exists:supply_items,id',
            'product_id' => 'nullable|uuid|exists:products,id',
            'sku' => 'required|string',
            'barcode' => 'nullable|string',
            'product_name' => 'nullable|string|max:500',
            'quantity' => 'required|integer|min:1',
            'weight' => 'nullable|numeric|min:0',
            'expiry_date' => 'nullable|date',
        ]);

        // Проверяем, не превышает ли количество доступное в поставке
        $supplyItem = null;
        if ($request->supply_item_id) {
            $supplyItem = \App\Models\SupplyItem::find($request->supply_item_id);
            if ($supplyItem) {
                $alreadyPacked = SupplyPackageItem::where('supply_item_id', $supplyItem->id)->sum('quantity');
                $available = $supplyItem->planned_qty - $alreadyPacked;
                
                if ($request->quantity > $available) {
                    return response()->json([
                        'success' => false,
                        'message' => "Доступно только {$available} ед. для упаковки (уже упаковано: {$alreadyPacked})",
                    ], 422);
                }
            }
        }

        $item = $package->items()->create([
            'supply_item_id' => $request->supply_item_id,
            'product_id' => $request->product_id,
            'sku' => $request->sku,
            'barcode' => $request->barcode,
            'product_name' => $request->product_name ?? $supplyItem?->product_name,
            'quantity' => $request->quantity,
            'weight' => $request->weight ?? $supplyItem?->weight,
            'expiry_date' => $request->expiry_date,
            'scanned_at' => now(),
            'scanned_by' => auth()->id(),
        ]);

        // Обновляем счётчики грузоместа
        $package->recalculateTotals();

        // Обновляем packed_qty в supply_item
        if ($supplyItem) {
            $totalPacked = SupplyPackageItem::where('supply_item_id', $supplyItem->id)->sum('quantity');
            $supplyItem->update(['packed_qty' => $totalPacked]);
        }

        return response()->json([
            'success' => true,
            'data' => $item,
            'package' => $this->formatPackage($package->fresh(['items'])),
            'message' => 'Товар добавлен в грузоместо',
        ], 201);
    }

    /**
     * Удалить товар из грузоместа
     * DELETE /api/supplies/{supplyId}/packages/{packageId}/items/{itemId}
     */
    public function removeItem(string|int $supplyId, string|int $packageId, string|int $itemId): JsonResponse
    {
        $package = SupplyPackage::where('supply_id', $supplyId)->findOrFail($packageId);

        if (!$package->canAddItem()) {
            return response()->json([
                'success' => false,
                'message' => 'Нельзя удалить товар из грузоместа в статусе ' . $package->status,
            ], 422);
        }

        $item = $package->items()->findOrFail($itemId);
        $supplyItemId = $item->supply_item_id;
        $item->delete();

        // Обновляем счётчики
        $package->recalculateTotals();

        // Обновляем packed_qty в supply_item
        if ($supplyItemId) {
            $supplyItem = \App\Models\SupplyItem::find($supplyItemId);
            if ($supplyItem) {
                $totalPacked = SupplyPackageItem::where('supply_item_id', $supplyItemId)->sum('quantity');
                $supplyItem->update(['packed_qty' => $totalPacked]);
            }
        }

        return response()->json([
            'success' => true,
            'package' => $this->formatPackage($package->fresh(['items'])),
            'message' => 'Товар удалён из грузоместа',
        ]);
    }

    /**
     * Отметить грузоместо как упакованное
     * POST /api/supplies/{supplyId}/packages/{packageId}/pack
     */
    public function pack(string|int $supplyId, string|int $packageId): JsonResponse
    {
        $package = SupplyPackage::where('supply_id', $supplyId)->findOrFail($packageId);

        if ($package->items()->count() === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Нельзя упаковать пустое грузоместо',
            ], 422);
        }

        $package->markAsPacked(auth()->id());

        $this->logEvent(
            $package->supply,
            SupplyEvent::TYPE_UPDATED,
            'Грузоместо упаковано',
            ['package_id' => $package->id, 'barcode' => $package->barcode]
        );

        return response()->json([
            'success' => true,
            'data' => $this->formatPackage($package->fresh(['items'])),
            'message' => 'Грузоместо отмечено как упакованное',
        ]);
    }

    /**
     * Авто-распределение товаров по грузоместам
     * POST /api/supplies/{supplyId}/packages/auto-pack
     */
    public function autoPack(Request $request, string|int $supplyId): JsonResponse
    {
        $supply = Supply::with(['items'])->findOrFail($supplyId);

        $request->validate([
            'max_items_per_box' => 'nullable|integer|min:1|max:1000',
            'max_weight_per_box' => 'nullable|numeric|min:0.1|max:100',
        ]);

        $maxItems = $request->input('max_items_per_box', 50);
        $maxWeight = $request->input('max_weight_per_box', 25); // кг

        // Получаем товары, которые ещё не упакованы полностью
        $itemsToPack = [];
        foreach ($supply->items as $item) {
            $alreadyPacked = SupplyPackageItem::where('supply_item_id', $item->id)->sum('quantity');
            $remaining = $item->planned_qty - $alreadyPacked;
            
            if ($remaining > 0) {
                $itemsToPack[] = [
                    'supply_item' => $item,
                    'remaining' => $remaining,
                ];
            }
        }

        if (empty($itemsToPack)) {
            return response()->json([
                'success' => false,
                'message' => 'Все товары уже упакованы',
            ], 422);
        }

        $createdPackages = [];

        DB::transaction(function () use ($supply, $itemsToPack, $maxItems, $maxWeight, &$createdPackages) {
            $currentPackage = null;
            $currentItems = 0;
            $currentWeight = 0;

            foreach ($itemsToPack as $itemData) {
                $item = $itemData['supply_item'];
                $remaining = $itemData['remaining'];
                $itemWeight = $item->weight ?? 0.1;

                while ($remaining > 0) {
                    // Создаём новый короб если нужно
                    if (!$currentPackage || $currentItems >= $maxItems || $currentWeight >= $maxWeight) {
                        $nextSequence = $supply->packages()->max('sequence_number') + 1;
                        $currentPackage = $supply->packages()->create([
                            'package_type' => SupplyPackage::TYPE_BOX,
                            'sequence_number' => $nextSequence,
                            'status' => SupplyPackage::STATUS_PACKING,
                        ]);
                        $createdPackages[] = $currentPackage;
                        $currentItems = 0;
                        $currentWeight = 0;
                    }

                    // Сколько можем добавить в текущий короб
                    $canAddByCount = $maxItems - $currentItems;
                    $canAddByWeight = $itemWeight > 0 ? floor(($maxWeight - $currentWeight) / $itemWeight) : $remaining;
                    $toAdd = min($remaining, $canAddByCount, max(1, $canAddByWeight));

                    if ($toAdd <= 0) {
                        // Нужен новый короб
                        $currentPackage = null;
                        continue;
                    }

                    // Добавляем товар
                    $currentPackage->items()->create([
                        'supply_item_id' => $item->id,
                        'product_id' => $item->product_id,
                        'sku' => $item->sku,
                        'barcode' => $item->barcode,
                        'product_name' => $item->product_name,
                        'quantity' => $toAdd,
                        'weight' => $itemWeight,
                    ]);

                    $remaining -= $toAdd;
                    $currentItems += $toAdd;
                    $currentWeight += $toAdd * $itemWeight;
                }
            }

            // Пересчитываем все созданные грузоместа
            foreach ($createdPackages as $pkg) {
                $pkg->recalculateTotals();
            }

            // Обновляем packed_qty для всех supply_items
            foreach ($supply->items as $item) {
                $totalPacked = SupplyPackageItem::where('supply_item_id', $item->id)->sum('quantity');
                $item->update(['packed_qty' => $totalPacked]);
            }
        });

        $this->logEvent(
            $supply,
            SupplyEvent::TYPE_UPDATED,
            'Авто-распределение товаров по грузоместам',
            ['packages_created' => count($createdPackages)]
        );

        return response()->json([
            'success' => true,
            'message' => 'Создано ' . count($createdPackages) . ' грузомест',
            'packages' => collect($createdPackages)->map(fn($pkg) => $this->formatPackage($pkg->fresh(['items']))),
        ]);
    }

    /**
     * Получить сводку по упаковке
     * GET /api/supplies/{supplyId}/packages/summary
     */
    public function summary(string|int $supplyId): JsonResponse
    {
        $supply = Supply::with(['items', 'packages.items'])->findOrFail($supplyId);

        $itemsSummary = [];
        foreach ($supply->items as $item) {
            $packed = SupplyPackageItem::where('supply_item_id', $item->id)->sum('quantity');
            $itemsSummary[] = [
                'supply_item_id' => $item->id,
                'sku' => $item->sku,
                'product_name' => $item->product_name,
                'planned_qty' => $item->planned_qty,
                'packed_qty' => $packed,
                'remaining_qty' => $item->planned_qty - $packed,
                'is_complete' => $packed >= $item->planned_qty,
            ];
        }

        $totalPlanned = $supply->items->sum('planned_qty');
        $totalPacked = collect($itemsSummary)->sum('packed_qty');

        return response()->json([
            'success' => true,
            'data' => [
                'supply_id' => $supply->id,
                'total_packages' => $supply->packages->count(),
                'packages_by_status' => $supply->packages->groupBy('status')->map->count(),
                'total_planned_qty' => $totalPlanned,
                'total_packed_qty' => $totalPacked,
                'packing_progress' => $totalPlanned > 0 ? round($totalPacked / $totalPlanned * 100, 1) : 0,
                'is_complete' => $totalPacked >= $totalPlanned,
                'items' => $itemsSummary,
            ],
        ]);
    }

    /**
     * Форматирование грузоместа для ответа
     */
    private function formatPackage(SupplyPackage $package, bool $includeItems = true): array
    {
        $data = [
            'id' => $package->id,
            'supply_id' => $package->supply_id,
            'package_type' => $package->package_type,
            'sequence_number' => $package->sequence_number,
            'barcode' => $package->barcode,
            'ozon_package_id' => $package->ozon_package_id,
            'weight' => $package->weight,
            'length' => $package->length,
            'width' => $package->width,
            'height' => $package->height,
            'volume' => $package->volume,
            'items_count' => $package->items_count,
            'total_quantity' => $package->total_quantity,
            'status' => $package->status,
            'packed_at' => $package->packed_at?->toIso8601String(),
            'label_printed_at' => $package->label_printed_at?->toIso8601String(),
            'label_print_count' => $package->label_print_count,
            'created_at' => $package->created_at->toIso8601String(),
        ];

        if ($includeItems && $package->relationLoaded('items')) {
            $data['items'] = $package->items->map(fn($item) => [
                'id' => $item->id,
                'supply_item_id' => $item->supply_item_id,
                'product_id' => $item->product_id,
                'sku' => $item->sku,
                'barcode' => $item->barcode,
                'product_name' => $item->product_name,
                'quantity' => $item->quantity,
                'weight' => $item->weight,
                'expiry_date' => $item->expiry_date?->toDateString(),
                'scanned_at' => $item->scanned_at?->toIso8601String(),
            ]);
        }

        return $data;
    }

    /**
     * Логирование события
     */
    private function logEvent(Supply $supply, string $type, string $title, array $changes = []): void
    {
        SupplyEvent::create([
            'supply_id' => $supply->id,
            'event_type' => $type,
            'title' => $title,
            'changes' => $changes,
            'initiated_by' => SupplyEvent::INITIATED_BY_USER,
            'user_id' => auth()->id(),
            'ip_address' => request()->ip(),
            'created_at' => now(),
        ]);
    }
}
