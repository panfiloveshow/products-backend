<?php

namespace Tests\Unit\AutoSupplyPlanning;

use App\Models\AutoSupplyPlan;
use App\Services\AutoSupplyPlanning\DeficitSurplusPlanningService;
use PHPUnit\Framework\TestCase;

class DeficitSurplusPlanningServiceTest extends TestCase
{
    public function test_analyzes_deficit_surplus_and_in_transit_in_russian_summary(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'ozon',
            'min_cover_days' => 7,
            'target_cover_days' => 21,
        ]);

        $result = (new DeficitSurplusPlanningService())->analyze([
            $this->line('SKU-1', destination: 'Кластер Москва', stock: 2, inTransit: 3, demand: 2, risk: 'high', lostRevenue: 150),
            $this->line('SKU-2', destination: 'Кластер Самара', stock: 100, inTransit: 0, demand: 2),
        ], $plan, ['as_of_date' => '2026-05-28']);

        $this->assertSame('deficit-surplus-1', $result['version']);
        $this->assertSame('включено', $result['status']);
        $this->assertSame(1, $result['deficit_summary']['lines']);
        $this->assertSame(9, $result['deficit_summary']['qty']);
        $this->assertSame(1, $result['deficit_summary']['high_risk_lines']);
        $this->assertSame(150.0, $result['deficit_summary']['lost_revenue_daily']);
        $this->assertSame(675.0, $result['deficit_summary']['lost_revenue_until_min_cover']);
        $this->assertSame(2.5, $result['deficit_summary']['earliest_stockout_after_days']);
        $this->assertSame('2026-05-31', $result['deficit_summary']['earliest_stockout_date']);
        $this->assertSame(2.5, $result['deficit_summary']['top'][0]['days_of_cover']);
        $this->assertSame('2026-05-31', $result['deficit_summary']['top'][0]['stockout_date']);
        $this->assertSame(675.0, $result['deficit_summary']['top'][0]['lost_revenue_until_min_cover']);
        $this->assertSame(1, $result['surplus_summary']['lines']);
        $this->assertSame(58, $result['surplus_summary']['qty']);
        $this->assertSame(29.0, $result['surplus_summary']['overstock_days_total']);
        $this->assertSame(50.0, $result['surplus_summary']['top'][0]['days_of_cover']);
        $this->assertSame(29.0, $result['surplus_summary']['top'][0]['overstock_days_over_target']);
        $this->assertSame(1, $result['in_transit_summary']['lines']);
        $this->assertSame(3, $result['in_transit_summary']['qty']);
        $this->assertSame(3, $result['in_transit_summary']['deficit_covered_qty']);
        $this->assertSame(1.5, $result['in_transit_summary']['coverage_days_total']);
        $this->assertFalse($result['redistribution']['allowed']);
        $this->assertStringContainsString('FBO Ozon/WB', $result['redistribution']['policy']);
        $this->assertContains('Сначала закрыть дефицит: есть риск отсутствия товара и потери выручки. Ближайшая дата риска отсутствия: 2026-05-31.', $result['recommendations']);
    }

    public function test_builds_internal_redistribution_suggestions_only_when_marketplace_allows_it(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'own_warehouse',
            'min_cover_days' => 7,
            'target_cover_days' => 21,
        ]);

        $result = (new DeficitSurplusPlanningService())->analyze([
            $this->line('SKU-1', destination: 'Склад дефицита', destinationId: 'D', stock: 2, demand: 2),
            $this->line('SKU-1', destination: 'Склад профицита', destinationId: 'S', stock: 100, demand: 2),
        ], $plan, ['as_of_date' => '2026-05-28']);

        $this->assertTrue($result['redistribution']['allowed']);
        $this->assertSame(1, $result['redistribution']['suggestions_count']);
        $this->assertSame('SKU-1', $result['redistribution']['suggestions'][0]['sku']);
        $this->assertSame('Склад профицита', $result['redistribution']['suggestions'][0]['from_destination']);
        $this->assertSame('Склад дефицита', $result['redistribution']['suggestions'][0]['to_destination']);
        $this->assertSame(12, $result['redistribution']['suggestions'][0]['transfer_qty']);
        $this->assertSame('2026-05-29', $result['redistribution']['suggestions'][0]['to_stockout_date']);
        $this->assertSame(50.0, $result['redistribution']['suggestions'][0]['from_days_of_cover']);
    }

    private function line(
        string $sku,
        string $destination,
        int $stock,
        float $demand,
        int $inTransit = 0,
        string $risk = 'low',
        float $lostRevenue = 0,
        ?string $destinationId = null,
    ): array {
        return [
            'sku' => $sku,
            'product_name' => 'Товар ' . $sku,
            'destination' => $destination,
            'destination_id' => $destinationId ?? $destination,
            'current_stock' => $stock,
            'in_transit' => $inTransit,
            'demand_daily' => $demand,
            'qty_rounded' => 10,
            'risk_level' => $risk,
            'lost_revenue_daily' => $lostRevenue,
            'cover_days_before' => $demand > 0 ? $stock / $demand : 0,
            'explain_json' => json_encode([
                'inputs' => [
                    'daily_demand' => $demand,
                    'min_cover_days' => 7,
                    'target_cover_days' => 21,
                ],
            ]),
        ];
    }
}
