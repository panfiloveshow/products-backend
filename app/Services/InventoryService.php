<?php

namespace App\Services;

use App\Models\InventoryAlert;
use App\Models\InventoryHistory;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Models\SyncLog;
use App\Jobs\SyncInventoryJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class InventoryService
{
    public function formatProductInventory(Product $product): array
    {
        $warehouses = $product->inventoryWarehouses;
        $totalMarketplaceStock = $warehouses->sum('quantity');
        
        // Продажи общие для SKU, берём max (не sum, т.к. данные дублируются по складам)
        $sales30Days = $warehouses->max('sales_30_days') ?? 0;

        $salesTrend = 'stable';
        if ($sales30Days > 0) {
            $previousSales = $this->getPreviousPeriodSales($product->sku, 30);
            if ($previousSales > 0) {
                $change = (($sales30Days - $previousSales) / $previousSales) * 100;
                if ($change > 10) {
                    $salesTrend = 'growing';
                } elseif ($change < -10) {
                    $salesTrend = 'declining';
                }
            }
        }

        // Группируем остатки по маркетплейсам (только склады с остатком > 0)
        $stocksByMarketplace = $this->groupStocksByMarketplace($warehouses);
        
        // Фильтруем склады - показываем только с остатком > 0
        $warehousesWithStock = $warehouses->filter(fn($w) => $w->quantity > 0);
        
        // Получаем себестоимость товара
        $costPrice = $product->unitEconomics()->latest()->value('cost_price') ?? 0;
        
        // Рассчитываем агрегированные показатели
        $totalInTransit = $warehouses->max('in_transit') ?? 0; // Товары в пути к клиенту (общее для SKU)
        $totalInWayToClient = $warehouses->sum('in_way_to_client');
        $totalInWayFromClient = $warehouses->sum('in_way_from_client');
        $totalReserved = $warehouses->sum('reserved');
        
        // Агрегированные продажи (берём максимум по складам, т.к. продажи общие для SKU)
        $totalSales7Days = $warehouses->max('sales_7_days') ?? 0;
        $totalSales14Days = $warehouses->max('sales_14_days') ?? 0;
        $totalSales28Days = $warehouses->max('sales_28_days') ?? 0;
        $totalSales30Days = $warehouses->max('sales_30_days') ?? 0;
        $avgDailySales = $warehouses->max('average_daily_sales') ?? 0;
        
        // Если sales_28_days пустой, используем sales_30_days (WB возвращает только 30 дней)
        if ($totalSales28Days == 0 && $totalSales30Days > 0) {
            $totalSales28Days = $totalSales30Days;
        }
        // Fallback на Product если всё ещё пусто
        if ($totalSales28Days == 0) {
            $totalSales28Days = $product->sales_28_days ?? 0;
        }
        if ($avgDailySales == 0) {
            $avgDailySales = $product->avg_daily_sales ?? 0;
        }
        
        // Стоимость остатков = остаток * себестоимость
        $stockValue = $totalMarketplaceStock * $costPrice;
        
        // Оборачиваемость = остаток / среднедневные продажи
        $turnoverDays = $avgDailySales > 0 ? round($totalMarketplaceStock / $avgDailySales, 1) : null;
        
        // Дней хватит запаса
        $daysOfStock = $avgDailySales > 0 ? (int) round($totalMarketplaceStock / $avgDailySales) : ($totalMarketplaceStock > 0 ? 999 : 0);
        
        // Общая стоимость хранения (сумма по всем складам)
        $totalStorageCostPerDay = $warehouses->sum('storage_cost_per_day') ?? 0;
        $totalStorageCostPerMonth = $warehouses->sum('storage_cost_per_month') ?? 0;
        
        // ФАКТИЧЕСКИЕ начисления за хранение из еженедельных отчётов WB
        $totalStorageFeeTotal = $warehouses->sum('storage_fee_total') ?? 0;
        $totalStorageFeeLastWeek = $warehouses->sum('storage_fee_last_week') ?? 0;
        $storageFeeReportFrom = $warehouses->min('storage_fee_report_from');
        $storageFeeReportTo = $warehouses->max('storage_fee_report_to');
        
        // Платное хранение Ozon: штраф за превышение 120 дней + обычная плата
        $totalPaidStoragePenalty = $warehouses->sum('paid_storage_penalty') ?? 0;
        $totalPaidStorageFee = $warehouses->sum('paid_storage_fee') ?? 0;
        $paidStorageFrom = $warehouses->min('paid_storage_from');
        $paidStorageTo = $warehouses->max('paid_storage_to');

        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'internal_stock' => $product->stock,
            'image_url' => $product->images[0] ?? null,
            'cost_price' => $costPrice,
            'category' => $product->category,
            'marketplace' => $product->marketplace,
            'sales_trend' => $salesTrend,
            // Продажи за 28 дней
            'sales_28_days' => $totalSales28Days,
            // Остатки на текущий день, шт
            'total_stock' => $totalMarketplaceStock,
            // Стоимость остатков на текущий день
            'stock_value' => round($stockValue, 2),
            // Оборачиваемость текущих остатков (дней)
            'turnover_days' => $turnoverDays,
            // Товары в пути к клиенту (delivering), шт
            'in_transit' => $totalInTransit,
            // В пути к клиенту, шт (legacy)
            'in_way_to_client' => $totalInWayToClient,
            // В пути от клиента, шт
            'in_way_from_client' => $totalInWayFromClient,
            // Зарезервировано
            'reserved' => $totalReserved,
            // Продажи по периодам
            'sales_7_days' => $totalSales7Days,
            'sales_14_days' => $totalSales14Days,
            'sales_30_days' => $totalSales30Days,
            'avg_daily_sales' => $avgDailySales,
            // Дней хватит запаса
            'days_of_stock' => $daysOfStock,
            // ФАКТИЧЕСКИЕ начисления за хранение из еженедельных отчётов WB (storage_fee)
            'storage_fee_total' => round($totalStorageFeeTotal, 2),
            'storage_fee_last_week' => round($totalStorageFeeLastWeek, 2),
            'storage_fee_report_from' => $storageFeeReportFrom,
            'storage_fee_report_to' => $storageFeeReportTo,
            // Платное хранение Ozon (штраф за >120 дней + обычная плата)
            'paid_storage_penalty' => round($totalPaidStoragePenalty, 2),
            'paid_storage_fee' => round($totalPaidStorageFee, 2),
            'paid_storage_total' => round($totalPaidStoragePenalty + $totalPaidStorageFee, 2),
            'paid_storage_from' => $paidStorageFrom,
            'paid_storage_to' => $paidStorageTo,
            // Стоимость хранения (расчётная по складам)
            'storage_cost_per_day' => round($totalStorageCostPerDay, 2),
            'storage_cost_per_month' => round($totalStorageCostPerMonth, 2),
            'warehouses_count' => $warehousesWithStock->count(),
            // Остатки сгруппированные по маркетплейсам
            'stocks_by_marketplace' => $stocksByMarketplace,
            // Плоский список складов с остатком > 0
            'marketplace_warehouses' => $warehousesWithStock->map(fn($w) => [
                'id' => $w->id,
                'name' => $w->warehouse_name,
                'marketplace' => $w->marketplace,
                'fulfillment_type' => $w->fulfillment_type,
                'region' => $w->region,
                'quantity' => $w->quantity,
                'reserved' => $w->reserved,
                'in_transit' => $w->in_transit,
                'in_way_to_client' => $w->in_way_to_client,
                'in_way_from_client' => $w->in_way_from_client,
                'sales_7_days' => $w->sales_7_days,
                'sales_14_days' => $w->sales_14_days,
                'sales_30_days' => $w->sales_30_days,
                'average_daily_sales' => $w->average_daily_sales,
                'days_of_stock' => $w->days_of_stock,
                'turnover_days' => $w->turnover_days,
                'recommended_quantity' => $w->recommended_quantity,
                'stock_status' => $w->stock_status,
                'storage_cost_per_day' => $w->storage_cost_per_day,
                'storage_cost_per_month' => $w->storage_cost_per_month,
                // Фактические начисления из еженедельных отчётов WB
                'storage_fee_total' => $w->storage_fee_total,
                'storage_fee_last_week' => $w->storage_fee_last_week,
                'storage_fee_report_from' => $w->storage_fee_report_from,
                'storage_fee_report_to' => $w->storage_fee_report_to,
                // Платное хранение Ozon
                'paid_storage_penalty' => $w->paid_storage_penalty,
                'paid_storage_fee' => $w->paid_storage_fee,
                'paid_storage_from' => $w->paid_storage_from,
                'paid_storage_to' => $w->paid_storage_to,
            ])->values(),
            'financials' => $this->calculateFinancials($product, $warehouses),
            'alerts' => $product->alerts()->active()->get(),
            'last_updated' => $warehouses->max('last_updated'),
        ];
    }

    /**
     * Группировка остатков по маркетплейсам с детализацией по складам
     * Показываем только склады с остатком > 0
     */
    private function groupStocksByMarketplace($warehouses): array
    {
        // Фильтруем только склады с остатком > 0
        $warehousesWithStock = $warehouses->filter(fn($w) => $w->quantity > 0);
        
        $grouped = $warehousesWithStock->groupBy('marketplace');
        $result = [];

        foreach ($grouped as $marketplace => $warehouseList) {
            $totalQty = $warehouseList->sum('quantity');
            $totalReserved = $warehouseList->sum('reserved');
            $totalInTransit = $warehouseList->sum('in_transit');
            
            // Группируем по типу фулфилмента внутри маркетплейса
            $byFulfillment = $warehouseList->groupBy('fulfillment_type');
            $fulfillmentData = [];
            
            foreach ($byFulfillment as $fulfillmentType => $items) {
                $fulfillmentData[$fulfillmentType ?: 'unknown'] = [
                    'total_quantity' => $items->sum('quantity'),
                    'total_reserved' => $items->sum('reserved'),
                    'warehouses' => $items->map(fn($w) => [
                        'id' => $w->id,
                        'name' => $w->warehouse_name,
                        'quantity' => $w->quantity,
                        'reserved' => $w->reserved,
                        'in_transit' => $w->in_transit,
                        'sales_7_days' => $w->sales_7_days,
                        'sales_14_days' => $w->sales_14_days,
                        'sales_30_days' => $w->sales_30_days,
                        'avg_daily_sales' => $w->average_daily_sales,
                        'days_of_stock' => $w->days_of_stock,
                        'turnover_days' => $w->turnover_days,
                        'stock_status' => $w->stock_status,
                        'storage_cost_per_day' => $w->storage_cost_per_day,
                        'storage_cost_per_month' => $w->storage_cost_per_month,
                    ])->values()->toArray(),
                ];
            }

            $result[$marketplace] = [
                'marketplace' => $marketplace,
                'marketplace_name' => $this->getMarketplaceName($marketplace),
                'total_quantity' => $totalQty,
                'total_reserved' => $totalReserved,
                'total_in_transit' => $totalInTransit,
                'available' => $totalQty - $totalReserved,
                'by_fulfillment' => $fulfillmentData,
                'warehouses_count' => $warehouseList->count(),
            ];
        }

        return $result;
    }

    private function getMarketplaceName(string $marketplace): string
    {
        return match ($marketplace) {
            'ozon' => 'Ozon',
            'wildberries' => 'Wildberries',
            'yandex' => 'Яндекс Маркет',
            default => ucfirst($marketplace),
        };
    }

    private function getPreviousPeriodSales(string $sku, int $days): float
    {
        return InventoryHistory::where('sku', $sku)
            ->whereBetween('date', [
                now()->subDays($days * 2)->toDateString(),
                now()->subDays($days)->toDateString(),
            ])
            ->sum('sales');
    }

    private function calculateFinancials(Product $product, $warehouses): array
    {
        $costPrice = $product->unitEconomics()->latest()->value('cost_price');
        $hasCostPrice = $costPrice !== null && $costPrice > 0;
        
        $totalStock = $product->stock + $warehouses->sum('quantity');
        $avgDailySales = $warehouses->max('average_daily_sales') ?? 0;
        
        // Дней запаса — рассчитываем всегда (не зависит от себестоимости)
        $daysOfSupply = $avgDailySales > 0 ? round($totalStock / $avgDailySales) : ($totalStock > 0 ? 999 : 0);
        
        // Оборачиваемость (раз/год) — рассчитываем всегда
        $turnoverRate = $daysOfSupply > 0 && $daysOfSupply < 999 ? round(365 / $daysOfSupply, 1) : 0;
        
        // Стоимость хранения — рассчитываем всегда
        $storageCostPerDay = $warehouses->sum(function ($w) {
            if ($w->storage_cost_per_day) {
                return $w->storage_cost_per_day;
            }
            $tariff = match ($w->marketplace) {
                'wildberries' => 0.5,
                'ozon' => 0.4,
                'yandex' => 0.3,
                default => 0.5,
            };
            return $w->quantity * $tariff;
        });
        
        // Если себестоимость НЕ загружена — финансовые показатели = null
        if (!$hasCostPrice) {
            return [
                'has_cost_price' => false,
                'total_value' => null,
                'frozen_capital' => null,
                'storage_cost_per_day' => round($storageCostPerDay, 2),
                'turnover_rate' => $turnoverRate,
                'days_of_supply' => $daysOfSupply,
            ];
        }
        
        // Себестоимость загружена — рассчитываем всё
        $totalValue = $totalStock * $costPrice;
        
        // Замороженный капитал = товары с оборачиваемостью > 60 дней
        $frozenCapital = 0;
        if ($daysOfSupply > 60) {
            // Излишек = остаток - (продажи за 60 дней)
            $optimalStock = $avgDailySales * 60;
            $excessStock = max(0, $totalStock - $optimalStock);
            $frozenCapital = $excessStock * $costPrice;
        }

        return [
            'has_cost_price' => true,
            'total_value' => round($totalValue, 2),
            'frozen_capital' => round($frozenCapital, 2),
            'storage_cost_per_day' => round($storageCostPerDay, 2),
            'turnover_rate' => $turnoverRate,
            'days_of_supply' => $daysOfSupply,
        ];
    }

    /**
     * Получить статистику остатков с кэшированием
     * Кэш инвалидируется при синхронизации
     */
    public function getInventoryStats(array $filters = []): array
    {
        $cacheKey = 'inventory_stats_' . md5(json_encode($filters));
        $cacheTtl = 300; // 5 минут
        
        return Cache::remember($cacheKey, $cacheTtl, function () use ($filters) {
            return $this->calculateInventoryStats($filters);
        });
    }
    
    /**
     * Инвалидировать кэш статистики остатков
     */
    public static function invalidateStatsCache(?int $integrationId = null, ?string $marketplace = null): void
    {
        $patterns = [
            'inventory_stats_' . md5(json_encode([])),
        ];
        
        if ($integrationId) {
            $patterns[] = 'inventory_stats_' . md5(json_encode(['integration_id' => $integrationId]));
        }
        
        if ($marketplace) {
            $patterns[] = 'inventory_stats_' . md5(json_encode(['marketplace' => $marketplace]));
        }
        
        if ($integrationId && $marketplace) {
            $patterns[] = 'inventory_stats_' . md5(json_encode(['integration_id' => $integrationId, 'marketplace' => $marketplace]));
            $patterns[] = 'inventory_stats_' . md5(json_encode(['marketplace' => $marketplace, 'integration_id' => $integrationId]));
        }
        
        foreach ($patterns as $key) {
            Cache::forget($key);
        }
    }
    
    /**
     * Расчёт статистики остатков (без кэша)
     */
    private function calculateInventoryStats(array $filters): array
    {
        $marketplace = $filters['marketplace'] ?? null;
        $integrationId = $filters['integration_id'] ?? null;
        
        // Базовый запрос товаров с остатками
        $query = Product::whereHas('inventoryWarehouses');

        if (!empty($marketplace) && $marketplace !== 'all') {
            $query->where('marketplace', $marketplace);
        }

        if (!empty($integrationId)) {
            $query->where('integration_id', $integrationId);
        }

        $totalProducts = $query->count();
        
        // Получаем SKU отфильтрованных товаров для точного подсчёта остатков
        $filteredSkus = $query->pluck('sku')->toArray();
        
        // Статистика с учётом фильтров
        $stockQuery = Product::query();
        $warehouseQuery = InventoryWarehouse::whereIn('sku', $filteredSkus);
        
        if (!empty($marketplace) && $marketplace !== 'all') {
            $stockQuery->where('marketplace', $marketplace);
            $warehouseQuery->where('marketplace', $marketplace);
        }
        
        if (!empty($integrationId)) {
            $stockQuery->where('integration_id', $integrationId);
        }
        
        $totalInternalStock = $stockQuery->sum('stock');
        $totalMarketplaceStock = $warehouseQuery->sum('quantity');
        
        // Low stock и out of stock с учётом фильтров
        $lowStockQuery = Product::whereHas('inventoryWarehouses', fn($q) => $q->lowStock());
        $outOfStockQuery = Product::whereHas('inventoryWarehouses', fn($q) => $q->outOfStock());
        
        if (!empty($marketplace) && $marketplace !== 'all') {
            $lowStockQuery->where('marketplace', $marketplace);
            $outOfStockQuery->where('marketplace', $marketplace);
        }
        
        if (!empty($integrationId)) {
            $lowStockQuery->where('integration_id', $integrationId);
            $outOfStockQuery->where('integration_id', $integrationId);
        }
        
        $lowStockProducts = $lowStockQuery->count();
        $outOfStockProducts = $outOfStockQuery->count();

        // Общая сумма платного хранения за период
        $totalStorageCostQuery = Product::query();
        if (!empty($marketplace) && $marketplace !== 'all') {
            $totalStorageCostQuery->where('marketplace', $marketplace);
        }
        if (!empty($integrationId)) {
            $totalStorageCostQuery->where('integration_id', $integrationId);
        }
        $totalStorageCost = $totalStorageCostQuery->sum('storage_cost');

        // Платное хранение Ozon (штраф >120 дней + обычная плата) из InventoryWarehouse
        $paidStorageQuery = InventoryWarehouse::whereIn('sku', $filteredSkus);
        if (!empty($marketplace) && $marketplace !== 'all') {
            $paidStorageQuery->where('marketplace', $marketplace);
        }
        $totalPaidStoragePenalty = (clone $paidStorageQuery)->sum('paid_storage_penalty');
        $totalPaidStorageFee = (clone $paidStorageQuery)->sum('paid_storage_fee');

        return [
            'total_products' => $totalProducts,
            'total_internal_stock' => $totalInternalStock,
            'total_marketplace_stock' => $totalMarketplaceStock,
            'low_stock_products' => $lowStockProducts,
            'out_of_stock_products' => $outOfStockProducts,
            'total_storage_cost' => round($totalStorageCost, 2),
            'total_paid_storage_penalty' => round($totalPaidStoragePenalty, 2),
            'total_paid_storage_fee' => round($totalPaidStorageFee, 2),
            'total_paid_storage' => round($totalPaidStoragePenalty + $totalPaidStorageFee, 2),
        ];
    }

    public function getForecast(string $sku): array
    {
        $product = Product::with('inventoryWarehouses')->where('sku', $sku)->first();
        
        if (!$product) {
            return [
                'sku' => $sku,
                'current_stock' => 0,
                'avg_daily_sales' => 0,
                'days_of_stock' => null,
                'stockout_date' => null,
                'trend' => 'stable',
                'forecast' => [],
                'warnings' => ['Товар не найден'],
            ];
        }

        $warehouses = $product->inventoryWarehouses;
        $totalStock = $warehouses->sum('quantity');
        $avgDailySales = $warehouses->max('average_daily_sales') ?? 0;
        
        // Пробуем получить историю из InventoryHistory
        $history = InventoryHistory::where('sku', $sku)
            ->orderBy('date', 'desc')
            ->limit(14)
            ->get();

        // Генерируем прогноз на 14 дней
        $forecast = [];
        $currentStock = $totalStock;
        $today = now();
        
        for ($i = 0; $i <= 14; $i++) {
            $date = $today->copy()->addDays($i);
            
            // Прогнозируемые продажи с небольшой вариацией
            $dailySales = $avgDailySales > 0 
                ? round($avgDailySales * (0.85 + (rand(0, 30) / 100)), 1) 
                : 0;
            
            $forecast[] = [
                'date' => $date->format('Y-m-d'),
                'predicted_stock' => max(0, round($currentStock)),
                'predicted_sales' => $dailySales,
                'confidence' => max(0.5, 1 - ($i * 0.03)),
            ];
            
            $currentStock -= $dailySales;
        }
        
        // Генерируем историю остатков (последние 14 дней)
        $stockHistory = [];
        $historyStock = $totalStock;
        
        if ($history->isNotEmpty()) {
            // Используем реальные данные
            foreach ($history->reverse() as $h) {
                $stockHistory[] = [
                    'date' => $h->date->format('Y-m-d'),
                    'stock' => $h->quantity,
                    'sales' => $h->sales ?? 0,
                ];
            }
        } else {
            // Генерируем на основе текущих данных
            // Сначала рассчитываем начальный остаток 14 дней назад
            // Остаток 14 дней назад = текущий остаток + продажи за 14 дней
            $sales14Days = $warehouses->max('sales_14_days') ?? ($avgDailySales * 14);
            $startStock = $totalStock + $sales14Days;
            $historyStock = $startStock;
            
            for ($i = 14; $i >= 0; $i--) {
                $date = $today->copy()->subDays($i);
                $dailySales = $avgDailySales > 0 
                    ? round($avgDailySales * (0.7 + (rand(0, 60) / 100)), 1) 
                    : 0;
                
                $stockHistory[] = [
                    'date' => $date->format('Y-m-d'),
                    'stock' => max(0, round($historyStock)),
                    'sales' => $dailySales,
                ];
                
                // Остатки уменьшаются с каждым днём из-за продаж
                $historyStock -= $dailySales;
            }
        }
        
        // Расчёт даты исчерпания запаса
        $stockoutDate = null;
        $daysOfStock = null;
        if ($avgDailySales > 0 && $totalStock > 0) {
            $daysOfStock = (int) round($totalStock / $avgDailySales);
            $stockoutDate = $today->copy()->addDays($daysOfStock)->format('Y-m-d');
        }
        
        // Определяем тренд
        $trend = 'stable';
        $sales7Days = $warehouses->max('sales_7_days') ?? 0;
        $sales14Days = $warehouses->max('sales_14_days') ?? 0;
        
        if ($sales14Days > 0 && $sales7Days > 0) {
            $prevWeekSales = $sales14Days - $sales7Days;
            if ($prevWeekSales > 0) {
                $change = (($sales7Days - $prevWeekSales) / $prevWeekSales) * 100;
                if ($change > 15) {
                    $trend = 'growing';
                } elseif ($change < -15) {
                    $trend = 'declining';
                }
            }
        }

        return [
            'sku' => $sku,
            'current_stock' => $totalStock,
            'avg_daily_sales' => $avgDailySales,
            'days_of_stock' => $daysOfStock,
            'stockout_date' => $stockoutDate,
            'trend' => $trend,
            'stock_history' => $stockHistory,
            'forecast' => $forecast,
            'warnings' => $history->isEmpty() ? ['Данные сгенерированы на основе текущих остатков'] : [],
        ];
    }

    /**
     * Получение финансовой аналитики по SKU
     * - Стоимость остатков = количество * себестоимость
     * - Замороженный капитал = стоимость остатков сверх нормы (>30 дней запаса)
     * - Оборачиваемость = 365 / дней запаса (раз/год)
     * - Дней запаса = количество / среднедневные продажи
     */
    public function getFinancialAnalytics(string $sku): array
    {
        $product = Product::with(['inventoryWarehouses', 'unitEconomics'])->where('sku', $sku)->first();
        
        if (!$product) {
            return [
                'sku' => $sku,
                'error' => 'Товар не найден',
            ];
        }

        $warehouses = $product->inventoryWarehouses;
        $unitEconomics = $product->unitEconomics->first();
        
        // Получаем себестоимость
        $costPrice = $unitEconomics?->cost_price ?? 0;
        
        // Суммируем остатки по всем складам
        $totalQuantity = $warehouses->sum('quantity');
        $avgDailySales = $warehouses->max('average_daily_sales') ?? 0;
        $daysOfStock = $warehouses->max('days_of_stock') ?? 0;
        
        // Стоимость остатков = количество * себестоимость
        $stockValue = round($totalQuantity * $costPrice, 2);
        
        // Дней запаса (если не рассчитано, считаем)
        if ($daysOfStock == 0 && $avgDailySales > 0) {
            $daysOfStock = (int) round($totalQuantity / $avgDailySales);
        }
        
        // Оборачиваемость (раз/год)
        $turnoverRate = $daysOfStock > 0 ? round(365 / $daysOfStock, 1) : 0;
        
        // Определяем статус оборачиваемости
        $turnoverStatus = match (true) {
            $turnoverRate >= 12 => 'high',      // >12 раз/год = отлично
            $turnoverRate >= 6 => 'normal',     // 6-12 раз/год = норма
            $turnoverRate >= 3 => 'low',        // 3-6 раз/год = низкая
            default => 'critical',               // <3 раз/год = критично
        };
        
        // Определяем статус дней запаса
        $daysStatus = match (true) {
            $daysOfStock == 0 => 'out_of_stock',
            $daysOfStock <= 7 => 'critical',
            $daysOfStock <= 14 => 'low',
            $daysOfStock <= 30 => 'normal',
            $daysOfStock <= 60 => 'excess',
            default => 'overstock',
        };
        
        // Замороженный капитал = стоимость остатков сверх нормы (>30 дней)
        $normalDaysStock = 30;
        $frozenCapital = 0;
        if ($avgDailySales > 0 && $daysOfStock > $normalDaysStock) {
            $excessQuantity = $totalQuantity - ($avgDailySales * $normalDaysStock);
            $frozenCapital = round(max(0, $excessQuantity) * $costPrice, 2);
        }
        
        // Потенциальная выручка (если продать все остатки)
        $price = $product->price ?? 0;
        $potentialRevenue = round($totalQuantity * $price, 2);
        
        // Потенциальная прибыль
        $potentialProfit = round($potentialRevenue - $stockValue, 2);

        return [
            'sku' => $sku,
            'product_name' => $product->name,
            'marketplace' => $product->marketplace,
            'stock_value' => $stockValue,
            'frozen_capital' => $frozenCapital,
            'turnover_rate' => $turnoverRate,
            'turnover_status' => $turnoverStatus,
            'turnover_status_label' => $this->getTurnoverStatusLabel($turnoverStatus),
            'days_of_stock' => $daysOfStock,
            'days_status' => $daysStatus,
            'days_status_label' => $this->getDaysStatusLabel($daysStatus),
            'total_quantity' => $totalQuantity,
            'avg_daily_sales' => $avgDailySales,
            'cost_price' => $costPrice,
            'price' => $price,
            'potential_revenue' => $potentialRevenue,
            'potential_profit' => $potentialProfit,
        ];
    }

    private function getTurnoverStatusLabel(string $status): string
    {
        return match ($status) {
            'high' => 'Высокая',
            'normal' => 'Нормальная',
            'low' => 'Низкая',
            'critical' => 'Критично низкая',
            default => 'Неизвестно',
        };
    }

    private function getDaysStatusLabel(string $status): string
    {
        return match ($status) {
            'out_of_stock' => 'Нет в наличии',
            'critical' => 'Критично мало',
            'low' => 'Мало',
            'normal' => 'Норма',
            'excess' => 'Избыток',
            'overstock' => 'Затоваривание',
            default => 'Неизвестно',
        };
    }

    /**
     * Получение стоимости хранения по SKU
     */
    public function getStorageCost(string $sku): array
    {
        $product = Product::with('inventoryWarehouses')->where('sku', $sku)->first();
        
        if (!$product) {
            return [
                'sku' => $sku,
                'storage_cost_per_day' => null,
                'storage_cost_per_month' => null,
                'warehouses' => [],
                'error' => 'Товар не найден',
            ];
        }

        $warehouses = $product->inventoryWarehouses;
        $totalStorageCostPerDay = 0;
        $totalStorageCostPerMonth = 0;
        $warehouseDetails = [];

        foreach ($warehouses as $warehouse) {
            $costPerDay = $warehouse->storage_cost_per_day ?? 0;
            $costPerMonth = $warehouse->storage_cost_per_month ?? 0;
            $quantity = $warehouse->quantity ?? 0;
            
            // Если нет данных из API, рассчитываем на основе тарифов
            if ($costPerDay == 0 && $quantity > 0) {
                $volumeLiters = $this->getProductVolume($product);
                $tariff = $this->getStorageTariff($product->marketplace);
                $costPerDay = round($volumeLiters * $tariff * $quantity, 2);
                $costPerMonth = round($costPerDay * 30, 2);
            }
            
            $totalStorageCostPerDay += $costPerDay;
            $totalStorageCostPerMonth += $costPerMonth;
            
            $warehouseDetails[] = [
                'warehouse_id' => $warehouse->warehouse_id,
                'warehouse_name' => $warehouse->warehouse_name,
                'quantity' => $quantity,
                'storage_cost_per_day' => $costPerDay,
                'storage_cost_per_month' => $costPerMonth,
            ];
        }

        return [
            'sku' => $sku,
            'marketplace' => $product->marketplace,
            'storage_cost_per_day' => round($totalStorageCostPerDay, 2),
            'storage_cost_per_month' => round($totalStorageCostPerMonth, 2),
            'warehouses' => $warehouseDetails,
        ];
    }

    /**
     * Получение объёма товара в литрах
     */
    private function getProductVolume(Product $product): float
    {
        $dimensions = match ($product->marketplace) {
            'wildberries' => $product->wb_data['dimensions'] ?? [],
            'ozon' => $product->ozon_data['dimensions'] ?? [],
            'yandex' => $product->yandex_data['weightDimensions'] ?? [],
            default => [],
        };

        $length = ($dimensions['length'] ?? 10) / 100; // см -> м
        $width = ($dimensions['width'] ?? 10) / 100;
        $height = ($dimensions['height'] ?? 10) / 100;
        
        return $length * $width * $height * 1000; // м³ -> литры
    }

    /**
     * Получение тарифа хранения для маркетплейса (руб/литр/день)
     */
    private function getStorageTariff(string $marketplace): float
    {
        return match ($marketplace) {
            'wildberries' => 0.5,
            'ozon' => 0.4,
            'yandex' => 0.3,
            default => 0.5,
        };
    }

    /**
     * Запускает синхронизацию остатков с маркетплейса
     * 
     * @param string $marketplace Название маркетплейса (wildberries, ozon, yandex)
     * @param array $credentials API-ключи для маркетплейса
     * @param int|null $integrationId ID интеграции из Sellico (опционально)
     */
    public function startSync(
        string $marketplace,
        array $credentials = [],
        ?int $integrationId = null
    ): SyncLog {
        $existingSync = SyncLog::where('marketplace', $marketplace)
            ->where('sync_type', 'inventory')
            ->running()
            ->first();

        if ($existingSync) {
            return $existingSync;
        }

        $syncLog = SyncLog::create([
            'marketplace' => $marketplace,
            'integration_id' => $integrationId,
            'sync_type' => 'inventory',
            'status' => SyncLog::STATUS_PENDING,
            'credentials' => $credentials,
        ]);

        SyncInventoryJob::dispatch($syncLog);

        return $syncLog;
    }

    public function getSyncStatuses(): array
    {
        $marketplaces = ['wildberries', 'ozon', 'yandex'];
        $statuses = [];

        foreach ($marketplaces as $marketplace) {
            $lastSync = SyncLog::where('marketplace', $marketplace)
                ->where('sync_type', 'inventory')
                ->latest()
                ->first();

            $statuses[$marketplace] = [
                'last_sync' => $lastSync?->completed_at,
                'status' => $lastSync?->status ?? 'never',
                'items_synced' => $lastSync?->items_synced ?? 0,
            ];
        }

        return $statuses;
    }

    public function getAIRecommendations(): array
    {
        $lowStockProducts = InventoryWarehouse::with('product')
            ->lowStock()
            ->orderBy('days_of_stock')
            ->limit(10)
            ->get();

        return $lowStockProducts->map(fn($w) => [
            'id' => $w->id,
            'type' => 'warning',
            'title' => "Низкий остаток: {$w->product->name}",
            'message' => "На складе {$w->warehouse_name} осталось {$w->quantity} шт. ({$w->days_of_stock} дней)",
            'impact' => $w->days_of_stock <= 7 ? 'high' : 'medium',
            'confidence' => 85,
            'action' => 'Рекомендуется пополнить запас',
        ])->toArray();
    }

    public function getRedistributionSuggestions(): array
    {
        $excessWarehouses = InventoryWarehouse::where('stock_status', 'excess')
            ->with('product')
            ->limit(10)
            ->get();

        $suggestions = [];

        foreach ($excessWarehouses as $excess) {
            $lowStock = InventoryWarehouse::where('sku', $excess->sku)
                ->where('id', '!=', $excess->id)
                ->lowStock()
                ->first();

            if ($lowStock) {
                $transferQty = min($excess->quantity / 2, $lowStock->recommended_quantity ?? 100);
                $suggestions[] = [
                    'from_warehouse_id' => $excess->warehouse_id,
                    'from_warehouse_name' => $excess->warehouse_name,
                    'to_warehouse_id' => $lowStock->warehouse_id,
                    'to_warehouse_name' => $lowStock->warehouse_name,
                    'quantity' => round($transferQty),
                    'savings' => round($transferQty * 0.5 * 30),
                    'reason' => "Перераспределение избытка со склада {$excess->warehouse_name}",
                ];
            }
        }

        return $suggestions;
    }

    public function getOverallStats(): array
    {
        return [
            'total_products' => Product::count(),
            'total_warehouses' => InventoryWarehouse::distinct('warehouse_id')->count(),
            'total_stock' => InventoryWarehouse::sum('quantity'),
            'critical_alerts' => InventoryAlert::active()->byType('critical')->count(),
            'warning_alerts' => InventoryAlert::active()->byType('warning')->count(),
            'by_marketplace' => InventoryWarehouse::select('marketplace')
                ->selectRaw('SUM(quantity) as total_stock')
                ->selectRaw('COUNT(DISTINCT warehouse_id) as warehouses')
                ->groupBy('marketplace')
                ->get()
                ->keyBy('marketplace')
                ->toArray(),
        ];
    }
}
