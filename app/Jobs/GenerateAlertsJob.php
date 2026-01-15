<?php

namespace App\Jobs;

use App\Models\InventoryAlert;
use App\Models\InventoryWarehouse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateAlertsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    public function handle(): void
    {
        Log::info("Starting alerts generation");

        $criticalWarehouses = InventoryWarehouse::where('stock_status', 'critical')
            ->with('product')
            ->get();

        $lowStockWarehouses = InventoryWarehouse::where('stock_status', 'low')
            ->with('product')
            ->get();

        $alertsCreated = 0;

        foreach ($criticalWarehouses as $warehouse) {
            $existingAlert = InventoryAlert::where('sku', $warehouse->sku)
                ->where('warehouse_id', $warehouse->warehouse_id)
                ->where('type', 'critical')
                ->where('is_resolved', false)
                ->first();

            if (!$existingAlert) {
                InventoryAlert::create([
                    'sku' => $warehouse->sku,
                    'warehouse_id' => $warehouse->warehouse_id,
                    'warehouse_name' => $warehouse->warehouse_name,
                    'type' => 'critical',
                    'message' => "Критически низкий остаток: {$warehouse->quantity} шт. на складе {$warehouse->warehouse_name}",
                    'action' => 'reorder',
                    'priority' => 10,
                ]);
                $alertsCreated++;
            }
        }

        foreach ($lowStockWarehouses as $warehouse) {
            $existingAlert = InventoryAlert::where('sku', $warehouse->sku)
                ->where('warehouse_id', $warehouse->warehouse_id)
                ->where('type', 'warning')
                ->where('is_resolved', false)
                ->first();

            if (!$existingAlert) {
                InventoryAlert::create([
                    'sku' => $warehouse->sku,
                    'warehouse_id' => $warehouse->warehouse_id,
                    'warehouse_name' => $warehouse->warehouse_name,
                    'type' => 'warning',
                    'message' => "Низкий остаток: {$warehouse->quantity} шт. ({$warehouse->days_of_stock} дней) на складе {$warehouse->warehouse_name}",
                    'action' => 'monitor',
                    'priority' => 5,
                ]);
                $alertsCreated++;
            }
        }

        $this->resolveOutdatedAlerts();

        Log::info("Alerts generation completed", [
            'created' => $alertsCreated,
        ]);
    }

    private function resolveOutdatedAlerts(): void
    {
        $activeAlerts = InventoryAlert::active()->get();

        foreach ($activeAlerts as $alert) {
            $warehouse = InventoryWarehouse::where('sku', $alert->sku)
                ->where('warehouse_id', $alert->warehouse_id)
                ->first();

            if (!$warehouse) {
                $alert->resolve();
                continue;
            }

            if ($alert->type === 'critical' && $warehouse->stock_status !== 'critical') {
                $alert->resolve();
            }

            if ($alert->type === 'warning' && !in_array($warehouse->stock_status, ['critical', 'low'])) {
                $alert->resolve();
            }
        }
    }
}
