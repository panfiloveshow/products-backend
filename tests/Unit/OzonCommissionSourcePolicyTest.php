<?php

namespace Tests\Unit;

use App\Domains\Ozon\UnitEconomics\OzonUnitEconomicsCalculator;
use App\Domains\UnitEconomics\DTO\CalculationInput;
use Tests\TestCase;

class OzonCommissionSourcePolicyTest extends TestCase
{
    private function input(array $overrides = []): CalculationInput
    {
        return CalculationInput::fromArray(array_replace(['sku' => 'COMMISSION-SOURCE-TEST', 'integration_id' => 1,
            'marketplace' => 'ozon', 'fulfillment_type' => 'FBO', 'price' => 1500, 'cost_price' => 100,
            'length' => 10, 'width' => 10, 'height' => 10, 'weight' => 1, 'redemption_rate' => 100,
            'category_id' => 'Автозвук', 'order_date' => '2026-09-01', 'commission_rate' => 31,
            'sales_7_days' => 100], $overrides));
    }

    public function test_unobserved_marketplace_rate_cannot_override_the_available_category_tariff(): void
    {
        $result = (new OzonUnitEconomicsCalculator)->calculate($this->input([
            'commission_rate_source' => 'unverified_marketplace',
        ]))->toArray();
        // The versioned repository table is 50%, with its configured -3 pp
        // adjustment. This is a model-selection test, not a live tariff claim.
        $this->assertSame(47.0, $result['commission_rate_base']);
        $this->assertSame('ozon_category_table', $result['commission_source']);
        $this->assertSame('unverified_marketplace', $result['commission_input_source']);
        $this->assertNull($result['commission_observed_at']);
    }

    public function test_default_and_unverified_cached_base_rates_cannot_replace_the_known_category_tariff(): void
    {
        foreach (['model_default', 'cached_base_unverified'] as $source) {
            $result = (new OzonUnitEconomicsCalculator)->calculate($this->input(['commission_rate_source' => $source]))->toArray();
            $this->assertSame(47.0, $result['commission_rate_base']);
        }
    }

    public function test_real_observation_manual_input_and_realized_rate_keep_their_existing_meaning(): void
    {
        $calculator = new OzonUnitEconomicsCalculator;
        $observed = $calculator->calculate($this->input(['commission_rate_source' => 'ozon_prices_v5', 'commission_observed_at' => '2026-08-29']))->toArray();
        $this->assertSame(31.0, $observed['commission_rate_base']);
        $this->assertSame('ozon_prices_v5', $observed['commission_source']);
        $this->assertSame('2026-08-29', $observed['commission_observed_at']);
        $manual = $calculator->calculate($this->input())->toArray();
        $this->assertSame(31.0, $manual['commission_rate_base']);
        $realized = $calculator->calculate($this->input(['commission_rate_source' => 'realized_finance', 'commission_rate_is_effective' => true, 'commission_rate' => 7]))->toArray();
        $this->assertSame(7.0, $realized['commission_rate_base']);
        $this->assertSame('realized_finance', $realized['commission_source']);
        $this->assertSame(0.0, $realized['locality_commission_discount_pp']);
    }

    public function test_an_unknown_category_does_not_invent_a_verified_tariff(): void
    {
        $result = (new OzonUnitEconomicsCalculator)->calculate($this->input([
            'category_id' => '__NO_MATCHING_CATEGORY__', 'commission_rate_source' => 'unverified_marketplace',
        ]))->toArray();
        $this->assertSame(31.0, $result['commission_rate_base']);
        $this->assertSame('unverified_marketplace', $result['commission_source']);
    }
}
