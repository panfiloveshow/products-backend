<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Product;
use App\Services\UnitEconomics\MarketplacePriceObservation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class MarketplacePriceObservationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_disabling_the_feature_does_not_freeze_prices_in_the_legacy_fallback(): void
    {
        config(['unit_economics_sync.enabled' => false, 'unit_economics_sync.integration_ids' => []]);
        $integration = Integration::factory()->wildberries()->create(['id' => 62028]);
        $product = Product::factory()->wildberries()->create(['integration_id' => $integration->id, 'price' => 800,
            'wb_data' => ['nmID' => 123, 'price_version' => 5, 'price_observed_at' => '2026-11-02T13:00:00+00:00']]);
        app(MarketplacePriceObservation::class)->apply($integration, ['123' => ['price' => 1000,
            'final_price' => 900, 'price_observed_at' => '2026-11-02T14:00:00+00:00']], 0);
        $this->assertEquals(900, $product->fresh()->price);
        $this->assertSame(5, $product->fresh()->wb_data['price_version']);
    }

    public function test_unchanged_price_advances_observation_but_keeps_change_time_and_existing_metadata(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 62012]);
        $product = Product::factory()->wildberries()->create(['integration_id' => $integration->id,
            'price' => 800, 'old_price' => 1000, 'wb_data' => ['nmID' => 123, 'sizeID' => 456,
                'stock_warehouses' => [['id' => 7]], 'price_changed_at' => '2026-10-01T00:00:00+00:00']]);
        $writer = app(MarketplacePriceObservation::class);
        $result = $writer->apply($integration, ['123:456' => ['price' => 1000, 'final_price' => 800,
            'price_observed_at' => '2026-11-02T13:00:00+00:00']], 1);
        $data = $product->fresh()->wb_data;

        $this->assertSame(1, $result['unchanged']);
        $this->assertSame(1, $data['price_version']);
        $this->assertSame('2026-11-02T13:00:00+00:00', $data['price_observed_at']);
        $this->assertSame('2026-10-01T00:00:00+00:00', $data['price_changed_at']);
        $this->assertSame([['id' => 7]], $data['stock_warehouses']);
        $writer->apply($integration, ['123:456' => ['price' => 1000, 'final_price' => 700,
            'price_observed_at' => '2026-11-02T14:00:00+00:00']], 2);
        $this->assertEquals(700, $product->fresh()->price);
        $this->assertSame('2026-11-02T14:00:00+00:00', $product->fresh()->wb_data['price_changed_at']);
    }

    public function test_missing_and_older_observations_never_replace_confirmed_ozon_price(): void
    {
        $integration = Integration::factory()->ozon()->create(['id' => 62013]);
        $product = Product::factory()->ozon()->create(['integration_id' => $integration->id, 'sku' => 'A',
            'price' => 850, 'ozon_data' => ['actual_price' => 850, 'price_version' => 2,
                'price_observed_at' => '2026-11-02T14:00:00+00:00']]);
        $writer = app(MarketplacePriceObservation::class);
        $missing = $writer->apply($integration, [], 3);
        $stale = $writer->apply($integration, ['A' => ['price' => 1000, 'actual_price' => 600,
            'price_observed_at' => '2026-11-02T13:00:00+00:00']], 1);

        $this->assertSame(1, $missing['missing']);
        $this->assertSame('price_not_returned', $product->fresh()->ozon_data['price_sync_error_code']);
        $this->assertSame(1, $stale['stale']);
        $this->assertEquals(850, $product->fresh()->price);
        $this->assertSame(2, $product->fresh()->ozon_data['price_version']);
        $writer->apply($integration, ['A' => ['price' => 1000, 'actual_price' => 850,
            'price_observed_at' => '2026-11-02T15:00:00+00:00']], 3);
        $this->assertNull($product->fresh()->ozon_data['price_sync_error_code']);
    }
}
