<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\UnitEconomicsSettings;
use App\Services\Manager\ManagerPriceFinancialEvidence;
use App\Services\UnitEconomics\OzonCommissionObservation;
use Tests\TestCase;

class ManagerPriceFinancialEvidenceTest extends TestCase
{
    private function fixture(string $at = '2026-11-06T00:00:00+00:00', float $rate = 20.001): array
    {
        $this->travelTo(now()->parse('2026-11-06T01:00:00Z'));
        $product = new Product(['integration_id' => 18, 'marketplace' => 'ozon', 'sku' => 'LEDGER', 'ozon_data' => ['product_id' => 10001]]);
        $product->id = 'b364cf77-a69c-4e77-b92c-e44855a199b3';
        $product->ozon_data = app(OzonCommissionObservation::class)->merge($product, $product->ozon_data,
            ['product_id' => 10001, 'offer_id' => 'LEDGER', 'commissions_observed_at' => $at, 'commissions' => ['sales_percent_fbo' => $rate]]);
        $preview = ['inputs' => ['integration_id' => 18, 'sku' => 'LEDGER', 'marketplace' => 'ozon', 'fulfillment_type' => 'FBO',
            'price' => 1300.01, 'cost_price' => 600, 'tax_percent' => 6, 'vat_percent' => 0, 'drr_percent' => 0, 'our_share_percent' => 0,
            'commission_rate' => $rate, 'commission_rate_source' => 'ozon_prices_v5', 'commission_observed_at' => $at,
            'acquiring_percent' => 1.5, 'redemption_rate' => 85, 'packaging_cost' => 0, 'additional_costs' => 0],
            'after' => ['price' => 1300.01, 'sku' => 'LEDGER', 'marketplace' => 'ozon', 'fulfillment_type' => 'FBO',
                'commission_source' => 'ozon_prices_v5', 'commission_input_source' => 'ozon_prices_v5', 'commission_observed_at' => $at]];
        $settings = new UnitEconomicsSettings(['integration_id' => 18, 'sku' => 'LEDGER', 'cost_price' => 600,
            'tax_percent' => 6, 'vat_percent' => 0, 'drr_percent' => 0, 'our_share_percent' => 0]);

        return [$product, $preview, $settings];
    }

    public function test_bound_native_observation_keeps_fractional_percent_and_does_not_certify_other_costs(): void
    {
        [$product, $preview, $settings] = $this->fixture();
        $evidence = app(ManagerPriceFinancialEvidence::class)->describe($product, 'FBO', $preview, $settings);
        $this->assertSame('incomplete', $evidence['status']);
        $this->assertFalse($evidence['execution_allowed']);
        $this->assertSame(20.001, $evidence['items']['commission_rate']['value']);
        $this->assertSame('observed', $evidence['items']['commission_rate']['status']);
        $this->assertSame('configured_unconfirmed', $evidence['items']['tax_percent']['status']);
        $this->assertSame(0.0, $evidence['items']['drr_percent']['value']);
        $this->assertSame('unverified', $evidence['items']['acquiring_percent']['status']);
        $this->assertNotContains('commission_rate', $evidence['unverified_inputs']);
        $this->assertContains('tax_percent', $evidence['unverified_inputs']);
    }

    public function test_old_commission_stays_stale_when_product_and_calculation_are_updated_now(): void
    {
        [$product, $preview, $settings] = $this->fixture('2026-11-01T00:00:00+00:00');
        $product->updated_at = now();
        $preview['after']['calculated_at'] = now()->toIso8601String();
        $item = app(ManagerPriceFinancialEvidence::class)->describe($product, 'FBO', $preview, $settings)['items']['commission_rate'];
        $this->assertSame('stale', $item['status']);
        $this->assertSame('2026-11-01T00:00:00+00:00', $item['observed_at']);
    }

    public function test_an_observation_for_another_scope_or_an_unused_rate_is_not_certified(): void
    {
        foreach (['scheme', 'product', 'input_rate', 'result_date', 'conflict'] as $case) {
            [$product, $preview, $settings] = $this->fixture();
            if ($case === 'scheme') $preview['inputs']['fulfillment_type'] = 'FBS';
            if ($case === 'product') $product->sku = 'ANOTHER';
            if ($case === 'input_rate') $preview['inputs']['commission_rate'] = 15;
            if ($case === 'result_date') $preview['after']['commission_observed_at'] = now()->toIso8601String();
            if ($case === 'conflict') {
                $data = $product->ozon_data; $data['commission_observations']['fbo']['status'] = 'conflict'; $product->ozon_data = $data;
            }
            $evidence = app(ManagerPriceFinancialEvidence::class)->describe($product, 'FBO', $preview, $settings);
            $this->assertNotSame('observed', $evidence['items']['commission_rate']['status'], $case);
            $this->assertFalse($evidence['execution_allowed']);
        }
    }

