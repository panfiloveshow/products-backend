<?php

namespace Tests\Unit;

use App\Services\Manager\ManagerDataException;
use App\Services\Manager\ManagerPricePolicy;
use PHPUnit\Framework\TestCase;

class ManagerPricePolicyTest extends TestCase
{
    private function scenario(string $price, string $profit, string $costs, string $before = '100.00'): array
    {
        return ['before' => ['price' => $before, 'net_profit' => '10.00', 'total_costs' => number_format((float) $before - 10, 2, '.', '')],
            'after' => ['price' => $price, 'net_profit' => $profit, 'total_costs' => $costs, 'margin_percent' => 10.00]];
    }

    private function limits(array $overrides = []): array
    {
        return array_replace(['min_margin_percent' => '10.00', 'min_profit_rub' => '0.00', 'max_change_percent' => '25.00'], $overrides);
    }

    public function test_rounded_margin_cannot_approve_a_loss_of_one_kopeck_at_the_threshold(): void
    {
        $policy = new ManagerPricePolicy;
        $quote = $this->scenario('1300.01', '130.00', '1170.01', '1081.38');
        $result = $policy->evaluate($quote, '1300.01', $this->limits());
        $this->assertSame('outside_limits', $result['status']);
        $this->assertSame(['min_margin_percent_not_met'], $result['violations']);
        $this->assertFalse($result['execution_allowed']);
        $quote['after']['net_profit'] = '130.01';
        $quote['after']['total_costs'] = '1170.00';
        $this->assertSame('within_limits', $policy->evaluate($quote, '1300.01', $this->limits())['status']);
    }

    public function test_change_limit_uses_the_exact_original_price_in_both_directions(): void
    {
        $policy = new ManagerPricePolicy;
        foreach ([['105.00', '20.00', '85.00', true], ['105.01', '20.00', '85.01', false],
            ['95.00', '20.00', '75.00', true], ['94.99', '20.00', '74.99', false]] as [$price, $profit, $cost, $allowed]) {
            $result = $policy->evaluate($this->scenario($price, $profit, $cost), $price, $this->limits(['max_change_percent' => '5.00']));
            $this->assertSame($allowed ? 'within_limits' : 'outside_limits', $result['status']);
            $this->assertSame($allowed ? [] : ['max_change_percent_exceeded'], $result['violations']);
        }
    }

    public function test_minimum_profit_does_not_hide_a_negative_or_sub_kopeck_amount(): void
    {
        $policy = new ManagerPricePolicy;
        $result = $policy->evaluate($this->scenario('100.00', '49.99', '50.01'), '100.00', $this->limits(['min_profit_rub' => '50.00']));
        $this->assertSame(['min_profit_rub_not_met'], $result['violations']);
        $result = $policy->evaluate($this->scenario('100.00', '-0.01', '100.01'), '100.00', $this->limits(['min_margin_percent' => '0.00']));
        $this->assertSame(['min_margin_percent_not_met', 'min_profit_rub_not_met'], $result['violations']);
        $this->expectException(ManagerDataException::class);
        $policy->evaluate($this->scenario('100.00', '0.001', '99.999'), '100.00', $this->limits());
    }

    public function test_no_thresholds_are_invented_and_numeric_zero_remains_an_explicit_choice(): void
    {
        $policy = new ManagerPricePolicy;
        $quote = $this->scenario('100.00', '0.00', '100.00');
        $this->assertSame('not_configured', $policy->evaluate($quote, '100.00', null)['status']);
        $result = $policy->evaluate($quote, '100.00', ['min_margin_percent' => '0', 'min_profit_rub' => '0', 'max_change_percent' => '0']);
        $this->assertSame('within_limits', $result['status']);
        $this->assertSame(['min_margin_percent' => '0.00', 'min_profit_rub' => '0.00', 'max_change_percent' => '0.00'], $result['constraints']);
    }

    public function test_forecast_cannot_be_reused_for_a_different_target_or_inconsistent_costs(): void
    {
        $policy = new ManagerPricePolicy;
        foreach ([['seller_price' => '105.01', 'costs' => '85.00'], ['seller_price' => '105.00', 'costs' => '84.97']] as $case) {
            try {
                $policy->evaluate($this->scenario('105.00', '20.00', $case['costs']), $case['seller_price'], $this->limits());
                $this->fail('An unbound or inconsistent forecast cannot be evaluated.');
            } catch (ManagerDataException $error) {
                $this->assertSame(422, $error->httpStatus);
            }
        }
    }

    public function test_separately_rounded_expenses_cannot_overstate_profit_for_a_limit(): void
    {
        $policy = new ManagerPricePolicy;
        $result = $policy->evaluate($this->scenario('100.00', '10.00', '90.01'), '100.00', $this->limits());
        $this->assertSame('outside_limits', $result['status']);
        $this->assertSame('9.99', $result['amounts']['profit_used_for_limits']);
        $this->assertSame(['min_margin_percent_not_met'], $result['violations']);
    }

    public function test_maximum_supported_amounts_keep_integer_precision_at_the_margin_boundary(): void
    {
        $policy = new ManagerPricePolicy;
        $quote = $this->scenario('1000000000.00', '100000000.00', '900000000.00', '1000000000.00');
        $limits = $this->limits(['max_change_percent' => '0']);
        $this->assertSame('within_limits', $policy->evaluate($quote, '1000000000.00', $limits)['status']);
        $quote['after']['net_profit'] = '99999999.99';
        $quote['after']['total_costs'] = '900000000.01';
        $this->assertSame(['min_margin_percent_not_met'], $policy->evaluate($quote, '1000000000.00', $limits)['violations']);
    }

    public function test_inexact_negative_or_unknown_constraints_are_rejected(): void
    {
        $policy = new ManagerPricePolicy;
        foreach ([['min_profit_rub' => '-1.00'], ['min_margin_percent' => '10.001'], ['max_change_percent' => '1e2'],
            ['min_margin_percent' => 10], ['min_margin_percent' => '100.01'], ['max_change_percent' => '1000.01'], ['force' => true]] as $override) {
            try {
                $policy->normalize($this->limits($override));
                $this->fail('Invalid policy must not be silently weakened.');
            } catch (ManagerDataException $error) {
                $this->assertSame(422, $error->httpStatus);
            }
        }
    }
}
