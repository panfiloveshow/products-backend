<?php

namespace App\Jobs;

use App\Models\InventoryWarehouse;
use App\Models\SyncLog;
use App\Services\Marketplace\MarketplaceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncInventoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;

    public function __construct(
        public SyncLog $syncLog
    ) {}

    public function handle(): void
    {
        $this->syncLog->start();

        try {
            // Получаем credentials из SyncLog (зашифрованы в БД)
            $credentials = $this->syncLog->credentials ?? [];
            
            // Создаём сервис маркетплейса с credentials
            $marketplaceService = MarketplaceFactory::create($this->syncLog->marketplace, $credentials);
            $inventory = $marketplaceService->getInventory();

            // WB: дополнительно синхронизируем FBS-остатки (склады продавца)
            if ($this->syncLog->marketplace === 'wildberries' && method_exists($marketplaceService, 'getFbsStocks')) {
                $fbsStocks = $marketplaceService->getFbsStocks();
                if (!empty($fbsStocks)) {
                    $inventory = array_merge($inventory, $fbsStocks);
                    Log::info('WB FBS stocks merged', ['fbs_count' => count($fbsStocks)]);
                }
            }

            // WB: загружаем продажи по складам для расчёта average_daily_sales
            $wbSalesByWarehouse = [];
            if ($this->syncLog->marketplace === 'wildberries' && method_exists($marketplaceService, 'getSalesByWarehouse')) {
                $wbSalesByWarehouse = $marketplaceService->getSalesByWarehouse(30);
                Log::info('WB sales by warehouse loaded', ['skus' => count($wbSalesByWarehouse)]);
            }

            if (empty($inventory)) {
                Log::warning("No inventory returned from marketplace API", [
                    'marketplace' => $this->syncLog->marketplace,
                ]);
                $this->syncLog->complete(0, 0);
                return;
            }

            $synced = 0;
            $failed = 0;
            $updated = 0;
            $created = 0;

            // Разворачиваем вложенный формат {sku, warehouses:[{warehouse_id,...}]} в плоский список
            $flatInventory = [];
            foreach ($inventory as $item) {
                $sku = $item['sku'] ?? null;
                if (empty($sku)) continue;

                if (!empty($item['warehouses']) && is_array($item['warehouses'])) {
                    foreach ($item['warehouses'] as $wh) {
                        $flatInventory[] = array_merge($wh, [
                            'sku'         => $sku,
                            'marketplace' => $this->syncLog->marketplace,
                        ]);
                    }
                } elseif (isset($item['warehouse_id']) || isset($item['warehouse_name'])) {
                    // уже плоский формат
                    $flatInventory[] = array_merge($item, [
                        'marketplace' => $this->syncLog->marketplace,
                    ]);
                }
            }

            // Для WB: если warehouse_id = "0" или пустой — используем хэш названия склада как уникальный ID
            if ($this->syncLog->marketplace === 'wildberries') {
                foreach ($flatInventory as &$stockData) {
                    $wid  = (string) ($stockData['warehouse_id'] ?? '');
                    $name = (string) ($stockData['warehouse_name'] ?? '');
                    if (($wid === '0' || $wid === '') && $name !== '') {
                        $stockData['warehouse_id'] = 'wb_' . substr(md5($name), 0, 8);
                    }
                }
                unset($stockData);
            }

            foreach ($flatInventory as $stockData) {
                // Пропускаем записи без SKU
                if (empty($stockData['sku'])) {
                    continue;
                }
                // Пропускаем записи без warehouse_id (но не отфильтровываем "0" — это может быть валидный ID)
                if (!isset($stockData['warehouse_id']) || $stockData['warehouse_id'] === null || $stockData['warehouse_id'] === '') {
                    continue;
                }

                try {
                    $result = $this->syncInventoryItem($stockData, $wbSalesByWarehouse);
                    $synced++;
                    
                    if ($result === 'created') {
                        $created++;
                    } elseif ($result === 'updated') {
                        $updated++;
                    }
                } catch (\Exception $e) {
                    $failed++;
                    Log::error("Failed to sync inventory", [
                        'marketplace' => $this->syncLog->marketplace,
                        'sku' => $stockData['sku'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            // Сохраняем метаданные о синхронизации
            $this->syncLog->update([
                'metadata' => [
                    'total_from_api' => count($inventory),
                    'created' => $created,
                    'updated' => $updated,
                    'unchanged' => $synced - $created - $updated,
                ],
            ]);
            
            $this->syncLog->complete($synced, $failed);

            Log::info("Inventory sync completed", [
                'marketplace' => $this->syncLog->marketplace,
                'synced' => $synced,
                'created' => $created,
                'updated' => $updated,
                'failed' => $failed,
            ]);
        } catch (\Exception $e) {
            $this->syncLog->fail($e->getMessage());

            Log::error("Inventory sync failed", [
                'marketplace' => $this->syncLog->marketplace,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Синхронизация одной записи остатков с оптимизацией
     * 
     * @return string 'created'|'updated'|'unchanged'
     */
    private function syncInventoryItem(array $stockData, array $wbSalesByWarehouse = []): string
    {
        $integrationId = $this->syncLog->integration_id;

        // Ищем существующую запись по sku + warehouse_id + integration_id
        $existing = InventoryWarehouse::where('sku', $stockData['sku'])
            ->where('warehouse_id', $stockData['warehouse_id'])
            ->when($integrationId, fn($q) => $q->where('integration_id', $integrationId))
            ->first();

        // Для WB: используем реальные продажи из Statistics API
        $avgDailySales = $stockData['average_daily_sales'] ?? null;
        if (!empty($wbSalesByWarehouse)) {
            $sku         = $stockData['sku'];
            $warehouseId = (string)($stockData['warehouse_id'] ?? '');
            if (isset($wbSalesByWarehouse[$sku][$warehouseId])) {
                $avgDailySales = $wbSalesByWarehouse[$sku][$warehouseId];
            }
        }

        $newData = [
            'warehouse_name'      => $stockData['warehouse_name'],
            'marketplace'         => $stockData['marketplace'],
            'quantity'            => $stockData['quantity'],
            'fulfillment_type'    => $stockData['fulfillment_type'] ?? null,
            'last_updated'        => now(),
            'integration_id'      => $integrationId,
        ];

        if ($avgDailySales !== null) {
            $newData['average_daily_sales'] = $avgDailySales;
        }

        if (!$existing) {
            // Создаём новую запись
            $warehouse = InventoryWarehouse::create(array_merge([
                'sku'            => $stockData['sku'],
                'warehouse_id'   => $stockData['warehouse_id'],
                'integration_id' => $integrationId,
            ], $newData));
            
            $warehouse->stock_status = $warehouse->calculateStockStatus();
            $warehouse->save();
            
            return 'created';
        }

        // Проверяем есть ли изменения (quantity, warehouse_name, integration_id)
        $hasChanges = $existing->quantity !== (int)$stockData['quantity']
            || $existing->warehouse_name !== $stockData['warehouse_name']
            || (string)$existing->integration_id !== (string)$integrationId;

        if ($hasChanges) {
            $existing->update($newData);
            $existing->stock_status = $existing->calculateStockStatus();
            $existing->save();
            return 'updated';
        }

        return 'unchanged';
    }

    public function failed(\Throwable $exception): void
    {
        $this->syncLog->fail($exception->getMessage());

        Log::error("SyncInventoryJob failed", [
            'marketplace' => $this->syncLog->marketplace,
            'error' => $exception->getMessage(),
        ]);
    }
}
