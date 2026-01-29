<?php

namespace App\Services;

use App\Domains\Ozon\OzonMarketplace;
use App\Domains\Wildberries\WildberriesMarketplace;
use App\Events\ShipmentStatusChanged;
use App\Exceptions\BusinessLogicException;
use App\Jobs\ProcessShipmentToMarketplaceJob;
use App\Models\Integration;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShipmentRecommendation;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ShipmentService
{
    public function create(array $data): Shipment
    {
        return DB::transaction(function () use ($data) {
            $supplier = !empty($data['supplier_id']) ? Supplier::find($data['supplier_id']) : null;
            $integration = !empty($data['integration_id']) ? Integration::find($data['integration_id']) : null;

            $shipment = Shipment::create([
                'name' => $data['name'],
                'status' => $data['status'] ?? Shipment::STATUS_DRAFT,
                'marketplace' => $data['marketplace'],
                'shipment_type' => $data['shipment_type'] ?? 'fbo',
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'warehouse_name' => $data['warehouse_name'] ?? null,
                'integration_id' => $data['integration_id'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'supplier_name' => $supplier?->name ?? $integration?->name,
                'supplier_address' => $supplier?->address,
                'description' => $data['description'] ?? null,
                'truck_type' => $data['truck_type'] ?? null,
                'truck_capacity' => $data['truck_capacity'] ?? null,
                'external_supply_id' => $data['external_supply_id'] ?? null,
                'created_by' => auth()->id() ?? Str::uuid(),
                'created_by_name' => auth()->user()?->name ?? 'System',
            ]);

            if (!empty($data['items'])) {
                foreach ($data['items'] as $itemData) {
                    $this->addItem($shipment, $itemData);
                }
            }

            return $shipment;
        });
    }

    public function addItem(Shipment $shipment, array $data): ShipmentItem
    {
        $product = Product::where('sku', $data['sku'])->first();

        $item = ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'sku' => $data['sku'],
            'product_name' => $data['product_name'] ?? $product?->name,
            'image_url' => $product?->images[0] ?? null,
            'current_stock' => $product?->stock ?? 0,
            'days_of_stock' => $this->calculateDaysOfStock($data['sku']),
            'priority' => $data['priority'] ?? $this->calculatePriority($data['sku']),
            'quantity' => $data['quantity'],
            'cost_price' => $data['cost_price'] ?? null,
            'volume_per_unit' => $data['volume_per_unit'] ?? null,
            'weight_per_unit' => $data['weight_per_unit'] ?? null,
            'marketplaces' => $data['marketplaces'] ?? [$shipment->marketplace],
        ]);

        return $item;
    }

    private function calculateDaysOfStock(string $sku): ?int
    {
        $product = Product::where('sku', $sku)->first();
        if (!$product) {
            return null;
        }

        $avgDailySales = $product->inventoryWarehouses()->avg('average_daily_sales');
        if (!$avgDailySales || $avgDailySales <= 0) {
            return null;
        }

        return (int) ($product->stock / $avgDailySales);
    }

    private function calculatePriority(string $sku): string
    {
        $daysOfStock = $this->calculateDaysOfStock($sku);

        if ($daysOfStock === null) {
            return 'medium';
        }

        if ($daysOfStock <= 7) {
            return 'critical';
        }

        if ($daysOfStock <= 14) {
            return 'medium';
        }

        return 'low';
    }

    public function getAvailableSlots(): array
    {
        $slots = [];
        $startDate = now()->addDays(1);

        for ($i = 0; $i < 14; $i++) {
            $date = $startDate->copy()->addDays($i);
            
            if ($date->isWeekend()) {
                continue;
            }

            $slots[] = [
                'id' => Str::uuid()->toString(),
                'date' => $date->toDateString(),
                'time_from' => '09:00',
                'time_to' => '12:00',
                'is_available' => true,
                'is_booked' => false,
            ];

            $slots[] = [
                'id' => Str::uuid()->toString(),
                'date' => $date->toDateString(),
                'time_from' => '14:00',
                'time_to' => '17:00',
                'is_available' => true,
                'is_booked' => false,
            ];
        }

        return $slots;
    }

    public function bookSlot(Shipment $shipment, array $slotData): array
    {
        $slot = [
            'id' => $slotData['slot_id'] ?? $slotData['timeslot_id'] ?? null,
            'date' => $slotData['date'] ?? null,
            'time_from' => $slotData['time_from'] ?? null,
            'time_to' => $slotData['time_to'] ?? null,
            'is_available' => false,
            'is_booked' => true,
            'booked_by' => auth()->id(),
        ];

        $shipment->update(['slot' => $slot]);

        return $slot;
    }

    public function createFromRecommendation(ShipmentRecommendation $recommendation): Shipment
    {
        return DB::transaction(function () use ($recommendation) {
            $firstSupplier = Supplier::first();

            $shipment = Shipment::create([
                'name' => "Поставка по рекомендации #{$recommendation->id}",
                'status' => Shipment::STATUS_DRAFT,
                'marketplace' => 'wildberries',
                'shipment_type' => 'fbo',
                'supplier_id' => $firstSupplier?->id ?? Str::uuid(),
                'supplier_name' => $firstSupplier?->name ?? 'Не указан',
                'created_by' => auth()->id() ?? Str::uuid(),
                'created_by_name' => auth()->user()?->name ?? 'System',
            ]);

            if ($recommendation->recommended_items) {
                foreach ($recommendation->recommended_items as $itemData) {
                    $this->addItem($shipment, $itemData);
                }
            }

            $recommendation->markAsUsed();

            return $shipment;
        });
    }

    public function exportToPdf(Shipment $shipment): string
    {
        // TODO: Implement PDF generation
        return "/exports/shipments/{$shipment->id}.pdf";
    }

    public function exportToCsv(Shipment $shipment): string
    {
        // TODO: Implement CSV generation
        return "/exports/shipments/{$shipment->id}.csv";
    }

    public function getStats(): array
    {
        return [
            'total' => Shipment::count(),
            'by_status' => Shipment::select('status')
                ->selectRaw('COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray(),
            'by_marketplace' => Shipment::select('marketplace')
                ->selectRaw('COUNT(*) as count')
                ->selectRaw('SUM(total_cost) as total_cost')
                ->groupBy('marketplace')
                ->get()
                ->keyBy('marketplace')
                ->toArray(),
            'this_month' => Shipment::whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count(),
            'total_cost_this_month' => Shipment::whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->sum('total_cost'),
            'pending_approval' => Shipment::status(Shipment::STATUS_PENDING_LOGISTICS)->count(),
            'avg_delivery_time_days' => $this->calculateAvgDeliveryTime(),
            'rejection_rate' => $this->calculateRejectionRate(),
            'this_week' => Shipment::whereBetween('created_at', [now()->startOfWeek(), now()])->count(),
        ];
    }

    /**
     * Отправить поставку на согласование с валидацией
     * 
     * @throws BusinessLogicException
     */
    public function submit(string $shipmentId): Shipment
    {
        $shipment = Shipment::with('items')->findOrFail($shipmentId);

        // Проверка: можно ли отправить
        if (!$shipment->canBeSubmitted()) {
            throw new BusinessLogicException('Поставка не может быть отправлена в текущем статусе');
        }

        // Проверка: есть ли товары
        if ($shipment->items->isEmpty()) {
            throw new BusinessLogicException('Нельзя отправить пустую поставку');
        }

        // Проверка: забронирован ли слот (опционально)
        if (empty($shipment->slot) && $this->isSlotRequired($shipment)) {
            throw new BusinessLogicException('Необходимо забронировать слот приёмки');
        }

        // Проверка: плановая дата не в прошлом
        if ($shipment->planned_date && $shipment->planned_date->isPast()) {
            throw new BusinessLogicException('Плановая дата не может быть в прошлом');
        }

        // Проверка наличия товаров на складе
        $this->validateStockAvailability($shipment);

        $oldStatus = $shipment->status;

        DB::transaction(function () use ($shipment) {
            $shipment->update([
                'status' => Shipment::STATUS_PENDING_LOGISTICS,
            ]);

            // Пересчитываем итоги
            $shipment->recalculateTotals();
        });

        // Отправляем событие
        event(new ShipmentStatusChanged($shipment, $oldStatus, $shipment->status));

        // Запускаем Job для отправки в маркетплейс
        ProcessShipmentToMarketplaceJob::dispatch($shipment->id);

        Log::info('Shipment submitted', [
            'shipment_id' => $shipment->id,
            'items_count' => $shipment->items->count(),
            'total_quantity' => $shipment->total_quantity,
        ]);

        return $shipment->fresh(['items']);
    }

    /**
     * Проверка наличия товаров на складе
     * 
     * @throws BusinessLogicException
     */
    private function validateStockAvailability(Shipment $shipment): void
    {
        $errors = [];

        foreach ($shipment->items as $item) {
            $available = $this->getAvailableQuantity($item->sku, $shipment->integration_id);
            
            if ($available < $item->quantity) {
                $errors[] = "SKU {$item->sku}: доступно {$available}, запрошено {$item->quantity}";
            }
        }

        if (!empty($errors)) {
            throw new BusinessLogicException(
                'Недостаточно товаров на складе: ' . implode('; ', $errors)
            );
        }
    }

    /**
     * Получить доступное количество товара
     */
    public function getAvailableQuantity(string $sku, ?string $integrationId = null): int
    {
        $query = InventoryWarehouse::where('sku', $sku);
        
        if ($integrationId) {
            $query->where('integration_id', $integrationId);
        }

        return (int) $query->sum('quantity');
    }

    /**
     * Требуется ли бронирование слота
     */
    private function isSlotRequired(Shipment $shipment): bool
    {
        // Для Ozon FBO слот обычно требуется
        if ($shipment->marketplace === 'ozon' && $shipment->shipment_type === 'fbo') {
            return true;
        }

        return false;
    }

    /**
     * Получить реальные слоты из API маркетплейса
     */
    public function getMarketplaceSlots(string $marketplace, ?string $warehouseId = null, ?string $integrationId = null): array
    {
        try {
            $integration = $integrationId 
                ? Integration::find($integrationId)
                : Integration::where('marketplace', $marketplace)->where('is_active', true)->first();

            if (!$integration) {
                Log::warning('No integration found for marketplace slots', [
                    'marketplace' => $marketplace,
                ]);
                return $this->getAvailableSlots(); // Fallback на фейковые слоты
            }

            $suppliesApi = match ($marketplace) {
                'ozon' => OzonMarketplace::fromIntegration($integration)->supplies(),
                'wildberries' => WildberriesMarketplace::fromIntegration($integration)->supplies(),
                default => null,
            };

            if (!$suppliesApi) {
                return $this->getAvailableSlots();
            }

            // Если склад не указан — получаем первый доступный
            if (!$warehouseId) {
                $warehouses = $suppliesApi->getAvailableWarehouses();
                $warehouseId = $warehouses[0]['id'] ?? null;
            }

            if (!$warehouseId) {
                return $this->getAvailableSlots();
            }

            $dateFrom = now()->addDay()->toDateString();
            $dateTo = now()->addDays(14)->toDateString();

            return $suppliesApi->getAcceptanceSlots($warehouseId, $dateFrom, $dateTo);

        } catch (\Exception $e) {
            Log::error('Failed to get marketplace slots', [
                'marketplace' => $marketplace,
                'error' => $e->getMessage(),
            ]);
            return $this->getAvailableSlots(); // Fallback
        }
    }

    /**
     * Создать поставку из рекомендаций по пополнению
     */
    public function createFromInventoryRecommendations(string $integrationId, string $warehouseId): Shipment
    {
        $integration = Integration::findOrFail($integrationId);
        
        // Получаем рекомендации по пополнению
        $recommendations = InventoryWarehouse::where('integration_id', $integrationId)
            ->where('days_of_stock', '<', 14) // Меньше 2 недель запаса
            ->where('average_daily_sales', '>', 0)
            ->orderBy('days_of_stock')
            ->limit(50)
            ->get();

        if ($recommendations->isEmpty()) {
            throw new BusinessLogicException('Нет товаров, требующих пополнения');
        }

        return DB::transaction(function () use ($integration, $warehouseId, $recommendations) {
            $supplier = Supplier::first();

            $shipment = Shipment::create([
                'name' => 'Пополнение по рекомендациям ' . now()->format('d.m.Y'),
                'status' => Shipment::STATUS_DRAFT,
                'marketplace' => $integration->marketplace,
                'shipment_type' => 'fbo',
                'warehouse_id' => $warehouseId,
                'integration_id' => $integration->id,
                'supplier_id' => $supplier?->id,
                'supplier_name' => $supplier?->name ?? 'Не указан',
                'created_by' => auth()->id() ?? Str::uuid(),
                'created_by_name' => auth()->user()?->name ?? 'System',
            ]);

            foreach ($recommendations as $rec) {
                // Рассчитываем количество для пополнения (на 30 дней)
                $targetDays = 30;
                $neededQuantity = max(0, ceil($rec->average_daily_sales * $targetDays) - $rec->quantity);

                if ($neededQuantity <= 0) {
                    continue;
                }

                $product = Product::where('sku', $rec->sku)->first();

                $this->addItem($shipment, [
                    'sku' => $rec->sku,
                    'product_name' => $product?->name ?? $rec->sku,
                    'quantity' => $neededQuantity,
                    'priority' => $rec->days_of_stock <= 7 ? 'critical' : 'medium',
                ]);
            }

            $shipment->recalculateTotals();

            return $shipment;
        });
    }

    /**
     * Рассчитать среднее время доставки
     */
    private function calculateAvgDeliveryTime(): ?float
    {
        $result = Shipment::where('status', Shipment::STATUS_DELIVERED)
            ->whereNotNull('delivered_at')
            ->whereNotNull('sent_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(DAY, sent_at, delivered_at)) as avg_days')
            ->value('avg_days');

        return $result ? round($result, 1) : null;
    }

    /**
     * Рассчитать процент отклонений
     */
    private function calculateRejectionRate(): float
    {
        $total = Shipment::whereNotIn('status', [Shipment::STATUS_DRAFT])->count();
        
        if ($total === 0) {
            return 0;
        }

        $rejected = Shipment::where('status', Shipment::STATUS_REJECTED)->count();

        return round(($rejected / $total) * 100, 2);
    }
}
