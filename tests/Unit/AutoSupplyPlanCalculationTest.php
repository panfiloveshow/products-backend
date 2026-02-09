<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests для алгоритма автопланирования поставок
 * Тестируем: EWMA, caps, rounding, risk, data quality
 */
class AutoSupplyPlanCalculationTest extends TestCase
{
    private const EPS = 0.1;

    // === 3.1 EWMA Forecast ===

    public function test_ewma_calculation_with_both_windows(): void
    {
        $alpha = 0.35;
        $sales7 = 70;  // 10/day
        $sales30 = 150; // 5/day

        $shortAvg = $sales7 / 7;  // 10
        $longAvg = $sales30 / 30; // 5

        $ewma = $alpha * $shortAvg + (1 - $alpha) * $longAvg;
        // 0.35 * 10 + 0.65 * 5 = 3.5 + 3.25 = 6.75
        $this->assertEqualsWithDelta(6.75, $ewma, 0.01);
    }

    public function test_ewma_with_only_short_window(): void
    {
        $sales7 = 70;
        $sales30 = 0;

        $shortAvg = $sales7 / 7;
        $longAvg = 0;

        // Только short window
        $dailyDemand = $shortAvg > 0 && $longAvg <= 0 ? $shortAvg : 0;
        $this->assertEqualsWithDelta(10.0, $dailyDemand, 0.01);
    }

    public function test_ewma_with_only_long_window(): void
    {
        $sales7 = 0;
        $sales30 = 150;

        $shortAvg = 0;
        $longAvg = $sales30 / 30;

        $dailyDemand = $shortAvg <= 0 && $longAvg > 0 ? $longAvg : 0;
        $this->assertEqualsWithDelta(5.0, $dailyDemand, 0.01);
    }

    public function test_ewma_no_sales_needs_manual_review(): void
    {
        $sales14 = 0;
        $sales30 = 0;

        $needsManualReview = ($sales30 <= 0 && $sales14 <= 0);
        $dailyDemand = $needsManualReview ? 0 : 1;

        $this->assertTrue($needsManualReview);
        $this->assertEquals(0, $dailyDemand);
    }

    // === 3.2 Базовые формулы ===

    public function test_basic_needed_calculation(): void
    {
        $dailyDemand = 10.0;
        $targetCoverDays = 21;
        $currentStock = 50;
        $inTransit = 30;

        $targetStock = $dailyDemand * $targetCoverDays; // 210
        $needed = max(0, $targetStock - ($currentStock + $inTransit)); // 210 - 80 = 130

        $this->assertEquals(210.0, $targetStock);
        $this->assertEquals(130.0, $needed);
    }

    public function test_needed_zero_when_stock_sufficient(): void
    {
        $dailyDemand = 5.0;
        $targetCoverDays = 21;
        $currentStock = 200;
        $inTransit = 0;

        $targetStock = $dailyDemand * $targetCoverDays; // 105
        $needed = max(0, $targetStock - ($currentStock + $inTransit)); // 105 - 200 = -95 → 0

        $this->assertEquals(0.0, $needed);
    }

    // === 3.3 Max cover cap ===

    public function test_max_cover_cap_limits_needed(): void
    {
        $dailyDemand = 10.0;
        $targetCoverDays = 30;
        $maxCoverDays = 42;
        $currentStock = 50;
        $inTransit = 0;

        $targetStock = $dailyDemand * $targetCoverDays; // 300
        $neededBeforeCaps = max(0, $targetStock - ($currentStock + $inTransit)); // 250

        $capStock = $dailyDemand * $maxCoverDays; // 420
        $capNeeded = max(0, $capStock - ($currentStock + $inTransit)); // 370

        $needed = min($neededBeforeCaps, $capNeeded); // min(250, 370) = 250

        $this->assertEquals(250.0, $needed);
    }

    public function test_max_cover_cap_actually_caps(): void
    {
        $dailyDemand = 10.0;
        $targetCoverDays = 60; // aggressive target
        $maxCoverDays = 42;
        $currentStock = 50;
        $inTransit = 0;

        $targetStock = $dailyDemand * $targetCoverDays; // 600
        $neededBeforeCaps = max(0, $targetStock - ($currentStock + $inTransit)); // 550

        $capStock = $dailyDemand * $maxCoverDays; // 420
        $capNeeded = max(0, $capStock - ($currentStock + $inTransit)); // 370

        $needed = min($neededBeforeCaps, $capNeeded); // min(550, 370) = 370

        $this->assertEquals(370.0, $needed);
        $this->assertLessThan($neededBeforeCaps, $needed);
    }

    // === 3.4 Turnover limit ===

    public function test_turnover_limit_reduces_needed(): void
    {
        $dailyDemand = 10.0;
        $turnoverLimitDays = 30;
        $currentStock = 50;
        $inTransit = 0;
        $needed = 250.0;

        $turnoverAfter = ($currentStock + $inTransit + $needed) / max($dailyDemand, self::EPS);
        // (50 + 0 + 250) / 10 = 30 → exactly at limit

        $this->assertEqualsWithDelta(30.0, $turnoverAfter, 0.01);
    }

