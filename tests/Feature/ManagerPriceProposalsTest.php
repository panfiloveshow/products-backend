<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\ManagerPriceProposal;
use App\Models\Product;
use App\Models\UnitEconomics;
use App\Models\UnitEconomicsSettings;
use App\Services\SellicoApiService;
use App\Services\UnitEconomicsCacheService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManagerPriceProposalsTest extends TestCase
{
    use LazilyRefreshDatabase;
    private array $permissions = ['products.view', 'marketing.view', 'unit_economics.view', 'unit_economics.calculate'];
    private int $actor = 42;
    private bool $serviceAccount = false;
    private string $vendorKey = 'vendor-secret';
    private float $nativePrice = 1000;
    private int $clubDiscount = 0;
    private int $discount = 0;
    private float $nativeBase = 1000;
    private ?\Closure $priceExecutor = null;
    private int $priceControlVersion = 0;
    private string $priceControlMode = 'legacy';
    /** false: the executor response omits legacy_held_count, as an earlier executor does. */
    private mixed $legacyHeldCount = 0;

    private function fixture(string $marketplace = 'wildberries'): Product
    {
        config(['services.manager.price_proposals_enabled' => true, 'services.sellico.base_url' => 'https://crm.test/api', 'services.sellico.skip_permission_check' => true]);
        Http::preventStrayRequests();
        $this->mock(SellicoApiService::class, fn ($m) => $m->shouldReceive('getServiceToken')->andReturn('service-session'));
        $this->withToken('human-session')->withHeader('X-Sellico-Workspace', '8');
        $scheme = $marketplace === 'ozon' ? 'RFBS' : 'DBS';
        Integration::factory()->{$marketplace}()->create(['id' => 62500, 'work_space_id' => 8, 'settings' => ['own_delivery_cost' => 50, 'own_return_cost' => 20]]);
        $product = Product::factory()->{$marketplace}()->create(['integration_id' => 62500, 'sku' => 'PROPOSAL', 'price' => 1000, 'old_price' => 1000, 'fulfillment_type' => $scheme,
            'wb_data' => ['nmID' => 10001, 'price_version' => 1, 'commissions_by_scheme' => ['dbs' => ['percent' => 10]]],
            'ozon_data' => ['product_id' => 10001, 'offer_id' => 'PROPOSAL', 'actual_price' => 1000, 'price_version' => 1,
                'dimensions' => ['depth' => 100, 'width' => 100, 'height' => 100, 'weight' => 1000], 'commissions_by_scheme' => ['rfbs' => ['percent' => 10]]]]);
        UnitEconomicsSettings::create(['integration_id' => 62500, 'sku' => 'PROPOSAL', 'cost_price' => 200, 'tax_percent' => 6, 'drr_percent' => 5,
            'vat_percent' => 3, 'our_share_percent' => 2, 'redemption_rate_override' => 100]);
        UnitEconomics::create(['integration_id' => 62500, 'sku' => 'PROPOSAL', 'marketplace' => $marketplace, 'fulfillment_type' => $scheme, 'is_actual_scheme' => true,
            'price' => 1000, 'cost_price' => 200, 'commission_percent' => 10, 'effective_logistics' => 50, 'net_profit' => 470, 'net_profit_per_unit' => 470,
            'margin_percent' => 47, 'acquiring_percent' => 2, 'redemption_rate' => 100, 'drr_percent' => 5, 'tariff_source' => 'seller_own_delivery', 'redemption_source' => 'manual',
            'dimensions_observed_at' => now(), 'acquiring_observed_at' => now(), 'marketplace_data' => ['commission_source' => 'wb_commission_api_scheme',
                'dimensions_source' => 'wb_product_characteristics', 'acquiring_source' => 'wb_realization_report_sku']]);
        if ($marketplace === 'ozon') $this->mock(\App\Services\Ozon\OzonLocalityService::class, function ($m) {
            $m->shouldReceive('resolveSkuLocality')->andReturn(['total_orders' => 0, 'locality_rate' => null, 'clusters_summary' => []]);
            $m->shouldReceive('buildShippingRoutesForIntegration')->andReturn([]);
            $m->shouldReceive('countSellerFboOrders7Days')->andReturn(0);
        });
        app(UnitEconomicsCacheService::class)->calculateAndCache($product, $scheme);
        Http::fake(fn ($r) => match (true) {
            $r->url() === 'https://crm.test/api/user' => Http::response(['id' => $this->actor, 'is_service_account' => $this->serviceAccount]),
            str_contains($r->url(), '/user-permissions') => Http::response(['user_id' => $this->actor, 'permissions' => $this->permissions]),
            $r->url() === 'https://crm.test/api/get-integrations/8' => Http::response([['id' => 62500, 'work_space_id' => 8, 'status' => 'active',
                'type' => $marketplace, 'name' => 'Review proposals', 'api_key' => $this->vendorKey, 'client_id' => '555']]),
            str_contains($r->url(), 'discounts-prices-api.wildberries.ru') => Http::response(['error' => false, 'data' => ['listGoods' => [[
                'nmID' => 10001, 'currencyIsoCode4217' => 'RUB', 'discount' => $this->discount, 'clubDiscount' => $this->clubDiscount, 'editableSizePrice' => false, 'wholesaleDiscountThreshold' => [],
                'sizes' => [['sizeID' => 20001, 'price' => $this->nativeBase, 'discountedPrice' => $this->nativePrice, 'clubDiscountedPrice' => $this->nativePrice, 'techSizeName' => 'M']],
            ]]]]),
            $r->url() === 'https://api-seller.ozon.ru/v5/product/info/prices' => Http::response(['items' => [[
                'product_id' => 10001, 'offer_id' => 'PROPOSAL', 'price' => ['currency_code' => 'RUB', 'price' => '1000.00', 'marketing_seller_price' => '1000.00',
                    'min_price' => '500', 'old_price' => '1000', 'auto_action_enabled' => false, 'auto_add_to_ozon_actions_list_enabled' => false],
                'marketing_actions' => ['actions' => [], 'ozon_actions_exist' => false],
            ]]]),
            str_starts_with($r->url(), 'https://ads.test/api/v1/manager/prices/execution-controls/context') => Http::response(['data' => [
                'workspace_id' => '8', 'integration_id' => '62500', 'marketplace' => $marketplace === 'wildberries' ? 'wb' : 'ozon',
                'integration_name' => 'Review proposals', 'scope' => 'configured_price_account',
                'account_proof' => ['resource_id' => '10001', 'resource_label' => 'PROPOSAL', 'source' => $marketplace === 'wildberries' ? 'WB Prices API' : 'Ozon Seller API', 'observed_at' => now()->utc()->toIso8601String()],
                'control' => ['mode' => $this->priceControlMode, 'version' => $this->priceControlVersion, 'changed_at' => $this->priceControlVersion === 0 ? null : '2026-11-05T00:00:00Z'],
                'context_fingerprint' => hash('sha256', 'isolated-price-control-'.$marketplace.'-'.$this->priceControlVersion.'-'.$this->priceControlMode), 'approval_available' => true,
                ...($this->legacyHeldCount === false ? [] : ['legacy_held_count' => $this->legacyHeldCount]),
            ]]),
            str_starts_with($r->url(), 'https://ads.test/api/v1/manager/prices/operations') && $this->priceExecutor !== null => ($this->priceExecutor)($r),
            default => Http::response([], 500),
        });

        return $product;
    }

    private function payload(Product $product): array
    {
        return ['integration_id' => 62500, 'product_id' => $product->id, 'fulfillment_type' => $product->fulfillment_type, 'seller_price' => '1200',
            'constraints' => ['min_margin_percent' => '10', 'min_profit_rub' => '0', 'max_change_percent' => '25']];
    }

    public function test_real_forecast_is_saved_for_review_without_changing_price_settings_or_other_business_rows(): void
    {
        $product = $this->fixture();
        $mutations = [];
        DB::listen(function ($q) use (&$mutations) { if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $q->sql)
            && ! str_contains($q->sql, 'manager_price_proposals')) $mutations[] = $q->sql; });
        $response = $this->postJson('/api/manager/price-proposals/prepare', $this->payload($product))->assertOk()
            ->assertJsonPath('status', 'ready_for_review')->assertJsonPath('seller_price', '1200.00')
            ->assertJsonPath('snapshot.scenario.before.net_profit', 470)->assertJsonPath('snapshot.scenario.after.net_profit', 614)
            ->assertJsonPath('constraints.max_change_percent', '25.00')->assertJsonPath('approval_available', false)
            ->assertJsonPath('snapshot.native_change.after.base_price', '1200.00')->assertJsonPath('snapshot.native_change.after.discount_percent', 0)
            ->assertJsonPath('execution_allowed', false)->assertJsonPath('price_changed', false);
        $this->assertStringNotContainsString('vendor-secret', $response->getContent());
        $this->assertStringNotContainsString('credential_binding', $response->getContent());
        $this->assertStringNotContainsString('calculation_identity', $response->getContent());
        $id = $response->json('id');
        $this->getJson('/api/manager/price-proposals/'.$id)->assertOk()->assertJsonPath('fingerprint', $response->json('fingerprint'));
        $this->assertSame([], $mutations);
        $this->assertEquals(1000, $product->fresh()->price);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_recheck_keeps_unchanged_review_without_replacing_the_original_snapshot_or_extending_its_lease(): void
    {
        $product = $this->fixture();
        $prepared = $this->postJson('/api/manager/price-proposals/prepare', $this->payload($product))->assertOk()->json();
        $this->travel(1)->minutes();
        $this->postJson('/api/manager/price-proposals/'.$prepared['id'].'/recheck', ['fingerprint' => $prepared['fingerprint']])->assertOk()
            ->assertJsonPath('status', 'ready_for_review')->assertJsonPath('expires_at', $prepared['expires_at'])
            ->assertJsonPath('snapshot', $prepared['snapshot'])->assertJsonPath('fingerprint', $prepared['fingerprint']);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_recheck_cancels_a_draft_when_native_price_or_financial_inputs_change(): void
    {
        $product = $this->fixture();
        $prepared = $this->postJson('/api/manager/price-proposals/prepare', $this->payload($product))->assertOk()->json();
        UnitEconomicsSettings::where('integration_id', 62500)->update(['vat_percent' => 4, 'our_share_percent' => 1]);
        $this->postJson('/api/manager/price-proposals/'.$prepared['id'].'/recheck', ['fingerprint' => $prepared['fingerprint']])->assertOk()
            ->assertJsonPath('status', 'cancelled')->assertJsonPath('message_code', 'source_changed')->assertJsonPath('snapshot', $prepared['snapshot']);
        app(UnitEconomicsCacheService::class)->onSettingsChanged(62500, $product->sku);
        $other = $this->postJson('/api/manager/price-proposals/prepare', $this->payload($product))->assertOk()->json();
        $this->nativePrice = 1001;
        $this->postJson('/api/manager/price-proposals/'.$other['id'].'/recheck', ['fingerprint' => $other['fingerprint']])->assertOk()->assertJsonPath('status', 'cancelled');
    }

    public function test_review_cannot_accept_changed_arguments_or_a_forged_fingerprint(): void
    {
        $product = $this->fixture();
        $prepared = $this->postJson('/api/manager/price-proposals/prepare', $this->payload($product))->assertOk()->json();
        $url = '/api/manager/price-proposals/'.$prepared['id'];
        $this->postJson($url.'/cancel', ['fingerprint' => str_repeat('a', 64)])->assertConflict();
        $this->postJson($url.'/recheck', ['fingerprint' => $prepared['fingerprint'], 'seller_price' => '900'])->assertUnprocessable();
        $this->postJson($url.'/cancel', ['fingerprint' => $prepared['fingerprint']])->assertOk()->assertJsonPath('status', 'cancelled');
        $this->postJson($url.'/recheck', ['fingerprint' => $prepared['fingerprint']])->assertOk()->assertJsonPath('status', 'cancelled');
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_expired_foreign_or_tampered_drafts_cannot_be_reused(): void
    {
        $product = $this->fixture();
        $prepared = $this->postJson('/api/manager/price-proposals/prepare', $this->payload($product))->assertOk()->json();
        $url = '/api/manager/price-proposals/'.$prepared['id'];
        $this->actor = 43;
        $this->getJson($url)->assertNotFound();
        $this->actor = 42;
        $this->withHeader('X-Sellico-Workspace', '9')->getJson($url)->assertNotFound();
        $this->withHeader('X-Sellico-Workspace', '8');
        $this->travel(6)->minutes();
        $this->getJson($url)->assertOk()->assertJsonPath('status', 'expired');
        $this->travelBack();
        ManagerPriceProposal::whereKey($prepared['id'])->update(['seller_price' => 999]);
        $this->getJson($url)->assertConflict();
    }

    public function test_recheck_requires_fresh_personal_calculation_permissions_while_stored_history_remains_readable(): void
    {
        $product = $this->fixture();
        $prepared = $this->postJson('/api/manager/price-proposals/prepare', $this->payload($product))->assertOk()->json();
        $url = '/api/manager/price-proposals/'.$prepared['id'];
        $this->permissions = ['products.view', 'marketing.view', 'unit_economics.view'];
        $this->postJson($url.'/recheck', ['fingerprint' => $prepared['fingerprint']])->assertForbidden();
        $this->getJson($url)->assertOk()->assertJsonPath('snapshot', $prepared['snapshot']);
        $this->permissions[] = 'unit_economics.calculate'; $this->serviceAccount = true;
        $this->postJson($url.'/recheck', ['fingerprint' => $prepared['fingerprint']])->assertForbidden();
    }

    public function test_personal_permissions_explicit_constraints_and_enabled_preparation_are_required(): void
    {
        $product = $this->fixture();
        $this->serviceAccount = true;
        $this->postJson('/api/manager/price-proposals/prepare', $this->payload($product))->assertForbidden();
        $this->serviceAccount = false;
        $this->permissions = ['products.view', 'marketing.view', 'unit_economics.view'];
        $this->postJson('/api/manager/price-proposals/prepare', $this->payload($product))->assertForbidden();
        $this->permissions[] = 'unit_economics.calculate';
        $bad = $this->payload($product); unset($bad['constraints']);
        $this->postJson('/api/manager/price-proposals/prepare', $bad)->assertUnprocessable();
        config(['services.manager.price_proposals_enabled' => false]);
        $this->postJson('/api/manager/price-proposals/prepare', $this->payload($product))->assertStatus(503);
        $this->assertSame(0, ManagerPriceProposal::count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'wildberries.ru'));
    }

    public function test_ozon_uses_the_real_financial_owner_and_saves_a_review_without_price_mutation(): void
    {
        $product = $this->fixture('ozon');
        $prepared = $this->postJson('/api/manager/price-proposals/prepare', $this->payload($product))->assertOk()
            ->assertJsonPath('marketplace', 'ozon')->assertJsonPath('fulfillment_type', 'RFBS')->assertJsonPath('snapshot.scenario.status', 'forecast')
            ->assertJsonPath('price_changed', false)->json();
        $this->postJson('/api/manager/price-proposals/'.$prepared['id'].'/recheck', ['fingerprint' => $prepared['fingerprint']])->assertOk()
            ->assertJsonPath('status', 'ready_for_review')->assertJsonPath('snapshot', $prepared['snapshot']);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET' && $r->url() !== 'https://api-seller.ozon.ru/v5/product/info/prices');
        $this->assertEquals(1000, $product->fresh()->price);
    }

    public function test_a_reconnected_store_or_changed_product_cannot_reuse_a_prepared_draft(): void
    {
        $product = $this->fixture();
        $prepared = $this->postJson('/api/manager/price-proposals/prepare', $this->payload($product))->assertOk()->json();
        $this->vendorKey = 'reconnected-secret';
        $this->postJson('/api/manager/price-proposals/'.$prepared['id'].'/recheck', ['fingerprint' => $prepared['fingerprint']])->assertOk()
            ->assertJsonPath('status', 'cancelled')->assertJsonPath('message_code', 'source_changed');
        $another = $this->postJson('/api/manager/price-proposals/prepare', $this->payload($product))->assertOk()->json();
        $product->update(['sku' => 'REMAPPED']);
        $this->postJson('/api/manager/price-proposals/'.$another['id'].'/recheck', ['fingerprint' => $another['fingerprint']])->assertOk()->assertJsonPath('status', 'cancelled');
    }

    public function test_blocked_complex_prices_and_missing_calculation_cannot_be_saved_as_reviewable_forecasts(): void
    {
        $product = $this->fixture(); $this->clubDiscount = 10;
        $this->postJson('/api/manager/price-proposals/prepare', $this->payload($product))->assertConflict();
        $this->clubDiscount = 0;
        \App\Models\UnitEconomicsCache::where('integration_id', 62500)->delete();
        $this->postJson('/api/manager/price-proposals/prepare', $this->payload($product))->assertConflict();
        $this->assertSame(0, ManagerPriceProposal::count());
    }

    public function test_wb_review_saves_only_an_exact_native_target_and_preserves_the_current_seller_discount(): void
    {
        $product = $this->fixture();
        $body = $this->payload($product); $body['seller_price'] = '1200.01';
        $this->postJson('/api/manager/price-proposals/prepare', $body)->assertUnprocessable()->assertJsonPath('code', 'native_target_unrepresentable');
        $this->assertSame(0, ManagerPriceProposal::count());
        $this->discount = 42; $this->nativeBase = 2001; $this->nativePrice = 1160.58;
        $product->update(['price' => 1160.58, 'old_price' => 2001]);
        app(UnitEconomicsCacheService::class)->calculateAndCache($product, 'DBS');
        $body['seller_price'] = '1276.58';
        $prepared = $this->postJson('/api/manager/price-proposals/prepare', $body)->assertOk()
            ->assertJsonPath('snapshot.native_change.after.base_price', '2201.00')
            ->assertJsonPath('snapshot.native_change.after.seller_price', '1276.58')->assertJsonPath('snapshot.native_change.after.discount_percent', 42)->json();
        $this->nativeBase = 1990; // Changed source cannot support the old review.
        $this->postJson('/api/manager/price-proposals/'.$prepared['id'].'/recheck', ['fingerprint' => $prepared['fingerprint']])->assertOk()
            ->assertJsonPath('status', 'cancelled');
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    private function enablePriceExecution(): void
    {
        $this->permissions = [...$this->permissions, 'marketing.manage', 'integrations.view'];
        config(['services.manager.price_execution_enabled' => true, 'services.manager.price_executor_url' => 'https://ads.test/api/v1']);
    }
    private function approvalBody(array $p): array { return ['fingerprint' => $p['fingerprint'], 'review_hash' => $p['personal_review']['hash'],
        'confirmations' => ['financial_assumptions_checked' => true, 'exact_price_and_limits_checked' => true]]; }
    private function nativeReceipt(array $p, string $status = 'verifying'): array { return ['version' => 'manager_price_receipt_v1', 'operation_id' => $p['id'],
        'native_change_id' => '15f77364-c58f-48c5-ae49-5508157f9252', 'marketplace' => $p['marketplace'] === 'wildberries' ? 'wb' : 'ozon',
        'proposal_fingerprint' => $p['fingerprint'], 'status' => $status, 'native_state' => ['verifying' => 'uploaded', 'succeeded' => 'applied', 'failed' => 'failed'][$status]]; }

    public function test_personal_review_keeps_assumptions_visible_and_sends_the_same_command_only_once(): void
    {
        $product = $this->fixture(); $this->enablePriceExecution();
        $p = $this->postJson('/api/manager/price-proposals/prepare', $this->payload($product))->assertOk()->assertJsonPath('approval_available', true)
            ->assertJsonPath('personal_review.source_verified', false)->assertJsonPath('personal_review.complete_dependency_graph_verified', false)->json();
        $count = 0; $callbackError = null;
        if ($path = getenv('MANAGER_PRICE_TEST_CONTRACT')) file_put_contents($path, json_encode($p, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->priceExecutor = function ($r) use ($p, &$count, &$callbackError) {
            try {
            $count++; $command = $r->data();
            if ($dir = getenv('MANAGER_PRICE_TEST_COMMAND_DIR')) file_put_contents($dir.'/wb.json', json_encode($command, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            $this->assertSame('human-session', $r->header('Authorization')[0] === 'Bearer human-session' ? 'human-session' : 'wrong');
            $this->assertSame($p['id'], $command['operation_id']); $this->assertSame(1200, $command['wb_base_price_rub']);
            $this->assertSame(0, $command['execution_control_version']);
            $this->assertSame(120000, $command['seller_price_cents']); $this->assertSame(10000 + 1, $command['before_wb']['nm_id']);
            $this->assertArrayNotHasKey('api_key', $command); $this->assertArrayNotHasKey('token', $command);
            // The real Go callback emits UTC Z rather than PHP's +00:00.
            foreach (['issued_at', 'expires_at'] as $f) $command[$f] = \Carbon\CarbonImmutable::parse($command[$f])->utc()->format('Y-m-d\TH:i:s\Z');
            $verdict = $this->postJson('/api/manager/price-proposals/'.$p['id'].'/validate-execution', $command);
            $this->assertSame(200, $verdict->status(), $verdict->getContent()); $verdict->assertJsonPath('execution_allowed', true);
            $command['seller_price_cents']++;
            $this->postJson('/api/manager/price-proposals/'.$p['id'].'/validate-execution', $command)->assertConflict();
            return Http::response(['data' => $this->nativeReceipt($p)]);
            } catch (\Throwable $error) { $callbackError = $error->getMessage().' at '.$error->getLine(); return Http::response([], 503); }
        };
        $path = '/api/manager/price-proposals/'.$p['id']; $body = $this->approvalBody($p);
        $approved = $this->postJson($path.'/approve', $body)->assertOk(); $this->assertNull($callbackError);
        $approved->assertJsonPath('status', 'verifying')->assertJsonPath('price_changed', false);
        $this->postJson($path.'/approve', $body)->assertOk()->assertJsonPath('status', 'verifying'); $this->assertSame(1, $count);
        $this->priceExecutor = fn ($r) => Http::response(['data' => $this->nativeReceipt($p, 'succeeded')]);
        $this->postJson($path.'/reconcile')->assertOk()->assertJsonPath('status', 'succeeded')->assertJsonPath('price_changed', true);
        $this->assertSame('incomplete', ManagerPriceProposal::first()->snapshot['scenario']['financial_evidence']['status']);
    }

    public function test_personal_price_confirmation_rejects_missing_checks_permissions_changed_sources_and_limits(): void
    {
        $product = $this->fixture(); $this->enablePriceExecution();
        $p = $this->postJson('/api/manager/price-proposals/prepare', $this->payload($product))->assertOk()->assertJsonPath('approval_available', true)->json();
        $path = '/api/manager/price-proposals/'.$p['id'].'/approve'; $body = $this->approvalBody($p);
        foreach ([false, 1, 'true'] as $check) { $bad = $body; $bad['confirmations']['financial_assumptions_checked'] = $check; $this->postJson($path, $bad)->assertUnprocessable(); }
        $bad = $body; $bad['review_hash'] = str_repeat('0', 64); $this->postJson($path, $bad)->assertConflict();
        $this->permissions = array_values(array_diff($this->permissions, ['marketing.manage'])); $this->postJson($path, $body)->assertForbidden();
        $this->permissions[] = 'marketing.manage'; $this->nativeBase = 1001; $this->nativePrice = 1001;
        $this->postJson($path, $body)->assertOk()->assertJsonPath('status', 'cancelled');
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_starts_with($r->url(), 'https://ads.test/'));
    }

    public function test_price_timeout_is_unknown_and_reconciliation_or_reapproval_never_sends_again(): void
    {
        $product = $this->fixture(); $this->enablePriceExecution();
        $p = $this->postJson('/api/manager/price-proposals/prepare', $this->payload($product))->assertOk()->assertJsonPath('approval_available', true)->json();
        $posts = 0; $reads = 0;
        $this->priceExecutor = function ($r) use (&$posts, &$reads) { if ($r->method() === 'POST') $posts++; else $reads++; return Http::response([], 503); };
        $path = '/api/manager/price-proposals/'.$p['id']; $body = $this->approvalBody($p);
        $this->postJson($path.'/approve', $body)->assertOk()->assertJsonPath('status', 'unknown');
        $this->postJson($path.'/approve', $body)->assertOk()->assertJsonPath('status', 'unknown');
        $this->postJson($path.'/reconcile')->assertOk()->assertJsonPath('status', 'unknown');
        $this->assertSame(1, $posts); $this->assertSame(1, $reads);
        config(['services.manager.price_execution_enabled' => false]);
        $this->postJson($path.'/approve', $body)->assertServiceUnavailable();
        $this->getJson($path)->assertOk()->assertJsonPath('status', 'unknown');
    }

    public function test_ozon_personal_approval_preserves_old_minimum_and_promotion_context_in_the_executor_contract(): void
    {
        $product = $this->fixture('ozon'); $this->enablePriceExecution();
        $p = $this->postJson('/api/manager/price-proposals/prepare', $this->payload($product))->assertOk()->assertJsonPath('approval_available', true)->json();
        $command = null;
        $this->priceExecutor = function ($r) use ($p, &$command) { $command = $r->data(); return Http::response(['data' => $this->nativeReceipt($p, 'succeeded')]); };
        $this->postJson('/api/manager/price-proposals/'.$p['id'].'/approve', $this->approvalBody($p))->assertOk()->assertJsonPath('status', 'succeeded');
        $this->assertSame(120000, $command['seller_price_cents']);
        $this->assertSame(100000, $command['before_ozon']['old_price_cents']); $this->assertSame(50000, $command['before_ozon']['min_price_cents']);
        $this->assertFalse($command['before_ozon']['conditions']['auto_action_enabled']); $this->assertFalse($command['before_ozon']['conditions']['has_actions']);
        $digest = "manager-price-executor-credentials-v1\0";
        foreach (['ozon', '555', 'vendor-secret'] as $v) $digest .= strlen($v).':'.$v;
        $this->assertSame(hash('sha256', $digest), $command['executor_credentials_digest']);
        if ($dir = getenv('MANAGER_PRICE_TEST_COMMAND_DIR')) file_put_contents($dir.'/ozon.json', json_encode($command, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    public function test_opening_manual_price_commands_does_not_reauthorize_an_older_financial_proposal(): void
    {
        $product = $this->fixture(); $this->enablePriceExecution();
        $old = $this->postJson('/api/manager/price-proposals/prepare', $this->payload($product))->assertOk()->json();
        $this->assertSame(0, $old['snapshot']['price_execution_control']['control']['version']);
        $this->priceControlMode = 'manual_only'; $this->priceControlVersion = 2;
        $this->postJson('/api/manager/price-proposals/'.$old['id'].'/approve', $this->approvalBody($old))->assertOk()
            ->assertJsonPath('status', 'cancelled')->assertJsonPath('message_code', 'source_changed');
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), 'ads.test'));
        $fresh = $this->postJson('/api/manager/price-proposals/prepare', $this->payload($product))->assertOk()->json();
        $this->assertSame(2, $fresh['snapshot']['price_execution_control']['control']['version']);
        $sent = [];
        $this->priceExecutor = function ($r) use ($fresh, &$sent) { $sent[] = $r->data(); return Http::response(['data' => $this->nativeReceipt($fresh, 'succeeded')]); };
        $this->postJson('/api/manager/price-proposals/'.$fresh['id'].'/approve', $this->approvalBody($fresh))->assertOk()->assertJsonPath('status', 'succeeded');
        $this->assertCount(1, $sent); $this->assertSame(2, $sent[0]['execution_control_version']);
        $this->assertNotSame($old['fingerprint'], $fresh['fingerprint']);
    }

    public function test_a_persisted_price_stop_prevents_creation_of_an_executable_financial_proposal(): void
    {
        $product = $this->fixture('ozon'); $this->enablePriceExecution();
        $this->priceControlVersion = 1; $this->priceControlMode = 'stopped';
        $this->postJson('/api/manager/price-proposals/prepare', $this->payload($product))->assertConflict();
        $this->assertSame(0, ManagerPriceProposal::count());
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), 'ads.test'));
    }

    public function test_unresolved_legacy_price_admissions_refuse_a_financial_proposal_before_any_approval(): void
    {
        $product = $this->fixture('ozon'); $this->enablePriceExecution();
        $this->priceControlVersion = 2; $this->priceControlMode = 'manual_only'; $this->legacyHeldCount = 1;
        $this->postJson('/api/manager/price-proposals/prepare', $this->payload($product))->assertConflict()
            ->assertJsonPath('code', 'legacy_price_admissions_unresolved');
        $this->assertSame(0, ManagerPriceProposal::count());
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), 'ads.test'));

        // The same account after every admission was personally reviewed.
        $this->legacyHeldCount = 0;
        $this->postJson('/api/manager/price-proposals/prepare', $this->payload($product))->assertOk();
        $this->assertSame(1, ManagerPriceProposal::count());
    }

    public function test_a_legacy_admission_recorded_after_preparation_revokes_the_review_without_a_price_command(): void
    {
        $product = $this->fixture('ozon'); $this->enablePriceExecution();
        $this->priceControlVersion = 2; $this->priceControlMode = 'manual_only';
        $proposal = $this->postJson('/api/manager/price-proposals/prepare', $this->payload($product))->assertOk()->json();
        $this->legacyHeldCount = 1;
        // The approval rechecks its source first: a definite hold revokes this
        // review instead of claiming it, so no command reaches the executor.
        $this->postJson('/api/manager/price-proposals/'.$proposal['id'].'/approve', $this->approvalBody($proposal))->assertOk()
            ->assertJsonPath('status', 'cancelled')->assertJsonPath('message_code', 'source_changed')->assertJsonPath('price_changed', false);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), 'ads.test'));
        // The next preparation names the reason.
        $this->postJson('/api/manager/price-proposals/prepare', $this->payload($product))->assertConflict()
            ->assertJsonPath('code', 'legacy_price_admissions_unresolved');
        $this->assertSame(1, ManagerPriceProposal::count());
    }

    public function test_a_malformed_legacy_hold_count_is_not_read_as_no_holds_and_a_silent_executor_keeps_the_previous_contract(): void
    {
        $product = $this->fixture('ozon'); $this->enablePriceExecution();
        $this->priceControlVersion = 2; $this->priceControlMode = 'manual_only';
        foreach ([-1, '0', 1.5, null, true] as $malformed) {
            $this->legacyHeldCount = $malformed;
            $this->postJson('/api/manager/price-proposals/prepare', $this->payload($product))->assertStatus(503)->assertJsonMissingPath('code');
        }
        $this->assertSame(0, ManagerPriceProposal::count());

        // An executor that predates the count still has its own dispatch fence.
        $this->legacyHeldCount = false;
        $this->postJson('/api/manager/price-proposals/prepare', $this->payload($product))->assertOk();
        $this->assertSame(1, ManagerPriceProposal::count());
    }
}
