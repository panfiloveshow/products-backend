<?php

namespace App\Jobs;

use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Models\ShipmentRecommendation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateShipmentRecommendationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 900;

    public function handle(): void
    {
        Log::info("Starting shipment recommendations generation");

        $criticalProducts = InventoryWarehouse::where('stock_status', 'critical')
            ->get()
            ->groupBy('sku');

        if ($criticalProducts->isEmpty()) {
            Log::info("No critical products found");
            return;
        }

        // Оптимизация H8: раньше на каждый SKU делался Product::where('sku')->first()
        // + отдельный запрос ->unitEconomics()->latest()->value() → 2 запроса на SKU.
        // Теперь грузим Product + последний UnitEconomics одним прогоном.
        $skus = $criticalProducts->keys()->filter()->unique()->values()->all();

        $productsBySku = Product::query()
            ->whereIn('sku', $skus)
            ->get()
            ->keyBy('sku');

        // Последняя запись unit_economics на SKU — через DB max(id).
        $latestCostPrice = \Illuminate\Support\Facades\DB::table('unit_economics')
            ->select('sku', 'cost_price')
            ->whereIn('id', function ($q) use ($skus) {
                $q->selectRaw('MAX(id)')
                    ->from('unit_economics')
                    ->whereIn('sku', $skus)
                    ->groupBy('sku');
            })
            ->pluck('cost_price', 'sku');

        $criticalItems = [];
        $recommendedItems = [];
        $totalCost = 0;
        $totalVolume = 0;

        foreach ($criticalProducts as $sku => $warehouses) {
            $product = $productsBySku->get($sku);
            if (! $product) {
                continue;
            }

            $avgDailySales = $warehouses->avg('average_daily_sales') ?? 1;
            $recommendedQty = (int) ($avgDailySales * 30);
            $costPrice = (float) ($latestCostPrice->get($sku) ?? 500);
            $estimatedCost = $recommendedQty * $costPrice;

            $criticalItems[] = [
                'sku' => $sku,
                'product_name' => $product->name,
                'days_of_stock' => $warehouses->min('days_of_stock') ?? 0,
                'recommended_quantity' => $recommendedQty,
                'estimated_cost' => $estimatedCost,
            ];

            $recommendedItems[] = [
                'sku' => $sku,
                'product_name' => $product->name,
                'quantity' => $recommendedQty,
                'cost_price' => $costPrice,
                'priority' => 'critical',
            ];

            $totalCost += $estimatedCost;
            $totalVolume += $recommendedQty * 0.001;
        }

        if (empty($criticalItems)) {
            return;
        }

        $existingRecommendation = ShipmentRecommendation::active()
            ->where('priority', 'urgent')
            ->first();

        if ($existingRecommendation) {
            $existingRecommendation->update([
                'critical_items' => $criticalItems,
                'recommended_items' => $recommendedItems,
                'total_cost' => $totalCost,
                'total_volume' => $totalVolume,
                'estimated_delivery_cost' => $totalVolume * 500,
            ]);
        } else {
            ShipmentRecommendation::create([
                'priority' => 'urgent',
                'title' => 'Срочная поставка критических товаров',
                'description' => 'Обнаружены товары с критически низкими остатками. Рекомендуется срочно создать поставку.',
                'critical_items' => $criticalItems,
                'recommended_items' => $recommendedItems,
                'total_cost' => $totalCost,
                'total_volume' => $totalVolume,
                'estimated_delivery_cost' => $totalVolume * 500,
                'reason' => 'Автоматическая рекомендация на основе анализа остатков',
                'deadline' => now()->addDays(3),
            ]);
        }

        Log::info("Shipment recommendations generated", [
            'critical_items' => count($criticalItems),
            'total_cost' => $totalCost,
        ]);
    }
}
