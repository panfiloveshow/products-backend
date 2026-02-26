<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShipmentRecommendation;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShipmentService
{
    public function create(array $data): Shipment
    {
        return DB::transaction(function () use ($data) {
            $supplier = Supplier::find($data['supplier_id']);

            $shipment = Shipment::create([
                'name' => $data['name'],
                'status' => Shipment::STATUS_DRAFT,
                'marketplace' => $data['marketplace'],
                'shipment_type' => $data['shipment_type'],
                'warehouse_name' => $data['warehouse_name'] ?? null,
                'supplier_id' => $data['supplier_id'],
                'supplier_name' => $supplier?->name,
                'supplier_address' => $supplier?->address,
                'truck_type' => $data['truck_type'] ?? null,
                'truck_capacity' => $data['truck_capacity'] ?? null,
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
            'id' => $slotData['slot_id'],
            'date' => $slotData['date'],
            'time_from' => $slotData['time_from'],
            'time_to' => $slotData['time_to'],
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
        ];
    }
}
