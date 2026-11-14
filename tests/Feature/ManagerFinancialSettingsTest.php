<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\ManagerFinancialSettingChange;
use App\Models\Product;
use App\Models\UnitEconomicsSettings;
use App\Services\SellicoApiService;
use App\Services\UnitEconomicsCacheService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManagerFinancialSettingsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Product $product;
    private int $user = 42;
    private string $key = 'vendor-test-only';
    private string $marketplace = 'ozon';
    private array $permissions = ['products.view', 'unit_economics.view', 'unit_economics.settings.edit'];
    private int $refreshes = 0;
    private bool $failRefresh = false;
    private bool $personalProfile = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sellico.base_url' => 'https://crm.test/api', 'services.manager.financial_settings_enabled' => true]);
        Http::preventStrayRequests();
        $this->mock(SellicoApiService::class, fn ($m) => $m->shouldReceive('getServiceToken')->andReturn('service-test-only'));
        $this->mock(UnitEconomicsCacheService::class, function ($m) {
            $m->shouldReceive('onSettingsChanged')->andReturnUsing(function ($integrationId, $sku) {
                $this->assertSame(62291, $integrationId); $this->assertSame('FINANCIAL-SKU', $sku); $this->refreshes++;
                if ($this->failRefresh) throw new \RuntimeException('Simulated calculation interruption');
            });
        });
        Http::fake(function ($r) {
            return match ($r->url()) {
                'https://crm.test/api/user' => Http::response(['id' => $this->user, ...($this->personalProfile ? ['is_service_account' => false] : [])]),
                'https://crm.test/api/workspaces/8/user-permissions' => Http::response(['user_id' => $this->user, 'permissions' => $this->permissions]),
                'https://crm.test/api/get-integrations/8' => Http::response([['id' => 62291, 'work_space_id' => 8, 'type' => $this->marketplace,
                    'name' => 'Test store', 'status' => 'active', 'api_key' => $this->key, 'client_id' => '555']]),
                default => throw new \RuntimeException('Unexpected external financial-settings request'),
            };
        });
        Integration::factory()->ozon()->create(['id' => 62291, 'work_space_id' => 8]);
        $this->product = Product::factory()->ozon()->create(['integration_id' => 62291, 'sku' => 'FINANCIAL-SKU', 'ozon_data' => ['product_id' => 10001]]);
        $this->withToken('human-test-only')->withHeader('X-Sellico-Workspace', '8')->withHeader('X-User-Id', '999');
    }

    private function prepare(array $values = ['cost_price' => '500', 'tax_percent' => '6', 'drr_percent' => '0']): array
    {
        return $this->postJson('/api/manager/financial-settings/prepare', ['integration_id' => 62291, 'product_id' => $this->product->id, 'values' => $values])->assertOk()->json();
    }

    private function approve(array $op): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/manager/financial-settings/'.$op['id'].'/approve', ['fingerprint' => $op['fingerprint'], 'confirmations' => ['financial_inputs_checked' => true]]);
    }

    public function test_prepare_only_saves_a_personal_proposal_without_creating_or_confirming_defaults(): void
    {
        $op = $this->prepare();
        $this->assertSame('500.00', $op['values']['cost_price']); $this->assertSame('0.00', $op['values']['drr_percent']);
        $this->assertNull($op['diff'][0]['before']); $this->assertNull($op['result']); $this->assertFalse($op['price_execution_allowed']);
        $this->assertDatabaseCount('unit_economics_settings', 0); $this->assertSame(0, $this->refreshes);
        $this->assertSame(42, ManagerFinancialSettingChange::findOrFail($op['id'])->created_by);
        $this->assertStringNotContainsString($this->key, json_encode($op));
    }

    public function test_approval_saves_exact_explicit_fields_once_and_preserves_default_provenance(): void
    {
        $settings = UnitEconomicsSettings::getOrCreate(62291, 'FINANCIAL-SKU');
        $op = $this->prepare();
        $this->approve($op)->assertOk()->assertJsonPath('status', 'succeeded')->assertJsonPath('result.settings_saved', true)
            ->assertJsonPath('result.price_changed', false)->assertJsonPath('result.recalculation_verified', false);
        $this->approve($op)->assertOk();
        $this->assertSame('500.00', $settings->fresh()->cost_price); $this->assertSame('0.00', $settings->fresh()->drr_percent);
        $this->assertSame(1, $this->refreshes); $this->assertDatabaseCount('unit_economics_settings', 1);
        $this->assertCount(1, array_filter(ManagerFinancialSettingChange::findOrFail($op['id'])->events, fn ($e) => $e['event'] === 'settings_saved'));
        Http::assertNotSent(fn ($r) => ! str_starts_with($r->url(), 'https://crm.test/'));
    }

    public function test_reading_a_saved_receipt_after_refresh_failure_does_not_reapply_or_claim_recalculation(): void
    {
        $op = $this->prepare(); $this->failRefresh = true;
        $this->approve($op)->assertOk()->assertJsonPath('status', 'succeeded')->assertJsonPath('result.recalculation_status', 'unconfirmed');
        $this->getJson('/api/manager/financial-settings/'.$op['id'])->assertOk()->assertJsonPath('result.settings_saved', true);
        $this->approve($op)->assertOk(); $this->assertSame(1, $this->refreshes);
        $this->assertDatabaseCount('unit_economics_settings', 1);
    }

    public function test_changed_settings_or_a_reconnected_store_cancel_the_old_proposal(): void
    {
        $settings = UnitEconomicsSettings::getOrCreate(62291, 'FINANCIAL-SKU'); $op = $this->prepare();
        $settings->update(['tax_percent' => 7]);
        $this->approve($op)->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('message_code', 'settings_changed');
        $this->assertSame('0.00', $settings->fresh()->cost_price);
        $other = $this->prepare(); $this->key = 'new-vendor-test-only';
        $this->approve($other)->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('message_code', 'source_changed');
        $this->assertSame(0, $this->refreshes);
    }

    public function test_revoked_rights_another_actor_expiry_and_stop_prevent_application(): void
    {
        $op = $this->prepare(); $this->permissions = ['products.view', 'unit_economics.view']; $this->approve($op)->assertForbidden();
        $this->permissions[] = 'unit_economics.settings.edit'; $this->user = 99; $this->approve($op)->assertNotFound();
        $this->user = 42; $this->travel(6)->minutes(); $this->approve($op)->assertOk()->assertJsonPath('status', 'expired');
        $another = $this->prepare(); config(['services.manager.financial_settings_enabled' => false]); $this->approve($another)->assertStatus(503);
        $this->assertDatabaseCount('unit_economics_settings', 0);
    }

    public function test_changing_the_cost_choice_without_changing_its_number_invalidates_the_proposal(): void
    {
        $op = $this->prepare(['cost_price' => '0']); $this->approve($op)->assertOk();
        $another = $this->prepare(['tax_percent' => '7']);
        $settings = UnitEconomicsSettings::where('integration_id', 62291)->where('sku', 'FINANCIAL-SKU')->firstOrFail();
        $settings->update(['cost_price' => 0]);
        $this->assertSame('0.00', $settings->fresh()->cost_price); $this->assertNull($settings->fresh()->explicit_cost_price);
        $this->approve($another)->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('message_code', 'settings_changed');
        $this->assertSame(1, $this->refreshes);
    }

    public function test_pending_marker_migration_prevents_preparation_and_application_but_preserves_receipt_reading(): void
    {
        $op = $this->prepare(['cost_price' => '0']);
        \Illuminate\Support\Facades\Schema::table('unit_economics_settings', fn ($t) => $t->dropColumn('explicit_cost_price'));
        $this->postJson('/api/manager/financial-settings/prepare', ['integration_id' => 62291, 'product_id' => $this->product->id,
            'values' => ['cost_price' => '0']])->assertStatus(503);
        $this->approve($op)->assertStatus(503);
        $this->getJson('/api/manager/financial-settings/'.$op['id'])->assertOk()->assertJsonPath('status', 'awaiting_confirmation');
        $this->assertDatabaseCount('unit_economics_settings', 0); $this->assertSame(0, $this->refreshes);
    }

    public function test_personal_confirmation_is_required_and_cannot_override_the_saved_fields(): void
    {
        $op = $this->prepare();
        foreach ([[], ['financial_inputs_checked' => false], ['financial_inputs_checked' => 'true'], ['financial_inputs_checked' => true, 'force' => true]] as $confirmations) {
            $this->postJson('/api/manager/financial-settings/'.$op['id'].'/approve', ['fingerprint' => $op['fingerprint'], 'confirmations' => $confirmations])->assertUnprocessable();
        }
        $this->postJson('/api/manager/financial-settings/'.$op['id'].'/approve', ['fingerprint' => $op['fingerprint'], 'confirmations' => ['financial_inputs_checked' => true], 'values' => ['cost_price' => '1']])->assertUnprocessable();
        $this->assertDatabaseCount('unit_economics_settings', 0);
    }

    public function test_json_object_reordering_does_not_change_the_saved_proposal(): void
    {
        UnitEconomicsSettings::getOrCreate(62291, 'FINANCIAL-SKU'); $op = $this->prepare();
        $row = ManagerFinancialSettingChange::findOrFail($op['id']); $b = $row->baseline;
        $row->update(['baseline' => ['values' => array_reverse($b['values'], true), 'settings_id' => $b['settings_id']], 'values' => array_reverse($row->values, true)]);
        $this->approve($op)->assertOk()->assertJsonPath('status', 'succeeded');
    }

    public function test_only_explicit_applied_values_gain_personal_provenance_and_metadata_updates_do_not_refresh_it(): void
    {
        UnitEconomicsSettings::getOrCreate(62291, 'FINANCIAL-SKU'); $op = $this->prepare(); $this->approve($op)->assertOk();
        $row = ManagerFinancialSettingChange::findOrFail($op['id']);
        $settings = UnitEconomicsSettings::where('integration_id', 62291)->where('sku', 'FINANCIAL-SKU')->firstOrFail();
        $preview = ['inputs' => ['integration_id' => 62291, 'sku' => 'FINANCIAL-SKU', 'marketplace' => 'ozon', 'fulfillment_type' => 'FBO',
            'price' => 1000, 'cost_price' => 500, 'tax_percent' => 6, 'drr_percent' => 0, 'vat_percent' => 0],
            'after' => ['sku' => 'FINANCIAL-SKU', 'marketplace' => 'ozon', 'fulfillment_type' => 'FBO', 'price' => 1000]];
        $evidence = app(\App\Services\Manager\ManagerPriceFinancialEvidence::class);
        $result = $evidence->describe($this->product, 'FBO', $preview, $settings, $row->credential_binding);
        $this->assertSame('user_supplied', $result['items']['cost_price']['status']);
        $this->assertSame('manager_personal_input', $result['items']['drr_percent']['source']);
        $this->assertSame(0.0, $result['items']['drr_percent']['value']);
        $this->assertSame('configured_unconfirmed', $result['items']['vat_percent']['status']);
        $this->assertNotContains('cost_price', $result['unverified_inputs']); $this->assertFalse($result['execution_allowed']);
        $other = $evidence->describe($this->product, 'FBO', $preview, $settings, str_repeat('a', 64));
        $this->assertSame('configured_unconfirmed', $other['items']['cost_price']['status']);
        $reusedProduct = clone $this->product; $reusedProduct->ozon_data = ['product_id' => 20002];
        $reused = $evidence->describe($reusedProduct, 'FBO', $preview, $settings, $row->credential_binding);
        $this->assertSame('configured_unconfirmed', $reused['items']['cost_price']['status']);
        $this->travel(73)->hours(); $settings->touch();
        $stale = $evidence->describe($this->product, 'FBO', $preview, $settings->fresh(), $row->credential_binding);
        $this->assertSame('user_input_stale', $stale['items']['cost_price']['status']);
        $this->assertContains('cost_price', $stale['unverified_inputs']);
        $this->assertSame($result['items']['cost_price']['observed_at'], $stale['items']['cost_price']['observed_at']);
        $settings->update(['cost_price' => 501]); $preview['inputs']['cost_price'] = 501;
        $changed = $evidence->describe($this->product, 'FBO', $preview, $settings->fresh(), $row->credential_binding);
        $this->assertSame('configured_unconfirmed', $changed['items']['cost_price']['status']);
    }

    public function test_ledger_write_failure_rolls_back_the_setting_and_readback_allows_a_definite_retry(): void
    {
        $op = $this->prepare();
        ManagerFinancialSettingChange::saving(function ($row) { if ($row->status === 'succeeded') throw new \RuntimeException('Simulated journal write failure'); });
        try { $this->approve($op)->assertStatus(503); }
        finally { ManagerFinancialSettingChange::flushEventListeners(); }
        $this->assertDatabaseCount('unit_economics_settings', 0); $this->assertSame(0, $this->refreshes);
        $this->getJson('/api/manager/financial-settings/'.$op['id'])->assertOk()->assertJsonPath('status', 'awaiting_confirmation');
        $this->approve($op)->assertOk()->assertJsonPath('status', 'succeeded');
        $this->assertDatabaseCount('unit_economics_settings', 1); $this->assertSame(1, $this->refreshes);
    }

    public function test_financial_reading_remains_compatible_before_the_new_migration(): void
    {
        $settings = UnitEconomicsSettings::getOrCreate(62291, 'FINANCIAL-SKU'); $settings->update(['cost_price' => 500]);
        \Illuminate\Support\Facades\Schema::drop('manager_financial_setting_changes');
        $preview = ['inputs' => ['integration_id' => 62291, 'sku' => 'FINANCIAL-SKU', 'marketplace' => 'ozon', 'fulfillment_type' => 'FBO', 'price' => 1000, 'cost_price' => 500],
            'after' => ['sku' => 'FINANCIAL-SKU', 'marketplace' => 'ozon', 'fulfillment_type' => 'FBO', 'price' => 1000]];
        $result = app(\App\Services\Manager\ManagerPriceFinancialEvidence::class)->describe($this->product, 'FBO', $preview, $settings, str_repeat('a', 64));
        $this->assertSame('configured_unconfirmed', $result['items']['cost_price']['status']); $this->assertFalse($result['execution_allowed']);
    }

    public function test_wb_preserves_explicit_zero_and_manual_buyout_provenance_for_its_selected_source(): void
    {
        $this->marketplace = 'wildberries'; Integration::findOrFail(62291)->update(['marketplace' => 'wildberries']);
        $this->product->update(['marketplace' => 'wildberries', 'wb_data' => ['nmID' => 50005]]);
        $op = $this->prepare(['tax_percent' => '0', 'acceptance_cost' => '0', 'redemption_rate_override' => '0']);
        $this->approve($op)->assertOk()->assertJsonPath('marketplace', 'wildberries')->assertJsonPath('result.price_changed', false);
        $row = ManagerFinancialSettingChange::findOrFail($op['id']); $settings = UnitEconomicsSettings::where('integration_id', 62291)->firstOrFail();
        $this->assertSame('0.00', $settings->redemption_rate_override);
        $preview = ['inputs' => ['integration_id' => 62291, 'sku' => 'FINANCIAL-SKU', 'marketplace' => 'wildberries', 'fulfillment_type' => 'FBS',
            'price' => 1000, 'tax_percent' => 0, 'acceptance_cost' => 0, 'redemption_rate' => 0, 'redemption_source' => 'manual'],
            'after' => ['sku' => 'FINANCIAL-SKU', 'marketplace' => 'wildberries', 'fulfillment_type' => 'FBS', 'price' => 1000]];
        $evidence = app(\App\Services\Manager\ManagerPriceFinancialEvidence::class);
        $result = $evidence->describe($this->product->fresh(), 'FBS', $preview, $settings, $row->credential_binding);
        $this->assertSame('user_supplied', $result['items']['redemption_rate']['status']);
        $this->assertSame(0.0, $result['items']['acceptance_cost']['value']);
        $preview['inputs']['redemption_source'] = 'default';
        $unselected = $evidence->describe($this->product->fresh(), 'FBS', $preview, $settings, $row->credential_binding);
        $this->assertSame('unverified', $unselected['items']['redemption_rate']['status']);
    }

    public function test_missing_personal_classification_cannot_certify_seller_input(): void
    {
        $this->personalProfile = false;
        $this->postJson('/api/manager/financial-settings/prepare', ['integration_id' => 62291, 'product_id' => $this->product->id, 'values' => ['tax_percent' => '6']])->assertStatus(503);
        $this->assertDatabaseCount('manager_financial_setting_changes', 0); $this->assertDatabaseCount('unit_economics_settings', 0);
    }

    public function test_invalid_fields_precision_and_foreign_store_are_rejected(): void
    {
        foreach ([['cost_price' => '1.001'], ['tax_percent' => '100.01'], ['cost_price' => 500], ['force' => '1'], []] as $values) {
            $this->postJson('/api/manager/financial-settings/prepare', ['integration_id' => 62291, 'product_id' => $this->product->id, 'values' => $values])->assertUnprocessable();
        }
        Integration::findOrFail(62291)->update(['work_space_id' => 9]);
        $this->postJson('/api/manager/financial-settings/prepare', ['integration_id' => 62291, 'product_id' => $this->product->id, 'values' => ['tax_percent' => '6']])->assertNotFound();
    }
}
