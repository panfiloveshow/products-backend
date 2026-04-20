<?php

namespace App\Jobs;

use App\Domains\Marketplace\MarketplaceFactory;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Models\SyncLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncInventoryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    public function __construct(
        public SyncLog $syncLog
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function uniqueId(): string
    {
        return implode(':', [
            'inventory',
            $this->syncLog->marketplace,
            (string) ($this->syncLog->integration_id ?? '0'),
        ]);
    }

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
                    $inventory = array_merge($inventory, $fboInventory);
                    Log::info('Ozon FBO inventory loaded', ['skus' => count($fboInventory)]);
                }
                if (method_exists($marketplaceService, 'getInventoryFbsPerWarehouse')) {
                    $fbsInventory = $marketplaceService->getInventoryFbsPerWarehouse();
                    $inventory = array_merge($inventory, $fbsInventory);
                    Log::info('Ozon FBS inventory loaded', ['skus' => count($fbsInventory)]);
                }
                if (empty($inventory)) {
                    $inventory = $marketplaceService->getInventory();
                }
            } else {
                $inventory = $marketplaceService->getInventory();
            }

            // Yandex: загружаем маппинг warehouseId → name для человекочитаемых названий складов
            $yandexWarehouseNames = [];
            if (in_array($this->syncLog->marketplace, ['yandex', 'yandex_market'], true) && method_exists($marketplaceService, 'getWarehouses')) {
                try {
                    $warehouses = $marketplaceService->getWarehouses();
                    foreach ($warehouses as $wh) {
                        $whId = (string) ($wh['id'] ?? '');
                        $whName = $wh['name'] ?? null;
                        if ($whId !== '' && $whName) {
                            $yandexWarehouseNames[$whId] = $whName;
                        }
                    }
                    Log::info('Yandex warehouse names loaded', ['count' => count($yandexWarehouseNames)]);
                } catch (\Exception $e) {
                    Log::warning('Failed to load Yandex warehouse names', ['error' => $e->getMessage()]);
                }
            }

            // WB: дополнительно синхронизируем FBS-остатки (склады продавца)
            if ($this->syncLog->marketplace === 'wildberries' && method_exists($marketplaceService, 'getFbsStocks')) {
                $fbsStocks = $marketplaceService->getFbsStocks();
                if (! empty($fbsStocks)) {
                    $inventory = array_merge($inventory, $fbsStocks);
                    Log::info('WB FBS stocks merged', ['fbs_count' => count($fbsStocks)]);
                }
            }

            // Yandex: получаем продажи для расчета average_daily_sales
            $salesBySkuWarehouse = [];
            if ($this->syncLog->marketplace === 'wildberries' && method_exists($marketplaceService, 'getSalesByWarehouse')) {
                $salesBySkuWarehouse = $marketplaceService->getSalesByWarehouse(30);
                Log::info('WB sales by warehouse loaded', ['skus' => count($salesBySkuWarehouse)]);
            } elseif ($this->syncLog->marketplace === 'ozon' && method_exists($marketplaceService, 'getSalesBySkuAndWarehouse')) {
                $salesBySkuWarehouse = $marketplaceService->getSalesBySkuAndWarehouse(30);
                Log::info('Ozon sales by warehouse loaded', ['skus' => count($salesBySkuWarehouse)]);
            } elseif (in_array($this->syncLog->marketplace, ['yandex', 'yandex_market'], true) && method_exists($marketplaceService, 'getSalesBySku')) {
                $salesBySku = $marketplaceService->getSalesBySku();

                // BUG FIX: getSalesBySku() ключует по shopSku, а getInventory() ключует по offerId ?? shopSku.
                // Строим маппинг shopSku → offerId из products.yandex_data чтобы соединить продажи с остатками.
                $shopSkuToOfferId = Product::whereIn('marketplace', ['yandex', 'yandex_market'])
                    ->where('integration_id', $this->syncLog->integration_id)
                    ->whereNotNull('yandex_data')
                    ->get(['sku', 'yandex_data'])
                    ->flatMap(function ($p) {
                        $offerId  = data_get($p->yandex_data, 'offerId');
                        $shopSku  = data_get($p->yandex_data, 'shopSku');
                        if ($shopSku && $offerId && $shopSku !== $offerId) {
                            return [$shopSku => $offerId];
                        }
                        return [];
                    })
                    ->toArray();

                // Нормализуем формат для Yandex продаж, дублируем запись под offerId-ключ если нужно
                foreach ($salesBySku as $sku => $salesData) {
                    $entry = [[
                        'avg_daily_sales' => ($salesData['sales_30_days'] ?? 0) / 30,
                        'sales_7_days'    => $salesData['sales_7_days']  ?? 0,
                        'sales_14_days'   => $salesData['sales_14_days'] ?? 0,
                        'sales_30_days'   => $salesData['sales_30_days'] ?? 0,
                    ]];
                    $salesBySkuWarehouse[(string) $sku] = $entry;
                    // Добавляем alias по offerId если он отличается от shopSku
                    if (isset($shopSkuToOfferId[(string) $sku])) {
                        $salesBySkuWarehouse[$shopSkuToOfferId[(string) $sku]] = $entry;
                    }
                }
                Log::info('Yandex sales loaded', ['skus' => count($salesBySku), 'aliases' => count($shopSkuToOfferId)]);
            }

            if (empty($inventory)) {
                if ($this->shouldRejectEmptyInventoryResponse()) {
                    throw new \RuntimeException(
                        'Marketplace returned no inventory rows while local warehouse rows exist for this integration; refusing to treat as success.'
                    );
                }

                Log::warning('No inventory returned from marketplace API', [
                    'marketplace' => $this->syncLog->marketplace,
                    'integration_id' => $this->syncLog->integration_id,
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
                if (empty($sku)) {
                    continue;
                }

                if (! empty($item['warehouses']) && is_array($item['warehouses'])) {
                    foreach ($item['warehouses'] as $wh) {
                        // Yandex: подставляем человекочитаемое название склада из маппинга
                        if (! empty($yandexWarehouseNames)) {
                            $whId = (string) ($wh['warehouse_id'] ?? '');
                            if (isset($yandexWarehouseNames[$whId]) && empty($wh['warehouse_name'])) {
                                $wh['warehouse_name'] = $yandexWarehouseNames[$whId];
                            }
                        }
                        $flatInventory[] = array_merge($wh, [
                            'sku' => $sku,
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
                    $wid = (string) ($stockData['warehouse_id'] ?? '');
                    $name = (string) ($stockData['warehouse_name'] ?? '');
                    if (($wid === '0' || $wid === '') && $name !== '') {
                        $stockData['warehouse_id'] = 'wb_'.substr(md5($name), 0, 8);
                    }

                    // sku: заменяем supplierArticle на реальный products.sku через vendorCode
                    $articleKey = $stockData['sku'] ?? '';
                    if ($articleKey && isset($vendorCodeMap[$articleKey])) {
                        $stockData['sku'] = $vendorCodeMap[$articleKey];
                        $stockData['supplier_article'] = $articleKey;
                    }
                }
                unset($stockData);
            }

            // Нормализуем статичные warehouse_id — добавляем integration_id чтобы разные интеграции не конфликтовали
            $integId = $this->syncLog->integration_id;
            if ($integId) {
                foreach ($flatInventory as &$item) {
                    $wid = (string) ($item['warehouse_id'] ?? '');
                    // Статичные warehouse_id без привязки к конкретному складу — добавляем суффикс интеграции
                    if (in_array(strtolower($wid), ['fbs', 'fbo', 'ozon_fbs', 'ozon_fbo'], true)) {
                        $item['warehouse_id'] = $wid.'_integ'.$integId;
                    }
                }
                unset($item);
            }

            // Формируем набор актуальных sku+warehouse_id из API для последующей очистки устаревших записей
            $apiPairs = [];
            foreach ($flatInventory as $stockData) {
                $sku = $stockData['sku'] ?? null;
                $wid = $stockData['warehouse_id'] ?? null;
                if ($sku && $wid !== null && $wid !== '') {
                    $apiPairs[] = $sku.'||'.$wid;
                }
            }

            foreach ($flatInventory as $stockData) {
                // Пропускаем записи без SKU
                if (empty($stockData['sku'])) {
                    continue;
                }
                // Пропускаем записи без warehouse_id (но не отфильтровываем "0" — это может быть валидный ID)
                if (! isset($stockData['warehouse_id']) || $stockData['warehouse_id'] === null || $stockData['warehouse_id'] === '') {
                    continue;
                }

                try {
                    $result = $this->syncInventoryItem($stockData, $salesBySkuWarehouse);
                    $synced++;

                    if ($result === 'created') {
                        $created++;
                    } elseif ($result === 'updated') {
                        $updated++;
                    }
                } catch (\Exception $e) {
                    $failed++;
                    Log::error('Failed to sync inventory', [
                        'marketplace' => $this->syncLog->marketplace,
                        'sku' => $stockData['sku'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            foreach ($flatInventory as $stockData) {
                if (empty($stockData['sku']) || ! isset($stockData['warehouse_id']) || $stockData['warehouse_id'] === null || $stockData['warehouse_id'] === '') {
                    continue;
                }

                if (! array_key_exists('warehouse_coefficient', $stockData) || $stockData['warehouse_coefficient'] === null) {
                    continue;
                }

                InventoryWarehouse::where('sku', $stockData['sku'])
                    ->where('warehouse_id', $stockData['warehouse_id'])
                    ->where('integration_id', $this->syncLog->integration_id)
                    ->update([
                        'warehouse_coefficient' => (float) $stockData['warehouse_coefficient'],
                    ]);
            }

            if ($this->syncLog->marketplace === 'wildberries') {
                $this->refreshWildberriesWarehouseCoefficients($marketplaceService);
            }

            // Удаляем устаревшие записи — те что были в БД, но не пришли из API.
            // Фикс: раньше использовался chunkById + row->delete() внутри итерации.
            // Это пропускает до ~половины записей из-за сдвига курсора по id,
            // если в одном chunk'е удаляется часть данных. Теперь собираем id
            // в буфер, потом одним whereIn-delete.
            if (! empty($apiPairs) && $this->syncLog->integration_id) {
                $apiPairsLookup = array_flip($apiPairs); // O(1) поиск вместо O(N)
                $idsToDelete = [];

                InventoryWarehouse::when(
                        in_array($this->syncLog->marketplace, ['yandex', 'yandex_market'], true),
                        fn ($q) => $q->whereIn('marketplace', ['yandex', 'yandex_market']),
                        fn ($q) => $q->where('marketplace', $this->syncLog->marketplace)
                    )
                    ->where('integration_id', $this->syncLog->integration_id)
                    ->select(['id', 'sku', 'warehouse_id'])
                    ->chunkById(500, function ($rows) use ($apiPairsLookup, &$idsToDelete) {
                        foreach ($rows as $row) {
                            $key = $row->sku.'||'.$row->warehouse_id;
                            if (! isset($apiPairsLookup[$key])) {
                                $idsToDelete[] = $row->id;
                            }
                        }
                    });

                $deleted = 0;
                if (! empty($idsToDelete)) {
                    // Батч-удаление по id — безопасно, не зависит от курсора.
                    foreach (array_chunk($idsToDelete, 1000) as $idBatch) {
                        $deleted += InventoryWarehouse::whereIn('id', $idBatch)->delete();
                    }
                }

                if ($deleted > 0) {
                    Log::info('Deleted stale inventory records', [
                        'marketplace' => $this->syncLog->marketplace,
                        'integration_id' => $this->syncLog->integration_id,
                        'deleted' => $deleted,
                    ]);
                }
            }

            // Yandex: обновляем fulfillment_type для ВСЕХ записей интеграции
            // (при синке обновляются только записи из API — остальные сохраняют старый тип)
            if (in_array($this->syncLog->marketplace, ['yandex', 'yandex_market'], true)
                && method_exists($marketplaceService, 'getScheme')
            ) {
                $detectedScheme = $marketplaceService->getScheme();
                $updatedType = InventoryWarehouse::when(
                        in_array($this->syncLog->marketplace, ['yandex', 'yandex_market'], true),
                        fn ($q) => $q->whereIn('marketplace', ['yandex', 'yandex_market']),
                        fn ($q) => $q->where('marketplace', $this->syncLog->marketplace)
                    )
                    ->where('integration_id', $this->syncLog->integration_id)
                    ->where('fulfillment_type', '!=', $detectedScheme)
                    ->update(['fulfillment_type' => $detectedScheme]);
                if ($updatedType > 0) {
                    Log::info('Yandex fulfillment_type bulk-updated', [
                        'integration_id' => $this->syncLog->integration_id,
                        'scheme' => $detectedScheme,
                        'updated' => $updatedType,
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

            Log::info('Inventory sync completed', [
                'marketplace' => $this->syncLog->marketplace,
                'synced' => $synced,
                'created' => $created,
                'updated' => $updated,
                'failed' => $failed,
            ]);

            // Для WB: после синхронизации остатков запускаем джоб сбора начислений за хранение
            if ($this->syncLog->marketplace === 'wildberries') {
                $credentials = $this->syncLog->credentials ?? [];
                if (! empty($credentials)) {
                    SyncStorageFeesJob::dispatch($this->syncLog->integration_id, $credentials, 4)
                        ->delay(now()->addSeconds(10));
                    Log::info('SyncStorageFeesJob dispatched after inventory sync', [
                        'integration_id' => $this->syncLog->integration_id,
                    ]);
                }
            }
        } catch (\Exception $e) {
            $this->syncLog->fail($e->getMessage());

            Log::error('Inventory sync failed', [
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
    private function syncInventoryItem(array $stockData, array $salesBySkuWarehouse = []): string
    {
        $integrationId = $this->syncLog->integration_id;

        // Ищем существующую запись по sku + warehouse_id + integration_id (все три поля — уникальный ключ)
        $existing = InventoryWarehouse::where('sku', $stockData['sku'])
            ->where('warehouse_id', $stockData['warehouse_id'])
            ->where('integration_id', $integrationId)
            ->first();

        // Продажи из API маркетплейса (WB Statistics / Ozon postings) + опционально из payload остатков
        $avgDailySales = $stockData['average_daily_sales'] ?? null;
        $sales7 = null;
        $sales14 = null;
        $sales30 = null;

        if (! empty($salesBySkuWarehouse)) {
            $sku = $stockData['sku'];
            $supplierArticle = $stockData['supplier_article'] ?? $sku;

            // Все склады этого SKU (приводим к строке для корректного поиска)
            $allWarehouses = $salesBySkuWarehouse[(string) $sku]
                          ?? $salesBySkuWarehouse[(string) $supplierArticle]
                          ?? null;

            if ($allWarehouses) {
                // avg_daily_sales и sales_* — суммарно по всем складам SKU.
                // Записываем одинаково на каждый склад, в matrix() используется MAX() для агрегации.
                $totalAvg = 0;
                $totalS7 = 0;
                $totalS14 = 0;
                $totalS30 = 0;
                foreach ($allWarehouses as $wData) {
                    if (is_array($wData)) {
                        $totalAvg += (float) ($wData['avg_daily_sales'] ?? 0);
                        $totalS7 += (int) ($wData['sales_7_days'] ?? 0);
                        $totalS14 += (int) ($wData['sales_14_days'] ?? 0);
                        $totalS30 += (int) ($wData['sales_30_days'] ?? 0);
                    }
                }
                if ($totalAvg > 0) {
                    $avgDailySales = round($totalAvg, 4);
                }
                if ($totalS7 > 0 || $totalS14 > 0 || $totalS30 > 0) {
                    $sales7 = $totalS7;
                    $sales14 = $totalS14;
                    $sales30 = $totalS30;
                } elseif ($totalAvg > 0) {
                    $sales7 = (int) round($totalAvg * 7);
                    $sales14 = (int) round($totalAvg * 14);
                    $sales30 = (int) round($totalAvg * 30);
                }

            }
        }

        // Если avg не пришёл из API, но есть суммарные продажи — выводим дневной спрос (как в UE / matrix)
        if (($avgDailySales === null || (float) $avgDailySales <= 0) && $sales30 !== null && $sales30 > 0) {
            $avgDailySales = round($sales30 / 30.0, 4);
        } elseif (($avgDailySales === null || (float) $avgDailySales <= 0) && $sales7 !== null && $sales7 > 0) {
            $avgDailySales = round($sales7 / 7.0, 4);
        }

        $qty = (int) ($stockData['quantity'] ?? 0);
        $daysOfStock = ($avgDailySales !== null && (float) $avgDailySales > 0)
            ? (int) round($qty / $avgDailySales)
            : null;

        $resolvedWarehouseName = trim((string) ($stockData['warehouse_name'] ?? ''));
        if ($resolvedWarehouseName === '') {
            $resolvedWarehouseName = (string) ($stockData['warehouse_id'] ?? 'unknown');
        }

        // BUG FIX: нормализуем marketplace — всегда сохраняем как 'yandex_market'
        $normalizedMarketplace = match ($stockData['marketplace']) {
            'yandex' => 'yandex_market',
            default => $stockData['marketplace'],
        };

        $newData = [
            'warehouse_name' => $resolvedWarehouseName,
            'marketplace' => $normalizedMarketplace,
            'quantity' => $qty,
            'fulfillment_type' => $stockData['fulfillment_type'] ?? null,
            'last_updated' => now(),
            'integration_id' => $integrationId,
        ];

        if (array_key_exists('warehouse_coefficient', $stockData) && $stockData['warehouse_coefficient'] !== null) {
            $newData['warehouse_coefficient'] = (float) $stockData['warehouse_coefficient'];
        }

        if ($avgDailySales !== null && (float) $avgDailySales > 0) {
            $newData['average_daily_sales'] = $avgDailySales;
            $newData['days_of_stock'] = $daysOfStock;
            $newData['turnover_days'] = $daysOfStock;
        }

        if ($sales7 !== null) {
            $newData['sales_7_days'] = $sales7;
        }
        if ($sales14 !== null) {
            $newData['sales_14_days'] = $sales14;
        }
        if ($sales30 !== null) {
            $newData['sales_30_days'] = $sales30;
        }

        if (! $existing) {
            // Создаём новую запись
            $warehouse = InventoryWarehouse::create(array_merge([
                'sku' => $stockData['sku'],
                'warehouse_id' => $stockData['warehouse_id'],
                'integration_id' => $integrationId,
            ], $newData));

            $warehouse->stock_status = $warehouse->calculateStockStatus();
            $warehouse->save();
            $this->persistWarehouseCoefficient($stockData, $integrationId);

            return 'created';
        }

        // Проверяем есть ли изменения
        $hasChanges = $existing->quantity !== (int) $stockData['quantity']
            || ($existing->warehouse_name ?? '') !== $resolvedWarehouseName
            || (string) $existing->integration_id !== (string) $integrationId
            || (array_key_exists('warehouse_coefficient', $newData) && (float) ($existing->warehouse_coefficient ?? 1.0) !== (float) $newData['warehouse_coefficient'])
            || ($sales7 !== null && (int) $existing->sales_7_days !== $sales7)
            || ($sales14 !== null && (int) $existing->sales_14_days !== $sales14)
            || ($sales30 !== null && (int) $existing->sales_30_days !== $sales30)
            || ($avgDailySales !== null && (float) $existing->average_daily_sales !== (float) $avgDailySales);

        if ($hasChanges) {
            $existing->update($newData);
            $existing->stock_status = $existing->calculateStockStatus();
            $existing->save();
            $this->persistWarehouseCoefficient($stockData, $integrationId);

            return 'updated';
        }

        // Данные не изменились — обновляем только last_updated чтобы фиксировать факт синхронизации
        $existing->update(['last_updated' => now()]);
        $this->persistWarehouseCoefficient($stockData, $integrationId);

        return 'unchanged';
    }

    private function persistWarehouseCoefficient(array $stockData, ?int $integrationId): void
    {
        if (! array_key_exists('warehouse_coefficient', $stockData) || $stockData['warehouse_coefficient'] === null) {
            return;
        }

        InventoryWarehouse::where('sku', $stockData['sku'])
            ->where('warehouse_id', $stockData['warehouse_id'])
            ->where('integration_id', $integrationId)
            ->update([
                'warehouse_coefficient' => (float) $stockData['warehouse_coefficient'],
            ]);
    }

    private function shouldRejectEmptyInventoryResponse(): bool
    {
        if (! $this->syncLog->integration_id) {
            return false;
        }

        $integrationId = $this->syncLog->integration_id;
        $mp = $this->syncLog->marketplace;

        $query = InventoryWarehouse::query()->where('integration_id', $integrationId);

        if (in_array($mp, ['yandex', 'yandex_market'], true)) {
            $query->whereIn('marketplace', ['yandex', 'yandex_market']);
        } else {
            $query->where('marketplace', $mp);
        }

        return $query->exists();
    }

    private function refreshWildberriesWarehouseCoefficients(object $marketplaceService): void
    {
        if (! method_exists($marketplaceService, 'getInventory')) {
            return;
        }

        $inventory = $marketplaceService->getInventory();
        foreach ($inventory as $item) {
            $sku = $item['sku'] ?? null;
            $warehouseId = $item['warehouse_id'] ?? null;
            $warehouseCoefficient = $item['warehouse_coefficient'] ?? null;

            if (! $sku || ! $warehouseId || $warehouseCoefficient === null) {
                continue;
            }

            InventoryWarehouse::where('sku', $sku)
                ->where('warehouse_id', $warehouseId)
                ->where('integration_id', $this->syncLog->integration_id)
                ->update([
                    'warehouse_coefficient' => (float) $warehouseCoefficient,
                ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->syncLog->fail($exception->getMessage());

        Log::error('SyncInventoryJob failed', [
            'marketplace' => $this->syncLog->marketplace,
            'error' => $exception->getMessage(),
        ]);
    }
}
