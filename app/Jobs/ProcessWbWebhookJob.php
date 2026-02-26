<?php

namespace App\Jobs;

use App\Models\InventoryWarehouse;
use App\Models\WbWebhookConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessWbWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(
        private int   $integrationId,
        private array $payload
    ) {}

    public function handle(): void
    {
        $eventType = $this->payload['event'] ?? $this->payload['type'] ?? null;

        Log::info('ProcessWbWebhookJob: обработка события WB', [
            'integration_id' => $this->integrationId,
            'event'          => $eventType,
        ]);

        // Обновляем счётчик событий и время последнего события
        WbWebhookConfig::where('integration_id', $this->integrationId)->update([
            'last_event_at' => now(),
            'events_count'  => \Illuminate\Support\Facades\DB::raw('events_count + 1'),
        ]);

        // OrderCreated / OrderUpdated — обновляем остатки
        if (in_array($eventType, ['OrderCreated', 'OrderUpdated', 'order_created', 'order_updated'])) {
            $this->handleOrderEvent();
        }

        // StocksUpdated — прямое обновление остатков
        if (in_array($eventType, ['StocksUpdated', 'stocks_updated'])) {
            $this->handleStocksEvent();
        }
    }

    private function handleOrderEvent(): void
    {
        $nmId     = $this->payload['nmId']    ?? $this->payload['nm_id']    ?? null;
        $barcode  = $this->payload['barcode'] ?? null;
        $warehouseId = $this->payload['warehouseId'] ?? $this->payload['warehouse_id'] ?? null;

        if (!$nmId && !$barcode) return;

        $sku = $nmId ? (string) $nmId : null;

        $query = InventoryWarehouse::where('integration_id', $this->integrationId)
            ->where('marketplace', 'wildberries');

        if ($sku) {
            $query->where('sku', $sku);
        }
        if ($warehouseId) {
            $query->where('warehouse_id', (string) $warehouseId);
        }

        $warehouse = $query->first();
        if (!$warehouse) return;

        // Уменьшаем остаток на 1 при создании заказа
        $eventType = $this->payload['event'] ?? $this->payload['type'] ?? '';
        if (in_array($eventType, ['OrderCreated', 'order_created'])) {
            $newQty = max(0, ($warehouse->quantity ?? 0) - 1);
            $warehouse->update(['quantity' => $newQty]);

            Log::info('ProcessWbWebhookJob: остаток уменьшен', [
                'sku'       => $sku,
                'old_qty'   => $warehouse->quantity + 1,
                'new_qty'   => $newQty,
            ]);
        }
    }

    private function handleStocksEvent(): void
    {
        $stocks = $this->payload['stocks'] ?? [];
        if (empty($stocks)) return;

        foreach ($stocks as $stockItem) {
            $nmId    = $stockItem['nmId']    ?? $stockItem['nm_id']    ?? null;
            $qty     = $stockItem['amount']  ?? $stockItem['quantity'] ?? null;
            $warehouseId = $stockItem['warehouseId'] ?? $stockItem['warehouse_id'] ?? null;

            if (!$nmId || $qty === null) continue;

            $query = InventoryWarehouse::where('integration_id', $this->integrationId)
                ->where('marketplace', 'wildberries')
                ->where('sku', (string) $nmId);

            if ($warehouseId) {
                $query->where('warehouse_id', (string) $warehouseId);
            }

            $query->update(['quantity' => max(0, (int) $qty)]);
        }

        Log::info('ProcessWbWebhookJob: остатки обновлены из StocksUpdated', [
            'integration_id' => $this->integrationId,
            'items_count'    => count($stocks),
        ]);
    }
}
