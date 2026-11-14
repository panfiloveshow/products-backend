<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Product;
use App\Models\UnitEconomics;
use App\Models\UnitEconomicsCache;
use App\Models\UnitEconomicsSettings;
use App\Services\Manager\ManagerStore;
use App\Services\SellicoApiService;
use App\Services\UnitEconomicsCacheService;
use App\Services\UnitEconomics\CalculationFingerprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManagerPricesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private array $permissions = ['products.view', 'marketing.view', 'unit_economics.view', 'unit_economics.calculate'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sellico.base_url' => 'https://crm.test/api', 'services.sellico.skip_permission_check' => true]);
        Http::preventStrayRequests();
        $this->mock(SellicoApiService::class, fn ($mock) => $mock->shouldReceive('getServiceToken')->andReturn('service-session'));
        $this->withToken('human-session')->withHeader('X-Sellico-Workspace', '8');
    }

    private function setupStore(string $marketplace = 'wildberries', int $workspace = 8): Product
    {
        $integration = Integration::factory()->{$marketplace}()->create(['id' => 62001, 'work_space_id' => $workspace]);

        return Product::factory()->{$marketplace}()->create(['integration_id' => $integration->id, 'sku' => 'TEST-SKU',
            'wb_data' => ['nmID' => 10001], 'ozon_data' => ['product_id' => 10001, 'offer_id' => 'TEST-SKU']]);
    }

    private function remote(string $marketplace = 'wildberries', ?array $permissions = null, ?array $storeOverrides = null, bool $noPromotions = false, bool $missingContext = false, string $wholesaleContext = 'known', bool $simpleWB = false): void
    {
        $store = array_replace(['id' => 62001, 'work_space_id' => 8, 'status' => 'active', 'type' => $marketplace, 'name' => 'Test store',
            'api_key' => 'vendor-secret', 'client_id' => '555'], $storeOverrides ?? []);
        Http::fake([
            'crm.test/api/user' => Http::response(['id' => 42, 'is_service_account' => false]),
            'crm.test/api/workspaces/8/user-permissions' => Http::response(['user_id' => 42, 'permissions' => $permissions ?? $this->permissions]),
            'crm.test/api/get-integrations/8' => Http::response([$store]),
            'discounts-prices-api.wildberries.ru/*' => Http::response(['error' => false, 'data' => ['listGoods' => [[
                'nmID' => 10001, 'vendorCode' => 'TEST-SKU', 'currencyIsoCode4217' => 'RUB', 'discount' => $simpleWB ? 0 : 42, 'clubDiscount' => $simpleWB ? 0 : 3, 'editableSizePrice' => ! $simpleWB,
                'sizes' => $simpleWB ? [['sizeID' => 20001, 'price' => 1000, 'discountedPrice' => 1000, 'clubDiscountedPrice' => 1000, 'techSizeName' => 'M']] : [
                    ['sizeID' => 20001, 'price' => 2001, 'discountedPrice' => 1160.58, 'clubDiscountedPrice' => 1125.76, 'techSizeName' => 'M'],
                    ['sizeID' => 20002, 'price' => 2201, 'discountedPrice' => 1276.58, 'clubDiscountedPrice' => 1238.28, 'techSizeName' => 'L'],
                ], ...match ($simpleWB ? 'empty' : $wholesaleContext) {
                    'missing' => [],
                    'null' => ['wholesaleDiscountThreshold' => null],
                    'object' => ['wholesaleDiscountThreshold' => (object) []],
                    'empty' => ['wholesaleDiscountThreshold' => []],
                    default => ['wholesaleDiscountThreshold' => [['quantity' => 5, 'discount' => 10]]],
                },
            ]]]]),
            'api-seller.ozon.ru/v5/product/info/prices' => Http::response(['items' => [[
                'product_id' => 10001, 'offer_id' => 'TEST-SKU',
                'price' => ['currency_code' => 'RUB', 'price' => '1081.38', 'marketing_seller_price' => $noPromotions ? '1081.38' : '1020.50', 'min_price' => '1000', 'old_price' => '2000', 'auto_action_enabled' => $missingContext ? null : ! $noPromotions, 'auto_add_to_ozon_actions_list_enabled' => false],
                'marketing_actions' => $missingContext ? null : ['actions' => $noPromotions ? [] : [['title' => 'Test promotion', 'value' => 5]], 'ozon_actions_exist' => ! $noPromotions],
            ]]]),
        ]);
    }

    private function url(Product $product, string $mode = 'context', string $scheme = 'FBO'): string
    {
        return '/api/manager/prices/'.$mode.'?integration_id=62001&product_id='.$product->id.'&fulfillment_type='.$scheme;
    }

    public function test_wb_context_preserves_sizes_club_prices_and_kopecks_without_a_write(): void
    {
        $product = $this->setupStore();
        $this->remote();
        $response = $this->getJson($this->url($product))->assertOk()->assertJsonPath('workspace_id', '8')
            ->assertJsonPath('snapshot.sizes.0.seller_price', '1160.58')->assertJsonPath('snapshot.sizes.1.club_price', '1238.28')
            ->assertJsonCount(2, 'snapshot.sizes')->assertJsonPath('snapshot.club_discount_percent', 3)->assertJsonPath('execution_allowed', false);
        $this->assertStringNotContainsString('vendor-secret', $response->getContent());
        Http::assertSentCount(4);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_missing_wholesale_conditions_are_unknown_and_block_forecast(): void
    {
        $this->assertUnknownWholesaleContext('missing');
    }

    public function test_null_wholesale_conditions_are_unknown_and_block_forecast(): void
    {
        $this->assertUnknownWholesaleContext('null');
    }

    public function test_object_wholesale_conditions_cannot_be_mistaken_for_a_known_empty_list(): void
    {
        $this->assertUnknownWholesaleContext('object');
    }

    private function assertUnknownWholesaleContext(string $mode): void
    {
        $product = $this->setupStore();
        $this->remote(wholesaleContext: $mode);
        $this->mock(UnitEconomicsCacheService::class, fn ($mock) => $mock->shouldNotReceive('previewPrice'));
        $this->getJson($this->url($product))->assertOk()
            ->assertJsonPath('snapshot.wholesale_discount_threshold', null)
            ->assertJsonPath('snapshot.wholesale_context_complete', false);
        $response = $this->postJson($this->url($product, 'simulate'), ['seller_price' => '1300.01'])
            ->assertOk()->assertJsonPath('scenario.status', 'blocked');
        $this->assertContains('incomplete_wholesale_context', $response->json('scenario.blockers'));
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_explicit_empty_wholesale_list_remains_known(): void
    {
        $product = $this->setupStore();
        $this->remote(wholesaleContext: 'empty');
        $this->getJson($this->url($product))->assertOk()
            ->assertJsonPath('snapshot.wholesale_discount_threshold', [])
            ->assertJsonPath('snapshot.wholesale_context_complete', true);
    }

    public function test_ozon_reads_only_the_selected_id_and_retains_promotions(): void
    {
        $product = $this->setupStore('ozon');
        $this->remote('ozon');
        $this->getJson($this->url($product))->assertOk()->assertJsonPath('snapshot.seller_price', '1081.38')
            ->assertJsonPath('snapshot.marketing_seller_price', '1020.50')->assertJsonPath('snapshot.auto_action_enabled', true);
        Http::assertSent(fn ($r) => $r->url() === 'https://api-seller.ozon.ru/v5/product/info/prices' && $r['filter']['product_id'] === ['10001'] && $r->hasHeader('Api-Key', 'vendor-secret'));
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && $r->url() !== 'https://api-seller.ozon.ru/v5/product/info/prices');
    }

    public function test_costs_cannot_be_read_using_only_marketing_permission(): void
    {
        $product = $this->setupStore();
        $this->remote(permissions: ['marketing.view', 'products.view']);
        $this->getJson($this->url($product))->assertForbidden();
        Http::assertSentCount(2);
    }

    public function test_foreign_local_store_is_denied_before_resolving_vendor_credentials(): void
    {
        $product = $this->setupStore(workspace: 9);
        $this->remote();
        $this->getJson($this->url($product))->assertNotFound();
        Http::assertSentCount(2);
    }

    public function test_foreign_crm_binding_cannot_use_an_old_local_key(): void
    {
        $product = $this->setupStore();
        $this->remote(storeOverrides: ['work_space_id' => 9]);
        $this->getJson($this->url($product))->assertStatus(503);
        Http::assertSentCount(3);
    }

    public function test_missing_economics_and_complex_prices_block_forecast_without_defaults(): void
    {
        $product = $this->setupStore();
        $this->remote();
        $this->mock(UnitEconomicsCacheService::class, fn ($mock) => $mock->shouldNotReceive('previewPrice'));
        $response = $this->postJson($this->url($product, 'simulate'), ['seller_price' => '1300.01'])->assertOk()
            ->assertJsonPath('scenario.status', 'blocked')->assertJsonPath('scenario.after', null)->assertJsonPath('scenario.execution_allowed', false);
        $this->assertContains('size_specific_price_requires_separate_scenario', $response->json('scenario.blockers'));
        $this->assertContains('club_price_requires_separate_scenario', $response->json('scenario.blockers'));
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_an_inexact_price_is_rejected_before_vendor_access(): void
    {
        $product = $this->setupStore();
        $this->remote();
        $this->postJson($this->url($product, 'simulate'), ['seller_price' => '1300.001'])->assertUnprocessable();
        Http::assertSentCount(2);
    }

    public function test_complete_context_calls_the_financial_owner_and_returns_a_forecast_without_mutations(): void
    {
        $product = $this->setupStore('ozon');
        $this->remote('ozon', noPromotions: true);
        $this->financialSnapshot($product);
        $this->mock(UnitEconomicsCacheService::class, function ($mock) use ($product) {
            $mock->shouldReceive('previewPrice')->once()->withArgs(fn ($selected, $scheme, $price) => $selected->id === $product->id && $scheme === 'FBO' && $price === 1300.01)
                ->andReturn(['before' => ['price' => 1081.38, 'net_profit' => 100, 'total_costs' => 981.38, 'margin_percent' => 9.25, '_calculation_identity' => $this->fixtureIdentity($product)],
                    'after' => ['price' => 1300.01, 'net_profit' => 200, 'total_costs' => 1100.01, 'margin_percent' => 15.38], 'inputs' => []]);
        });
        $mutations = [];
        DB::listen(function ($query) use (&$mutations) {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
                $mutations[] = $query->sql;
            }
        });
        $this->postJson($this->url($product, 'simulate'), ['seller_price' => '1300.01'])->assertOk()
            ->assertJsonPath('scenario.status', 'forecast')->assertJsonPath('scenario.after.net_profit', 200)->assertJsonPath('execution_allowed', false);
        $this->assertSame([], $mutations);
        Http::assertSentCount(4);
    }

    public function test_real_owner_wb_forecast_supports_nonzero_vat_and_share_without_price_execution(): void
    {
        $product = $this->wbFinancialContext();
        $mutations = [];
        DB::listen(function ($q) use (&$mutations) { if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $q->sql)) $mutations[] = $q->sql; });
        $response = $this->postJson($this->url($product, 'simulate', 'DBS'), ['seller_price' => '1200'])->assertOk()
            ->assertJsonPath('scenario.status', 'forecast')->assertJsonPath('scenario.before.net_profit', 470)
            ->assertJsonPath('scenario.after.net_profit', 614)->assertJsonPath('scenario.after.total_costs', 586)
            ->assertJsonPath('scenario.financial_model', 'products_main_financials_v1')
            ->assertJsonPath('scenario.financial_evidence.input_usage.items.storage_cost', 'not_applied')
            ->assertJsonPath('scenario.financial_evidence.input_usage.items.own_delivery_cost', 'applied')
            ->assertJsonMissingPath('scenario.after.financial_input_usage')->assertJsonMissingPath('scenario.before.financial_input_usage')
            ->assertJsonPath('scenario.financial_evidence.status', 'incomplete')->assertJsonPath('scenario.execution_allowed', false);
        $this->assertSame([], $response->json('scenario.blockers'));
        $this->assertSame([], $mutations);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_unversioned_owner_cannot_claim_wb_vat_and_share_are_covered(): void
    {
        $product = $this->wbFinancialContext();
        $this->mock(UnitEconomicsCacheService::class, fn ($m) => $m->shouldReceive('previewPrice')->once()->andReturn([
            'before' => ['price' => 1000, 'net_profit' => 470, 'total_costs' => 530, 'margin_percent' => 47],
            'after' => ['price' => 1200, 'net_profit' => 614, 'total_costs' => 586, 'margin_percent' => 51.17], 'inputs' => [],
        ]));
        $this->postJson($this->url($product, 'simulate', 'DBS'), ['seller_price' => '1200'])->assertOk()
            ->assertJsonPath('scenario.status', 'blocked')->assertJsonPath('scenario.after', null)
            ->assertJsonPath('scenario.blockers', ['wb_tax_configuration_requires_supported_model']);
    }

    public function test_old_calculation_version_is_not_accepted_after_seller_cost_model_update(): void
    {
        $product = $this->wbFinancialContext();
        UnitEconomicsCache::where('integration_id', 62001)->update(['data_version' => UnitEconomicsCache::CURRENT_DATA_VERSION - 1]);
        $this->mock(UnitEconomicsCacheService::class, fn ($m) => $m->shouldNotReceive('previewPrice'));
        $this->postJson($this->url($product, 'simulate', 'DBS'), ['seller_price' => '1200'])->assertOk()
            ->assertJsonPath('scenario.status', 'blocked')->assertJsonPath('scenario.after', null)
            ->assertJsonPath('scenario.blockers', ['missing_current_calculation_snapshot']);
    }

    private function wbFinancialContext(): Product
    {
        $product = $this->setupStore();
        $this->remote(simpleWB: true);
        Integration::findOrFail(62001)->update(['settings' => ['own_delivery_cost' => 50, 'own_return_cost' => 20]]);
        $product->update(['price' => 1000, 'old_price' => 1000, 'fulfillment_type' => 'DBS',
            'wb_data' => ['nmID' => 10001, 'price_version' => 1, 'commissions_by_scheme' => ['dbs' => ['percent' => 10]]]]);
        UnitEconomicsSettings::create(['integration_id' => 62001, 'sku' => $product->sku, 'cost_price' => 200, 'tax_percent' => 6,
            'drr_percent' => 5, 'vat_percent' => 3, 'our_share_percent' => 2, 'redemption_rate_override' => 100]);
        UnitEconomics::create(['integration_id' => 62001, 'sku' => $product->sku, 'marketplace' => 'wildberries', 'fulfillment_type' => 'DBS',
            'is_actual_scheme' => true, 'price' => 1000, 'cost_price' => 200, 'commission_percent' => 10, 'effective_logistics' => 50,
            'net_profit' => 470, 'net_profit_per_unit' => 470, 'margin_percent' => 47, 'acquiring_percent' => 2, 'redemption_rate' => 100,
            'drr_percent' => 5, 'tariff_source' => 'seller_own_delivery', 'redemption_source' => 'manual',
            'dimensions_observed_at' => now(), 'acquiring_observed_at' => now(),
            'marketplace_data' => ['commission_source' => 'wb_commission_api_scheme', 'dimensions_source' => 'wb_product_characteristics',
                'acquiring_source' => 'wb_realization_report_sku']]);
        app(UnitEconomicsCacheService::class)->calculateAndCache($product, 'DBS');

        return $product;
    }

    public function test_context_and_forecast_follow_recalculated_main_costs_while_legacy_profit_is_unchanged(): void
    {
        $product = $this->wbFinancialContext();
        UnitEconomicsSettings::where('integration_id', 62001)->where('sku', $product->sku)->firstOrFail()->update(['cost_price' => 300]);
        app(UnitEconomicsCacheService::class)->onSettingsChanged(62001, $product->sku);
        $this->assertEquals(470, UnitEconomics::where('integration_id', 62001)->value('net_profit_per_unit'));
        $this->getJson($this->url($product, 'context', 'DBS'))->assertOk()
            ->assertJsonPath('economics.net_profit', 370)->assertJsonPath('economics.source', 'unit_economics_cache');
        $this->postJson($this->url($product, 'simulate', 'DBS'), ['seller_price' => '1200'])->assertOk()
            ->assertJsonPath('scenario.status', 'forecast')->assertJsonPath('scenario.before.net_profit', 370)
            ->assertJsonPath('scenario.after.net_profit', 514)->assertJsonPath('scenario.execution_allowed', false);
    }

    public function test_legacy_profit_and_timestamp_do_not_override_fresh_main_money_or_leak_its_private_identity(): void
    {
        $product = $this->wbFinancialContext();
        UnitEconomics::where('integration_id', 62001)->update(['net_profit_per_unit' => null, 'net_profit' => -100, 'updated_at' => now()->subDays(10)]);
        $response = $this->postJson($this->url($product, 'simulate', 'DBS'), ['seller_price' => '1200'])->assertOk()
            ->assertJsonPath('scenario.status', 'forecast')->assertJsonPath('economics.net_profit', 470)
            ->assertJsonPath('economics.source', 'unit_economics_cache')->assertJsonPath('economics.financial_inputs_verified', false);
        $this->assertStringNotContainsString('calculation_identity', $response->getContent());
        $this->assertStringNotContainsString('input_hash', $response->getContent());
        $this->assertArrayNotHasKey('inputs', $response->json('scenario'));
    }

    public function test_main_money_preserves_existing_wb_source_guards(): void
    {
        $product = $this->wbFinancialContext();
        UnitEconomics::where('integration_id', 62001)->update(['acquiring_observed_at' => now()->subDays(10)]);
        $this->mock(UnitEconomicsCacheService::class, fn ($m) => $m->shouldNotReceive('previewPrice'));
        $response = $this->postJson($this->url($product, 'simulate', 'DBS'), ['seller_price' => '1200'])->assertOk()
            ->assertJsonPath('scenario.status', 'blocked')->assertJsonPath('economics.net_profit', 470);
        $this->assertContains('stale_acquiring_source', $response->json('scenario.blockers'));
    }

    public function test_unusable_main_snapshots_never_fall_back_to_old_money(): void
    {
        $product = $this->wbFinancialContext();
        // Keep the malformed cache references valid at the storage layer so
        // PostgreSQL can exercise the manager's binding checks too.
        $otherProduct = Product::factory()->wildberries()->create(['integration_id' => 62001, 'sku' => 'OTHER-BINDING']);
        Integration::factory()->wildberries()->create(['id' => 62002, 'work_space_id' => 8]);
        $row = UnitEconomicsCache::where('integration_id', 62001)->where('fulfillment_type', 'DBS')->firstOrFail();
        $original = $row->getRawOriginal();
        $identity = $row->marketplace_data['calculation_identity'];
        $changes = [
            ['product_id' => $otherProduct->id], ['integration_id' => 62002], ['sku' => 'OTHER'],
            ['marketplace' => 'ozon'], ['fulfillment_type' => 'FBS'], ['source_version' => 0],
            ['calculated_at' => now()->subHours(73)], ['calculated_at' => now()->addMinutes(6)],
            ['marketplace_data' => ['calculation_identity' => null]],
            ['marketplace_data' => ['calculation_identity' => [...$identity, 'version' => 'unknown']]],
            ['marketplace_data' => ['calculation_identity' => [...$identity, 'product_binding' => str_repeat('a', 64)]]],
            ['marketplace_data' => ['calculation_identity' => [...$identity, 'input_hash' => 'bad']]],
            ['marketplace_data' => ['calculation_identity' => [...$identity, 'extra' => true]]],
            ['marketplace_data' => ['calculation_identity' => $identity, 'calculation_error_code' => 'failed']],
            ['net_profit' => 471], ['cost_price' => -1],
        ];
        $this->mock(UnitEconomicsCacheService::class, fn ($m) => $m->shouldNotReceive('previewPrice'));
        foreach ($changes as $change) {
            $row->setRawAttributes($original, true);
            $row->forceFill($change)->save();
            $this->postJson($this->url($product, 'simulate', 'DBS'), ['seller_price' => '1200'])->assertOk()
                ->assertJsonPath('economics', null)->assertJsonPath('scenario.status', 'blocked')->assertJsonPath('scenario.after', null);
            // Restore by primary key even when a test changes the row's scope.
            $row->setRawAttributes($original, true);
            DB::table('unit_economics_cache')->where('id', $row->id)->update(array_diff_key($original, ['id' => true]));
        }
    }

    public function test_reused_external_product_id_and_other_recipe_cannot_reuse_a_cached_calculation(): void
    {
        $product = $this->wbFinancialContext();
        $row = UnitEconomicsCache::where('integration_id', 62001)->where('fulfillment_type', 'DBS')->firstOrFail();
        $original = $row->marketplace_data;
        $row->update(['marketplace_data' => [...$original, 'calculation_identity' => [...$original['calculation_identity'], 'input_hash' => str_repeat('a', 64)]]]);
        $this->postJson($this->url($product, 'simulate', 'DBS'), ['seller_price' => '1200'])->assertOk()
            ->assertJsonPath('scenario.blockers', ['calculation_inputs_changed_requires_sync'])->assertJsonPath('scenario.after', null);
        $row->update(['marketplace_data' => $original]);
        $product->update(['wb_data' => [...$product->wb_data, 'nmID' => 10002]]);
        // This boundary is before the vendor response; inspect the scoped owner
        // directly because the existing fake returns only the old external ID.
        $result = (new \App\Services\Manager\ManagerPriceCalculationSnapshot)->read($product, 'DBS', ['readiness_reasons' => []]);
        $this->assertNull($result['economics']);
        $this->assertSame(['missing_current_calculation_snapshot'], $result['reasons']);
    }

    public function test_explicit_zero_cost_is_distinct_from_an_unentered_zero_in_main_money(): void
    {
        $product = $this->wbFinancialContext();
        $settings = UnitEconomicsSettings::where('integration_id', 62001)->firstOrFail();
        $settings->update(['cost_price' => 0]);
        $settings->forceFill(['explicit_cost_price' => 0])->save();
        app(UnitEconomicsCacheService::class)->onSettingsChanged(62001, $product->sku);
        $this->postJson($this->url($product, 'simulate', 'DBS'), ['seller_price' => '1200'])->assertOk()
            ->assertJsonPath('economics.cost_price', 0)->assertJsonPath('scenario.status', 'forecast')->assertJsonPath('scenario.after.net_profit', 814);
        $settings->forceFill(['explicit_cost_price' => null])->save();
        $this->postJson($this->url($product, 'simulate', 'DBS'), ['seller_price' => '1200'])->assertOk()
            ->assertJsonPath('economics', null)->assertJsonPath('scenario.status', 'blocked')->assertJsonPath('scenario.after', null);
    }

    public function test_price_publication_recalculates_changed_costs_even_when_the_observed_price_is_unchanged(): void
    {
        $product = $this->wbFinancialContext();
        $service = app(UnitEconomicsCacheService::class);
        $service->onSettingsChanged(62001, $product->sku);
        $before = UnitEconomicsCache::where('integration_id', 62001)->where('fulfillment_type', 'DBS')->firstOrFail();
        UnitEconomicsSettings::where('integration_id', 62001)->update(['cost_price' => 300]);
        $product->update(['wb_data' => [...$product->wb_data, 'price_version' => 2, 'price_observed_at' => now()->toRfc3339String(), 'price_source' => 'wb_prices_api']]);
        $service->publishObservedProduct($product, 2);
        $after = $before->fresh();
        $this->assertEquals(370, $after->net_profit);
        $this->assertEquals(300, $after->cost_price);
        $this->assertEquals(2, $after->source_version);
        $this->assertNotSame($before->marketplace_data['calculation_identity']['input_hash'], $after->marketplace_data['calculation_identity']['input_hash']);
    }

    public function test_unchanged_price_publication_preserves_calculation_time_but_rebuilds_expired_money(): void
    {
        $product = $this->wbFinancialContext();
        $service = app(UnitEconomicsCacheService::class);
        $service->onSettingsChanged(62001, $product->sku);
        $row = UnitEconomicsCache::where('integration_id', 62001)->where('fulfillment_type', 'DBS')->firstOrFail();
        $calculatedAt = $row->calculated_at->toRfc3339String();
        $this->travel(5)->minutes();
        $product->update(['wb_data' => [...$product->wb_data, 'price_version' => 2, 'price_observed_at' => now()->toRfc3339String(), 'price_source' => 'wb_prices_api']]);
        $service->publishObservedProduct($product, 2);
        $this->assertSame($calculatedAt, $row->fresh()->calculated_at->toRfc3339String());
        UnitEconomicsCache::where('integration_id', 62001)->update(['calculated_at' => now()->subHours(73)]);
        $product->update(['wb_data' => [...$product->wb_data, 'price_version' => 3, 'price_observed_at' => now()->toRfc3339String()]]);
        $service->publishObservedProduct($product, 3);
        $this->assertSame(now()->toRfc3339String(), $row->fresh()->calculated_at->toRfc3339String());
        $this->assertEquals(3, $row->fresh()->source_version);
    }

    public function test_equal_profit_does_not_hide_changed_calculation_inputs_before_recalculation(): void
    {
        $product = $this->wbFinancialContext();
        UnitEconomicsSettings::where('integration_id', 62001)->where('sku', $product->sku)->firstOrFail()
            ->update(['vat_percent' => 4, 'our_share_percent' => 1]);
        $this->postJson($this->url($product, 'simulate', 'DBS'), ['seller_price' => '1200'])->assertOk()
            ->assertJsonPath('scenario.status', 'blocked')->assertJsonPath('scenario.after', null)
            ->assertJsonPath('scenario.blockers', ['calculation_inputs_changed_requires_sync']);
    }

    public function test_ozon_chat_context_follows_the_real_main_calculation_after_settings_change(): void
    {
        $product = $this->setupStore('ozon');
        $this->remote('ozon', noPromotions: true);
        config(['ozon_rfbs_tariffs' => ['enabled' => true, 'version' => 'test-only',
            'price_bands' => [['label' => 'test', 'max' => null]], 'weight_bands' => [['label' => 'test', 'max' => null]], 'rates' => [[100]]]]);
        Integration::findOrFail(62001)->update(['settings' => ['rfbs_logistics_tariffs' => ['enabled' => true, 'rates' => [[100]]]]]);
        $product->update(['price' => 1081.38, 'old_price' => 2000, 'fulfillment_type' => 'RFBS',
            'ozon_data' => ['product_id' => 10001, 'offer_id' => 'TEST-SKU', 'actual_price' => 1081.38, 'price_version' => 1,
                'dimensions' => ['depth' => 100, 'width' => 100, 'height' => 100, 'weight' => 1000],
                'commissions_by_scheme' => ['rfbs' => ['percent' => 10]], 'redemption' => ['redemption_rate' => 100, 'source' => 'manual']]]);
        $settings = UnitEconomicsSettings::create(['integration_id' => 62001, 'sku' => $product->sku, 'cost_price' => 200,
            'tax_percent' => 6, 'vat_percent' => 3, 'our_share_percent' => 2, 'drr_percent' => 5, 'redemption_rate_override' => 100]);
        UnitEconomics::create(['integration_id' => 62001, 'sku' => $product->sku, 'marketplace' => 'ozon', 'fulfillment_type' => 'RFBS', 'is_actual_scheme' => true,
            'price' => 1081.38, 'cost_price' => 200, 'commission_percent' => 10, 'effective_logistics' => 100,
            'net_profit' => 500, 'net_profit_per_unit' => 500, 'margin_percent' => 46.24, 'acquiring_percent' => 2, 'redemption_rate' => 100, 'drr_percent' => 5]);
        // Only the PostgreSQL locality reader is replaced; financial assembly,
        // domain calculator, cache publication and manager HTTP path are real.
        $this->mock(\App\Services\Ozon\OzonLocalityService::class, function ($m) {
            $m->shouldReceive('resolveSkuLocality')->andReturn(['total_orders' => 0, 'locality_rate' => null, 'clusters_summary' => []]);
            $m->shouldReceive('buildShippingRoutesForIntegration')->andReturn([]);
            $m->shouldReceive('countSellerFboOrders7Days')->andReturn(0);
        });
        $service = app(UnitEconomicsCacheService::class);
        $first = $service->calculateAndCache($product, 'RFBS');
        $settings->update(['cost_price' => 300]);
        $service->onSettingsChanged(62001, $product->sku);
        $main = $first->fresh();
        $this->assertEqualsWithDelta(100, (float) $first->net_profit - (float) $main->net_profit, 0.01);
        $mutations = [];
        DB::listen(function ($q) use (&$mutations) { if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $q->sql)) $mutations[] = $q->sql; });
        $this->getJson($this->url($product, 'context', 'RFBS'))->assertOk()
            ->assertJsonPath('economics.net_profit', (float) $main->net_profit)->assertJsonPath('economics.source', 'unit_economics_cache');
        $response = $this->postJson($this->url($product, 'simulate', 'RFBS'), ['seller_price' => '1300.01'])->assertOk()
            ->assertJsonPath('scenario.status', 'forecast')->assertJsonPath('scenario.before.net_profit', (float) $main->net_profit)
            ->assertJsonPath('scenario.financial_evidence.status', 'incomplete')->assertJsonPath('scenario.execution_allowed', false);
        $this->assertSame([], $mutations);
        $this->assertStringNotContainsString('calculation_identity', $response->getContent());
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET' && $r->url() !== 'https://api-seller.ozon.ru/v5/product/info/prices');
    }

    public function test_old_source_version_and_future_calculation_cannot_be_used_as_a_current_main_snapshot(): void
    {
        $product = $this->wbFinancialContext();
        $product->update(['wb_data' => [...$product->wb_data, 'price_version' => 2]]);
        $this->postJson($this->url($product, 'simulate', 'DBS'), ['seller_price' => '1200'])->assertOk()
            ->assertJsonPath('scenario.status', 'blocked')->assertJsonPath('scenario.after', null);
        $product->update(['wb_data' => [...$product->wb_data, 'price_version' => 1]]);
        UnitEconomicsCache::where('integration_id', 62001)->update(['calculated_at' => now()->addHours(2)]);
        $this->postJson($this->url($product, 'simulate', 'DBS'), ['seller_price' => '1200'])->assertOk()
            ->assertJsonPath('scenario.status', 'blocked')->assertJsonPath('scenario.after', null);
    }

    public function test_live_price_drift_blocks_even_a_complete_old_economics_snapshot(): void
    {
        $product = $this->setupStore('ozon');
        $this->remote('ozon', noPromotions: true);
        $this->financialSnapshot($product);
        UnitEconomicsCache::query()->where('integration_id', $product->integration_id)->update(['price' => 999, 'total_costs' => 899]);
        $this->mock(UnitEconomicsCacheService::class, fn ($mock) => $mock->shouldNotReceive('previewPrice'));
        $response = $this->postJson($this->url($product, 'simulate'), ['seller_price' => '1300.01'])->assertOk()->assertJsonPath('scenario.status', 'blocked');
        $this->assertContains('economics_price_differs_from_live', $response->json('scenario.blockers'));
    }

    public function test_numeric_settings_and_a_passed_policy_do_not_certify_financial_inputs(): void
    {
        $product = $this->setupStore('ozon');
        $this->remote('ozon', noPromotions: true);
        $this->financialSnapshot($product);
        UnitEconomicsSettings::where('integration_id', $product->integration_id)->where('sku', $product->sku)->delete();
        // This is the real automatic defaults path, with only cost entered.
        UnitEconomicsSettings::getOrCreate($product->integration_id, $product->sku)->update(['cost_price' => 600]);
        $this->mock(UnitEconomicsCacheService::class, fn ($mock) => $mock->shouldReceive('previewPrice')->once()
            ->andReturn(['before' => ['price' => 1081.38, 'net_profit' => 100, 'total_costs' => 981.38, 'margin_percent' => 9.25, '_calculation_identity' => $this->fixtureIdentity($product)],
                'after' => ['price' => 1300.01, 'net_profit' => 200, 'total_costs' => 1100.01, 'margin_percent' => 15.38,
                    'sku' => $product->sku, 'marketplace' => 'ozon', 'fulfillment_type' => 'FBO', 'commission_source' => 'repo_fallback', 'commission_rate_base' => 15],
                'inputs' => ['sku' => $product->sku, 'integration_id' => $product->integration_id, 'marketplace' => 'ozon', 'fulfillment_type' => 'FBO',
                    'price' => 1300.01, 'cost_price' => 600, 'tax_percent' => 6, 'vat_percent' => 0,
                    'drr_percent' => 0, 'our_share_percent' => 0, 'commission_rate' => 15, 'commission_rate_source' => 'model_default',
                    'acquiring_percent' => 1.5, 'redemption_rate' => 85, '_extra' => ['api_key' => 'never-expose-this']]]));
        $response = $this->postJson($this->url($product, 'simulate'), ['seller_price' => '1300.01', 'constraints' => [
            'min_margin_percent' => '10', 'min_profit_rub' => '0', 'max_change_percent' => '25']])->assertOk()
            ->assertJsonPath('scenario.status', 'forecast')->assertJsonPath('scenario.policy.status', 'within_limits')
            ->assertJsonPath('scenario.financial_evidence.status', 'incomplete')
            ->assertJsonPath('scenario.financial_evidence.execution_allowed', false)
            ->assertJsonPath('scenario.financial_evidence.items.tax_percent.status', 'configured_unconfirmed')
            ->assertJsonPath('scenario.financial_evidence.items.vat_percent.value', 0)
            ->assertJsonPath('scenario.financial_evidence.items.commission_rate.status', 'model_assumption');
        $this->assertArrayNotHasKey('inputs', $response->json('scenario'));
        $this->assertStringNotContainsString('never-expose-this', $response->getContent());
    }

    public function test_scenario_checks_explicit_limits_using_kopecks_instead_of_the_display_margin(): void
    {
        $product = $this->setupStore('ozon');
        $this->remote('ozon', noPromotions: true);
        $this->financialSnapshot($product);
        $this->mock(UnitEconomicsCacheService::class, fn ($mock) => $mock->shouldReceive('previewPrice')->once()
            ->andReturn(['before' => ['price' => 1081.38, 'net_profit' => 100, 'total_costs' => 981.38, 'margin_percent' => 9.25, '_calculation_identity' => $this->fixtureIdentity($product)],
                'after' => ['price' => 1300.01, 'net_profit' => 130, 'total_costs' => 1170.01, 'margin_percent' => 10], 'inputs' => []]));
        $this->postJson($this->url($product, 'simulate'), ['seller_price' => '1300.01', 'constraints' => [
            'min_margin_percent' => '10', 'min_profit_rub' => '0', 'max_change_percent' => '25']])->assertOk()
            ->assertJsonPath('scenario.status', 'forecast')->assertJsonPath('scenario.after.net_profit', 130)
            ->assertJsonPath('scenario.policy.status', 'outside_limits')
            ->assertJsonPath('scenario.policy.constraints.min_margin_percent', '10.00')
            ->assertJsonPath('scenario.policy.violations', ['min_margin_percent_not_met'])
            ->assertJsonPath('scenario.policy.execution_allowed', false);
    }

    public function test_invalid_or_extra_limit_fields_are_rejected_before_price_access(): void
    {
        $product = $this->setupStore('ozon');
        $this->remote('ozon', noPromotions: true);
        $this->postJson($this->url($product, 'simulate'), ['seller_price' => '1300.01', 'constraints' => [
            'min_margin_percent' => '10.001', 'min_profit_rub' => '0', 'max_change_percent' => '25', 'force' => true]])
            ->assertUnprocessable();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api-seller.ozon.ru'));
    }

    public function test_a_financial_owner_result_for_another_target_is_blocked(): void
    {
        $product = $this->setupStore('ozon');
        $this->remote('ozon', noPromotions: true);
        $this->financialSnapshot($product);
        $this->mock(UnitEconomicsCacheService::class, fn ($mock) => $mock->shouldReceive('previewPrice')->once()
            ->andReturn(['before' => ['price' => 1081.38, 'net_profit' => 100, 'total_costs' => 981.38, 'margin_percent' => 9.25, '_calculation_identity' => $this->fixtureIdentity($product)],
                'after' => ['price' => 1200, 'net_profit' => 200, 'total_costs' => 1000, 'margin_percent' => 16.67], 'inputs' => []]));
        $response = $this->postJson($this->url($product, 'simulate'), ['seller_price' => '1300.01'])
            ->assertOk()->assertJsonPath('scenario.status', 'blocked')->assertJsonPath('scenario.after', null);
        $this->assertContains('calculation_target_or_money_mismatch', $response->json('scenario.blockers'));
    }

    // The following policy tests replace the financial owner. Supply its
    // private calculation receipt as well as matching main-cache money.
    private function fixtureIdentity(Product $product): array
    {
        return CalculationFingerprint::forInputs($product, ['sku' => $product->sku, 'integration_id' => $product->integration_id,
            'marketplace' => $product->marketplace, 'fulfillment_type' => 'FBO', 'price' => 1081.38, 'cost_price' => 600]);
    }

    private function financialSnapshot(Product $product): void
    {
        $field = $product->marketplace === 'ozon' ? 'ozon_data' : 'wb_data';
        $product->update(['price' => 1081.38, $field => [...$product->{$field}, 'price_version' => 1]]);
        UnitEconomicsSettings::create(['integration_id' => $product->integration_id, 'sku' => $product->sku, 'cost_price' => 600,
            'tax_percent' => 6, 'vat_percent' => 0, 'our_share_percent' => 0, 'drr_percent' => 5]);
        UnitEconomics::create(['integration_id' => $product->integration_id, 'sku' => $product->sku, 'marketplace' => $product->marketplace, 'fulfillment_type' => 'FBO',
            'is_actual_scheme' => true, 'price' => 1081.38, 'cost_price' => 600, 'commission_percent' => 20, 'effective_logistics' => 100,
            'net_profit' => 100, 'net_profit_per_unit' => 100, 'margin_percent' => 9.25, 'acquiring_percent' => 1.5, 'redemption_rate' => 80, 'drr_percent' => 5]);
        UnitEconomicsCache::create(['integration_id' => $product->integration_id, 'sku' => $product->sku, 'product_id' => $product->id, 'product_name' => $product->name,
            'marketplace' => $product->marketplace, 'fulfillment_type' => 'FBO', 'data_version' => UnitEconomicsCache::CURRENT_DATA_VERSION, 'source_version' => 1, 'calculated_at' => now(),
            'price' => 1081.38, 'cost_price' => 600, 'total_costs' => 981.38, 'net_profit' => 100, 'margin_percent' => 9.25,
            'to_settlement_account' => 700, 'commission_percent' => 20, 'commission_amount' => 216.28, 'effective_logistics' => 100,
            'acquiring_percent' => 1.5, 'acquiring_amount' => 16.22, 'redemption_rate' => 80, 'drr_percent' => 5, 'drr_amount' => 54.07,
            'tax_percent' => 6, 'tax_amount' => 64.88, 'vat_percent' => 0, 'vat_amount' => 0, 'our_share_percent' => 0, 'our_share_amount' => 0,
            'marketplace_data' => ['calculation_identity' => $this->fixtureIdentity($product)]]);
    }

    public function test_missing_optional_promotion_fields_remain_unknown_and_block_a_forecast(): void
    {
        $product = $this->setupStore('ozon');
        $this->remote('ozon', noPromotions: true, missingContext: true);
        $this->financialSnapshot($product);
        $this->mock(UnitEconomicsCacheService::class, fn ($mock) => $mock->shouldNotReceive('previewPrice'));
        $this->getJson($this->url($product))->assertOk()->assertJsonPath('snapshot.seller_price', '1081.38')
            ->assertJsonPath('snapshot.auto_action_enabled', null)->assertJsonPath('snapshot.promotion_context_complete', false);
        $response = $this->postJson($this->url($product, 'simulate'), ['seller_price' => '1300.01'])->assertOk()->assertJsonPath('scenario.status', 'blocked');
        $this->assertContains('incomplete_promotion_context', $response->json('scenario.blockers'));
    }

    public function test_store_serialization_does_not_expose_keys_or_bindings(): void
    {
        $store = new ManagerStore(8, 62001, 'ozon', 'Test', 'vendor-secret', '555', 'private-binding');
        $this->assertSame(['workspace_id' => 8, 'integration_id' => 62001, 'marketplace' => 'ozon', 'name' => 'Test'], $store->jsonSerialize());
        $this->assertStringNotContainsString('vendor-secret', print_r($store, true));
        $this->assertStringNotContainsString('private-binding', json_encode($store));
    }
}
