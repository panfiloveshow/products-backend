<?php

namespace App\Jobs;

use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Models\SyncLog;
use App\Models\Integration;
use App\Domains\Marketplace\MarketplaceFactory;
use App\Support\ActivityLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncSalesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    public function __construct(
        private ?string $marketplace = null
    ) {}

    public function handle(): void
    {
        Log::info('SyncSalesJob started', ['marketplace' => $this->marketplace]);

        // Берём последний completed sync_log на каждую интеграцию (дедупликация)
        $syncLogs = SyncLog::query()
            ->when($this->marketplace, fn($q) => $q->where('marketplace', $this->marketplace))
            ->whereNotNull('credentials')
            ->where('status', 'completed')
            ->orderByDesc('created_at')
            ->get()
            ->unique('integration_id');

        $processed = 0;
        foreach ($syncLogs as $syncLog) {
            try {
                $this->syncMarketplaceSales($syncLog);
                $processed++;
                // Пауза между интеграциями чтобы не получать 429
                if ($processed < $syncLogs->count()) {
                    sleep(1);
                }
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
        $marketplace   = $syncLog->marketplace;
        $integrationId = $syncLog->integration_id;
        // User-токен сохранён в credentials предыдущего sync'а (IntegrationController::sync).
        // Если его нет (например, cron AutoSync) — activity не отправляем.
        $userToken     = $syncLog->credentials['_sellico_token'] ?? null;

        try {
            $integration = Integration::find($integrationId);
            $service     = MarketplaceFactory::create($marketplace, $syncLog->credentials, $integration);
        } catch (\Exception $e) {
            Log::warning("SyncSalesJob: Не удалось создать сервис для {$marketplace}");
            return;
        }

        // Для Ozon: разбивка продаж по складам через /v1/analytics/data (dimension: sku + warehouse_id)
        if ($marketplace === 'ozon' && method_exists($service, 'getSalesBySkuAndWarehouse')) {
            $this->syncOzonSalesByWarehouse($service, $integrationId, $userToken);
            return;
        }

        // Для WB и других: общие продажи по SKU (API не даёт разбивку по складам)
        if (!method_exists($service, 'getSalesBySku')) {
            Log::info("SyncSalesJob: {$marketplace} не поддерживает getSalesBySku");
            return;
        }

        $salesData = $service->getSalesBySku(28);

        if (empty($salesData)) {
            Log::info("SyncSalesJob: Нет данных о продажах для {$marketplace}");
            return;
        }

        Log::info("SyncSalesJob: Обработка {$marketplace}", ['skus_count' => count($salesData)]);

        $updatedProducts   = 0;
        $updatedWarehouses = 0;

        foreach ($salesData as $sku => $sales) {
            if (!$sku) {
                continue;
            }

            $sales30       = $sales['sales_30_days'] ?? $sales['sales_28_days'] ?? 0;
            $avgDailySales = $sales['avg_daily_sales'] ?? 0;

            $product = Product::where('sku', $sku)
                ->where('marketplace', $marketplace)
                ->where('integration_id', $integrationId)
                ->first();

            if ($product) {
                $product->sales_28_days    = $sales30;
                $product->avg_daily_sales  = $avgDailySales;
                $stock = $product->stock ?? 0;
                $product->turnover_days    = $avgDailySales > 0
                    ? round($stock / $avgDailySales, 1)
                    : ($stock > 0 ? null : 0);
                $product->save();
                $updatedProducts++;
            }

            $warehouses    = InventoryWarehouse::where('sku', $sku)
                ->where('marketplace', $marketplace)
                ->where('integration_id', $integrationId)
                ->get();
            $totalQuantity = $warehouses->sum('quantity');

            foreach ($warehouses as $warehouse) {
                $warehouse->sales_7_days        = $sales['sales_7_days'] ?? 0;
                $warehouse->sales_14_days       = $sales['sales_14_days'] ?? 0;
                $warehouse->sales_30_days       = $sales30;
                $warehouse->sales_28_days       = $sales30;
                $warehouse->average_daily_sales = $avgDailySales;

                if ($avgDailySales > 0) {
                    $warehouse->days_of_stock = (int) round($totalQuantity / $avgDailySales);
                    $warehouse->turnover_days = round($totalQuantity / $avgDailySales, 1);
                } else {
                    $warehouse->days_of_stock = $totalQuantity > 0 ? 999 : 0;
                    $warehouse->turnover_days = $totalQuantity > 0 ? 999 : null;
                }

                if (method_exists($warehouse, 'calculateStockStatus')) {
                    $warehouse->stock_status = $warehouse->calculateStockStatus();
                }

                $warehouse->save();
                $updatedWarehouses++;
            }
        }

        Log::info("SyncSalesJob: {$marketplace} завершено", [
            'updated_products'   => $updatedProducts,
            'updated_warehouses' => $updatedWarehouses,
        ]);

        if ($integrationId) {
            ActivityLogger::forIntegration(
                (int) $integrationId,
                'sales_sync_completed',
                'Синхронизация продаж завершена',
                "Маркетплейс: {$marketplace}. Обновлено товаров: {$updatedProducts}, складов: {$updatedWarehouses}",
                [
                    'entity_type' => 'integration',
                    'entity_id' => $integrationId,
                    'marketplace' => $marketplace,
                    'sync_type' => 'sales',
                    'updated_products' => $updatedProducts,
                    'updated_warehouses' => $updatedWarehouses,
                ],
                $userToken,
            );
        }
    }

    /**
     * Синхронизация продаж Ozon: FBO + FBS по складам.
     */
    private function syncOzonSalesByWarehouse($service, int $integrationId, ?string $userToken = null): void
    {
        $fboSales = method_exists($service, 'getSalesBySkuAndWarehouse')
            ? $service->getSalesBySkuAndWarehouse(28)
            : [];

        $fbsSales = method_exists($service, 'getSalesBySkuAndWarehouseFbs')
            ? $service->getSalesBySkuAndWarehouseFbs(28)
            : [];

        if (empty($fboSales) && empty($fbsSales)) {
            Log::info('SyncSalesJob: Ozon — нет данных по складам, fallback');
            $this->syncMarketplaceSalesFallback($service, 'ozon', $integrationId);
            return;
        }

        Log::info('SyncSalesJob: Ozon продажи по складам', [
            'fbo_skus' => count($fboSales),
            'fbs_skus' => count($fbsSales),
        ]);

        $updatedProducts   = 0;
        $updatedWarehouses = 0;

        // Обновляем FBO склады
        if (!empty($fboSales)) {
            $this->applyOzonWarehouseSales($fboSales, 'FBO', $integrationId, $updatedProducts, $updatedWarehouses);
        }

        // Обновляем FBS склады
        if (!empty($fbsSales)) {
            $this->applyOzonWarehouseSales($fbsSales, 'FBS', $integrationId, $updatedProducts, $updatedWarehouses);
        }

        Log::info('SyncSalesJob: Ozon продажи завершено', [
            'updated_products'   => $updatedProducts,
            'updated_warehouses' => $updatedWarehouses,
        ]);

        ActivityLogger::forIntegration(
            $integrationId,
            'sales_sync_completed',
            'Синхронизация продаж Ozon завершена',
            "Обновлено товаров: {$updatedProducts}, складов: {$updatedWarehouses} (FBO + FBS)",
            [
                'entity_type' => 'integration',
                'entity_id' => $integrationId,
                'marketplace' => 'ozon',
                'sync_type' => 'sales',
                'updated_products' => $updatedProducts,
                'updated_warehouses' => $updatedWarehouses,
            ],
            $userToken,
        );
    }

    /**
     * Применяет данные о продажах к складам указанного типа (FBO или FBS).
     */
    private function applyOzonWarehouseSales(array $byWarehouse, string $fulfillmentType, int $integrationId, int &$updatedProducts, int &$updatedWarehouses): void
    {
        foreach ($byWarehouse as $sku => $warehouseSales) {
            $totalSales30  = 0;
            $totalAvgDaily = 0;
            foreach ($warehouseSales as $wData) {
                $totalSales30  += $wData['sales_30_days'] ?? 0;
                $totalAvgDaily += $wData['avg_daily_sales'] ?? 0;
            }

            // Обновляем Product суммарными продажами (только если FBO — основная схема, или нет FBO данных)
            if ($fulfillmentType === 'FBO') {
                $product = Product::where('sku', $sku)
                    ->where('marketplace', 'ozon')
                    ->where('integration_id', $integrationId)
                    ->first();

                if ($product) {
                    $product->sales_28_days   = $totalSales30;
                    $product->avg_daily_sales = round($totalAvgDaily, 2);
                    $stock = $product->stock ?? 0;
                    $product->turnover_days   = $totalAvgDaily > 0
                        ? round($stock / $totalAvgDaily, 1)
                        : ($stock > 0 ? null : 0);
                    $product->save();
                    $updatedProducts++;
                }
            }

            // Строим индекс продаж по нормализованному имени склада
            $salesByNormalizedName = [];
            foreach ($warehouseSales as $apiWhId => $wData) {
                $normalizedName = self::normalizeWarehouseName($wData['warehouse_name'] ?? '');
                if ($normalizedName) {
                    $salesByNormalizedName[$normalizedName] = $wData;
                }
                // Также индексируем по warehouse_id для точного совпадения
                $salesByNormalizedName[$apiWhId] = $wData;
            }

            // Обновляем только склады нужного типа (FBO или FBS)
            $warehouses = InventoryWarehouse::where('sku', $sku)
                ->where('marketplace', 'ozon')
                ->where('integration_id', $integrationId)
                ->where(function ($q) use ($fulfillmentType) {
                    $q->where('fulfillment_type', $fulfillmentType)
                      ->orWhere('fulfillment_type', strtolower($fulfillmentType));
                })
                ->get();

            foreach ($warehouses as $warehouse) {
                $dbWhNormalized = self::normalizeWarehouseName($warehouse->warehouse_name ?? '');
                $whData = $salesByNormalizedName[$dbWhNormalized]
                       ?? $salesByNormalizedName[$warehouse->warehouse_id]
                       ?? null;

                if ($whData) {
                    $whAvgDaily                     = $whData['avg_daily_sales'] ?? 0;
                    $warehouse->sales_7_days        = $whData['sales_7_days'] ?? 0;
                    $warehouse->sales_14_days       = $whData['sales_14_days'] ?? 0;
                    $warehouse->sales_30_days       = $whData['sales_30_days'] ?? 0;
                    $warehouse->sales_28_days       = $whData['sales_30_days'] ?? 0;
                    $warehouse->average_daily_sales = $whAvgDaily;

                    $whQty = $warehouse->quantity ?? 0;
                    if ($whAvgDaily > 0) {
                        $warehouse->days_of_stock = (int) round($whQty / $whAvgDaily);
                        $warehouse->turnover_days = round($whQty / $whAvgDaily, 1);
                    } else {
                        $warehouse->days_of_stock = $whQty > 0 ? 999 : 0;
                        $warehouse->turnover_days = $whQty > 0 ? 999 : null;
                    }
                } else {
                    $warehouse->sales_7_days        = 0;
                    $warehouse->sales_14_days       = 0;
                    $warehouse->sales_30_days       = 0;
                    $warehouse->sales_28_days       = 0;
                    $warehouse->average_daily_sales = 0;
                    $warehouse->days_of_stock       = $warehouse->quantity > 0 ? 999 : 0;
                    $warehouse->turnover_days       = $warehouse->quantity > 0 ? 999 : null;
                }

                if (method_exists($warehouse, 'calculateStockStatus')) {
                    $warehouse->stock_status = $warehouse->calculateStockStatus();
                }
                $warehouse->save();
                $updatedWarehouses++;
            }
        }
    }

    /**
     * Нормализует название склада для сопоставления:
     * убирает пробелы, приводит к верхнему регистру, убирает знаки препинания.
     * Пример: "Ростов-на-Дону РФЦ" -> "РОСТОВНАДОНУРФЦ"
     */
    private static function normalizeWarehouseName(string $name): string
    {
        $name = mb_strtoupper($name, 'UTF-8');
        $name = preg_replace('/[^А-ЯЁA-Z0-9]/u', '', $name);
        return $name;
    }

    /**
     * Запасной вариант для Ozon: общие продажи по SKU (если разбивка по складам недоступна)
     */
    private function syncMarketplaceSalesFallback($service, string $marketplace, int $integrationId): void
    {
        if (!method_exists($service, 'getSalesBySku')) {
            return;
        }

        $salesData = $service->getSalesBySku(28);
        if (empty($salesData)) {
            return;
        }

        foreach ($salesData as $sku => $sales) {
            if (!$sku) continue;

            $sales30       = $sales['sales_30_days'] ?? 0;
            $avgDailySales = $sales['avg_daily_sales'] ?? 0;

            $product = Product::where('sku', $sku)
                ->where('marketplace', $marketplace)
                ->where('integration_id', $integrationId)
                ->first();

            if ($product) {
                $product->sales_28_days   = $sales30;
                $product->avg_daily_sales = $avgDailySales;
                $stock = $product->stock ?? 0;
                $product->turnover_days   = $avgDailySales > 0
                    ? round($stock / $avgDailySales, 1)
                    : ($stock > 0 ? null : 0);
                $product->save();
            }

            $warehouses    = InventoryWarehouse::where('sku', $sku)
                ->where('marketplace', $marketplace)
                ->where('integration_id', $integrationId)
                ->get();
            $totalQuantity = $warehouses->sum('quantity');

            foreach ($warehouses as $warehouse) {
                $warehouse->sales_7_days        = $sales['sales_7_days'] ?? 0;
                $warehouse->sales_14_days       = $sales['sales_14_days'] ?? 0;
                $warehouse->sales_30_days       = $sales30;
                $warehouse->sales_28_days       = $sales30;
                $warehouse->average_daily_sales = $avgDailySales;

                if ($avgDailySales > 0) {
                    $warehouse->days_of_stock = (int) round($totalQuantity / $avgDailySales);
                    $warehouse->turnover_days = round($totalQuantity / $avgDailySales, 1);
                } else {
                    $warehouse->days_of_stock = $totalQuantity > 0 ? 999 : 0;
                    $warehouse->turnover_days = $totalQuantity > 0 ? 999 : null;
                }

                if (method_exists($warehouse, 'calculateStockStatus')) {
                    $warehouse->stock_status = $warehouse->calculateStockStatus();
                }

                $warehouse->save();
            }
        }

        Log::info("SyncSalesJob: {$marketplace} fallback завершён", ['skus' => count($salesData)]);
    }
}
