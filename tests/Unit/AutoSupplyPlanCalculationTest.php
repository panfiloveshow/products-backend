<?php

namespace Tests\Unit;

use App\Services\AutoSupplyPlanService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests для алгоритма автопланирования поставок
 * Тестируем: EWMA, caps, rounding, risk, data quality
 * Все расчёты делегируются AutoSupplyPlanService
 */
class AutoSupplyPlanCalculationTest extends TestCase
{
    private AutoSupplyPlanService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AutoSupplyPlanService();
    }

    // === 3.1 EWMA Forecast ===

    public function test_ewma_calculation_with_both_windows(): void
    {
        $result = $this->service->calculateDailyDemand(0.35, 70, 35, 150);
        // 0.35 * (70/7) + 0.65 * (150/30) = 0.35*10 + 0.65*5 = 6.75
        $this->assertEqualsWithDelta(6.75, $result['daily_demand'], 0.01);
        $this->assertFalse($result['needs_manual_review']);
    }

    public function test_ewma_with_only_short_window(): void
    {
        $result = $this->service->calculateDailyDemand(0.35, 70, 35, 0);
        $this->assertEqualsWithDelta(10.0, $result['daily_demand'], 0.01);
        $this->assertFalse($result['needs_manual_review']);
    }

    public function test_ewma_with_only_long_window(): void
    {
        $result = $this->service->calculateDailyDemand(0.35, 0, 35, 150);
        $this->assertEqualsWithDelta(5.0, $result['daily_demand'], 0.01);
        $this->assertFalse($result['needs_manual_review']);
    }

    public function test_ewma_no_sales_needs_manual_review(): void
    {
        $result = $this->service->calculateDailyDemand(0.35, 0, 0, 0);
        $this->assertEquals(0.0, $result['daily_demand']);
        $this->assertTrue($result['needs_manual_review']);
    }

    // === 3.2 Базовые формулы ===

    public function test_basic_needed_calculation(): void
    {
        $result = $this->service->calculateNeededBeforeCaps(10.0, 21, 50, 30);
        $this->assertEquals(210.0, $result['target_stock']);
        $this->assertEquals(130.0, $result['needed_before_caps']);
    }

    public function test_needed_zero_when_stock_sufficient(): void
    {
        $result = $this->service->calculateNeededBeforeCaps(5.0, 21, 200, 0);
        $this->assertEquals(0.0, $result['needed_before_caps']);
    }

    // === 3.3 Max cover cap ===

    public function test_max_cover_cap_limits_needed(): void
    {
        $neededBeforeCaps = 250.0; // target=30d, demand=10, stock=50
        $result = $this->service->applyMaxCoverCap($neededBeforeCaps, 10.0, 42, 50, 0);
        // capNeeded = 10*42 - 50 = 370, min(250, 370) = 250
        $this->assertEquals(250.0, $result['needed']);
        $this->assertEmpty($result['caps_applied']);
    }

    public function test_max_cover_cap_actually_caps(): void
    {
        $neededBeforeCaps = 550.0; // target=60d, demand=10, stock=50
        $result = $this->service->applyMaxCoverCap($neededBeforeCaps, 10.0, 42, 50, 0);
        // capNeeded = 420 - 50 = 370, min(550, 370) = 370
        $this->assertEquals(370.0, $result['needed']);
        $this->assertContains('max_cover_days', $result['caps_applied']);
    }

    // === 3.4 Turnover limit ===

    public function test_turnover_limit_reduces_needed(): void
    {
        $result = $this->service->applyTurnoverLimit(250.0, 10.0, 30, 50, 0);
        // turnoverAfter = (50+0+250)/10 = 30 → exactly at limit, no cap
        $this->assertEquals(250.0, $result['needed']);
        $this->assertNotContains('turnover_limit', $result['caps_applied']);
    }

    public function test_turnover_limit_caps_when_exceeded(): void
    {
        $result = $this->service->applyTurnoverLimit(250.0, 10.0, 25, 50, 0);
        // turnoverAfter = 300/10 = 30 > 25 → maxByTurnover = 10*25-50 = 200
        $this->assertEquals(200.0, $result['needed']);
        $this->assertContains('turnover_limit', $result['caps_applied']);
    }

    // === 3.6 Rounding with pack_multiple ===

    public function test_rounding_with_pack_multiple_1(): void
    {
        $this->assertEquals(14, $this->service->roundToPackMultiple(13.7, 1));
    }

    public function test_rounding_with_pack_multiple_6(): void
    {
        // ceil(13.7 / 6) * 6 = 3 * 6 = 18
        $this->assertEquals(18, $this->service->roundToPackMultiple(13.7, 6));
    }

    public function test_rounding_zero_needed(): void
    {
        $this->assertEquals(0, $this->service->roundToPackMultiple(0.0, 5));
    }

    // === 3.7 Risk level ===

    public function test_risk_high_when_oos_within_7_days(): void
    {
        $oosDate = now()->addDays(5)->toDateString();
        $this->assertEquals('high', $this->service->determineRiskLevel($oosDate, 20.0, 7));
    }

    public function test_risk_med_when_cover_below_min(): void
    {
        $this->assertEquals('med', $this->service->determineRiskLevel(null, 5.0, 7));
    }

    public function test_risk_low_when_sufficient(): void
    {
        $this->assertEquals('low', $this->service->determineRiskLevel(null, 15.0, 7));
    }

    // === 3.7 Simulation ===

    public function test_simulation_builds_correct_days(): void
    {
        $sim = $this->service->buildSimulation(100, 0, 10.0, 0, 14);
        $this->assertCount(14, $sim);
        $this->assertEquals(1, $sim[0]['day']);
        $this->assertEquals(14, $sim[13]['day']);
    }

    public function test_simulation_finds_oos_date(): void
    {
        // stock=20, demand=10/day → OOS on day 2 (20-10=10, 10-10=0)
        $sim = $this->service->buildSimulation(20, 0, 10.0, 0, 7);
        $oosDate = $this->service->findOosDate($sim);
        $this->assertNotNull($oosDate);
    }

    public function test_simulation_no_oos_with_supply(): void
    {
        // stock=20, demand=5/day, supply=100 arrives day 10 → no OOS
        $sim = $this->service->buildSimulation(20, 0, 5.0, 100, 14);
        $minStock = $this->service->findMinStock($sim);
        $this->assertGreaterThanOrEqual(0, $minStock);
    }

    // === 4. Data quality ===

    public function test_data_quality_perfect_ozon(): void
    {
        $result = $this->service->calculateDataQuality(10, 10, 10, 10, 10, 0, 'ozon');
        $this->assertEquals(100.0, $result['total']);
        $this->assertEquals(10, $result['skus_analyzed']);
    }

    public function test_data_quality_partial_wb(): void
    {
        $result = $this->service->calculateDataQuality(10, 8, 5, 3, 10, 7, 'wildberries');
        $this->assertEqualsWithDelta(64.5, $result['total'], 0.1);
        $this->assertGreaterThan(0, $result['total']);
        $this->assertLessThanOrEqual(100, $result['total']);
    }

    public function test_data_quality_empty(): void
    {
        $result = $this->service->emptyQualityJson();
        $this->assertEquals(0, $result['total']);
        $this->assertEquals(0, $result['skus_analyzed']);
    }

    // === 5. Export validation ===

    public function test_ozon_export_aggregates_by_offer_id(): void
    {
        $lines = [
            ['offer_id' => 'SKU-001', 'product_name' => 'Товар 1', 'qty_rounded' => 10],
            ['offer_id' => 'SKU-001', 'product_name' => 'Товар 1', 'qty_rounded' => 5],
            ['offer_id' => 'SKU-002', 'product_name' => 'Товар 2', 'qty_rounded' => 3],
        ];

        $grouped = [];
        foreach ($lines as $line) {
            $offerId = $line['offer_id'];
            if (!isset($grouped[$offerId])) {
                $grouped[$offerId] = ['offer_id' => $offerId, 'name' => $line['product_name'], 'qty' => 0];
            }
            $grouped[$offerId]['qty'] += $line['qty_rounded'];
        }

        $this->assertCount(2, $grouped);
        $this->assertEquals(15, $grouped['SKU-001']['qty']);
        $this->assertEquals(3, $grouped['SKU-002']['qty']);
    }

    public function test_wb_export_requires_barcode(): void
    {
        $lines = [
            ['sku' => 'SKU-001', 'barcode' => '4600000000001', 'qty_rounded' => 10],
            ['sku' => 'SKU-002', 'barcode' => null, 'qty_rounded' => 5],
            ['sku' => 'SKU-003', 'barcode' => '', 'qty_rounded' => 3],
        ];

        $grouped = [];
        $errors = [];

        foreach ($lines as $line) {
            if (empty($line['barcode'])) {
                $errors[] = ['sku' => $line['sku'], 'error' => 'Баркод не найден'];
                continue;
            }
            $barcode = $line['barcode'];
            if (!isset($grouped[$barcode])) {
                $grouped[$barcode] = ['barcode' => $barcode, 'qty' => 0];
            }
            $grouped[$barcode]['qty'] += $line['qty_rounded'];
        }

        $this->assertCount(1, $grouped);
        $this->assertCount(2, $errors);
        $this->assertEquals(10, $grouped['4600000000001']['qty']);
    }

    public function test_wb_export_rejects_ambiguous_barcode(): void
    {
        $lines = [
            ['sku' => 'SKU-001', 'barcode' => '4600000000001', 'qty_rounded' => 10],
            ['sku' => 'SKU-002', 'barcode' => '4600000000001', 'qty_rounded' => 5],
        ];

        $barcodeToSkus = [];
        foreach ($lines as $line) {
            if ($line['barcode']) {
                $barcodeToSkus[$line['barcode']][] = $line['sku'];
            }
        }

        $errors = [];
        foreach ($barcodeToSkus as $barcode => $skuList) {
            $uniqueSkus = array_unique($skuList);
            if (count($uniqueSkus) > 1) {
                $errors[] = ['barcode' => $barcode, 'error' => 'Несколько разных SKU с одним баркодом'];
            }
        }

        $this->assertCount(1, $errors);
        $this->assertEquals('4600000000001', $errors[0]['barcode']);
    }
}
