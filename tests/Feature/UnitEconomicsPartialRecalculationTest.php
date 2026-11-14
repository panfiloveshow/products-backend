<?php

namespace Tests\Feature;

use App\Domains\UnitEconomics\UnitEconomicsOrchestrator;
use App\Models\Integration;
use App\Models\Product;
use App\Models\UnitEconomicsCache;
use App\Services\UnitEconomicsCacheService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use Tests\TestCase;

class UnitEconomicsPartialRecalculationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_failed_sku_keeps_its_previous_complete_set_of_schemes_while_healthy_sku_updates(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 62002]);
        $broken = Product::factory()->wildberries()->create(['integration_id' => $integration->id, 'sku' => 'BROKEN']);
        $healthy = Product::factory()->wildberries()->create(['integration_id' => $integration->id, 'sku' => 'HEALTHY']);
        $schemes = ['FBO', 'FBS', 'DBS', 'EDBS', 'DBW'];
        foreach ([$broken, $healthy] as $product) {
            foreach ($schemes as $scheme) {
                $this->cacheRow($product, $scheme, 1000)->forceFill(['updated_at' => now()->subHour()])->save();
            }
        }

        $service = Mockery::mock(UnitEconomicsCacheService::class, [Mockery::mock(UnitEconomicsOrchestrator::class)])->makePartial();
        $service->shouldReceive('calculateAndCache')->andReturnUsing(function (Product $product, string $scheme): UnitEconomicsCache {
            if ($product->sku === 'BROKEN' && $scheme === 'FBS') {
                throw new \RuntimeException('Unavailable calculation input');
            }

            return $this->cacheRow($product, $scheme, 1200);
        });

        $result = $service->recalculateIntegration($integration->id);

        $this->assertSame(1, $result['success']);
        $this->assertSame(1, $result['errors']);
        $this->assertSame('partial', $result['status']);
        $this->assertSame(['BROKEN'], $result['failed_skus']);
        $this->assertSame(5, UnitEconomicsCache::where('sku', 'BROKEN')->count());
        $this->assertSame(['1000.00'], UnitEconomicsCache::where('sku', 'BROKEN')->pluck('price')->unique()->values()->all());
        $this->assertSame(['1200.00'], UnitEconomicsCache::where('sku', 'HEALTHY')->pluck('price')->unique()->values()->all());
        foreach ($schemes as $scheme) {
            $this->assertSame(1, $result['schemes'][$scheme]);
        }
    }

    private function cacheRow(Product $product, string $scheme, float $price): UnitEconomicsCache
    {
        return UnitEconomicsCache::updateOrCreate([
            'integration_id' => $product->integration_id,
            'sku' => $product->sku,
            'marketplace' => 'wildberries',
            'fulfillment_type' => $scheme,
        ], [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'price' => $price,
            'calculated_at' => now(),
        ]);
    }
}
