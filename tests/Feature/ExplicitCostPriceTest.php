<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\CostPriceController;
use App\Models\Integration;
use App\Models\ManagerFinancialSettingChange;
use App\Models\Product;
use App\Models\UnitEconomics;
use App\Models\UnitEconomicsCache;
use App\Models\UnitEconomicsSettings;
use App\Services\CostPriceParserService;
use App\Services\Manager\ManagerPriceFinancialEvidence;
use App\Services\Ozon\OzonOrderUnitEconomicsService;
use App\Services\Ozon\OzonSupplyFixationService;
use App\Services\Ozon\OzonSupplySyncService;
use App\Services\PostingService;
use App\Services\SellicoApiService;
use App\Services\UnitEconomicsCacheService;
use App\Services\UnitEconomicsService;
use App\Services\UnitEconomicsSynchronizer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class ExplicitCostPriceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_personal_zero_is_used_by_the_real_engine_and_does_not_certify_an_old_calculation(): void
    {
        config(['services.sellico.base_url' => 'https://crm.test/api', 'services.manager.financial_settings_enabled' => true]);
        Http::preventStrayRequests();
        $this->mock(SellicoApiService::class, fn ($m) => $m->shouldReceive('getServiceToken')->andReturn('test-service'));
        Http::fake([
            'https://crm.test/api/user' => Http::response(['id' => 42, 'is_service_account' => false]),
            'https://crm.test/api/workspaces/8/user-permissions' => Http::response(['user_id' => 42, 'permissions' => ['products.view', 'unit_economics.view', 'unit_economics.settings.edit']]),
            'https://crm.test/api/get-integrations/8' => Http::response([['id' => 62300, 'work_space_id' => 8, 'type' => 'wildberries',
                'name' => 'Test zero store', 'status' => 'active', 'api_key' => 'test-vendor']]),
        ]);
        [$product, $settings, $calculated] = $this->fixture('wildberries', 62300);
        $engine = app(UnitEconomicsCacheService::class);
        $before = $engine->previewPrice($product, 'DBS', 1000);
        $this->assertEquals(500, $before['inputs']['cost_price']);
        $this->assertEquals(220, $before['after']['net_profit']);

        $this->withToken('test-personal')->withHeader('X-Sellico-Workspace', '8');
        $op = $this->postJson('/api/manager/financial-settings/prepare', ['integration_id' => 62300, 'product_id' => $product->id,
            'values' => ['cost_price' => '0']])->assertOk()->json();
        $this->postJson('/api/manager/financial-settings/'.$op['id'].'/approve', ['fingerprint' => $op['fingerprint'],
            'confirmations' => ['financial_inputs_checked' => true]])->assertOk()->assertJsonPath('result.settings_saved', true)
            ->assertJsonPath('result.price_changed', false)->assertJsonPath('result.recalculation_verified', false);

        $settings->refresh();
        $this->assertSame('0.00', $settings->cost_price);
        $this->assertSame('0.00', $settings->explicit_cost_price);
        $this->assertArrayNotHasKey('explicit_cost_price', $settings->toArray());
        $cache = UnitEconomicsCache::where('integration_id', 62300)->where('sku', $product->sku)->where('fulfillment_type', 'DBS')->firstOrFail();
        $this->assertEquals(0, $cache->cost_price);
        $this->assertEquals(720, $cache->net_profit);

        $mutations = [];
        DB::listen(function ($q) use (&$mutations) { if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $q->sql)) $mutations[] = $q->sql; });
        $after = $engine->previewPrice($product->fresh(), 'DBS', 1000);
        $this->assertEquals(0, $after['inputs']['cost_price']);
        $this->assertEquals(500, $after['after']['net_profit'] - $before['after']['net_profit']);
        $row = ManagerFinancialSettingChange::findOrFail($op['id']);
        $evidence = app(ManagerPriceFinancialEvidence::class)->describe($product, 'DBS', $after, $settings, $row->credential_binding);
        $this->assertSame('user_supplied', $evidence['items']['cost_price']['status']);
        $this->assertFalse($evidence['execution_allowed']);
        $this->assertSame([], $mutations);
        $this->assertEquals(1000, $product->fresh()->price);

        // The legacy export is backed by UnitEconomics, not its newer cache.
        $old = $this->export($product);
        $this->assertEquals(0, $old['cost_price']);
        $this->assertTrue($old['explicit_zero_cost_price']);
        $this->assertNotContains('missing_cost_price', $old['readiness_reasons']);
        $this->assertContains('cost_price_not_recalculated', $old['readiness_reasons']);
        $this->assertFalse($old['ready']);
        // Re-entering the old sentinel must withdraw the personal choice even
        // when its numeric value has not changed.
        $settings->update(['cost_price' => 0]);
        $this->assertNull($settings->fresh()->explicit_cost_price);
        $unconfirmed = app(ManagerPriceFinancialEvidence::class)->describe($product, 'DBS', $after, $settings->fresh(), $row->credential_binding);
        $this->assertNotSame('user_supplied', $unconfirmed['items']['cost_price']['status']);
        Http::assertNotSent(fn ($r) => ! str_starts_with($r->url(), 'https://crm.test/'));
    }

    public function test_ozon_zero_overrides_alias_and_product_fallbacks_only_after_a_matching_calculation(): void
    {
        [$product, $settings, $calculated] = $this->fixture('ozon', 62301);
        UnitEconomicsSettings::create(['integration_id' => 62301, 'sku' => $product->vendor_code, 'cost_price' => 700]);
        $settings->forceFill(['explicit_cost_price' => '0.00'])->save();
        $old = $this->export($product);
        $this->assertEquals(0, $old['cost_price']);
        $this->assertContains('cost_price_not_recalculated', $old['readiness_reasons']);
        $this->assertFalse($old['ready']);
        $calculated->update(['cost_price' => 0, 'net_profit' => 720, 'net_profit_per_unit' => 720, 'margin_percent' => 72]);
        $fresh = $this->export($product);
        $this->assertTrue($fresh['ready']);
        $this->assertTrue($fresh['explicit_zero_cost_price']);
        $this->assertEquals(0, $fresh['cost_price']);
        $settings->update(['tax_percent' => 7]);
        $this->assertSame('0.00', $settings->fresh()->explicit_cost_price);
        $settings->update(['cost_price' => 0]);
        $legacy = $this->export($product);
        $this->assertEquals(700, $legacy['cost_price']);
        $this->assertFalse($legacy['explicit_zero_cost_price']);
    }

    public function test_ozon_real_engine_uses_personal_zero_and_ignores_a_mismatched_marker(): void
    {
        Http::preventStrayRequests(); Queue::fake();
        config(['ozon_rfbs_tariffs' => ['enabled' => true, 'version' => 'test-only',
            'price_bands' => [['label' => 'test', 'max' => null]],
            'weight_bands' => [['label' => 'test', 'max' => null]], 'rates' => [[100]]]]);
        [$product, $settings, $calculated] = $this->fixture('ozon', 62305);
        $calculated->update(['fulfillment_type' => 'RFBS']);
        Integration::findOrFail(62305)->update(['settings' => ['rfbs_logistics_tariffs' => ['enabled' => true, 'rates' => [[100]]]]]);
        $product->update(['fulfillment_type' => 'RFBS', 'ozon_data' => ['actual_price' => 1000,
            'dimensions' => ['depth' => 100, 'width' => 100, 'height' => 100, 'weight' => 1000], 'commissions_by_scheme' => ['rfbs' => ['percent' => 10]]]]);
        $this->mock(\App\Services\Ozon\OzonLocalityService::class, function ($m) {
            $m->shouldReceive('resolveSkuLocality')->andReturn(['total_orders' => 0, 'locality_rate' => null, 'clusters_summary' => []]);
            $m->shouldReceive('buildShippingRoutesForIntegration')->andReturn([]);
            $m->shouldReceive('countSellerFboOrders7Days')->andReturn(0);
        });
        $engine = app(UnitEconomicsCacheService::class);
        $settings->forceFill(['explicit_cost_price' => '500.00'])->save();
        $before = $engine->previewPrice($product, 'RFBS', 1000);
        $this->assertEquals(500, $before['inputs']['cost_price']);
        $settings->forceFill(['explicit_cost_price' => '0.00'])->save();
        $after = $engine->previewPrice($product, 'RFBS', 1000);
        $this->assertEquals(0, $after['inputs']['cost_price']);
        $this->assertEquals(500, $after['after']['net_profit'] - $before['after']['net_profit']);
        $this->assertEquals(100, $after['after']['costs']['logistics']);
        Http::assertNothingSent(); Queue::assertNothingPushed();
    }

    public function test_old_defaults_and_reads_before_the_marker_migration_keep_their_existing_meaning(): void
    {
        [$product, $settings] = $this->fixture('wildberries', 62302);
        $settings->fill(['explicit_cost_price' => '0.00']);
        $this->assertNull($settings->explicit_cost_price);
        $this->assertEquals(500, app(UnitEconomicsCacheService::class)->previewPrice($product, 'DBS', 1000)['inputs']['cost_price']);
        $this->assertContains('missing_cost_price', $this->export($product)['readiness_reasons']);
        $this->assertFalse($this->export($product)['explicit_zero_cost_price']);
        Schema::table('unit_economics_settings', fn ($t) => $t->dropColumn('explicit_cost_price'));
        $settings = $settings->fresh(); $settings->update(['cost_price' => 0]);
        $this->assertEquals(500, app(UnitEconomicsCacheService::class)->previewPrice($product, 'DBS', 1000)['inputs']['cost_price']);
        $this->assertContains('missing_cost_price', $this->export($product)['readiness_reasons']);
    }

    public function test_legacy_sync_persists_an_explicit_zero_for_every_scheme_and_keeps_legacy_fallbacks(): void
    {
        Http::preventStrayRequests(); Http::fake(['*' => Http::response([], 200)]); Queue::fake();
        [$product, $settings] = $this->fixture('wildberries', 62303);
        $settings->forceFill(['explicit_cost_price' => '0.00'])->save();
        $schemes = ['FBO', 'FBS', 'DBS', 'EDBS', 'DBW'];
        foreach ($schemes as $scheme) UnitEconomics::updateOrCreate(['integration_id' => 62303, 'sku' => $product->sku, 'marketplace' => 'wildberries',
            'fulfillment_type' => $scheme], ['price' => 1000, 'cost_price' => 500]);
        $sync = fn () => app(UnitEconomicsSynchronizer::class)->syncProducts(app(UnitEconomicsService::class),
            Mockery::mock(OzonSupplyFixationService::class), Mockery::mock(OzonOrderUnitEconomicsService::class),
            Mockery::mock(OzonSupplySyncService::class), Mockery::mock(PostingService::class), 62303, 'wildberries', skipCacheDispatch: true);
        $result = $sync();
        $this->assertSame(0, $result['errors']);
        $this->assertSame(5, $result['synced']);
        $this->assertSame(['0.00'], UnitEconomics::where('integration_id', 62303)->pluck('cost_price')->unique()->values()->all());
        $export = $this->export($product);
        $this->assertNotContains('cost_price_not_recalculated', $export['readiness_reasons']);
        $this->assertNotContains('missing_cost_price', $export['readiness_reasons']);
        // A legacy automatic zero still preserves the existing per-scheme cost.
        $settings->update(['cost_price' => 0]);
        UnitEconomics::where('integration_id', 62303)->update(['cost_price' => 500]);
        $result = $sync(); $this->assertSame(0, $result['errors']);
        $this->assertSame(['500.00'], UnitEconomics::where('integration_id', 62303)->pluck('cost_price')->unique()->values()->all());
    }

    public function test_cost_backfill_does_not_replace_a_personal_zero_with_an_old_calculation(): void
    {
        [, $settings] = $this->fixture('wildberries', 62304);
        $settings->forceFill(['explicit_cost_price' => '0.00'])->save();
        $this->artisan('unit-economics:migrate-cost-price')->assertSuccessful();
        $this->assertSame('0.00', $settings->fresh()->cost_price);
        $this->assertSame('0.00', $settings->fresh()->explicit_cost_price);
    }

    private function fixture(string $marketplace, int $id): array
    {
        Integration::factory()->{$marketplace}()->create(['id' => $id, 'work_space_id' => 8, 'credentials' => [],
            'settings' => ['own_delivery_cost' => 50, 'own_return_cost' => 20]]);
        $product = Product::factory()->{$marketplace}()->create(['integration_id' => $id, 'sku' => 'ZERO-'.$id, 'vendor_code' => 'ALIAS-'.$id,
            'price' => 1000, 'old_price' => 1000, 'cost_price' => 600, 'fulfillment_type' => 'DBS', 'marketplace_id' => (string) (50000 + $id),
            'wb_data' => ['nmID' => 50000 + $id, 'commissions_by_scheme' => ['dbs' => ['percent' => 10]]],
            'ozon_data' => ['product_id' => 70000 + $id, 'sku' => 80000 + $id]]);
        $settings = UnitEconomicsSettings::create(['integration_id' => $id, 'sku' => $product->sku, 'cost_price' => 0, 'tax_percent' => 6,
            'vat_percent' => 0, 'our_share_percent' => 0, 'drr_percent' => 5, 'redemption_rate_override' => 100]);
        $calculated = UnitEconomics::create(['integration_id' => $id, 'sku' => $product->sku, 'marketplace' => $marketplace, 'fulfillment_type' => 'DBS',
            'is_actual_scheme' => true, 'price' => 1000, 'cost_price' => 500, 'commission_percent' => 10, 'acquiring_percent' => 2, 'tax_percent' => 6,
            'effective_logistics' => 50, 'net_profit' => 220, 'net_profit_per_unit' => 220, 'margin_percent' => 22, 'drr_percent' => 5]);

        return [$product, $settings, $calculated];
    }

    private function export(Product $product): array
    {
        return (new CostPriceController(new CostPriceParserService()))->unitEconomicsExport(
            Request::create('/api/products/unit-economics/export?integration_id='.$product->integration_id))->getData(true)['items'][0];
    }
}
