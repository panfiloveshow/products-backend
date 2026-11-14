<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Product;
use App\Models\UnitEconomicsCache;
use App\Services\UnitEconomics\UnitEconomicsFreshness;
use App\Services\UnitEconomics\UnitEconomicsSyncCoordinator;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class UnitEconomicsFreshnessTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_recent_cache_with_unknown_price_observation_is_never_reported_fresh(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 62016]);
        $product = Product::factory()->wildberries()->create(['integration_id' => $integration->id, 'sku' => 'A']);
        UnitEconomicsCache::create(['integration_id' => $integration->id, 'sku' => 'A', 'product_id' => $product->id,
            'product_name' => 'A', 'marketplace' => 'wildberries', 'fulfillment_type' => 'FBO', 'price' => 1000]);

        $freshness = app(UnitEconomicsFreshness::class)->forIntegration($integration);

        $this->assertFalse($freshness['overall']['is_fresh']);
        $this->assertSame('unknown', $freshness['status']);
        $this->assertSame('unknown', $freshness['stages']['unit_economics']['status']);
        $this->assertSame(0, $freshness['source']['observed']);
        $this->assertNotSame('green', $freshness['overall']['color']);
    }

    public function test_rate_limit_reports_retry_time_and_persisted_status_without_queue_payload_inspection(): void
    {
        $integration = Integration::factory()->ozon()->create(['id' => 62017]);
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        $run = $coordinator->request($integration);
        $next = now()->addMinutes(15)->utc()->toIso8601String();
        $coordinator->updateStage($run->id, 'prices', 'waiting_external', ['next_retry_at' => $next, 'error_code' => 'rate_limited'], 1);

        $freshness = app(UnitEconomicsFreshness::class)->forIntegration($integration);

        $this->assertSame('waiting_external', $freshness['status']);
        $this->assertSame($next, $freshness['next_retry_at']);
        $this->assertSame('rate_limited', $freshness['stages']['prices']['error_code']);
        $this->assertTrue($freshness['overall']['is_updating']);
        $this->assertFalse($freshness['overall']['is_fresh']);
    }
}
