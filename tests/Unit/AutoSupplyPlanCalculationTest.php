<?php

namespace Tests\Unit;

use App\Jobs\CalculateAutoSupplyPlanJob;
use App\Services\AutoSupplyPlanService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

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

    public function test_analysis_period_days_uses_user_window_for_demand_facts(): void
    {
        $job = new CalculateAutoSupplyPlanJob('test-plan-id');
        $method = (new ReflectionClass($job))->getMethod('analysisPeriodDays');
        $method->setAccessible(true);

        $this->assertSame(28, $method->invoke($job, ['analysis_period_days' => 28], 60));
        $this->assertSame(56, $method->invoke($job, ['analysis_period_days' => 56], 30));
        $this->assertSame(30, $method->invoke($job, [], 30));
        $this->assertSame(56, $method->invoke($job, ['analysis_period_days' => 55], 30));
    }

    public function test_ozon_qty_anchor_is_forced_to_internal_engine(): void
    {
        $job = new CalculateAutoSupplyPlanJob('test-plan-id');
        $method = (new ReflectionClass($job))->getMethod('effectiveOzonQtyAnchor');
        $method->setAccessible(true);

        $this->assertSame('internal', $method->invoke($job, 'ozon', 'ozon'));
        $this->assertSame('internal', $method->invoke($job, 'max', 'ozon'));
        $this->assertSame('internal', $method->invoke($job, 'average', 'ozon'));
        $this->assertSame('internal', $method->invoke($job, 'internal', 'ozon'));
        $this->assertSame('internal', $method->invoke($job, 'ozon', 'wildberries'));
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

    public function test_posting_fbo_v3_can_be_marked_as_real_demand_source(): void
    {
        $result = $this->service->calculateDailyDemandV2(
            0.35,
            0,
            0,
            0,
            2.5,
            2.5,
            30,
            100,
            'stable',
            0,
            0,
            'posting_fbo_v3'
        );

        $this->assertEqualsWithDelta(2.5, $result['daily_demand'], 0.01);
        $this->assertSame('posting_fbo_v3', $result['source']);
        $this->assertFalse($result['needs_manual_review']);
    }

    public function test_posting_fbo_v3_positive_trend_does_not_double_boost_observed_demand(): void
    {
        $result = $this->service->calculateDailyDemandV2(
            0.35,
            70,
            35,
            150,
            10.0,
            10.0,
            30,
            100,
            'growing',
            20,
            0,
            'posting_fbo_v3'
        );

        $this->assertEqualsWithDelta(10.0, $result['daily_demand'], 0.01);
        $this->assertSame('posting_fbo_v3', $result['source']);
    }

    public function test_ozon_posting_demand_shape_caps_suspected_promo_spike(): void
    {
        $result = $this->service->shapeOzonPostingDemand([
            'sales_7_days' => 7,
            'sales_14_days' => 167,
            'sales_30_days' => 260,
            'ordered_units_total' => 780,
            'avg_daily_sales' => 26.0,
            'winsorized_avg_daily_sales' => 18.0,
            'peak_day_units' => 155,
            'peak_share' => 0.1987,
            'active_days' => 12,
            'median_nonzero_daily_units' => 8,
        ]);

        $this->assertTrue($result['suspected_spike']);
        $this->assertSame('warning', $result['confidence_level']);
        $this->assertLessThan(26.0, $result['daily_demand']);
        $this->assertLessThanOrEqual(13.0, $result['daily_demand']);
        $this->assertContains('promo_spike_peak_vs_median', $result['confidence_reasons']);
    }

    public function test_ozon_posting_demand_spike_guard_does_not_use_stale_external_average_as_floor(): void
    {
        $result = $this->service->shapeOzonPostingDemand([
            'sales_7_days' => 7,
            'sales_14_days' => 167,
            'sales_30_days' => 260,
            'ordered_units_total' => 780,
            'avg_daily_sales' => 26.0,
            'winsorized_avg_daily_sales' => 18.0,
            'peak_day_units' => 155,
            'peak_share' => 0.1987,
            'active_days' => 12,
            'median_nonzero_daily_units' => 8,
        ], localAvgDaily: 26.0, ozonAds: 20.0);

        $this->assertTrue($result['suspected_spike']);
        $this->assertTrue($result['capped_external_daily_demand']);
        $this->assertLessThanOrEqual(5.0, $result['daily_demand']);
        $this->assertSame($result['guardrail_cap_daily_demand'], $result['daily_demand']);
        $this->assertContains('external_sources_capped_by_spike_guard', $result['confidence_reasons']);
    }

    public function test_ozon_aggregate_demand_shape_caps_post_promo_cooldown(): void
    {
        $result = $this->service->shapeOzonAggregateDemand(
            dailyDemand: 26.0,
            sales7: 7,
            sales14: 28,
            sales30: 780,
            avgDailySalesApi: 26.0
        );

        $this->assertTrue($result['suspected_spike']);
        $this->assertSame('warning', $result['confidence_level']);
        $this->assertLessThan(26.0, $result['daily_demand']);
        $this->assertLessThanOrEqual(13.0, $result['daily_demand']);
        $this->assertContains('aggregate_sales_no_postings', $result['confidence_reasons']);
        $this->assertContains('post_promo_cooldown', $result['confidence_reasons']);
    }

    public function test_ozon_aggregate_demand_shape_low_when_no_recent_sales_after_spike(): void
    {
        $result = $this->service->shapeOzonAggregateDemand(
            dailyDemand: 26.0,
            sales7: 0,
            sales14: 0,
            sales30: 780,
            avgDailySalesApi: 26.0
        );

        $this->assertTrue($result['suspected_spike']);
        $this->assertSame('low', $result['confidence_level']);
        $this->assertLessThanOrEqual(13.0, $result['daily_demand']);
        $this->assertContains('no_recent_sales_after_30d_spike', $result['confidence_reasons']);
    }

    public function test_ozon_posting_demand_prevents_false_order_report_missing_source(): void
    {
        $warehouse = (object) [
            'sales_30_days' => 0,
            'sales_14_days' => 0,
            'real_avg_daily_sales' => 0,
        ];

        $missing = $this->service->detectMissingSources(
            $warehouse,
            (object) ['barcode' => null],
            'ozon',
            (object) ['cost_price' => 100],
            false,
            true
        );

        $this->assertNotContains('sales_history', $missing);
        $this->assertNotContains('ozon_order_report', $missing);
        $this->assertNotContains('ozon_posting_demand', $missing);
    }

    public function test_protective_quantity_guard_caps_post_promo_quantity_to_trial_cover(): void
    {
        $result = $this->service->applyProtectiveQuantityGuard(
            qty: 693,
            dailyDemand: 26.0,
            currentStock: 38,
            inTransit: 0,
            packMultiple: 1,
            confidenceReasons: ['promo_spike_peak_vs_median', 'post_promo_cooldown'],
            lowConfidenceTrial: true,
            promoMode: 'post_promo',
            marketplace: 'ozon',
        );

        $this->assertTrue($result['applied']);
        $this->assertSame(196, $result['qty']);
        $this->assertSame(7, $result['trial_cover_days']);
        $this->assertSame('protective_post_promo_trial_quantity', $result['reason']);
        $this->assertContains('protective_trial_quantity_cap', $result['reasons']);
    }

    public function test_protective_quantity_guard_does_not_touch_stable_quantity(): void
    {
        $result = $this->service->applyProtectiveQuantityGuard(
            qty: 80,
            dailyDemand: 4.0,
            currentStock: 10,
            inTransit: 0,
            packMultiple: 1,
            confidenceReasons: [],
            lowConfidenceTrial: false,
            promoMode: 'none',
            marketplace: 'ozon',
        );

        $this->assertFalse($result['applied']);
        $this->assertSame(80, $result['qty']);
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

    // ═══════════════════════════════════════════════════════════════════
    // v2: Тесты для улучшенного расчёта автопополнения
    // ═══════════════════════════════════════════════════════════════════

    // === ABC-приоритет ===

    public function test_abc_priority_a_for_high_revenue(): void
    {
        $this->assertEquals('A', $this->service->calculateAbcPriority(150000));
    }

    public function test_abc_priority_b_for_medium_revenue(): void
    {
        $this->assertEquals('B', $this->service->calculateAbcPriority(50000));
    }

    public function test_abc_priority_c_for_low_revenue(): void
    {
        $this->assertEquals('C', $this->service->calculateAbcPriority(10000));
    }

    public function test_abc_target_days_without_settings(): void
    {
        $this->assertEquals(21, $this->service->getTargetDaysByAbc('A', null, 14));
        $this->assertEquals(14, $this->service->getTargetDaysByAbc('B', null, 14));
        $this->assertEquals(14, $this->service->getTargetDaysByAbc('C', null, 14));
    }

    // === Улучшенный прогноз спроса (v2) ===

    public function test_demand_v2_prefers_real_avg_daily_sales(): void
    {
        $result = $this->service->calculateDailyDemandV2(
            0.35, 70, 35, 150,
            realAvgDailySales: 8.5
        );
        $this->assertEqualsWithDelta(8.5, $result['daily_demand'], 0.01);
        $this->assertEquals('ozon_order_report', $result['source']);
    }

    public function test_demand_v2_uses_effective_when_oos(): void
    {
        $result = $this->service->calculateDailyDemandV2(
            0.35, 70, 35, 150,
            realAvgDailySales: 0,
            effectiveDailySales: 7.0,
            daysInStock30: 20
        );
        $this->assertEqualsWithDelta(7.0, $result['daily_demand'], 0.01);
        $this->assertEquals('effective_oos_adjusted', $result['source']);
    }

    public function test_demand_v2_falls_back_to_ewma(): void
    {
        $result = $this->service->calculateDailyDemandV2(
            0.35, 70, 35, 150,
            realAvgDailySales: 0,
            effectiveDailySales: 0,
            daysInStock30: 30
        );
        $this->assertEqualsWithDelta(6.75, $result['daily_demand'], 0.01);
        $this->assertEquals('ewma', $result['source']);
    }

    public function test_demand_v2_applies_redemption_rate(): void
    {
        $result = $this->service->calculateDailyDemandV2(
            0.35, 70, 35, 150,
            realAvgDailySales: 10.0,
            redemptionRate: 80
        );
        // 10.0 * 0.8 = 8.0
        $this->assertEqualsWithDelta(8.0, $result['daily_demand'], 0.01);
    }

    public function test_demand_v2_applies_trend_adjustment(): void
    {
        $result = $this->service->calculateDailyDemandV2(
            0.35, 0, 0, 0,
            avgDailySalesApi: 10.0,
            salesTrend: 'growing',
            salesTrendPercent: 20
        );
        // 10.0 * (1 + 0.20 * 0.5) = 10.0 * 1.10 = 11.0
        $this->assertEqualsWithDelta(11.0, $result['daily_demand'], 0.01);
    }

    // === Корректировка на тренд ===

    public function test_trend_adjustment_growing(): void
    {
        // 20% тренд → +10% к demand (50% сглаживание)
        $result = $this->service->adjustDemandByTrend(10.0, 'growing', 20);
        $this->assertEqualsWithDelta(11.0, $result, 0.01);
    }

    public function test_trend_adjustment_declining(): void
    {
        // -15% тренд → -7.5% к demand
        $result = $this->service->adjustDemandByTrend(10.0, 'declining', -15);
        $this->assertEqualsWithDelta(9.25, $result, 0.01);
    }

    public function test_trend_adjustment_stable_no_change(): void
    {
        $result = $this->service->adjustDemandByTrend(10.0, 'stable', 15);
        $this->assertEquals(10.0, $result);
    }

    public function test_trend_adjustment_capped_at_20_percent(): void
    {
        // 50% тренд → capped to 20% → +10%
        $result = $this->service->adjustDemandByTrend(10.0, 'growing', 50);
        $this->assertEqualsWithDelta(11.0, $result, 0.01);
    }

    // === Волатильность ===

    public function test_volatility_calculation(): void
    {
        // avg7=10, avg14=8, avg30=6 → mean=8, variance=((2²+0²+2²)/3)=2.67, std=1.63, cv=0.2041
        $result = $this->service->calculateVolatility(10, 8, 6);
        $this->assertGreaterThan(0, $result);
        $this->assertLessThan(1, $result);
    }

    public function test_volatility_zero_with_single_value(): void
    {
        $result = $this->service->calculateVolatility(10, 0, 0);
        $this->assertEquals(0, $result);
    }

    public function test_volatility_zero_with_equal_values(): void
    {
        $result = $this->service->calculateVolatility(5, 5, 5);
        $this->assertEquals(0, $result);
    }

    // === Динамический safety stock ===

    public function test_dynamic_safety_stock(): void
    {
        // demand=10, volatility=0.3, lead_time=5, minSafety=3
        // dynamic = 10 * 5 * (1 + 0.3 * 1.5) = 50 * 1.45 = 72.5
        // min = 10 * 3 = 30
        // max(72.5, 30) = 72.5
        $result = $this->service->calculateDynamicSafetyStock(10.0, 0.3, 5, 3);
        $this->assertEqualsWithDelta(72.5, $result, 0.01);
    }

    public function test_dynamic_safety_stock_uses_min_when_low_volatility(): void
    {
        // demand=10, volatility=0, lead_time=1, minSafety=5
        // dynamic = 10 * 1 * (1 + 0) = 10
        // min = 10 * 5 = 50
        // max(10, 50) = 50
        $result = $this->service->calculateDynamicSafetyStock(10.0, 0.0, 1, 5);
        $this->assertEqualsWithDelta(50.0, $result, 0.01);
    }

    // === Needed с safety stock ===

    public function test_needed_with_safety_stock(): void
    {
        // demand=10, target=21, safety=30, stock=50, transit=20
        // target_stock = 10*21 + 30 = 240
        // needed = max(0, 240 - 70) = 170
        $result = $this->service->calculateNeededWithSafety(10.0, 21, 30.0, 50, 20);
        $this->assertEqualsWithDelta(240.0, $result['target_stock'], 0.01);
        $this->assertEqualsWithDelta(170.0, $result['needed_before_caps'], 0.01);
        $this->assertEqualsWithDelta(30.0, $result['safety_stock'], 0.01);
    }

    // === Приоритет v2 ===

    public function test_priority_v2_critical_for_a_with_oos(): void
    {
        $oosDate = now()->addDays(2)->toDateString();
        $result = $this->service->calculatePriorityScoreV2(
            'A', $oosDate, 3.0, 7, 'growing', 35, 10000, 50000
        );
        $this->assertEquals('critical', $result['priority']);
        $this->assertGreaterThanOrEqual(70, $result['score']);
    }

    public function test_priority_v2_low_for_c_stable_no_oos(): void
    {
        $result = $this->service->calculatePriorityScoreV2(
            'C', null, 20.0, 7, 'stable', 5, 0, 1000
        );
        $this->assertEquals('low', $result['priority']);
        $this->assertLessThan(30, $result['score']);
    }

    public function test_priority_v2_medium_for_b_with_low_cover(): void
    {
        $result = $this->service->calculatePriorityScoreV2(
            'B', null, 5.0, 7, 'stable', 20, 0, 5000
        );
        $this->assertEquals('medium', $result['priority']);
    }

    public function test_priority_v2_margin_bonus(): void
    {
        $resultHigh = $this->service->calculatePriorityScoreV2('B', null, 10.0, 7, 'stable', 35);
        $resultLow = $this->service->calculatePriorityScoreV2('B', null, 10.0, 7, 'stable', 5);
        $this->assertGreaterThan($resultLow['score'], $resultHigh['score']);
    }

    public function test_priority_v2_ozon_lost_profit_bonus(): void
    {
        $resultWith = $this->service->calculatePriorityScoreV2('B', null, 10.0, 7, 'stable', 0, 15000);
        $resultWithout = $this->service->calculatePriorityScoreV2('B', null, 10.0, 7, 'stable', 0, 0);
        $this->assertGreaterThan($resultWithout['score'], $resultWith['score']);
    }
}
