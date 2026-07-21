<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Product;
use App\Models\UnitEconomics;
use App\Models\UnitEconomicsCache;
use App\Services\UnitEconomics\UnitEconomicsPriceIntegrityService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class UnitEconomicsPriceIntegrityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_detects_cache_price_drift_and_becomes_healthy_after_correction(): void
    {
        $integration = Integration::create([
            'id' => 901,
            'name' => 'Ozon integrity test',
            'marketplace' => 'ozon',
            'credentials' => ['client_id' => 'test', 'api_key' => 'test'],
            'is_active' => true,
        ]);
        $product = Product::create([
            'sku' => 'PRICE-INTEGRITY-1',
            'name' => 'Integrity product',
            'marketplace' => 'ozon',
            'integration_id' => $integration->id,
            'price' => 1700,
            'ozon_data' => [
                'actual_price' => 1700,
                'marketing_seller_price' => 1700,
                'price_observed_at' => now()->utc()->toIso8601String(),
            ],
        ]);

        foreach (['FBO', 'FBS', 'RFBS', 'EXPRESS'] as $scheme) {
            UnitEconomics::create([
                'integration_id' => $integration->id,
                'sku' => $product->sku,
                'marketplace' => 'ozon',
                'fulfillment_type' => $scheme,
                'price' => 1700,
                'marketplace_data' => [
                    'actual_price' => 1700,
                    'price_observed_at' => now()->subMinute()->utc()->toIso8601String(),
                ],
            ]);
            UnitEconomicsCache::create([
                'integration_id' => $integration->id,
                'product_id' => $product->id,
                'sku' => $product->sku,
                'product_name' => $product->name,
                'marketplace' => 'ozon',
                'fulfillment_type' => $scheme,
                'price' => $scheme === 'FBO' ? 2000 : 1700,
            ]);
        }

        $service = $this->app->make(UnitEconomicsPriceIntegrityService::class);
        $drifted = $service->inspectIntegration($integration);

        $this->assertFalse($drifted['healthy']);
        $this->assertTrue($drifted['repairable']);
        $this->assertSame(1, $drifted['issue_counts']['price_drift']);
        $this->assertSame(1700.0, collect($drifted['issues'])->firstWhere('type', 'price_drift')['expected']);

        UnitEconomicsCache::where('integration_id', $integration->id)
            ->where('sku', $product->sku)
            ->where('fulfillment_type', 'FBO')
            ->update(['price' => 1700]);

        $healthy = $service->inspectIntegration($integration->fresh());

        $this->assertTrue($healthy['healthy']);
        $this->assertSame([], $healthy['issues']);
    }
}
