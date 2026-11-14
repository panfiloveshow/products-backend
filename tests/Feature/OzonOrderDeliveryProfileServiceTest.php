<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Product;
use App\Services\Ozon\OzonOrderDeliveryProfileService;
use App\Services\UnitEconomicsSynchronizer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OzonOrderDeliveryProfileServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_enrichment_of_a_stale_product_instance_keeps_the_latest_confirmed_price_metadata(): void
    {
        $integration = Integration::factory()->ozon()->create(['id' => 62040]);
        $stale = Product::factory()->ozon()->create(['integration_id' => $integration->id, 'sku' => 'RACE', 'price' => 1000,
            'ozon_data' => ['price_version' => 1, 'actual_price' => 1000]]);
        Product::whereKey($stale->id)->update(['price' => 800, 'ozon_data' => ['price_version' => 2,
            'actual_price' => 800, 'price_observed_at' => '2026-11-02T16:00:00+00:00']]);
        $profile = (new OzonOrderDeliveryProfileService)->fromLocality(['total_orders' => 0]);
        $save = new \ReflectionMethod(UnitEconomicsSynchronizer::class, 'persistOzonDeliveryProfile');
        $save->invoke(app(UnitEconomicsSynchronizer::class), $integration->id, $stale, $profile);
        $fresh = $stale->fresh();
        $this->assertSame(2, $fresh->ozon_data['price_version']);
        $this->assertSame(800, $fresh->ozon_data['actual_price']);
        $this->assertEquals(800, $stale->price);
        $this->assertSame('2026-11-02T16:00:00+00:00', $fresh->ozon_data['price_observed_at']);
    }

    public function test_profiles_use_only_recent_orders_of_the_requested_integration_and_clear_obsolete_estimates(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real-order locality aggregation requires PostgreSQL.');
        }
        Integration::factory()->ozon()->create(['id' => 62038]);
        Integration::factory()->ozon()->create(['id' => 62039]);
        foreach ([[62038, 'Москва', 2], [62038, 'Москва', 3], [62038, 'Казань', 4],
            [62038, 'Казань', 40], [62039, 'Казань', 2]] as [$id, $destination, $age]) {
            $posting = (string) Str::uuid();
            $item = (string) Str::uuid();
            DB::table('postings')->insert(['id' => $posting, 'integration_id' => (string) $id,
                'marketplace' => 'ozon', 'posting_number' => $posting, 'status' => 'delivered', 'in_process_at' => now()->subDays($age)]);
            DB::table('posting_items')->insert(['id' => $item, 'posting_id' => $posting, 'sku' => 'A', 'name' => 'A', 'quantity' => 1]);
            DB::table('ozon_order_unit_economics')->insert(['integration_id' => $id, 'posting_id' => $posting,
                'posting_item_id' => $item, 'posting_number' => $posting, 'sku' => 'A', 'order_date' => now()->subDays($age),
                'shipping_cluster_name' => 'Москва', 'destination_cluster_name' => $destination,
                'sale_price' => 1000, 'markup_reason_code' => 'local_cluster']);
        }
        $profiles = app(OzonOrderDeliveryProfileService::class)->forIntegration(62038, 28);
        $this->assertSame(['A'], array_keys($profiles));
        $this->assertSame(66.67, $profiles['A']['expected_locality_rate']);
        $this->assertSame(3, array_sum(array_column($profiles['A']['clusters_summary'], 'orders_count')));
        $product = Product::factory()->ozon()->create(['integration_id' => 62038, 'sku' => 'A', 'price' => 1000,
            'ozon_data' => ['weighted_logistics_cost' => 999, 'weighted_non_local_markup_percent' => 99, 'price_version' => 7]]);
        $save = new \ReflectionMethod(UnitEconomicsSynchronizer::class, 'persistOzonDeliveryProfile');
        $save->invoke(app(UnitEconomicsSynchronizer::class), 62038, $product, $profiles['A']);
        $data = $product->fresh()->ozon_data;
        $this->assertSame('real_orders', $data['profile_source']);
        $this->assertNull($data['weighted_logistics_cost']);
        $this->assertNull($data['weighted_non_local_markup_percent']);
        $this->assertSame(7, $data['price_version']);
    }
}
