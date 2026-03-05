<?php

namespace App\Jobs;

use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Models\SyncLog;
use App\Domains\Marketplace\MarketplaceFactory;
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

            // Загружаем Integration объект для передачи в фабрику (нужен OzonMarketplace)
            $integration = $this->syncLog->integration_id
                ? \App\Models\Integration::find($this->syncLog->integration_id)
                : null;

            // Создаём сервис маркетплейса с credentials
            $marketplaceService = MarketplaceFactory::create($this->syncLog->marketplace, $credentials, $integration);

            // Для Ozon: получаем FBO + FBS остатки отдельно и объединяем
            if ($this->syncLog->marketplace === 'ozon') {
                $inventory = [];
                if (method_exists($marketplaceService, 'getInventoryPerWarehouse')) {
                    $fboInventory = $marketplaceService->getInventoryPerWarehouse();
                    $inventory    = array_merge($inventory, $fboInventory);
                    Log::info('Ozon FBO inventory loaded', ['skus' => count($fboInventory)]);
                }
                if (method_exists($marketplaceService, 'getInventoryFbsPerWarehouse')) {
                    $fbsInventory = $marketplaceService->getInventoryFbsPerWarehouse();
                    $inventory    = array_merge($inventory, $fbsInventory);
                    Log::info('Ozon FBS inventory loaded', ['skus' => count($fbsInventory)]);
                }
                if (empty($inventory)) {
                    $inventory = $marketplaceService->getInventory();
                }
            } else {
                $inventory = $marketplaceService->getInventory();
            }

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

            // Для WB: нормализуем warehouse_id и маппируем supplierArticle → реальный products.sku (barcode)
            if ($this->syncLog->marketplace === 'wildberries') {
                // Строим маппинг vendorCode → sku из таблицы products
                $vendorCodeMap = Product::where('marketplace', 'wildberries')
                    ->whereNotNull('wb_data')
                    ->where('integration_id', $this->syncLog->integration_id)
                    ->get(['sku', 'wb_data'])
                    ->mapWithKeys(function ($p) {
                        $vendorCode = data_get($p->wb_data, 'vendorCode');
                        return $vendorCode ? [$vendorCode => $p->sku] : [];
                    })
                    ->toArray();

                Log::info('WB vendorCode map loaded', ['count' => count($vendorCodeMap)]);

                foreach ($flatInventory as &$stockData) {
                    // warehouse_id: заменяем "0" на хэш от названия склада
                    $wid  = (string) ($stockData['warehouse_id'] ?? '');
                    $name = (string) ($stockData['warehouse_name'] ?? '');
                    if (($wid === '0' || $wid === '') && $name !== '') {
                        $stockData['warehouse_id'] = 'wb_' . substr(md5($name), 0, 8);
                    }

                    // sku: заменяем supplierArticle на реальный products.sku через vendorCode
                    $articleKey = $stockData['sku'] ?? '';
                    if ($articleKey && isset($vendorCodeMap[$articleKey])) {
                        $stockData['sku']              = $vendorCodeMap[$articleKey];
                        $stockData['supplier_article'] = $articleKey;
                    }
                }
                unset($stockData);
            }

            // Формируем набор актуальных sku+warehouse_id из API для последующей очистки устаревших записей
            $apiPairs = [];
            foreach ($flatInventory as $stockData) {
                $sku = $stockData['sku'] ?? null;
                $wid = $stockData['warehouse_id'] ?? null;
                if ($sku && $wid !== null && $wid !== '') {
                    $apiPairs[] = $sku . '||' . $wid;
                }
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

            // Удаляем устаревшие записи — те что были в БД, но не пришли из API
            // Это исправляет накопление старых складов и завышенный суммарный остаток
            if (!empty($apiPairs) && $this->syncLog->integration_id) {
                $deleted = 0;
                InventoryWarehouse::where('marketplace', $this->syncLog->marketplace)
                    ->where('integration_id', $this->syncLog->integration_id)
                    ->chunkById(500, function ($rows) use ($apiPairs, &$deleted) {
                        foreach ($rows as $row) {
                            $key = $row->sku . '||' . $row->warehouse_id;
                            if (!in_array($key, $apiPairs, true)) {
                                $row->delete();
                                $deleted++;
                            }
                        }
                    });

                if ($deleted > 0) {
                    Log::info('Deleted stale inventory records', [
                        'marketplace'    => $this->syncLog->marketplace,
                        'integration_id' => $this->syncLog->integration_id,
                        'deleted'        => $deleted,
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

            // Для WB: после синхронизации остатков запускаем джоб сбора начислений за хранение
            if ($this->syncLog->marketplace === 'wildberries') {
                $credentials = $this->syncLog->credentials ?? [];
                if (!empty($credentials)) {
                    SyncStorageFeesJob::dispatch($this->syncLog->integration_id, $credentials, 4)
                        ->delay(now()->addSeconds(10));
                    Log::info('SyncStorageFeesJob dispatched after inventory sync', [
                        'integration_id' => $this->syncLog->integration_id,
                    ]);
                }
            }
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
        $sales7        = null;
        $sales14       = null;
        $sales30       = null;

        if (!empty($wbSalesByWarehouse)) {
            $sku             = $stockData['sku'];
            $supplierArticle = $stockData['supplier_article'] ?? $sku;

            // Все склады этого SKU (приводим к строке для корректного поиска)
            $allWarehouses = $wbSalesByWarehouse[(string)$sku]
                          ?? $wbSalesByWarehouse[(string)$supplierArticle]
                          ?? null;

            if ($allWarehouses) {
                // avg_daily_sales и sales_* — суммарно по всем складам SKU.
                // Записываем одинаково на каждый склад, в matrix() используется MAX() для агрегации.
                $totalAvg   = 0;
                $totalS7    = 0;
                $totalS14   = 0;
                $totalS30   = 0;
                foreach ($allWarehouses as $wData) {
                    if (is_array($wData)) {
                        $totalAvg += (float)($wData['avg_daily_sales'] ?? 0);
                        $totalS7  += (int)($wData['sales_7_days']  ?? 0);
                        $totalS14 += (int)($wData['sales_14_days'] ?? 0);
                        $totalS30 += (int)($wData['sales_30_days'] ?? 0);
                    }
                }
                if ($totalAvg > 0) {
                    $avgDailySales = round($totalAvg, 4);
                }
                if ($totalS7 > 0 || $totalS14 > 0 || $totalS30 > 0) {
                    $sales7  = $totalS7;
                    $sales14 = $totalS14;
                    $sales30 = $totalS30;
                } elseif ($totalAvg > 0) {
                    $sales7  = (int) round($totalAvg * 7);
                    $sales14 = (int) round($totalAvg * 14);
                    $sales30 = (int) round($totalAvg * 30);
                }

            }
        }

        $qty         = (int) ($stockData['quantity'] ?? 0);
        $daysOfStock = ($avgDailySales !== null && $avgDailySales > 0)
            ? (int) round($qty / $avgDailySales)
            : null;

        $newData = [
            'warehouse_name'   => $stockData['warehouse_name'],
            'marketplace'      => $stockData['marketplace'],
            'quantity'         => $qty,
            'fulfillment_type' => $stockData['fulfillment_type'] ?? null,
            'last_updated'     => now(),
            'integration_id'   => $integrationId,
        ];

        if ($avgDailySales !== null) {
            $newData['average_daily_sales'] = $avgDailySales;
            $newData['days_of_stock']       = $daysOfStock;
            $newData['turnover_days']        = $daysOfStock;
        }

        if ($sales7 !== null)  $newData['sales_7_days']  = $sales7;
        if ($sales14 !== null) $newData['sales_14_days'] = $sales14;
        if ($sales30 !== null) $newData['sales_30_days'] = $sales30;


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

        // Проверяем есть ли изменения
        $hasChanges = $existing->quantity !== (int)$stockData['quantity']
            || $existing->warehouse_name !== $stockData['warehouse_name']
            || (string)$existing->integration_id !== (string)$integrationId
            || ($sales7  !== null && (int)$existing->sales_7_days  !== $sales7)
            || ($sales14 !== null && (int)$existing->sales_14_days !== $sales14)
            || ($sales30 !== null && (int)$existing->sales_30_days !== $sales30)
            || ($avgDailySales !== null && (float)$existing->average_daily_sales !== (float)$avgDailySales);

        if ($hasChanges) {
            $existing->update($newData);
            $existing->stock_status = $existing->calculateStockStatus();
            $existing->save();
            return 'updated';
        }

        // Данные не изменились — обновляем только last_updated чтобы фиксировать факт синхронизации
        $existing->update(['last_updated' => now()]);

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
