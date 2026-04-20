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
        Log::info('Starting alerts generation');

        $alertsCreated = 0;
        $alertsCreated += $this->generateAlertsForStatus('critical', 10, 'reorder');
        $alertsCreated += $this->generateAlertsForStatus('low', 5, 'monitor');

        $this->resolveOutdatedAlerts();

        Log::info('Alerts generation completed', [
            'created' => $alertsCreated,
        ]);
    }

    /**
     * Генерирует алерты для warehouses с указанным stock_status.
     *
     * Оптимизация (H7): раньше для каждого warehouse делался отдельный
     * SELECT к inventory_alerts → N+1. Теперь подгружаем активные алерты одним
     * запросом в keyed-set и чекаем принадлежность в памяти.
     */
    private function generateAlertsForStatus(string $status, int $priority, string $action): int
    {
        $warehouses = InventoryWarehouse::where('stock_status', $status)->get();
        if ($warehouses->isEmpty()) {
            return 0;
        }

        $alertType = $status === 'critical' ? 'critical' : 'warning';

        // Одной выборкой тянем все уже существующие активные алерты по данным SKU+warehouse_id.
        $skus = $warehouses->pluck('sku')->filter()->unique()->all();
        $warehouseIds = $warehouses->pluck('warehouse_id')->filter()->unique()->all();
        $existingKeys = InventoryAlert::query()
            ->whereIn('sku', $skus)
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('type', $alertType)
            ->where('is_resolved', false)
            ->get(['sku', 'warehouse_id'])
            ->map(fn ($row) => $row->sku.'||'.$row->warehouse_id)
            ->flip(); // flip → O(1) проверка через isset

        $created = 0;
        foreach ($warehouses as $warehouse) {
            $key = $warehouse->sku.'||'.$warehouse->warehouse_id;
            if (isset($existingKeys[$key])) {
                continue;
            }

            $message = $status === 'critical'
                ? "Критически низкий остаток: {$warehouse->quantity} шт. на складе {$warehouse->warehouse_name}"
                : "Низкий остаток: {$warehouse->quantity} шт. ({$warehouse->days_of_stock} дней) на складе {$warehouse->warehouse_name}";

            InventoryAlert::create([
                'sku' => $warehouse->sku,
                'warehouse_id' => $warehouse->warehouse_id,
                'warehouse_name' => $warehouse->warehouse_name,
                'type' => $alertType,
                'message' => $message,
                'action' => $action,
                'priority' => $priority,
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * Разрешает устаревшие алерты (когда статус склада уже восстановился).
     *
     * Оптимизация (H7): раньше для каждого алерта делался SELECT по
     * inventory_warehouses → N+1. Теперь загружаем warehouses одной выборкой
     * по набору (sku, warehouse_id) из активных алертов.
     */
    private function resolveOutdatedAlerts(): void
    {
        $activeAlerts = InventoryAlert::active()->get();
        if ($activeAlerts->isEmpty()) {
            return;
        }

        $skus = $activeAlerts->pluck('sku')->filter()->unique()->all();
        $warehouseIds = $activeAlerts->pluck('warehouse_id')->filter()->unique()->all();

        // Ключ "{sku}||{warehouse_id}" → stock_status.
        $warehouseStatus = InventoryWarehouse::query()
            ->whereIn('sku', $skus)
            ->whereIn('warehouse_id', $warehouseIds)
            ->get(['sku', 'warehouse_id', 'stock_status'])
            ->mapWithKeys(fn ($row) => [$row->sku.'||'.$row->warehouse_id => $row->stock_status]);

        foreach ($activeAlerts as $alert) {
            $key = $alert->sku.'||'.$alert->warehouse_id;
            $status = $warehouseStatus->get($key);

            if ($status === null) {
                $alert->resolve();
                continue;
            }

            if ($alert->type === 'critical' && $status !== 'critical') {
                $alert->resolve();
                continue;
            }

            if ($alert->type === 'warning' && ! in_array($status, ['critical', 'low'], true)) {
                $alert->resolve();
            }
        }
    }
}
