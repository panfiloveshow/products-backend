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

/**
 * Job для синхронизации стоимости хранения с маркетплейсов
 * Использует /v3/finance/transaction/list для получения фактических начислений за хранение
 */
class SyncStorageCostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    private ?string $marketplace;
    private int $days;
    private ?int $integrationId;
    private int $maxWaitSeconds;

    public function __construct(?string $marketplace = null, int $days = 30, ?int $integrationId = null, int $maxWaitSeconds = 120)
    {
        $this->marketplace = $marketplace;
        $this->days = $days;
        $this->integrationId = $integrationId;
        $this->maxWaitSeconds = max(15, min($maxWaitSeconds, 180));
    }

    public function handle(): void
    {
        Log::info('SyncStorageCostJob started', [
            'marketplace' => $this->marketplace,
            'days' => $this->days,
            'integration_id' => $this->integrationId,
            'max_wait_seconds' => $this->maxWaitSeconds,
        ]);

        $syncLogs = SyncLog::query()
            ->when($this->marketplace, fn($q) => $q->where('marketplace', $this->marketplace))
            ->when($this->integrationId, fn($q) => $q->where('integration_id', $this->integrationId))
            ->whereNotNull('credentials')
            ->latest()
            ->get();

        $syncLogs = $syncLogs->unique('integration_id')->values();

        foreach ($syncLogs as $syncLog) {
            try {
                $this->syncMarketplaceStorageCost($syncLog);
            } catch (\Exception $e) {
                Log::error('SyncStorageCostJob error', [
                    'marketplace' => $syncLog->marketplace,
                    'integration_id' => $syncLog->integration_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('SyncStorageCostJob completed');
    }

    private function syncMarketplaceStorageCost(SyncLog $syncLog): void
    {
        $marketplace = $syncLog->marketplace;
        $integrationId = $syncLog->integration_id;
        $credentials = (array) ($syncLog->credentials ?? []);

        if ($marketplace === 'ozon' && (empty($credentials['client_id']) || empty($credentials['api_key']))) {
            Log::warning('SyncStorageCostJob: skip Ozon integration without credentials', [
                'integration_id' => $integrationId,
                'has_client_id' => ! empty($credentials['client_id']),
                'has_api_key' => ! empty($credentials['api_key']),
            ]);

            return;
        }

        try {
            $integration = Integration::find($integrationId);
            $service = MarketplaceFactory::create($marketplace, $credentials, $integration);
        } catch (\Exception $e) {
            Log::warning("SyncStorageCostJob: Cannot create service for {$marketplace}");
            return;
        }

        // Проверяем поддержку метода getPlacementCostByProducts (новый API отчётов)
        if (!method_exists($service, 'getPlacementCostByProducts')) {
            Log::info("SyncStorageCostJob: {$marketplace} does not support getPlacementCostByProducts");
            return;
        }

        // Календарные окна — ровно те, что подписаны в UI: «текущий месяц» (1-е — сегодня)
        // и «прошлый месяц» целиком. Раньше писалось скользящее окно N дней в поле
        // prev_month, текущий месяц не заполнял никто, а min/max отчётных дат разных SKU
        // склеивались в лейбл двухмесячного «периода».
        $currentFrom = now()->startOfMonth()->format('Y-m-d');
        $currentTo = now()->format('Y-m-d');
        $prevFrom = now()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d');
        $prevTo = now()->subMonthNoOverflow()->endOfMonth()->format('Y-m-d');

        $currentData = $service->getPlacementCostByProducts($currentFrom, $currentTo, $this->maxWaitSeconds);
        $prevData = $service->getPlacementCostByProducts($prevFrom, $prevTo, $this->maxWaitSeconds);

        if (empty($currentData) && empty($prevData)) {
            Log::info("SyncStorageCostJob: No placement data for {$marketplace}");
            return;
        }

        $skus = array_values(array_unique(array_merge(array_keys($currentData), array_keys($prevData))));

        Log::info("SyncStorageCostJob: Processing {$marketplace}", [
            'skus_count' => count($skus),
            'current_period' => "$currentFrom..$currentTo",
            'prev_period' => "$prevFrom..$prevTo",
        ]);

        $updatedProducts = 0;
        $updatedWarehouses = 0;
        $totalCurrentCost = 0.0;
        $totalPrevCost = 0.0;

        foreach ($skus as $sku) {
            $currentCost = (float) ($currentData[$sku]['placement_cost'] ?? 0);
            $prevCost = (float) ($prevData[$sku]['placement_cost'] ?? 0);
            $totalCurrentCost += $currentCost;
            $totalPrevCost += $prevCost;

            // 1) Product.storage_cost — полный прошлый месяц (стабильная величина для
            //    юнит-экономики; месяц-к-дате в начале месяца занижал бы хранение)
            $product = Product::where('sku', $sku)
                ->where('marketplace', $marketplace)
                ->where('integration_id', $integrationId)
                ->first();

            if ($product) {
                $product->storage_cost = $prevCost > 0 ? $prevCost : $currentCost;
                $product->storage_cost_updated_at = now();
                $product->save();
                $updatedProducts++;
            }

            // 2) inventory_warehouses: сумма SKU уходит в запись с максимальным
            //    quantity, остальные склады обнуляем — SUM по SKU остаётся точным.
            $warehouses = InventoryWarehouse::where('sku', $sku)
                ->where('marketplace', $marketplace)
                ->where('integration_id', $integrationId)
                ->orderByDesc('quantity')
                ->get();

            $isPrimary = true;
            foreach ($warehouses as $w) {
                $w->storage_fee_total = $isPrimary ? round($currentCost, 2) : 0;
                $w->storage_fee_prev_month = $isPrimary ? round($prevCost, 2) : 0;
                $w->storage_fee_report_from = $currentFrom;
                $w->storage_fee_report_to = $currentTo;
                $w->storage_fee_prev_month_period = $prevTo;
                $w->save();
                $updatedWarehouses++;
                $isPrimary = false;
            }
        }

        // SKU интеграции без расходов хранения в обоих периодах: обнуляем суммы и
        // выравниваем даты, чтобы старые отчётные окна не склеивали период в UI.
        $clearedRows = InventoryWarehouse::where('marketplace', $marketplace)
            ->where('integration_id', $integrationId)
            ->whereNotIn('sku', $skus)
            ->update([
                'storage_fee_total' => 0,
                'storage_fee_prev_month' => 0,
                'storage_fee_report_from' => $currentFrom,
                'storage_fee_report_to' => $currentTo,
                'storage_fee_prev_month_period' => $prevTo,
            ]);

        Log::info("SyncStorageCostJob: {$marketplace} completed", [
            'integration_id' => $integrationId,
            'updated_products' => $updatedProducts,
            'updated_warehouses' => $updatedWarehouses,
            'cleared_rows' => $clearedRows,
            'total_current_cost' => round($totalCurrentCost, 2),
            'total_prev_cost' => round($totalPrevCost, 2),
        ]);
    }
}
