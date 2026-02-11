<?php

namespace App\Jobs;

use App\Models\InventoryHistory;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Models\SyncLog;
use App\Models\Integration;
use App\Domains\Marketplace\MarketplaceFactory;
use App\Services\TurnoverCalculationService;
use App\Services\UnitEconomicsCacheService;
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
        // Убираем лимит времени выполнения для синхронного режима
        set_time_limit(0);
        
        $this->syncLog->start();

        try {
            // Получаем credentials из SyncLog (зашифрованы в БД)
            $credentials = $this->syncLog->credentials ?? [];
            
            // Получаем Integration для передачи в маркетплейс (нужно для Ozon схемы работы)
            $integration = Integration::find($this->syncLog->integration_id);
            
            // Создаём сервис маркетплейса с credentials и integration
            $marketplace = MarketplaceFactory::create($this->syncLog->marketplace, $credentials, $integration);
            
            // Для Ozon используем getDetailedInventory() для получения детальных данных по каждому складу
            // getInventory() возвращает только агрегированные данные (Ozon FBO Fbo), что не подходит для отображения по складам
            if ($this->syncLog->marketplace === 'ozon' && method_exists($marketplace, 'getDetailedInventory')) {
                $inventory = $marketplace->getDetailedInventory();
                Log::info('Ozon: using getDetailedInventory for warehouse-level data', ['count' => count($inventory)]);
            } else {
                $inventory = $marketplace->getInventory();
            }

            if (empty($inventory)) {
                Log::warning("No inventory returned from marketplace API", [
                    'marketplace' => $this->syncLog->marketplace,
                ]);
                $this->syncLog->complete(0, 0);
                return;
            }
            
            // Получаем продажи по SKU для расчёта оборачиваемости
            $salesBySku = [];
            if (method_exists($marketplace, 'getSalesBySku')) {
                $salesBySku = $marketplace->getSalesBySku();
            }
            
            // Получаем стоимость хранения по SKU из отчёта о размещении Ozon
            // Загружаем за текущий месяц, прошлый месяц. За всё время = сумма обоих.
            $storageCostBySku = [];
            $storageFeeReportFrom = null;
            $storageFeeReportTo = null;
            
            // Сохраняем real_* данные (из отчёта заказов) перед удалением/обновлением записей
            // Ключ: sku||warehouse_name — чтобы восстановить на правильный склад
            $savedRealData = [];
            $allRealRows = InventoryWarehouse::where('integration_id', $this->syncLog->integration_id)
                ->whereNotNull('sales_report_id')
                ->get(['sku', 'warehouse_name', 'real_avg_daily_sales', 'real_sales_period_days', 'real_turnover_days', 'real_days_of_stock', 'sales_report_id']);
            foreach ($allRealRows as $row) {
                $key = $row->sku . '||' . $row->warehouse_name;
                $savedRealData[$key] = [
                    'real_avg_daily_sales' => $row->real_avg_daily_sales,
                    'real_sales_period_days' => $row->real_sales_period_days,
                    'real_turnover_days' => $row->real_turnover_days,
                    'real_days_of_stock' => $row->real_days_of_stock,
                    'sales_report_id' => $row->sales_report_id,
                ];
                // Также сохраняем по SKU (для случая когда warehouse_name меняется)
                if (!isset($savedRealData[$row->sku])) {
                    $savedRealData[$row->sku] = $savedRealData[$key];
                }
            }
            
            if ($this->syncLog->marketplace === 'ozon' && method_exists($marketplace, 'getPlacementCostByProducts')) {
                try {
                    // Удаляем старые агрегированные записи
                    InventoryWarehouse::where('marketplace', 'ozon')
                        ->where('integration_id', $this->syncLog->integration_id)
                        ->where(function($q) {
                            $q->where('warehouse_name', 'LIKE', 'Ozon FBO%')
                              ->orWhere('warehouse_name', 'LIKE', 'Ozon FBS%')
                              ->orWhere('warehouse_name', 'FBS');
                        })
                        ->delete();
                    
                    $currentFrom = now()->startOfMonth()->format('Y-m-d');
                    $currentTo = now()->format('Y-m-d');
                    $prevFrom = now()->subMonth()->startOfMonth()->format('Y-m-d');
                    $prevTo = now()->subMonth()->endOfMonth()->format('Y-m-d');
                    $storageFeeReportFrom = $currentFrom;
                    $storageFeeReportTo = $currentTo;
                    
                    // Текущий месяц
                    $currentMonthData = $marketplace->getPlacementCostByProducts($currentFrom, $currentTo, 120);
                    Log::info('Ozon placement cost current month', [
                        'count' => count($currentMonthData),
                        'period' => "$currentFrom - $currentTo",
                        'total' => round(array_sum(array_column($currentMonthData, 'placement_cost')), 2),
                    ]);
                    
                    // Пауза между запросами отчётов (Ozon rate limit)
                    sleep(5);
                    
                    // Прошлый месяц
                    $prevMonthData = $marketplace->getPlacementCostByProducts($prevFrom, $prevTo, 120);
                    Log::info('Ozon placement cost prev month', [
                        'count' => count($prevMonthData),
                        'period' => "$prevFrom - $prevTo",
                        'total' => round(array_sum(array_column($prevMonthData, 'placement_cost')), 2),
                    ]);
                    
                    // Сбрасываем старые данные
                    InventoryWarehouse::where('marketplace', 'ozon')
                        ->where('integration_id', $this->syncLog->integration_id)
                        ->update([
                            'storage_fee_total' => null,
                            'storage_fee_report_from' => null,
                            'storage_fee_report_to' => null,
                            'storage_fee_prev_month' => null,
                            'storage_fee_prev_month_period' => null,
                            'storage_fee_all_time' => null,
                        ]);
                    
                    // Собираем все SKU
                    $allSkus = array_unique(array_merge(array_keys($currentMonthData), array_keys($prevMonthData)));
                    
                    foreach ($allSkus as $sku) {
                        $currentCost = $currentMonthData[$sku]['placement_cost'] ?? 0;
                        $prevCost = $prevMonthData[$sku]['placement_cost'] ?? 0;
                        $allTimeCost = $currentCost + $prevCost;
                        
                        $storageCostBySku[$sku] = [
                            'storage_fee_total' => $currentCost,
                            'storage_fee_report_from' => $currentFrom,
                            'storage_fee_report_to' => $currentTo,
                            'storage_fee_prev_month' => $prevCost,
                            'storage_fee_prev_month_period' => "$prevFrom - $prevTo",
                            'storage_fee_all_time' => $allTimeCost,
                        ];
                    }
                    
                    Log::info('Ozon placement cost combined', [
                        'skus' => count($storageCostBySku),
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Failed to load Ozon placement cost', ['error' => $e->getMessage()]);
                }
            } elseif (method_exists($marketplace, 'getStorageCostBySku')) {
                $storageCostBySku = $marketplace->getStorageCostBySku();
            }
            
            // ФАКТИЧЕСКИЕ начисления за хранение из еженедельных отчётов реализации WB
            // ОТКЛЮЧЕНО: Слишком тяжёлый запрос (80+ MB данных), вызывает Out of Memory
            // TODO: Реализовать отдельный джоб с пагинацией или увеличить лимит памяти
            $storageFeesBySku = [];
            
            // Получаем товары в пути к клиенту (для Ozon)
            $inTransitBySku = [];
            if (method_exists($marketplace, 'getInTransitBySku')) {
                $inTransitBySku = $marketplace->getInTransitBySku();
            }
            
            // Получаем возвраты по SKU (для Ozon)
            $returnsBySku = [];
            if (method_exists($marketplace, 'getReturnsBySku')) {
                $returnsBySku = $marketplace->getReturnsBySku();
            }
            
            // Получаем коэффициенты складов (КС) для WB FBO
            // Индексируем по названию склада для сопоставления
            $warehouseCoefficients = [];
            $warehouseCoefByName = [];
            if (method_exists($marketplace, 'getWarehouseCoefficients')) {
                $warehouseCoefficients = $marketplace->getWarehouseCoefficients();
                // Создаём индекс по названию склада (нормализованному)
                foreach ($warehouseCoefficients as $id => $data) {
                    $name = $data['warehouse_name'] ?? '';
                    $normalizedName = mb_strtolower(trim($name));
                    $warehouseCoefByName[$normalizedName] = $data;
                    // Также добавляем по ID
                    $warehouseCoefByName[(string)$id] = $data;
                }
            }
            
            // Получаем коэффициенты FBS складов продавца (для WB)
            $fbsWarehouseCoefs = [];
            if (method_exists($marketplace, 'getFbsWarehouseCoefficients')) {
                $fbsWarehouseCoefs = $marketplace->getFbsWarehouseCoefficients();
                Log::info('FBS warehouse coefficients loaded', [
                    'count' => count($fbsWarehouseCoefs),
                ]);
            }
            
            // Общая сумма хранения Ozon из cash-flow-statement (MarketplaceServiceStorageItem)
            // Без привязки к SKU — Ozon не предоставляет разбивку
            // Загружаем за текущий и прошлый месяц для отображения в сводке
            $ozonStorageTotals = [];
            if ($this->syncLog->marketplace === 'ozon' && method_exists($marketplace, 'getStorageTotalFromCashFlow')) {
                try {
                    $currentMonthFrom = now()->startOfMonth()->format('Y-m-d');
                    $currentMonthTo = now()->format('Y-m-d');
                    $prevMonthFrom = now()->subMonth()->startOfMonth()->format('Y-m-d');
                    $prevMonthTo = now()->subMonth()->endOfMonth()->format('Y-m-d');
                    
                    $currentMonth = $marketplace->getStorageTotalFromCashFlow($currentMonthFrom, $currentMonthTo);
                    $prevMonth = $marketplace->getStorageTotalFromCashFlow($prevMonthFrom, $prevMonthTo);
                    
                    $ozonStorageTotals = [
                        'current_month' => [
                            'total' => $currentMonth['total'] ?? 0,
                            'from' => $currentMonthFrom,
                            'to' => $currentMonthTo,
                        ],
                        'prev_month' => [
                            'total' => $prevMonth['total'] ?? 0,
                            'from' => $prevMonthFrom,
                            'to' => $prevMonthTo,
                        ],
                    ];
                    
                    Log::info('Ozon storage totals from cash-flow', $ozonStorageTotals);
                } catch (\Exception $e) {
                    Log::warning('Failed to load Ozon storage totals', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            // Обновляем комиссии Ozon из v3 API (чтобы комиссии всегда были актуальными)
            if ($this->syncLog->marketplace === 'ozon') {
                $this->updateOzonCommissions($marketplace);
            }

            $synced = 0;
            $failed = 0;
            $updated = 0;
            $created = 0;
            
            // Для Ozon: отслеживаем SKU, которым уже записали storage_fee_total
            // чтобы не дублировать на все записи складов одного SKU
            $skusWithStorageFeeWritten = [];

            // Отключаем foreign key checks для SQLite (товары могут быть из разных интеграций)
            if (DB::getDriverName() === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = OFF');
            }
            
            DB::beginTransaction();

            foreach ($inventory as $stockData) {
                try {
                    // Добавляем данные о продажах если есть
                    $sku = $stockData['sku'] ?? null;
                    if ($sku && isset($salesBySku[$sku])) {
                        $stockData = array_merge($stockData, $salesBySku[$sku]);
                    }
                    
                    // Добавляем стоимость хранения если есть (расчётная)
                    // Для Ozon: записываем storage_fee_total только в первую запись склада для SKU
                    if ($sku && isset($storageCostBySku[$sku])) {
                        if ($this->syncLog->marketplace === 'ozon') {
                            // Для Ozon: записываем storage_fee только если ещё не записали для этого SKU
                            if (!isset($skusWithStorageFeeWritten[$sku])) {
                                $stockData = array_merge($stockData, $storageCostBySku[$sku]);
                                $skusWithStorageFeeWritten[$sku] = true;
                            }
                            // Иначе не добавляем storage_fee_total (останется null)
                        } else {
                            $stockData = array_merge($stockData, $storageCostBySku[$sku]);
                        }
                    }
                    
                    // Добавляем ФАКТИЧЕСКИЕ начисления за хранение из отчётов реализации WB
                    if ($sku && isset($storageFeesBySku[$sku])) {
                        $stockData['storage_fee_total'] = $storageFeesBySku[$sku]['storage_fee_total'] ?? 0;
                        $stockData['storage_fee_last_week'] = $storageFeesBySku[$sku]['storage_fee_last_week'] ?? 0;
                        $stockData['storage_fee_report_from'] = $storageFeesBySku[$sku]['report_date_from'] ?? null;
                        $stockData['storage_fee_report_to'] = $storageFeesBySku[$sku]['report_date_to'] ?? null;
                    }
                    
                    
                    // Добавляем товары в пути к клиенту если есть
                    if ($sku && isset($inTransitBySku[$sku])) {
                        $stockData['in_way_to_client'] = $inTransitBySku[$sku];
                    }
                    
                    // Добавляем возвраты если есть
                    if ($sku && isset($returnsBySku[$sku])) {
                        $stockData['in_way_from_client'] = $returnsBySku[$sku];
                    }
                    
                    // Если есть warehouses массив - создаём запись для каждого склада
                    $warehouses = $stockData['warehouses'] ?? [];
                    if (!empty($warehouses)) {
                        foreach ($warehouses as $warehouse) {
                            // Определяем fulfillment_type: из warehouse > из stockData > из warehouse_type
                            $warehouseType = $warehouse['warehouse_type'] ?? null;
                            $fulfillmentType = $warehouse['fulfillment_type'] 
                                ?? $stockData['fulfillment_type'] 
                                ?? ($warehouseType ? strtoupper($warehouseType) : null);
                            
                            // Получаем коэффициент склада (КС) из API
                            $warehouseId = $warehouse['warehouse_id'] ?? $warehouse['warehouse_name'] ?? 'default';
                            $warehouseName = $warehouse['warehouse_name'] ?? $warehouse['warehouseName'] ?? 'Unknown';
                            
                            // Для FBS складов используем коэффициенты из getFbsWarehouseCoefficients
                            // Для FBO складов — из getWarehouseCoefficients
                            $warehouseCoef = null;
                            if ($fulfillmentType === 'FBS' && isset($fbsWarehouseCoefs[$warehouseId])) {
                                // FBS склад продавца — используем FBS коэффициент
                                $warehouseCoef = $fbsWarehouseCoefs[$warehouseId]['delivery_coef'] ?? null;
                            } else {
                                // FBO склад WB — ищем по названию или ID
                                $normalizedName = mb_strtolower(trim($warehouseName));
                                $warehouseCoef = $warehouseCoefByName[$normalizedName]['delivery_coef'] 
                                    ?? $warehouseCoefByName[(string)$warehouseId]['delivery_coef'] 
                                    ?? null;
                            }
                            
                            $warehouseData = array_merge($stockData, [
                                'warehouse_id' => $warehouseId,
                                'warehouse_name' => $warehouse['warehouse_name'] ?? $warehouse['warehouseName'] ?? 'Unknown',
                                'warehouse_coefficient' => $warehouseCoef,
                                'quantity' => $warehouse['quantity'] ?? 0,
                                'fulfillment_type' => $fulfillmentType,
                                'in_way_to_client' => $warehouse['inWayToClient'] ?? $stockData['in_way_to_client'] ?? 0,
                                'in_way_from_client' => $warehouse['inWayFromClient'] ?? $stockData['in_way_from_client'] ?? 0,
                            ]);
                            unset($warehouseData['warehouses']); // Убираем вложенный массив
                            
                            // storage_fee_total — фактические начисления за хранение на складах маркетплейса
                            // Применяется только к FBO/FBY, т.к. FBS/RFBS/EXPRESS — товар на складе продавца
                            if (!in_array($fulfillmentType, ['FBO', 'FBY'], true)) {
                                $warehouseData['storage_fee_total'] = null;
                                $warehouseData['storage_fee_last_week'] = null;
                            }
                            
                            $result = $this->syncInventoryItem($warehouseData);
                            $synced++;
                            
                            if ($result === 'created') {
                                $created++;
                            } elseif ($result === 'updated') {
                                $updated++;
                            }
                        }
                    } else {
                        // Fallback: если warehouses нет, создаём одну запись (Ozon getDetailedInventory)
                        // Для Ozon: storage_fee_total применяется только к FBO складам
                        $fulfillmentType = $stockData['fulfillment_type'] ?? null;
                        if ($this->syncLog->marketplace === 'ozon' && !in_array($fulfillmentType, ['FBO', 'FBY'], true)) {
                            $stockData['storage_fee_total'] = null;
                            $stockData['storage_fee_last_week'] = null;
                        }
                        
                        $result = $this->syncInventoryItem($stockData);
                        $synced++;
                        
                        if ($result === 'created') {
                            $created++;
                        } elseif ($result === 'updated') {
                            $updated++;
                        }
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

            DB::commit();
            
            // Включаем обратно foreign key checks для SQLite
            if (DB::getDriverName() === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = ON');
            }
            
            // Восстанавливаем real_* данные из отчёта заказов (могли потеряться при delete+create)
            if (!empty($savedRealData)) {
                $restored = 0;
                $newRows = InventoryWarehouse::where('integration_id', $this->syncLog->integration_id)
                    ->whereNull('sales_report_id')
                    ->get();
                
                foreach ($newRows as $row) {
                    // Ищем сохранённые данные: сначала по sku||warehouse, потом по sku
                    $key = $row->sku . '||' . $row->warehouse_name;
                    $realData = $savedRealData[$key] ?? $savedRealData[$row->sku] ?? null;
                    
                    if ($realData) {
                        // Пересчитываем turnover и days_of_stock с новым quantity
                        $avgDaily = $realData['real_avg_daily_sales'] ?? 0;
                        if ($avgDaily > 0) {
                            $realData['real_turnover_days'] = round($row->quantity / $avgDaily, 1);
                            $realData['real_days_of_stock'] = (int) ceil($row->quantity / $avgDaily);
                        } else {
                            $realData['real_turnover_days'] = $row->quantity > 0 ? 999 : 0;
                            $realData['real_days_of_stock'] = $row->quantity > 0 ? 999 : 0;
                        }
                        
                        $row->update($realData);
                        $restored++;
                    }
                }
                
                if ($restored > 0) {
                    Log::info('Restored real_* data from Ozon order report after sync', [
                        'integration_id' => $this->syncLog->integration_id,
                        'saved_skus' => count($savedRealData),
                        'restored_rows' => $restored,
                    ]);
                }
            }
            
            // Сохраняем метаданные о синхронизации
            $metadata = [
                'total_from_api' => count($inventory),
                'created' => $created,
                'updated' => $updated,
                'unchanged' => $synced - $created - $updated,
            ];
            if (!empty($ozonStorageTotals)) {
                $metadata['storage_totals'] = $ozonStorageTotals;
            }
            $this->syncLog->update(['metadata' => $metadata]);
            
            $this->syncLog->complete($synced, $failed);
            
            // Инвалидируем кэш статистики (товары + остатки + хранение Ozon)
            \App\Services\ProductService::invalidateStatsCache($this->syncLog->integration_id, $this->syncLog->marketplace);
            \App\Services\InventoryService::invalidateStatsCache($this->syncLog->integration_id, $this->syncLog->marketplace);
            \Illuminate\Support\Facades\Cache::forget("ozon_storage_totals_{$this->syncLog->integration_id}");
            
            // Синхронизируем Product.stock с суммой остатков на складах
            $this->syncProductStocks();
            
            // Сохраняем историю остатков для графиков динамики
            $this->saveInventoryHistory();
            
            // Пересчитываем оборачиваемость с учётом дней наличия
            $this->recalculateEffectiveTurnover();
            
            // Пересчитываем юнит-экономику для всех товаров интеграции
            $this->recalculateUnitEconomics();
            
            // Запускаем отдельный джоб для синхронизации storage fees WB
            // Выделен в отдельный джоб для изоляции памяти (отчёт реализации WB может быть 80+ MB)
            $this->dispatchStorageFeesSync();

            Log::info("Inventory sync completed", [
                'marketplace' => $this->syncLog->marketplace,
                'synced' => $synced,
                'created' => $created,
                'updated' => $updated,
                'failed' => $failed,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
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
     * @return string 'created'|'updated'|'unchanged'|'skipped'
     */
    private function syncInventoryItem(array $stockData): string
    {
        $sku = $stockData['sku'] ?? null;
        $quantity = $stockData['quantity'] ?? 0;
        
        // Проверяем существование товара для этой интеграции
        $productQuery = Product::where('sku', $sku);
        if ($this->syncLog->integration_id) {
            $productQuery->where('integration_id', $this->syncLog->integration_id);
        }
        
        if (!$sku || !$productQuery->exists()) {
            // Логируем пропуск только для записей с остатками > 0
            if ($quantity > 0) {
                Log::debug("syncInventoryItem: skipped - product not found", [
                    'sku' => $sku,
                    'quantity' => $quantity,
                    'integration_id' => $this->syncLog->integration_id,
                    'marketplace' => $this->syncLog->marketplace,
                ]);
            }
            return 'skipped';
        }
        
        // Получаем warehouse_id с fallback на дефолтное значение
        $warehouseId = $stockData['warehouse_id'] ?? ($stockData['warehouse_name'] ?? null) ?? 'default';
        
        // Ищем существующую запись по sku + warehouse_id + integration_id
        $existing = InventoryWarehouse::where('sku', $sku)
            ->where('warehouse_id', $warehouseId)
            ->where('integration_id', $this->syncLog->integration_id)
            ->first();

        // Рассчитываем оборачиваемость
        $hasSalesData = array_key_exists('sales_30_days', $stockData)
            || array_key_exists('avg_daily_sales', $stockData)
            || array_key_exists('sales_7_days', $stockData)
            || array_key_exists('sales_14_days', $stockData);
        $existingSales30 = $existing?->sales_30_days ?? 0;
        $existingAvgDaily = $existing?->average_daily_sales ?? 0;
        $sales30 = $hasSalesData ? ($stockData['sales_30_days'] ?? 0) : $existingSales30;
        $avgDailySales = $hasSalesData
            ? ($stockData['avg_daily_sales'] ?? ($sales30 > 0 ? $sales30 / 30 : 0))
            : $existingAvgDaily;
        $quantity = $stockData['quantity'] ?? 0;
        
        // Если есть продажи — рассчитываем дни запаса
        // Если продаж нет, но товар есть — показываем 999 (избыток)
        // Если товара нет — показываем 0
        if ($avgDailySales > 0) {
            $turnoverDays = round($quantity / $avgDailySales, 1);
            $daysOfStock = (int)ceil($quantity / $avgDailySales);
        } elseif ($quantity > 0) {
            $turnoverDays = 999;
            $daysOfStock = 999;
        } else {
            $turnoverDays = 0;
            $daysOfStock = 0;
        }

        // Стоимость хранения (расчётная)
        // FBS/RFBS/EXPRESS/DBS — товар на складе продавца, Ozon/WB не берут плату за хранение
        $fulfillmentType = $stockData['fulfillment_type'] ?? null;
        $hasPaidStorage = in_array($fulfillmentType, ['FBO', 'FBY', null], true);
        
        $storageCostPerDay = null;
        $storageCostPerMonth = null;
        
        if ($hasPaidStorage) {
            $storageCostPerDay = $stockData['storage_cost_per_day'] ?? null;
            $storageCostPerMonth = $stockData['storage_cost_per_month'] ?? null;
            
            // Если нет данных из API, рассчитываем по базовым тарифам (только для FBO/FBY)
            if ($storageCostPerDay === null && $quantity > 0) {
                $tariffPerUnit = match ($this->syncLog->marketplace) {
                    'wildberries' => 0.5,  // ~0.5 руб/шт/день
                    'ozon' => 0.4,         // ~0.4 руб/шт/день
                    'yandex' => 0.3,       // ~0.3 руб/шт/день
                    default => 0.5,
                };
                $storageCostPerDay = round($quantity * $tariffPerUnit, 2);
                $storageCostPerMonth = round($storageCostPerDay * 30, 2);
            }
        }

        $storageFeeTotal = array_key_exists('storage_fee_total', $stockData)
            ? $stockData['storage_fee_total']
            : ($existing?->storage_fee_total ?? null);
        $storageFeeLastWeek = array_key_exists('storage_fee_last_week', $stockData)
            ? $stockData['storage_fee_last_week']
            : ($existing?->storage_fee_last_week ?? null);
        $storageFeeReportFrom = array_key_exists('storage_fee_report_from', $stockData)
            ? $stockData['storage_fee_report_from']
            : ($existing?->storage_fee_report_from ?? null);
        $storageFeeReportTo = array_key_exists('storage_fee_report_to', $stockData)
            ? $stockData['storage_fee_report_to']
            : ($existing?->storage_fee_report_to ?? null);

        $newData = [
            'warehouse_name' => $stockData['warehouse_name'] ?? $warehouseId,
            'warehouse_coefficient' => $stockData['warehouse_coefficient'] ?? null,
            'marketplace' => $stockData['marketplace'] ?? $this->syncLog->marketplace,
            'fulfillment_type' => $stockData['fulfillment_type'] ?? null,
            'quantity' => $quantity,
            'reserved' => $stockData['reserved'] ?? 0,
            'in_transit' => $stockData['in_transit'] ?? 0,
            'in_way_to_client' => $stockData['in_way_to_client'] ?? 0,
            'in_way_from_client' => $stockData['in_way_from_client'] ?? 0,
            'sales_7_days' => $hasSalesData ? ($stockData['sales_7_days'] ?? 0) : ($existing?->sales_7_days ?? 0),
            'sales_14_days' => $hasSalesData ? ($stockData['sales_14_days'] ?? 0) : ($existing?->sales_14_days ?? 0),
            'sales_30_days' => $sales30,
            'average_daily_sales' => round($avgDailySales, 2),
            'days_of_stock' => $daysOfStock,
            'turnover_days' => $turnoverDays,
            'storage_cost_per_day' => $storageCostPerDay,
            'storage_cost_per_month' => $storageCostPerMonth,
            // Фактические начисления за хранение из еженедельных отчётов WB
            'storage_fee_total' => $storageFeeTotal,
            'storage_fee_last_week' => $storageFeeLastWeek,
            'storage_fee_report_from' => $storageFeeReportFrom,
            'storage_fee_report_to' => $storageFeeReportTo,
            // Хранение за прошлый месяц и за всё время (из отчёта о размещении Ozon)
            'storage_fee_prev_month' => array_key_exists('storage_fee_prev_month', $stockData)
                ? $stockData['storage_fee_prev_month']
                : ($existing?->storage_fee_prev_month ?? null),
            'storage_fee_prev_month_period' => array_key_exists('storage_fee_prev_month_period', $stockData)
                ? $stockData['storage_fee_prev_month_period']
                : ($existing?->storage_fee_prev_month_period ?? null),
            'storage_fee_all_time' => array_key_exists('storage_fee_all_time', $stockData)
                ? $stockData['storage_fee_all_time']
                : ($existing?->storage_fee_all_time ?? null),
            'last_updated' => now(),
        ];

        if (!$existing) {
            // Создаём новую запись
            $warehouse = InventoryWarehouse::create(array_merge([
                'sku' => $stockData['sku'],
                'warehouse_id' => $warehouseId,
                'integration_id' => $this->syncLog->integration_id,
            ], $newData));
            
            $warehouse->stock_status = $warehouse->calculateStockStatus();
            $warehouse->save();
            
            return 'created';
        }

        // Проверяем есть ли изменения
        $newCoef = $stockData['warehouse_coefficient'] ?? null;
        $newStorageFee = $stockData['storage_fee_total'] ?? null;
        $newPaidStorageFee = $stockData['paid_storage_fee'] ?? null;
        $hasChanges = $existing->quantity !== $quantity
            || $existing->warehouse_name !== ($stockData['warehouse_name'] ?? $warehouseId)
            || $existing->fulfillment_type !== ($stockData['fulfillment_type'] ?? null)
            || $existing->warehouse_coefficient != $newCoef // != для сравнения float/null
            || $existing->sales_30_days !== $sales30
            || $existing->storage_fee_total != $newStorageFee // Обновляем если изменилось платное хранение
            || $existing->paid_storage_fee != $newPaidStorageFee; // Обновляем если изменилось платное хранение Ozon

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
    
    /**
     * Синхронизация Product.stock и fulfillment_type с данными складов
     * - stock = сумма остатков на всех складах
     * - fulfillment_type = определяется по типам складов с остатками (FBO, FBS, MIXED)
     */
    private function syncProductStocks(): void
    {
        try {
            // Получаем все SKU с остатками и типами складов для этой интеграции
            $query = InventoryWarehouse::where('marketplace', $this->syncLog->marketplace);
            
            // Если есть integration_id, фильтруем по нему
            if ($this->syncLog->integration_id) {
                $query->where('integration_id', $this->syncLog->integration_id);
            }
            
            // Логируем общее количество записей в InventoryWarehouse
            $totalWarehouseRecords = (clone $query)->count();
            $recordsWithStock = (clone $query)->where('quantity', '>', 0)->count();
            
            Log::info("syncProductStocks: checking InventoryWarehouse records", [
                'marketplace' => $this->syncLog->marketplace,
                'integration_id' => $this->syncLog->integration_id,
                'total_warehouse_records' => $totalWarehouseRecords,
                'records_with_stock' => $recordsWithStock,
            ]);
            
            // Получаем остатки и типы складов по SKU
            $warehouseData = $query
                ->where('quantity', '>', 0) // Только склады с остатками
                ->get(['sku', 'quantity', 'fulfillment_type']);
            
            // Группируем по SKU
            $stocksBySku = [];
            $fulfillmentTypesBySku = [];
            
            foreach ($warehouseData as $wh) {
                $sku = $wh->sku;
                
                if (!isset($stocksBySku[$sku])) {
                    $stocksBySku[$sku] = 0;
                    $fulfillmentTypesBySku[$sku] = [];
                }
                
                $stocksBySku[$sku] += $wh->quantity;
                
                if ($wh->fulfillment_type) {
                    $fulfillmentTypesBySku[$sku][$wh->fulfillment_type] = true;
                }
            }
            
            // Обновляем Product.stock и fulfillment_type для каждого SKU
            $updated = 0;
            foreach ($stocksBySku as $sku => $totalQuantity) {
                $productQuery = Product::where('sku', $sku)
                    ->where('marketplace', $this->syncLog->marketplace);
                
                // Если есть integration_id, обновляем только товары этой интеграции
                if ($this->syncLog->integration_id) {
                    $productQuery->where('integration_id', $this->syncLog->integration_id);
                }
                
                // Определяем fulfillment_type на основе типов складов с остатками
                $types = array_keys($fulfillmentTypesBySku[$sku] ?? []);
                $fulfillmentType = $this->determineFulfillmentType($types);
                
                $affected = $productQuery->update([
                    'stock' => (int) $totalQuantity,
                    'fulfillment_type' => $fulfillmentType,
                ]);
                $updated += $affected;
            }
            
            // Обнуляем stock для товаров без записей в InventoryWarehouse
            // ТОЛЬКО для товаров этой интеграции!
            $skusWithStock = array_keys($stocksBySku);
            $zeroQuery = Product::where('marketplace', $this->syncLog->marketplace)
                ->where('stock', '>', 0);
            
            // Если есть integration_id, обнуляем только товары этой интеграции
            if ($this->syncLog->integration_id) {
                $zeroQuery->where('integration_id', $this->syncLog->integration_id);
            }
            
            $zeroed = $zeroQuery
                ->when(!empty($skusWithStock), fn($q) => $q->whereNotIn('sku', $skusWithStock))
                ->update(['stock' => 0]);
            
            Log::info("Product stocks synced with warehouse totals", [
                'marketplace' => $this->syncLog->marketplace,
                'integration_id' => $this->syncLog->integration_id,
                'products_updated' => $updated,
                'products_zeroed' => $zeroed,
            ]);
        } catch (\Exception $e) {
            Log::warning("Failed to sync product stocks", [
                'marketplace' => $this->syncLog->marketplace,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Определить fulfillment_type товара на основе типов складов с остатками
     * 
     * WB схемы:
     * - FBO/FBW: Склад WB
     * - FBS: Ваш склад, логистика WB
     * - DBS: Своя доставка
     * - EDBS: Экспресс своя
     * - DBW: Курьер WB от вас
     * - MIXED: Смешанная схема (остатки на разных типах складов)
     * 
     * @param array $types Массив типов складов с остатками ['FBO', 'FBS', 'DBS', ...]
     * @return string FBO, FBS, DBS, EDBS, DBW или MIXED
     */
    private function determineFulfillmentType(array $types): string
    {
        if (empty($types)) {
            return 'FBO'; // По умолчанию
        }
        
        // Убираем дубликаты
        $uniqueTypes = array_unique($types);
        
        // Если только один тип — возвращаем его
        if (count($uniqueTypes) === 1) {
            return strtoupper(reset($uniqueTypes));
        }
        
        // Несколько типов — смешанная схема
        return 'MIXED';
    }
    
    /**
     * Сохранение истории остатков для графиков динамики
     * Создаёт ежедневный снимок остатков по каждому SKU и складу
     */
    private function saveInventoryHistory(): void
    {
        try {
            $today = now()->toDateString();
            $saved = 0;
            $updated = 0;
            
            // Получаем записи остатков для этого маркетплейса порциями (chunk)
            InventoryWarehouse::where('marketplace', $this->syncLog->marketplace)
                ->select('sku', 'warehouse_id', 'quantity', 'sales_30_days')
                ->chunk(500, function ($warehouses) use (&$saved, &$updated, $today) {
                    foreach ($warehouses as $wh) {
                        // Проверяем существование записи
                        $existing = InventoryHistory::where('sku', $wh->sku)
                            ->where('warehouse_id', $wh->warehouse_id)
                            ->where('date', $today)
                            ->first();
                        
                        $data = [
                            'quantity' => $wh->quantity,
                            'sales' => $wh->sales_30_days ? (int) round($wh->sales_30_days / 30) : 0,
                        ];
                        
                        if ($existing) {
                            // Обновляем существующую запись
                            $existing->update($data);
                            $updated++;
                        } else {
                            // Создаём новую запись
                            InventoryHistory::create(array_merge([
                                'sku' => $wh->sku,
                                'warehouse_id' => $wh->warehouse_id,
                                'date' => $today,
                            ], $data));
                            $saved++;
                        }
                    }
                });
            
            Log::info("Inventory history saved", [
                'marketplace' => $this->syncLog->marketplace,
                'created' => $saved,
                'updated' => $updated,
                'date' => $today,
            ]);
        } catch (\Exception $e) {
            Log::warning("Failed to save inventory history", [
                'marketplace' => $this->syncLog->marketplace,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Пересчёт эффективной оборачиваемости с учётом дней наличия товара
     * Использует данные из InventoryHistory для определения реальных дней продаж
     */
    private function recalculateEffectiveTurnover(): void
    {
        try {
            $service = app(TurnoverCalculationService::class);
            $result = $service->recalculateAll($this->syncLog->marketplace);
            
            Log::info("Effective turnover recalculated", [
                'marketplace' => $this->syncLog->marketplace,
                'updated' => $result['updated'],
                'errors' => $result['errors'],
            ]);
        } catch (\Exception $e) {
            Log::warning("Failed to recalculate effective turnover", [
                'marketplace' => $this->syncLog->marketplace,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Пересчёт юнит-экономики для всех товаров интеграции
     * Вызывается после синхронизации остатков для актуализации расчётов
     */
    private function recalculateUnitEconomics(): void
    {
        try {
            $service = app(UnitEconomicsCacheService::class);
            $integrationId = $this->syncLog->integration_id;
            
            $products = Product::where('integration_id', $integrationId)->get();
            $count = 0;
            $errors = 0;
            
            foreach ($products as $product) {
                try {
                    $service->recalculateProduct($product);
                    $count++;
                } catch (\Exception $e) {
                    $errors++;
                    Log::warning("Failed to recalculate unit economics for product", [
                        'sku' => $product->sku,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            Log::info("Unit economics recalculated", [
                'marketplace' => $this->syncLog->marketplace,
                'integration_id' => $integrationId,
                'products' => $count,
                'errors' => $errors,
            ]);
        } catch (\Exception $e) {
            Log::warning("Failed to recalculate unit economics", [
                'marketplace' => $this->syncLog->marketplace,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Обновление комиссий Ozon из v3 API
     * Гарантирует актуальность комиссий при каждой синхронизации остатков
     */
    private function updateOzonCommissions($marketplace): void
    {
        try {
            $integrationId = $this->syncLog->integration_id;
            
            // Получаем все offer_id товаров интеграции
            $products = Product::where('integration_id', $integrationId)->get(['id', 'sku', 'ozon_data']);
            
            if ($products->isEmpty()) {
                return;
            }
            
            $offerIds = $products->pluck('sku')->toArray();
            
            // Получаем актуальные комиссии из v3 API
            if (!method_exists($marketplace, 'getProductsWithCommissions')) {
                // Fallback: используем products API напрямую
                $productsApi = $marketplace->getProductsApi();
                if (!$productsApi || !method_exists($productsApi, 'getProductsWithCommissions')) {
                    return;
                }
                $commissions = $productsApi->getProductsWithCommissions($offerIds);
            } else {
                $commissions = $marketplace->getProductsWithCommissions($offerIds);
            }
            
            if (empty($commissions)) {
                return;
            }
            
            $updated = 0;
            
            foreach ($products as $product) {
                $sku = $product->sku;
                if (!isset($commissions[$sku])) {
                    continue;
                }
                
                $productCommissions = $commissions[$sku];
                $ozonData = $product->ozon_data ?? [];
                
                // Обновляем комиссии в ozon_data
                $ozonData['commissions'] = [
                    'fbo' => [
                        'percent' => $productCommissions['fbo']['percent'] ?? 15,
                        'category_id' => $ozonData['category_id'] ?? 0,
                        'delivery_amount' => $productCommissions['fbo']['delivery_amount'] ?? 0,
                        'return_amount' => $productCommissions['fbo']['return_amount'] ?? 0,
                    ],
                    'fbs' => [
                        'percent' => $productCommissions['fbs']['percent'] ?? 15,
                        'category_id' => $ozonData['category_id'] ?? 0,
                        'delivery_amount' => $productCommissions['fbs']['delivery_amount'] ?? 0,
                        'return_amount' => $productCommissions['fbs']['return_amount'] ?? 0,
                    ],
                    'rfbs' => [
                        'percent' => $productCommissions['rfbs']['percent'] ?? 15,
                        'category_id' => $ozonData['category_id'] ?? 0,
                    ],
                    'express' => [
                        'percent' => $productCommissions['fbp']['percent'] ?? $productCommissions['express']['percent'] ?? 15,
                        'category_id' => $ozonData['category_id'] ?? 0,
                    ],
                ];
                
                $product->ozon_data = $ozonData;
                $product->save();
                $updated++;
            }
            
            Log::info("Ozon commissions updated", [
                'integration_id' => $integrationId,
                'updated' => $updated,
                'total' => $products->count(),
            ]);
        } catch (\Exception $e) {
            Log::warning("Failed to update Ozon commissions", [
                'marketplace' => $this->syncLog->marketplace,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Запуск отдельного джоба для синхронизации storage fees WB
     * 
     * Выделен в отдельный джоб для:
     * - Изоляции памяти (отчёт реализации WB может быть 80+ MB)
     * - Возможности запуска отдельно от основной синхронизации
     * - Chunk-обработки для экономии памяти
     */
    private function dispatchStorageFeesSync(): void
    {
        // Только для Wildberries
        if ($this->syncLog->marketplace !== 'wildberries') {
            return;
        }
        
        // Нужен integration_id и credentials
        if (!$this->syncLog->integration_id) {
            Log::debug('dispatchStorageFeesSync: skipped - no integration_id');
            return;
        }
        
        $credentials = $this->syncLog->credentials;
        if (empty($credentials)) {
            Log::debug('dispatchStorageFeesSync: skipped - no credentials');
            return;
        }
        
        try {
            SyncStorageFeesJob::dispatch(
                $this->syncLog->integration_id,
                $credentials,
                4 // weeks
            )->onQueue('inventory');
            
            Log::info('SyncStorageFeesJob dispatched', [
                'integration_id' => $this->syncLog->integration_id,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to dispatch SyncStorageFeesJob', [
                'integration_id' => $this->syncLog->integration_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
