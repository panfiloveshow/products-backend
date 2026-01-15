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

    public function __construct(?string $marketplace = null, int $days = 30)
    {
        $this->marketplace = $marketplace;
        $this->days = $days;
    }

    public function handle(): void
    {
        Log::info('SyncStorageCostJob started', [
            'marketplace' => $this->marketplace,
            'days' => $this->days,
        ]);

        $syncLogs = SyncLog::query()
            ->when($this->marketplace, fn($q) => $q->where('marketplace', $this->marketplace))
            ->whereNotNull('credentials')
            ->get();

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

        try {
            $integration = Integration::find($integrationId);
            $service = MarketplaceFactory::create($marketplace, $syncLog->credentials, $integration);
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
        $placementData = $service->getPlacementCostByProducts($dateFrom, $dateTo, 120);

        if (empty($placementData)) {
            Log::info("SyncStorageCostJob: No placement data for {$marketplace}");
            return;
        }

        Log::info("SyncStorageCostJob: Processing {$marketplace}", [
            'skus_count' => count($placementData),
        ]);

        $updatedProducts = 0;
        $totalStorageCost = 0;

        foreach ($placementData as $sku => $data) {
            $storageCost = $data['placement_cost'] ?? 0;
            $totalStorageCost += $storageCost;

            // Обновляем Product
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
        }

        Log::info("SyncStorageCostJob: {$marketplace} completed", [
            'updated_products' => $updatedProducts,
            'total_storage_cost' => round($totalStorageCost, 2),
            'period_days' => $this->days,
        ]);
    }
}
