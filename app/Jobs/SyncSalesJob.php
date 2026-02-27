<?php

namespace App\Jobs;

use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Models\SyncLog;
use App\Models\Integration;
use App\Domains\Marketplace\MarketplaceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncSalesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(
        private ?string $marketplace = null
    ) {}

    public function handle(): void
    {
        Log::info('SyncSalesJob started', ['marketplace' => $this->marketplace]);

        $syncLogs = SyncLog::query()
            ->when($this->marketplace, fn($q) => $q->where('marketplace', $this->marketplace))
            ->whereNotNull('credentials')
            ->get();

        foreach ($syncLogs as $syncLog) {
            try {
                $this->syncMarketplaceSales($syncLog);
            } catch (\Exception $e) {
                Log::error('SyncSalesJob error', [
                    'marketplace' => $syncLog->marketplace,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('SyncSalesJob completed');
    }

    private function syncMarketplaceSales(SyncLog $syncLog): void
    {
        $marketplace = $syncLog->marketplace;
        $integrationId = $syncLog->integration_id;
        
        try {
            $integration = Integration::find($integrationId);
            $service = MarketplaceFactory::create($marketplace, $syncLog->credentials, $integration);
        } catch (\Exception $e) {
            Log::warning("SyncSalesJob: Cannot create service for {$marketplace}");
            return;
        }

        // Проверяем что сервис поддерживает получение продаж
        if (!method_exists($service, 'getSalesBySku')) {
            Log::info("SyncSalesJob: {$marketplace} does not support getSalesBySku");
            return;
        }

        // Получаем продажи за 28 дней (ключ = числовой SKU Ozon)
        $salesData = $service->getSalesBySku(28);
        
        if (empty($salesData)) {
            Log::info("SyncSalesJob: No sales data for {$marketplace}");
            return;
        }

        Log::info("SyncSalesJob: Processing {$marketplace}", ['skus_count' => count($salesData)]);

        // Для Ozon: строим маппинг ozon_sku -> offer_id (наш SKU)
        $ozonSkuToOfferId = [];
        if ($marketplace === 'ozon') {
            $products = Product::where('marketplace', 'ozon')
                ->where('integration_id', $integrationId)
                ->whereNotNull('ozon_data')
                ->get(['sku', 'ozon_data']);
            
            foreach ($products as $product) {
                $ozonSku = $product->ozon_data['sku'] ?? null;
                if ($ozonSku) {
                    $ozonSkuToOfferId[$ozonSku] = $product->sku;
                }
            }
            
            Log::info("SyncSalesJob: Built ozon_sku mapping", ['count' => count($ozonSkuToOfferId)]);
        }

        $updatedProducts = 0;
        $updatedWarehouses = 0;
        
        foreach ($salesData as $ozonSku => $sales) {
            // Для Ozon конвертируем числовой SKU в наш offer_id
            $sku = $marketplace === 'ozon' 
                ? ($ozonSkuToOfferId[$ozonSku] ?? null)
                : $ozonSku;
            
            if (!$sku) {
                continue;
            }

            $sales28 = $sales['sales_28_days'] ?? 0;
            $avgDailySales = $sales['avg_daily_sales'] ?? 0;

            // Обновляем Product
            $product = Product::where('sku', $sku)
                ->where('marketplace', $marketplace)
                ->where('integration_id', $integrationId)
                ->first();

            if ($product) {
                $product->sales_28_days = $sales28;
                $product->avg_daily_sales = $avgDailySales;
                
                // Оборачиваемость = остаток / среднедневные продажи
                $stock = $product->stock ?? 0;
                if ($avgDailySales > 0) {
                    $product->turnover_days = round($stock / $avgDailySales, 1);
                } else {
                    $product->turnover_days = $stock > 0 ? null : 0; // null = н/д (нет продаж)
                }
                
                $product->save();
                $updatedProducts++;
            }

            // Обновляем InventoryWarehouse
            // sales_* и average_daily_sales — данные по всему SKU (не по конкретному складу),
            // поэтому записываем одинаково на все склады. В matrix() используется MAX() для агрегации.
            $warehouses = InventoryWarehouse::where('sku', $sku)
                ->where('marketplace', $marketplace)
                ->where('integration_id', $integrationId)
                ->get();

            // Суммарный остаток по всем складам для корректного расчёта days_of_stock
            $totalQuantity = $warehouses->sum('quantity');

            foreach ($warehouses as $warehouse) {
                $warehouse->sales_7_days = $sales['sales_7_days'] ?? 0;
                $warehouse->sales_14_days = $sales['sales_14_days'] ?? 0;
                $warehouse->sales_28_days = $sales28;
                $warehouse->average_daily_sales = $avgDailySales;

                // days_of_stock/turnover_days считаем от суммарного остатка по SKU
                // (avg_daily_sales — общий по SKU, не по складу)
                if ($avgDailySales > 0) {
                    $warehouse->days_of_stock = (int) round($totalQuantity / $avgDailySales);
                    $warehouse->turnover_days = round($totalQuantity / $avgDailySales, 1);
                } else {
                    $warehouse->days_of_stock = $totalQuantity > 0 ? 999 : 0;
                    $warehouse->turnover_days = $totalQuantity > 0 ? 999 : null;
                }

                // Обновляем статус на основе дней запаса
                if (method_exists($warehouse, 'calculateStockStatus')) {
                    $warehouse->stock_status = $warehouse->calculateStockStatus();
                }

                $warehouse->save();
                $updatedWarehouses++;
            }
        }

        Log::info("SyncSalesJob: {$marketplace} completed", [
            'updated_products' => $updatedProducts,
            'updated_warehouses' => $updatedWarehouses,
        ]);
    }
}
