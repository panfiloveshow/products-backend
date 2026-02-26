<?php

namespace App\Services;

use App\Models\InventoryAlert;
use App\Models\InventoryHistory;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Models\SyncLog;
use App\Jobs\SyncInventoryJob;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function formatProductInventory(Product $product): array
    {
        $warehouses = $product->inventoryWarehouses;
        $totalMarketplaceStock = $warehouses->sum('quantity');
        $sales28Days = $warehouses->sum('average_daily_sales') * 28;

        $salesTrend = 'stable';
        if ($sales28Days > 0) {
            $previousSales = $this->getPreviousPeriodSales($product->sku, 28);
            if ($previousSales > 0) {
                $change = (($sales28Days - $previousSales) / $previousSales) * 100;
                if ($change > 10) {
                    $salesTrend = 'growing';
                } elseif ($change < -10) {
                    $salesTrend = 'declining';
                }
            }
        }

        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'internal_stock' => $product->stock,
            'image_url' => $product->images[0] ?? null,
            'cost_price' => $product->unitEconomics()->latest()->value('cost_price'),
            'category' => $product->category,
            'sales_trend' => $salesTrend,
            'sales_28_days' => round($sales28Days),
            'marketplace_warehouses' => $warehouses->map(fn($w) => [
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
            'financials' => $this->calculateFinancials($product, $warehouses),
            'alerts' => $product->alerts()->active()->get(),
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

    private function calculateFinancials(Product $product, $warehouses): array
    {
        $costPrice = $product->unitEconomics()->latest()->value('cost_price') ?? 0;
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

        if (!empty($filters['marketplace']) && $filters['marketplace'] !== 'all') {
            $query->whereHas('inventoryWarehouses', function ($q) use ($filters) {
                $q->where('marketplace', $filters['marketplace']);
            });
        }

        $totalProducts = $query->count();
        $totalInternalStock = Product::sum('stock');
        $totalMarketplaceStock = InventoryWarehouse::sum('quantity');

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