    public function test_selected_model_rate_is_shown_instead_of_discarded_unverified_rate(): void
    {
        [$product, $preview, $settings] = $this->fixture();
        $preview['inputs']['commission_rate'] = 31;
        $preview['inputs']['commission_rate_source'] = 'unverified_marketplace';
        $preview['after']['commission_source'] = 'ozon_category_table';
        $preview['after']['commission_rate_base'] = 47;
        $item = app(ManagerPriceFinancialEvidence::class)->describe($product, 'FBO', $preview, $settings)['items']['commission_rate'];
        $this->assertSame(47.0, $item['value']);
        $this->assertSame('model_assumption', $item['status']);
        $this->assertSame('financial_model', $item['source']);
        $this->assertNull($item['observed_at']);
    }

    public function test_foreign_settings_missing_inputs_and_non_numeric_values_cannot_be_certified(): void
    {
        [$product, $preview, $settings] = $this->fixture();
        $settings->integration_id = 99;
        $preview['inputs']['acquiring_percent'] = true;
        $preview['inputs']['redemption_rate'] = INF;
        $preview['inputs']['_extra'] = ['api_key' => 'must-not-escape', 'customer' => 'must-not-escape'];
        $evidence = app(ManagerPriceFinancialEvidence::class)->describe($product, 'FBO', $preview, $settings);
        $this->assertSame('unverified', $evidence['items']['tax_percent']['status']);
        $this->assertSame('missing', $evidence['items']['acquiring_percent']['status']);
        $this->assertSame('missing', $evidence['items']['redemption_rate']['status']);
        $this->assertStringNotContainsString('must-not-escape', json_encode($evidence));
        $missing = app(ManagerPriceFinancialEvidence::class)->describe($product, 'FBO', [], $settings);
        $this->assertSame('unavailable', $missing['status']);
        $this->assertNull($missing['seller_price']);
        $this->assertFalse($missing['execution_allowed']);
    }

    public function test_usage_is_bound_to_the_owner_and_scheme_and_cannot_be_inferred_from_zeroes(): void
    {
        [$product, $preview, $settings] = $this->fixture();
        $fields = \App\Services\UnitEconomics\FinancialInputUsage::FIELDS;
        $usage = ['version' => 'price_input_usage_v1', 'source' => 'products_main_financials_v1', 'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO', 'items' => array_fill_keys(array_keys($fields), 'applied')];
        $usage['items']['own_delivery_cost'] = 'not_applied';
        $preview['after']['financial_input_usage'] = $usage;
        $read = fn ($candidate) => app(ManagerPriceFinancialEvidence::class)->describe($product, 'FBO', $candidate, $settings);
        $valid = $read($preview);
        $this->assertSame('applied', $valid['input_usage']['items']['vat_percent']);
        $this->assertContains('vat_percent', $valid['unverified_applied_inputs']);
        $this->assertNotContains('own_delivery_cost', $valid['unverified_applied_inputs']);
        $this->assertContains('own_delivery_cost', $valid['unverified_inputs']);
        $this->assertSame('incomplete', $valid['status']);
        foreach ([
            null, [...$usage, 'fulfillment_type' => 'RFBS'], [...$usage, 'source' => 'guessed'],
            [...$usage, 'items' => [...$usage['items'], 'vat_percent' => 'not_applied']],
            [...$usage, 'items' => [...$usage['items'], 'price' => 'not_applied']],
        ] as $invalid) {
            $preview['after']['financial_input_usage'] = $invalid;
            $result = $read($preview);
            $this->assertNull($result['input_usage']['source']);
            $this->assertSame($result['unverified_inputs'], $result['unverified_applied_inputs']);
            $this->assertFalse($result['execution_allowed']);
        }
        $preview['after']['financial_input_usage'] = $usage;
        $preview['inputs']['sku'] = 'OTHER';
        $unbound = $read($preview);
        $this->assertSame('unavailable', $unbound['status']);
        $this->assertNull($unbound['input_usage']['source']);
    }
}
