<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Product;
use App\Services\UnitEconomics\MarketplacePriceObservation;
use App\Services\UnitEconomics\OzonCommissionObservation;
use App\Services\UnitEconomicsCacheService;
use App\Services\UnitEconomicsSynchronizer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OzonCommissionObservationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Integration $integration;

    private function product(): Product
    {
        $integration = Integration::factory()->ozon()->create(['id' => 62101]);
        $this->integration = $integration;

        return Product::factory()->ozon()->create(['integration_id' => $integration->id, 'sku' => 'COMMISSION-TEST', 'price' => 850,
            'ozon_data' => ['product_id' => 10001, 'actual_price' => 850, 'commissions' => ['rfbs' => ['percent' => 10]],
                'dimensions' => ['depth' => 100, 'width' => 100, 'height' => 100, 'weight' => 1000],
                'redemption' => ['redemption_rate' => 100, 'source' => 'manual']]]);
    }

    private function row(string $at, array $commissions = [], array $extra = []): array
    {
        return array_replace(['product_id' => 10001, 'offer_id' => 'COMMISSION-TEST', 'price' => 1000,
            'actual_price' => 850, 'marketing_seller_price' => 850, 'price_observed_at' => $at, 'commissions' => $commissions], $extra);
    }

    public function test_only_returned_rates_get_their_own_observation_and_explicit_zero_is_kept(): void
    {
        $product = $this->product();
        $at = now()->utc()->subHours(2)->toIso8601String();
        app(MarketplacePriceObservation::class)->apply($this->integration, [$product->sku => $this->row($at, ['sales_percent_fbo' => 20, 'sales_percent_fbs' => 0])], 1);
        $data = $product->fresh()->ozon_data;
        $this->assertSame('20.00', $data['commission_observations']['fbo']['percent']);
        $this->assertSame('0.00', $data['commission_observations']['fbs']['percent']);
        $this->assertSame($at, $data['commission_observations']['fbo']['observed_at']);
        $this->assertArrayNotHasKey('rfbs', $data['commission_observations']);
        $this->assertEquals(20, $data['commissions']['fbo']['percent']);
        $this->assertEquals(0, $data['commissions']['fbs']['percent']);
        $this->assertEquals(10, $data['commissions']['rfbs']['percent']);
    }

    public function test_a_price_only_update_does_not_refresh_a_commission_or_borrow_another_scheme_date(): void
    {
        $product = $this->product();
        $at = now()->utc()->subHours(2)->toIso8601String();
        $writer = app(MarketplacePriceObservation::class);
        $writer->apply($this->integration, [$product->sku => $this->row($at, ['sales_percent_fbo' => 20])], 1);
        $before = $product->fresh()->ozon_data['commission_observations'];
        $writer->apply($this->integration, [$product->sku => $this->row(now()->utc()->subHour()->toIso8601String())], 2);
        $fresh = $product->fresh();
        $this->assertSame($before, $fresh->ozon_data['commission_observations']);
        $this->assertSame($at, app(OzonCommissionObservation::class)->forScheme($fresh, 'FBO')['observed_at']);
        $this->assertNull(app(OzonCommissionObservation::class)->forScheme($fresh, 'FBS'));
    }

    public function test_partial_older_rates_do_not_replace_newer_rates_for_other_schemes(): void
    {
        $product = $this->product();
        $new = now()->utc()->subHour()->toIso8601String();
        $old = now()->utc()->subHours(2)->toIso8601String();
        $observer = app(OzonCommissionObservation::class);
        $data = $observer->merge($product, $product->ozon_data, $this->row($new, ['sales_percent_fbo' => 20]));
        $data = $observer->merge($product, $data, $this->row($old, ['sales_percent_fbo' => 19, 'sales_percent_fbs' => 22]));
        $this->assertSame('20.00', $data['commission_observations']['fbo']['percent']);
        $this->assertSame($new, $data['commission_observations']['fbo']['observed_at']);
        $this->assertSame('22.00', $data['commission_observations']['fbs']['percent']);
        $this->assertSame($old, $data['commission_observations']['fbs']['observed_at']);
    }

    public function test_foreign_identity_or_malformed_rates_and_times_cannot_certify_or_replace_a_rate(): void
    {
        $product = $this->product();
        $at = now()->utc()->subHours(2)->toIso8601String();
        $observer = app(OzonCommissionObservation::class);
        $data = $observer->merge($product, $product->ozon_data, $this->row($at, ['sales_percent_fbo' => 20]));
        foreach ([
            $this->row($at, ['sales_percent_fbo' => 30], ['product_id' => 999]),
            $this->row($at, ['sales_percent_fbo' => 30], ['product_id' => []]),
            $this->row($at, ['sales_percent_fbo' => 30], ['offer_id' => 'OTHER']),
            $this->row('now', ['sales_percent_fbo' => 30]),
            $this->row('2026-02-31T12:00:00+00:00', ['sales_percent_fbo' => 30]),
            $this->row('2026-11-05T12:00:00+99:00', ['sales_percent_fbo' => 30]),
            $this->row('2026-11-05T12:00:00+14:01', ['sales_percent_fbo' => 30]),
            $this->row(now()->utc()->addHour()->toIso8601String(), ['sales_percent_fbo' => 30]),
            $this->row($at, ['sales_percent_fbo' => null]),
            $this->row($at, ['sales_percent_fbo' => true]),
            $this->row($at, ['sales_percent_fbo' => -1]),
            $this->row($at, ['sales_percent_fbo' => 100.01]),
            $this->row($at, ['sales_percent_fbo' => '20.000000001']),
            $this->row($at, ['fbo' => ['percent' => 15]]),
        ] as $row) {
            $this->assertSame($data, $observer->merge($product, $data, $row));
        }
        $product->ozon_data = $data;
        $this->assertNull($observer->forScheme($product, 'FBS'));
        $data['commission_observations']['fbo']['integration_id'] = '999';
        $product->ozon_data = $data;
        $this->assertNull($observer->forScheme($product, 'FBO'));
    }

    public function test_fractional_basis_points_are_not_rounded_as_ruble_amounts(): void
    {
        $product = $this->product();
        $observer = app(OzonCommissionObservation::class);
        $product->ozon_data = $observer->merge($product, $product->ozon_data,
            $this->row(now()->utc()->subHour()->toIso8601String(), ['sales_percent_fbo' => '20.001']));
        $this->assertSame('20.001', $observer->forScheme($product, 'FBO')['percent']);
        $this->assertEquals(20.001, $product->ozon_data['commissions']['fbo']['percent']);
    }

    public function test_fee_time_is_independent_of_price_time_and_keeps_microsecond_order(): void
    {
        $product = $this->product();
        $observer = app(OzonCommissionObservation::class);
        $base = now()->utc()->subHours(2)->format('Y-m-d\TH:i:s');
        $old = $base.'.100000+00:00';
        $current = $base.'.800000+00:00';
        $new = $base.'.900000+00:00';
        $priceAt = now()->utc()->subHour()->toIso8601String();
        $data = $observer->merge($product, $product->ozon_data, $this->row($priceAt, ['sales_percent_fbo' => 20], ['commissions_observed_at' => $current]));
        $data = $observer->merge($product, $data, $this->row($priceAt, ['sales_percent_fbo' => 19], ['commissions_observed_at' => $old]));
        $this->assertSame($current, $data['commission_observations']['fbo']['observed_at']);
        $data = $observer->merge($product, $data, $this->row($priceAt, ['sales_percent_fbo' => 21], ['commissions_observed_at' => $new]));
        $this->assertSame($new, $data['commission_observations']['fbo']['observed_at']);
        $this->assertSame('21.00', $data['commission_observations']['fbo']['percent']);
    }

    public function test_conflicting_same_time_rates_are_not_certified_and_a_newer_read_resolves_them(): void
    {
        $product = $this->product();
        $observer = app(OzonCommissionObservation::class);
        $at = now()->utc()->subHours(2)->toIso8601String();
        $data = $observer->merge($product, $product->ozon_data, $this->row($at, ['sales_percent_fbo' => 20]));
        $data = $observer->merge($product, $data, $this->row($at, ['sales_percent_fbo' => 21]));
        $product->ozon_data = $data;
        $this->assertSame('conflict', $data['commission_observations']['fbo']['status']);
        $this->assertNull($observer->forScheme($product, 'FBO'));
        $new = now()->utc()->subHour()->toIso8601String();
        $product->ozon_data = $observer->merge($product, $data, $this->row($new, ['sales_percent_fbo' => 21]));
        $this->assertSame('21.00', $observer->forScheme($product, 'FBO')['percent']);
        $this->assertSame($new, $observer->forScheme($product, 'FBO')['observed_at']);
    }

    public function test_source_timestamp_is_not_inferred_from_product_updated_at(): void
    {
        $product = $this->product();
        $this->mock(\App\Services\Ozon\OzonLocalityService::class, function ($mock) {
            $mock->shouldReceive('resolveSkuLocality')->andReturn(['total_orders' => 0, 'locality_rate' => null, 'clusters_summary' => []]);
            $mock->shouldReceive('buildShippingRoutesForIntegration')->andReturn([]);
            $mock->shouldReceive('countSellerFboOrders7Days')->andReturn(0);
        });
        Http::preventStrayRequests();
        $service = app(UnitEconomicsCacheService::class);
        $input = (new \ReflectionMethod($service, 'prepareCalculationInput'))->invoke($service, $product, null, 'RFBS');
        $this->assertNull($input['commission_observed_at'], 'a freshly changed product is not an observed commission');
        $this->assertSame('unverified_marketplace', $input['commission_rate_source']);
        Http::assertNothingSent();
    }

    public function test_real_input_builder_uses_the_bound_rate_and_its_original_date(): void
    {
        $product = $this->product();
        $at = now()->utc()->subDays(2)->toIso8601String();
        $product->ozon_data = app(OzonCommissionObservation::class)->merge($product, $product->ozon_data,
            $this->row($at, ['sales_percent_rfbs' => 23]));
        $this->mock(\App\Services\Ozon\OzonLocalityService::class, function ($mock) {
            $mock->shouldReceive('resolveSkuLocality')->andReturn(['total_orders' => 0, 'locality_rate' => null, 'clusters_summary' => []]);
            $mock->shouldReceive('buildShippingRoutesForIntegration')->andReturn([]);
            $mock->shouldReceive('countSellerFboOrders7Days')->andReturn(0);
        });
        $service = app(UnitEconomicsCacheService::class);
        $input = (new \ReflectionMethod($service, 'prepareCalculationInput'))->invoke($service, $product, null, 'RFBS');
        $this->assertEquals(23, $input['commission_rate']);
        $this->assertSame($at, $input['commission_observed_at']);
        $this->assertSame('ozon_prices_v5', $input['commission_rate_source']);
        $this->assertNull(app(OzonCommissionObservation::class)->forScheme($product, 'EXPRESS'));
    }

    public function test_legacy_sync_builder_uses_the_selected_response_time_without_changing_product_metadata(): void
    {
        $product = $this->product();
        $at = now()->utc()->subDays(2)->toIso8601String();
        $before = $product->ozon_data;
        $service = app(UnitEconomicsSynchronizer::class);
        $input = (new \ReflectionMethod($service, 'buildCalculationData'))->invokeArgs($service, [
            'product' => $product, 'inventory' => null, 'marketplace' => 'ozon', 'forceFulfillmentType' => 'RFBS',
            'productPriceData' => $this->row($at, ['sales_percent_rfbs' => 23]),
        ]);
        $this->assertEquals(23, $input['commission_percent']);
        $this->assertSame($at, $input['commission_observed_at']);
        $this->assertSame($before, $product->fresh()->ozon_data);
    }

    public function test_legacy_price_persistence_keeps_fee_time_when_a_later_price_has_no_fee(): void
    {
        config(['unit_economics_sync.enabled' => false, 'unit_economics_sync.integration_ids' => []]);
        $product = $this->product();
        $at = now()->utc()->subHours(2)->toIso8601String();
        $service = app(UnitEconomicsSynchronizer::class);
        $persist = new \ReflectionMethod($service, 'persistOzonCurrentPrices');
        $this->assertSame(1, $persist->invoke($service, collect([$product]), [$product->sku => $this->row($at, ['sales_percent_rfbs' => 23])]));
        $product = $product->fresh();
        $this->assertSame($at, app(OzonCommissionObservation::class)->forScheme($product, 'RFBS')['observed_at']);
        $this->assertSame(1, $persist->invoke($service, collect([$product]), [$product->sku => $this->row(now()->utc()->subHour()->toIso8601String(), [], ['actual_price' => 950])]));
        $this->assertEquals(950, $product->fresh()->price);
        $this->assertSame($at, app(OzonCommissionObservation::class)->forScheme($product->fresh(), 'RFBS')['observed_at']);
    }
}
