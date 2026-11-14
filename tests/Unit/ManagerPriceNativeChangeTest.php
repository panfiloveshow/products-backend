<?php

namespace Tests\Unit;

use App\Services\Manager\ManagerDataException;
use App\Services\Manager\ManagerPriceNativeChange;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManagerPriceNativeChangeTest extends TestCase
{
    private function wb(int $discount = 0, string $base = '1000.00', string $seller = '1000.00'): array
    {
        return ['marketplace' => 'wildberries', 'scenario' => ['seller_price' => '1200.00'], 'snapshot' => [
            'external_id' => '10001', 'currency' => 'RUB', 'discount_percent' => $discount, 'club_discount_percent' => 0,
            'editable_size_price' => false, 'wholesale_context_complete' => true, 'wholesale_discount_threshold' => [],
            'observed_at' => now()->toIso8601String(), 'sizes' => [
                ['size_id' => '10', 'base_price' => $base, 'seller_price' => $seller, 'club_price' => $seller],
                ['size_id' => '9', 'base_price' => $base, 'seller_price' => $seller, 'club_price' => $seller],
            ],
        ]];
    }

    public static function wbCases(): array
    {
        return [['1200.00', 0, '1000.00', '1000.00', '1200.00'], ['1276.58', 42, '2001.00', '1160.58', '2201.00'],
            ['1015.00', 42, '2001.00', '1160.58', '1750.00'], ['10.01', 99, '1000.00', '10.00', '1001.00']];
    }

    #[DataProvider('wbCases')]
    public function test_wb_preserves_discount_and_maps_the_exact_seller_target_to_integer_native_rubles(string $target, int $discount, string $oldBase, string $oldSeller, string $newBase): void
    {
        $context = $this->wb($discount, $oldBase, $oldSeller); $context['scenario']['seller_price'] = $target;
        $change = (new ManagerPriceNativeChange)->describe($context);
        $this->assertSame('manager_price_native_change_v1', $change['version']);
        $this->assertSame(['seller_price' => $target, 'base_price' => $newBase, 'discount_percent' => $discount], $change['after']);
        $this->assertSame(['9', '10'], array_column($change['before']['sizes'], 'size_id'));
        $this->assertArrayNotHasKey('observed_at', $change['before']);
        $this->assertSame($context['snapshot']['sizes'], array_reverse($change['before']['sizes']));
    }

    public function test_wb_never_rounds_an_unrepresentable_target_changes_discount_or_accepts_inconsistent_baselines(): void
    {
        $cases = [];
        $v = $this->wb(); $v['scenario']['seller_price'] = '1200.01'; $cases[] = $v;
        $v = $this->wb(42, '2001.00', '1160.58'); $v['scenario']['seller_price'] = '1300.01'; $cases[] = $v;
        $v = $this->wb(); $v['scenario']['seller_price'] = '1000.00'; $cases[] = $v;
        $v = $this->wb(); $v['snapshot']['sizes'][0]['base_price'] = '1000.01'; $cases[] = $v;
        $v = $this->wb(); $v['snapshot']['sizes'][0]['seller_price'] = '999.00'; $cases[] = $v;
        $v = $this->wb(); $v['snapshot']['sizes'][0]['size_id'] = '9'; $cases[] = $v;
        $v = $this->wb(); $v['snapshot']['club_discount_percent'] = 3; $cases[] = $v;
        $v = $this->wb(); $v['snapshot']['wholesale_context_complete'] = false; $cases[] = $v;
        $v = $this->wb(); $v['snapshot']['wholesale_discount_threshold'] = [['quantity' => 5, 'discount' => 10]]; $cases[] = $v;
        foreach ($cases as $context) {
            try { (new ManagerPriceNativeChange)->describe($context); $this->fail('Unverified WB command accepted'); }
            catch (ManagerDataException $e) { $this->assertContains($e->httpStatus, [409, 422]); }
        }
    }

    public function test_ozon_keeps_old_minimum_and_promotion_conditions_without_rounding_kopecks(): void
    {
        $context = ['marketplace' => 'ozon', 'scenario' => ['seller_price' => '1300.01'], 'snapshot' => [
            'external_id' => '9007199254740993', 'offer_id' => 'PRODUCT', 'currency' => 'RUB', 'seller_price' => '1000.00', 'marketing_seller_price' => '1000.00',
            'old_price' => '2000.00', 'min_price' => '500.00', 'auto_action_enabled' => false, 'auto_add_to_actions' => false,
            'promotion_context_complete' => true, 'marketing_actions' => ['actions' => [], 'ozon_actions_exist' => false], 'observed_at' => now()->toIso8601String(),
        ]];
        $change = (new ManagerPriceNativeChange)->describe($context);
        $this->assertSame('9007199254740993', $change['resource_id']);
        $this->assertSame(['seller_price' => '1300.01', 'old_price' => '2000.00', 'min_price' => '500.00'], $change['after']);
        $this->assertArrayNotHasKey('observed_at', $change['before']);
        $context['snapshot']['auto_add_to_actions'] = true;
        $this->expectException(ManagerDataException::class);
        (new ManagerPriceNativeChange)->describe($context);
    }
}
