<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\UnitEconomics;
use App\Services\UnitEconomicsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CalculateUnitEconomicsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800;

    public function __construct(
        private ?string $marketplace = null
    ) {}

    public function handle(UnitEconomicsService $service): void
    {
        Log::info("Starting unit economics calculation", [
            'marketplace' => $this->marketplace ?? 'all',
        ]);

        $query = Product::query();

        if ($this->marketplace) {
            // BUG FIX: нормализуем marketplace — товары хранятся как 'yandex_market',
            // но вызов может приходить с 'yandex'
            if (in_array($this->marketplace, ['yandex', 'yandex_market'], true)) {
                $query->whereIn('marketplace', ['yandex', 'yandex_market']);
            } else {
                $query->where('marketplace', $this->marketplace);
            }
        }

        $products = $query->get();
        $processed = 0;
        $failed = 0;

        foreach ($products as $product) {
            try {
                $this->calculateForProduct($product, $service);
                $processed++;
            } catch (\Exception $e) {
                $failed++;
                Log::error("Failed to calculate unit economics", [
                    'sku' => $product->sku,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info("Unit economics calculation completed", [
            'processed' => $processed,
            'failed' => $failed,
        ]);
    }

    private function calculateForProduct(Product $product, UnitEconomicsService $service): void
    {
        $existingUE = UnitEconomics::where('sku', $product->sku)
            ->where('marketplace', $this->normalizeMarketplace($product->marketplace))
            ->latest()
            ->first();

        $costPrice = $existingUE?->cost_price ?? ($product->price * 0.4);

        // BUG FIX: фильтруем inventory_warehouses по marketplace чтобы не смешивать данные разных МП
        $normalizedMp = $this->normalizeMarketplace($product->marketplace);
        $inventoryQuery = $product->inventoryWarehouses();
        if (in_array($product->marketplace, ['yandex', 'yandex_market'], true)) {
            $inventoryQuery->whereIn('marketplace', ['yandex', 'yandex_market']);
        } else {
            $inventoryQuery->where('marketplace', $product->marketplace);
        }
        if ($product->integration_id) {
            $inventoryQuery->where('integration_id', $product->integration_id);
        }
        $salesCount = $inventoryQuery->sum('average_daily_sales') * 30;

        $marketplaceData = $this->getMarketplaceDefaults($product->marketplace);

        // Для Yandex: определяем fulfillment_type из yandex_data если есть
        if (in_array($product->marketplace, ['yandex', 'yandex_market'], true)) {
            $yandexData = $product->yandex_data ?? [];
            $processingState = $yandexData['processingState'] ?? null;
            if ($processingState) {
                // processingState может содержать информацию о схеме работы
                if (stripos($processingState, 'fbs') !== false) {
                    $marketplaceData['fulfillment_type'] = 'FBS';
                } elseif (stripos($processingState, 'dbs') !== false) {
                    $marketplaceData['fulfillment_type'] = 'DBS';
                } else {
                    $marketplaceData['fulfillment_type'] = 'FBY';
                }
            }
            
            // Берем fulfillment_type из inventory если есть (фильтруем по marketplace/integration)
            $invForType = $product->inventoryWarehouses()
                ->whereIn('marketplace', ['yandex', 'yandex_market'])
                ->when($product->integration_id, fn ($q) => $q->where('integration_id', $product->integration_id))
                ->first();
            if ($invForType && $invForType->fulfillment_type) {
                $marketplaceData['fulfillment_type'] = $invForType->fulfillment_type;
            }
        }

        $data = array_merge([
            'sku' => $product->sku,
            'product_name' => $product->name,
            'marketplace' => $this->normalizeMarketplace($product->marketplace),
            'integration_id' => $product->integration_id,
            'price' => $product->price ?? 0,
            'cost_price' => $costPrice,
            'sales_count' => max(1, (int) $salesCount),
            'is_actual_scheme' => true,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
        ], $marketplaceData);

        $service->createOrUpdate($data);
    }

    private function normalizeMarketplace(string $marketplace): string
    {
        return match ($marketplace) {
            'yandex' => 'yandex_market',
            default => $marketplace,
        };
    }

    private function getMarketplaceDefaults(string $marketplace): array
    {
        return match ($marketplace) {
            'wildberries' => [
                'wb_commission_percent' => 15,
                'volume_liters' => 0.5,
                'storage_tariff' => 0.5,
                'storage_days' => 30,
                'logistics_cost' => 50,
            ],
            'ozon' => [
                'fbo_commission_percent' => 15,
                'fbs_commission_percent' => 12,
                'last_mile_cost' => 40,
                'acquiring_percent' => 1.5,
                'fulfillment_type' => 'FBO',
            ],
            'yandex', 'yandex_market' => [
                'referral_fee_percent' => 5,
                'fby_delivery' => 50,
                'fbs_delivery' => 40,
                'acquiring_percent' => 1.5,
                'storage_cost' => 0,
                'fulfillment_type' => 'FBY', // Default to FBY for Yandex
            ],
            default => [],
        };
    }
}