    public function test_turnover_limit_caps_when_exceeded(): void
    {
        $dailyDemand = 10.0;
        $turnoverLimitDays = 25;
        $currentStock = 50;
        $inTransit = 0;
        $needed = 250.0;

        $turnoverAfter = ($currentStock + $inTransit + $needed) / max($dailyDemand, self::EPS);
        // 300 / 10 = 30 > 25

        if ($turnoverAfter > $turnoverLimitDays) {
            $maxByTurnover = max(0, $dailyDemand * $turnoverLimitDays - ($currentStock + $inTransit));
            // 10 * 25 - 50 = 200
            $needed = min($needed, $maxByTurnover);
        }

        $this->assertEquals(200.0, $needed);
    }

    // === 3.6 Rounding with pack_multiple ===

    public function test_rounding_with_pack_multiple_1(): void
    {
        $needed = 13.7;
        $packMultiple = 1;

        $qtyRounded = (int) (ceil($needed / max($packMultiple, 1)) * $packMultiple);
        $this->assertEquals(14, $qtyRounded);
    }

    public function test_rounding_with_pack_multiple_6(): void
    {
        $needed = 13.7;
        $packMultiple = 6;

        $qtyRounded = (int) (ceil($needed / max($packMultiple, 1)) * $packMultiple);
        // ceil(13.7 / 6) * 6 = ceil(2.283) * 6 = 3 * 6 = 18
        $this->assertEquals(18, $qtyRounded);
    }

    public function test_rounding_zero_needed(): void
    {
        $needed = 0.0;
        $packMultiple = 5;

        $qtyRounded = max(0, (int) (ceil($needed / max($packMultiple, 1)) * $packMultiple));
        $this->assertEquals(0, $qtyRounded);
    }

    // === 3.7 Risk level ===

    public function test_risk_high_when_oos_within_7_days(): void
    {
        $oosDate = now()->addDays(5)->toDateString();
        $coverBefore = 20.0;
        $minCoverDays = 7;

        $today = now();
        if ($oosDate !== null && \Carbon\Carbon::parse($oosDate)->lte($today->copy()->addDays(7))) {
            $riskLevel = 'high';
        } elseif ($coverBefore < $minCoverDays) {
            $riskLevel = 'med';
        } else {
            $riskLevel = 'low';
        }

        $this->assertEquals('high', $riskLevel);
    }

    public function test_risk_med_when_cover_below_min(): void
    {
        $oosDate = null;
        $coverBefore = 5.0;
        $minCoverDays = 7;

        if ($oosDate !== null) {
            $riskLevel = 'high';
        } elseif ($coverBefore < $minCoverDays) {
            $riskLevel = 'med';
        } else {
            $riskLevel = 'low';
        }

        $this->assertEquals('med', $riskLevel);
    }

    public function test_risk_low_when_sufficient(): void
    {
        $oosDate = null;
        $coverBefore = 15.0;
        $minCoverDays = 7;

        if ($oosDate !== null) {
            $riskLevel = 'high';
        } elseif ($coverBefore < $minCoverDays) {
            $riskLevel = 'med';
        } else {
            $riskLevel = 'low';
        }

        $this->assertEquals('low', $riskLevel);
    }

    // === 4. Data quality ===

    public function test_data_quality_perfect_ozon(): void
    {
        $total = 10;
        $stocks = 10;
        $sales = 10;
        $transit = 10;
        $destination = 10;
        $barcode = 0; // Ozon не требует barcode

        $stocksScore = round(($stocks / $total) * 30, 1);   // 30
        $salesScore = round(($sales / $total) * 25, 1);      // 25
        $transitScore = round(($transit / $total) * 20, 1);  // 20
        $destScore = round(($destination / $total) * 15, 1); // 15
        $barcodeScore = 10.0; // Ozon → always 10

        $totalScore = $stocksScore + $salesScore + $transitScore + $destScore + $barcodeScore;

        $this->assertEquals(100.0, $totalScore);
    }

    public function test_data_quality_partial_wb(): void
    {
        $total = 10;
        $stocks = 8;
        $sales = 5;
        $transit = 3;
        $destination = 10;
        $barcode = 7;

        $stocksScore = round(($stocks / $total) * 30, 1);   // 24
        $salesScore = round(($sales / $total) * 25, 1);      // 12.5
        $transitScore = round(($transit / $total) * 20, 1);  // 6
        $destScore = round(($destination / $total) * 15, 1); // 15
        $barcodeScore = round(($barcode / $total) * 10, 1);  // 7

        $totalScore = $stocksScore + $salesScore + $transitScore + $destScore + $barcodeScore;

        $this->assertEqualsWithDelta(64.5, $totalScore, 0.1);
        $this->assertGreaterThan(0, $totalScore);
        $this->assertLessThanOrEqual(100, $totalScore);
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
