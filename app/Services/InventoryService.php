<?php

namespace App\Services;

use App\Jobs\SyncInventoryJob;
use App\Models\InventoryAlert;
use App\Models\InventoryHistory;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Models\SyncLog;
use App\Support\SyncStartGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    /**
     * Пакетная предзагрузка для formatProductInventory (одна страница списка).
     *
     * @return array{cost_price_by_sku: array<string, mixed>, alerts_by_sku: Collection, previous_period_sales_by_sku: array<string, float>}
     */
    public function preloadFormatProductInventoryData(Collection $products): array
    {
        $skus = $products->pluck('sku')->unique()->filter()->values()->all();
        if ($skus === []) {
            return [
                'cost_price_by_sku' => [],
                'alerts_by_sku' => collect(),
                'previous_period_sales_by_sku' => [],
            ];
        }

        $maxIdRows = DB::table('unit_economics')
            ->select('sku')
            ->selectRaw('MAX(id) as max_id')
            ->whereIn('sku', $skus)
            ->groupBy('sku')
            ->pluck('max_id', 'sku');

        $costPriceBySku = [];
        $ids = $maxIdRows->filter()->values()->all();
        if ($ids !== []) {
            $rows = DB::table('unit_economics')
                ->whereIn('id', $ids)
                ->get(['sku', 'cost_price']);
            foreach ($rows as $row) {
                $costPriceBySku[$row->sku] = $row->cost_price;
            }
        }

        $alertsBySku = InventoryAlert::query()
            ->whereIn('sku', $skus)
            ->active()
            ->get()
            ->groupBy('sku');

        $skusNeedingTrend = $products->filter(function (Product $product) {
            $sumAds = $product->inventoryWarehouses->sum('average_daily_sales');

            return $sumAds * 28 > 0;
        })->pluck('sku')->unique()->values()->all();

        $previousPeriodSalesBySku = [];
        if ($skusNeedingTrend !== []) {
            $dateFrom = now()->subDays(56)->toDateString();
            $dateTo = now()->subDays(28)->toDateString();
            $previousPeriodSalesBySku = InventoryHistory::query()
                ->whereIn('sku', $skusNeedingTrend)
                ->whereBetween('date', [$dateFrom, $dateTo])
                ->selectRaw('sku, COALESCE(SUM(sales), 0) as total')
                ->groupBy('sku')
                ->pluck('total', 'sku')
                ->map(fn ($v) => (float) $v)
                ->all();
        }

        return [
            'cost_price_by_sku' => $costPriceBySku,
            'alerts_by_sku' => $alertsBySku,
            'previous_period_sales_by_sku' => $previousPeriodSalesBySku,
        ];
    }

    public function formatProductInventory(Product $product, array $preloaded = []): array
    {
        $warehouses = $product->inventoryWarehouses;
        $totalMarketplaceStock = $warehouses->sum('quantity');
        $sales28Days = $warehouses->sum('average_daily_sales') * 28;

        $usePreload = isset(
            $preloaded['cost_price_by_sku'],
            $preloaded['alerts_by_sku'],
            $preloaded['previous_period_sales_by_sku']
        );

        $salesTrend = 'stable';
        if ($sales28Days > 0) {
            $previousSales = $usePreload
                ? (float) ($preloaded['previous_period_sales_by_sku'][$product->sku] ?? 0)
                : $this->getPreviousPeriodSales($product->sku, 28);
            if ($previousSales > 0) {
                $change = (($sales28Days - $previousSales) / $previousSales) * 100;
                if ($change > 10) {
                    $salesTrend = 'growing';
                } elseif ($change < -10) {
                    $salesTrend = 'declining';
                }
            }
        }

        $totalStock = $product->stock + $totalMarketplaceStock;

        $costPrice = $usePreload
            ? ($preloaded['cost_price_by_sku'][$product->sku] ?? null)
            : $product->unitEconomics()->latest()->value('cost_price');

        $alerts = $usePreload
            ? ($preloaded['alerts_by_sku'][$product->sku] ?? collect())
            : $product->alerts()->active()->get();

        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'marketplace' => $product->marketplace,
            'internal_stock' => $product->stock,
            'total_stock' => $totalStock,
            'image_url' => ($product->images ?? [])[0] ?? null,
            'cost_price' => $costPrice,
            'category' => $product->category,
            'sales_trend' => $salesTrend,
            'sales_28_days' => round($sales28Days),
            'marketplace_warehouses' => $warehouses->map(fn ($w) => [
                'id' => $w->id,
                'name' => $w->warehouse_name,
                'marketplace' => $w->marketplace,
                'fulfillment_type' => $w->fulfillment_type,
                'region' => $w->region,
                'quantity' => $w->quantity,
                'average_daily_sales' => $w->average_daily_sales,
                'days_of_stock' => $w->days_of_stock,
                'recommended_quantity' => $w->recommended_quantity,
                'stock_status' => $w->stock_status,
                'in_way_to_client' => $w->in_way_to_client,
                'in_way_from_client' => $w->in_way_from_client,
            ]),
            'financials' => $this->calculateFinancials(
                $product,
                $warehouses,
                $usePreload ? (float) ($costPrice ?? 0) : null
            ),
            'alerts' => $alerts instanceof Collection ? $alerts->values() : $alerts,
            'last_updated' => $warehouses->max('last_updated'),
        ];
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

    private function calculateFinancials(Product $product, $warehouses, ?float $resolvedCostPrice = null): array
    {
        $costPrice = $resolvedCostPrice !== null
            ? $resolvedCostPrice
            : (float) ($product->unitEconomics()->latest()->value('cost_price') ?? 0);
        $totalStock = $product->stock + $warehouses->sum('quantity');
        $totalValue = $totalStock * $costPrice;

        $storageCostPerDay = $warehouses->sum(function ($w) {
            return $w->quantity * 0.5;
        });

        $avgDailySales = $warehouses->avg('average_daily_sales') ?? 0;
        $daysOfSupply = $avgDailySales > 0 ? $totalStock / $avgDailySales : 0;
        $turnoverRate = $daysOfSupply > 0 ? 365 / $daysOfSupply : 0;

        return [
            'total_value' => round($totalValue, 2),
            'frozen_capital' => round($totalValue * 0.3, 2),
            'storage_cost_per_day' => round($storageCostPerDay, 2),
            'turnover_rate' => round($turnoverRate, 2),
            'days_of_supply' => round($daysOfSupply),
        ];
    }

    public function getInventoryStats(array $filters = []): array
    {
        $query = Product::whereHas('inventoryWarehouses');

        if (! empty($filters['marketplace']) && $filters['marketplace'] !== 'all') {
            $mp = strtolower((string) $filters['marketplace']);
            $query->whereHas('inventoryWarehouses', function ($q) use ($mp) {
                if (in_array($mp, ['yandex', 'yandex_market'], true)) {
                    $q->whereIn('marketplace', ['yandex', 'yandex_market']);
                } else {
                    $q->where('marketplace', $mp);
                }
            });
        }

        $totalProducts = $query->count();
        $totalInternalStock = Product::sum('stock');

        // Суммируем quantity по каждому уникальному SKU+warehouse_id (не по всей таблице)
        // чтобы избежать задвоения если одна запись дублируется
        $totalMarketplaceStock = InventoryWarehouse::selectRaw('SUM(quantity) as total')
            ->value('total') ?? 0;

        $lowStockProducts = Product::whereHas('inventoryWarehouses', function ($q) {
            $q->lowStock();
        })->count();

        $outOfStockProducts = Product::whereHas('inventoryWarehouses', function ($q) {
            $q->outOfStock();
        })->count();

        return [
            'total_products' => $totalProducts,
            'total_internal_stock' => $totalInternalStock,
            'total_marketplace_stock' => $totalMarketplaceStock,
            'low_stock_products' => $lowStockProducts,
            'out_of_stock_products' => $outOfStockProducts,
        ];
    }

    public function getForecast(string $sku): array
    {
        $history = InventoryHistory::where('sku', $sku)
            ->orderBy('date', 'desc')
            ->limit(30)
            ->get();

        if ($history->isEmpty()) {
            return [
                'sku' => $sku,
                'predicted_sales' => 0,
                'change_percent' => 0,
                'confidence' => 0,
                'forecast_period_days' => 14,
                'recommended_order_quantity' => 0,
                'reason' => 'Недостаточно данных для прогноза',
                'factors' => [],
                'warnings' => ['Нет исторических данных'],
                'training_data_points' => 0,
                'last_updated' => now(),
            ];
        }

        $avgDailySales = $history->avg('sales');
        $predictedSales = $avgDailySales * 14;

        $recentAvg = $history->take(7)->avg('sales');
        $olderAvg = $history->skip(7)->take(7)->avg('sales');
        $changePercent = $olderAvg > 0 ? (($recentAvg - $olderAvg) / $olderAvg) * 100 : 0;

        $safetyStock = $avgDailySales * 7;
        $recommendedOrder = max(0, ($avgDailySales * 30) - $safetyStock);

        return [
            'sku' => $sku,
            'predicted_sales' => round($predictedSales),
            'change_percent' => round($changePercent, 1),
            'confidence' => min(100, $history->count() * 3.3),
            'forecast_period_days' => 14,
            'recommended_order_quantity' => round($recommendedOrder),
            'reason' => $changePercent > 0 ? 'Рост продаж' : ($changePercent < 0 ? 'Снижение продаж' : 'Стабильные продажи'),
            'factors' => [],
            'warnings' => [],
            'training_data_points' => $history->count(),
            'last_updated' => now(),
        ];
    }

    /**
     * Запускает синхронизацию остатков с маркетплейса
     *
     * @param  string  $marketplace  Название маркетплейса (wildberries, ozon, yandex)
     * @param  array  $credentials  API-ключи для маркетплейса
     * @param  int|null  $integrationId  ID интеграции из Sellico (опционально)
     */
    public function startSync(
        string $marketplace,
        array $credentials = [],
        ?int $integrationId = null,
        ?int $dispatchDelaySeconds = null
    ): SyncLog {
        $lock = Cache::lock(
            SyncStartGuard::cacheLockKey('inventory', $marketplace, $integrationId),
            120
        );

        return $lock->block(15, function () use ($marketplace, $credentials, $integrationId, $dispatchDelaySeconds) {
            $existingSync = SyncStartGuard::findActiveDuplicate('inventory', $marketplace, $integrationId);
            if ($existingSync) {
                return $existingSync;
            }

            $syncLog = SyncLog::create([
                'marketplace' => SyncStartGuard::storageMarketplace($marketplace),
                'integration_id' => $integrationId,
                'sync_type' => 'inventory',
                'status' => SyncLog::STATUS_PENDING,
                'credentials' => $credentials,
            ]);

            $pending = SyncInventoryJob::dispatch($syncLog);
            if ($dispatchDelaySeconds !== null && $dispatchDelaySeconds > 0) {
                $pending->delay(now()->addSeconds($dispatchDelaySeconds));
            }

            return $syncLog;
        });
    }

    public function getSyncStatuses(): array
    {
        $marketplaces = ['wildberries', 'ozon', 'yandex_market'];
        $statuses = [];

        foreach ($marketplaces as $marketplace) {
            $lastSyncQuery = SyncLog::where('sync_type', 'inventory')->latest();

            if ($marketplace === 'yandex_market') {
                $lastSyncQuery->whereIn('marketplace', ['yandex_market', 'yandex']);
            } else {
                $lastSyncQuery->where('marketplace', $marketplace);
            }

            $lastSync = $lastSyncQuery->first();

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

        return $lowStockProducts->map(fn ($w) => [
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

    public static function invalidateStatsCache(?int $integrationId = null, ?string $marketplace = null): void
    {
        \Illuminate\Support\Facades\Cache::forget("inventory_stats_{$integrationId}_{$marketplace}");
        \Illuminate\Support\Facades\Cache::forget('inventory_stats_all');
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
