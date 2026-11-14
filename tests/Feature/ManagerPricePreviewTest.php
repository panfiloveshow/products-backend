<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Product;
use App\Models\UnitEconomics;
use App\Models\UnitEconomicsCache;
use App\Models\UnitEconomicsSettings;
use App\Services\UnitEconomicsCacheService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ManagerPricePreviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_ozon_rfbs_price_scenario_reselects_the_delivery_price_band_without_writing(): void
    {
        config(['ozon_rfbs_tariffs' => ['enabled' => true, 'version' => 'test-only',
            'price_bands' => [['label' => 'test low', 'max' => 1000], ['label' => 'test high', 'max' => null]],
            'weight_bands' => [['label' => 'test weight', 'max' => null]], 'rates' => [[100], [300]]]]);
        $integration = Integration::factory()->ozon()->create(['id' => 62021, 'settings' => ['rfbs_logistics_tariffs' => ['enabled' => true, 'rates' => [[100], [300]]]]]);
        $product = Product::factory()->ozon()->create(['integration_id' => $integration->id, 'sku' => 'RFBS-PREVIEW-TEST', 'price' => 900, 'old_price' => 900, 'fulfillment_type' => 'RFBS',
            'ozon_data' => ['actual_price' => 900, 'dimensions' => ['depth' => 100, 'width' => 100, 'height' => 100, 'weight' => 1000],
                'commissions_by_scheme' => ['rfbs' => ['percent' => 10]], 'redemption' => ['redemption_rate' => 100, 'source' => 'manual']]]);
        UnitEconomicsSettings::create(['integration_id' => $integration->id, 'sku' => $product->sku, 'cost_price' => 200, 'tax_percent' => 6, 'vat_percent' => 0,
            'our_share_percent' => 0, 'drr_percent' => 5, 'redemption_rate_override' => 100]);
        UnitEconomics::create(['integration_id' => $integration->id, 'sku' => $product->sku, 'marketplace' => 'ozon', 'fulfillment_type' => 'RFBS',
            'price' => 900, 'cost_price' => 200, 'acquiring_percent' => 2, 'commission_percent' => 10, 'redemption_rate' => 100, 'price_segment' => 'cached-before-segment']);
        // Locality aggregates use PostgreSQL-specific SQL. RFBS does not apply
        // non-local markup; only this read adapter is replaced in the SQLite fixture.
        $this->mock(\App\Services\Ozon\OzonLocalityService::class, function ($mock) {
            $mock->shouldReceive('resolveSkuLocality')->andReturn(['total_orders' => 0, 'locality_rate' => null, 'clusters_summary' => []]);
            $mock->shouldReceive('buildShippingRoutesForIntegration')->andReturn([]);
            $mock->shouldReceive('countSellerFboOrders7Days')->andReturn(0);
        });
        Http::preventStrayRequests(); Queue::fake();
        $mutations = [];
        DB::listen(function ($query) use (&$mutations) { if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query->sql)) $mutations[] = $query->sql; });
        $result = app(UnitEconomicsCacheService::class)->previewPrice($product, 'RFBS', 1100);
        $this->assertEquals(100, $result['before']['costs']['logistics']);
        $this->assertEquals(300, $result['after']['costs']['logistics']);
        $this->assertEquals(300, $result['inputs']['own_delivery_cost']);
        $evidence = app(\App\Services\Manager\ManagerPriceFinancialEvidence::class)->describe($product, 'RFBS', $result,
            UnitEconomicsSettings::where('integration_id', $integration->id)->where('sku', $product->sku)->first());
        $this->assertSame('incomplete', $evidence['status']);
        $this->assertSame(300.0, $evidence['items']['own_delivery_cost']['value']);
        $this->assertSame('unverified', $evidence['items']['commission_rate']['status']);
        $this->assertSame('configured_unconfirmed', $evidence['items']['tax_percent']['status']);
        $this->assertFalse($evidence['execution_allowed']);
        $this->assertNotSame('cached-before-segment', $result['after']['price_segment']);
        $this->assertEquals($result['after']['price'] - $result['after']['total_costs'], $result['after']['net_profit']);
        $this->assertSame([], $mutations); Queue::assertNothingPushed(); Http::assertNothingSent();
        $this->assertEquals(900, $product->fresh()->price);
    }

    public function test_real_financial_engine_preview_includes_returns_and_changes_no_business_rows(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 62020, 'settings' => ['own_delivery_cost' => 50, 'own_return_cost' => 20]]);
        $product = Product::factory()->wildberries()->create(['integration_id' => $integration->id, 'sku' => 'PREVIEW-TEST',
            'price' => 1000, 'old_price' => 1000, 'fulfillment_type' => 'DBS',
            'wb_data' => ['own_return_cost' => 20, 'commissions_by_scheme' => ['dbs' => ['percent' => 10]], 'redemption' => ['redemption_rate' => 80, 'source' => 'manual']]]);
        UnitEconomicsSettings::create(['integration_id' => $integration->id, 'sku' => $product->sku, 'cost_price' => 200,
            'tax_percent' => 6, 'vat_percent' => 0, 'our_share_percent' => 0, 'drr_percent' => 5, 'redemption_rate_override' => 80]);
        UnitEconomics::create(['integration_id' => $integration->id, 'sku' => $product->sku, 'marketplace' => 'wildberries', 'fulfillment_type' => 'DBS',
            'price' => 1000, 'cost_price' => 200, 'acquiring_percent' => 2, 'commission_percent' => 10, 'redemption_rate' => 80]);
        Http::preventStrayRequests();
        Queue::fake();
        $mutations = [];
        DB::listen(function ($query) use (&$mutations) {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query->sql)) {
                $mutations[] = $query->sql;
            }
        });

        $result = app(UnitEconomicsCacheService::class)->previewPrice($product, 'DBS', 1200);

        // 80% redemption: (delivery 50 + return 20) * (20/80) = 17.50.
        // Before: 1000 - 200 - 67.50 - 10% - 2% - 5% - 6% = 502.50.
        $this->assertEquals(17.5, $result['before']['expected_return_cost']);
        $this->assertEquals(502.5, $result['before']['net_profit']);
        $this->assertEquals(656.5, $result['after']['net_profit']);
        $this->assertEquals(543.5, $result['after']['total_costs']);
        $this->assertSame([], $mutations);
        $this->assertSame(0, UnitEconomicsCache::count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertEquals(1000, $product->fresh()->price);
    }
}
