<?php

namespace App\Jobs;

use App\Models\InventoryHistory;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CalculateForecastsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800;

    public function handle(): void
    {
        Log::info("Starting forecasts calculation");

        $products = Product::whereHas('inventoryWarehouses')->get();
        $processed = 0;

        foreach ($products as $product) {
            try {
                $this->calculateProductForecast($product);
                $processed++;
            } catch (\Exception $e) {
                Log::error("Failed to calculate forecast for product", [
                    'sku' => $product->sku,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info("Forecasts calculation completed", [
            'processed' => $processed,
        ]);
    }

    private function calculateProductForecast(Product $product): void
    {
        $warehouses = $product->inventoryWarehouses;

        foreach ($warehouses as $warehouse) {
            $history = InventoryHistory::where('sku', $product->sku)
                ->where('warehouse_id', $warehouse->warehouse_id)
                ->orderBy('date', 'desc')
                ->limit(30)
                ->get();

            if ($history->isEmpty()) {
                continue;
            }

            $avgDailySales = $history->avg('sales') ?? 0;
            $daysOfStock = $avgDailySales > 0 
                ? (int) ($warehouse->quantity / $avgDailySales) 
                : null;

            $safetyStock = $avgDailySales * 7;
            $recommendedQuantity = max(0, ($avgDailySales * 30) - $warehouse->quantity + $safetyStock);

            $warehouse->update([
                'average_daily_sales' => round($avgDailySales, 2),
                'days_of_stock' => $daysOfStock,
                'recommended_quantity' => (int) $recommendedQuantity,
                'stock_status' => $warehouse->calculateStockStatus(),
            ]);
        }
    }
}
