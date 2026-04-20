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

        $dateFrom = now()->subDays($this->days)->format('Y-m-d');
        $dateTo = now()->format('Y-m-d');

        // Получаем данные о стоимости размещения из отчёта (ключ = offer_id/артикул)
        $placementData = $service->getPlacementCostByProducts($dateFrom, $dateTo, $this->maxWaitSeconds);

        if (empty($placementData)) {
            Log::info("SyncStorageCostJob: No placement data for {$marketplace}");
            return;
        }

        Log::info("SyncStorageCostJob: Processing {$marketplace}", [
            'skus_count' => count($placementData),
        ]);

        $updatedProducts = 0;
        $updatedWarehouses = 0;
        $totalStorageCost = 0;

        foreach ($placementData as $sku => $data) {
            $storageCost = (float) ($data['placement_cost'] ?? 0);
            $totalStorageCost += $storageCost;

            // 1) Обновляем Product (обратная совместимость)
            $product = Product::where('sku', $sku)
                ->where('marketplace', $marketplace)
                ->where('integration_id', $integrationId)
                ->first();

            if ($product) {
                $product->storage_cost = $storageCost;
                $product->storage_cost_updated_at = now();
                $product->save();
                $updatedProducts++;
            }

            // 2) Обновляем inventory_warehouses: общая сумма уходит в запись с
            //    максимальным quantity, остальные склады обнуляем — SUM по SKU
            //    в Excel-экспорте даст точное число платного хранения.
            $warehouses = InventoryWarehouse::where('sku', $sku)
                ->where('marketplace', $marketplace)
                ->where('integration_id', $integrationId)
                ->orderByDesc('quantity')
                ->get();

            $isPrimary = true;
            foreach ($warehouses as $w) {
                $w->storage_fee_prev_month = $isPrimary ? round($storageCost, 2) : 0;
                $w->storage_fee_report_from = $dateFrom;
                $w->storage_fee_report_to = $dateTo;
                $w->save();
                $updatedWarehouses++;
                $isPrimary = false;
            }
        }

        Log::info("SyncStorageCostJob: {$marketplace} completed", [
            'integration_id' => $integrationId,
            'updated_products' => $updatedProducts,
            'updated_warehouses' => $updatedWarehouses,
            'total_storage_cost' => round($totalStorageCost, 2),
            'period_days' => $this->days,
        ]);
    }
}
