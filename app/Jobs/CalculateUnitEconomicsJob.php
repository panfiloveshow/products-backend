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
            $query->where('marketplace', $this->marketplace);
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
        $salesCount = $product->inventoryWarehouses()
            ->sum('average_daily_sales') * 30;

        $marketplaceData = $this->getMarketplaceDefaults($product->marketplace);

        $data = array_merge([
            'sku' => $product->sku,
            'product_name' => $product->name,
            'marketplace' => $this->normalizeMarketplace($product->marketplace),
            'price' => $product->price ?? 0,
            'cost_price' => $costPrice,
            'sales_count' => max(1, (int) $salesCount),
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
            ],
            default => [],
        };
    }
}
