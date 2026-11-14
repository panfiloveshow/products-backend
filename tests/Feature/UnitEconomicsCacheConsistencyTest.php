<?php

namespace Tests\Feature;

use App\Models\UnitEconomicsCache;
use App\Services\UnitEconomics\UnitEconomicsConsistencyInspector;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UnitEconomicsCacheConsistencyTest extends TestCase
{
    #[DataProvider('returnCases')]
    public function test_checks_independently_calculated_per_sold_unit_costs(string $marketplace, string $scheme, float $redemption, float $forward, float $backward, float $processing, ?int $orders, ?int $returns, float $expected): void
    {
        $row = new UnitEconomicsCache(['marketplace' => $marketplace, 'fulfillment_type' => $scheme,
            'redemption_rate' => $redemption, 'logistics_cost' => $forward, 'return_logistics_cost' => $backward,
            'return_processing_cost' => $processing, 'last_mile_cost' => 25, 'processing_cost' => 5,
            'expected_return_cost' => $expected, 'effective_logistics' => $forward + 30 + $expected,
            'marketplace_data' => ['return_audit' => ['model' => 'per_sold_unit_v1',
                'orders_count' => $orders, 'returns_count' => $returns, 'cancelled_count' => 0]]]);
        $inspector = new UnitEconomicsConsistencyInspector;
        $good = $inspector->inspect($row);
        $this->assertTrue($good['return_checked']);
        $this->assertSame($expected, $good['return_expected']);
        $this->assertFalse($good['has_drift']);
        $row->expected_return_cost = $expected + 5;
        $this->assertTrue($inspector->inspect($row)['has_drift']);
    }

    public static function returnCases(): array
    {
        return [
            // Fixed expectations from independent arithmetic: 150 * 30 / 70.
            'WB both legs per sold item' => ['wildberries', 'FBO', 70, 100, 50, 0, 100, 0, 64.29],
            // (100+100+15) * (0.2+0.1) / 0.7.
            'Ozon cancellations and post-delivery returns' => ['ozon', 'FBO', 80, 130, 100, 15, 100, 10, 92.14],
            'Ozon full buyout still has post-delivery returns' => ['ozon', 'FBS', 100, 300, 300, 50, 100, 10, 72.22],
            'Ozon own delivery includes both legs' => ['ozon', 'RFBS', 50, 100, 70, 0, 100, 0, 170.0],
            'zero buyout capped at three returns per sale' => ['ozon', 'FBO', 0, 200, 200, 15, 100, 0, 1245.0],
        ];
    }

    public function test_unknown_legacy_formula_inputs_are_reported_as_unchecked_instead_of_false_drift(): void
    {
        $row = new UnitEconomicsCache(['marketplace' => 'ozon', 'redemption_rate' => 70,
            'logistics_cost' => 100, 'expected_return_cost' => 75, 'effective_logistics' => 175]);
        $result = (new UnitEconomicsConsistencyInspector)->inspect($row);
        $this->assertFalse($result['return_checked']);
        $this->assertNull($result['return_expected']);
        $this->assertFalse($result['has_drift']);
    }
}
