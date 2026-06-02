<?php

namespace Tests\Unit\AutoSupplyPlanning;

use App\Models\AutoSupplyPlan;
use App\Services\AutoSupplyPlanning\PlanLineOptimizer;
use PHPUnit\Framework\TestCase;

class PlanLineOptimizerTest extends TestCase
{
    public function test_budget_limit_selects_higher_roi_candidate_first(): void
    {
        $plan = new AutoSupplyPlan([
            'mode' => AutoSupplyPlan::MODE_MAX_PROFIT,
            'params' => ['planning_mode' => AutoSupplyPlan::MODE_MAX_PROFIT],
            'budget_limit' => 100,
        ]);

        $result = (new PlanLineOptimizer())->optimize([
            $this->line('LOW-ROI', supplyCost: 90, expectedProfit: 20, roi: 10),
            $this->line('HIGH-ROI', supplyCost: 90, expectedProfit: 200, roi: 100),
        ], $plan);

        $this->assertCount(1, $result['lines']);
        $this->assertSame('HIGH-ROI', $result['lines'][0]['sku']);
        $this->assertSame(1, $result['summary']['budget_skipped_lines']);
        $this->assertSame(90.0, $result['summary']['budget_used']);
    }

    public function test_budget_limit_selects_best_combination_not_only_first_expensive_line(): void
    {
        $plan = new AutoSupplyPlan([
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => ['planning_mode' => AutoSupplyPlan::MODE_BALANCED],
            'budget_limit' => 100,
        ]);

        $result = (new PlanLineOptimizer())->optimize([
            $this->line('EXPENSIVE-SINGLE', supplyCost: 100, expectedProfit: 5000, roi: 100, risk: 'high', dailyDemand: 2),
            $this->line('SMALL-OOS-A', supplyCost: 50, expectedProfit: 100, roi: 10, risk: 'high', dailyDemand: 5, abc: 'A'),
            $this->line('SMALL-OOS-B', supplyCost: 50, expectedProfit: 100, roi: 10, risk: 'high', dailyDemand: 5, abc: 'A'),
        ], $plan);

        $selectedSkus = array_column($result['lines'], 'sku');

        $this->assertSame(['SMALL-OOS-A', 'SMALL-OOS-B'], $selectedSkus);
        $this->assertSame(100.0, $result['summary']['budget_used']);
        $this->assertSame('score_knapsack_v1', $result['summary']['budget_selection_policy']);
        $this->assertSame('score_knapsack_v1', $result['summary']['candidate_audit']['budget_selection_policy']);
        $this->assertSame(1, $result['summary']['budget_skipped_lines']);

        $rejectedBySku = collect($result['summary']['candidate_audit']['top_rejected'])->keyBy('sku')->all();
        $this->assertSame('budget_limit', $rejectedBySku['EXPENSIVE-SINGLE']['decision']);
    }

    public function test_negative_profit_can_be_filtered_after_candidates_are_built(): void
    {
        $plan = new AutoSupplyPlan([
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => ['skip_negative_profit' => true],
        ]);

        $result = (new PlanLineOptimizer())->optimize([
            $this->line('BAD', expectedProfit: -50, roi: -10),
            $this->line('GOOD', expectedProfit: 100, roi: 20),
        ], $plan);

        $this->assertCount(1, $result['lines']);
        $this->assertSame('GOOD', $result['lines'][0]['sku']);
        $this->assertSame(1, $result['summary']['negative_profit_skipped_lines']);
        $this->assertSame(1, $result['summary']['skipped_by_reason']['negative_profit']);
    }

    public function test_post_promo_careful_penalizes_low_confidence_spike_lines(): void
    {
        $plan = new AutoSupplyPlan([
            'mode' => AutoSupplyPlan::MODE_POST_PROMO_CAREFUL,
            'params' => ['planning_mode' => AutoSupplyPlan::MODE_POST_PROMO_CAREFUL],
        ]);

        $result = (new PlanLineOptimizer())->optimize([
            $this->line('SPIKE', expectedProfit: 500, roi: 80, confidence: 'low', risk: 'low'),
            $this->line('OOS', expectedProfit: 120, roi: 20, confidence: 'good', risk: 'high'),
        ], $plan);

        $this->assertSame('OOS', $result['lines'][0]['sku']);
        $this->assertSame('oos_risk', json_decode($result['lines'][0]['explain_json'], true)['reason']);
        $this->assertSame('not_recommended_low_confidence', json_decode($result['lines'][1]['explain_json'], true)['reason']);
    }

    public function test_optimizer_writes_explainable_selection_metadata(): void
    {
        $plan = new AutoSupplyPlan([
            'mode' => AutoSupplyPlan::MODE_PROTECT_OOS,
            'params' => ['planning_mode' => AutoSupplyPlan::MODE_PROTECT_OOS],
        ]);

        $result = (new PlanLineOptimizer())->optimize([
            $this->line('A', risk: 'high', currentStock: 1, dailyDemand: 3),
        ], $plan, ['min_cover_days' => 7]);

        $explain = json_decode($result['lines'][0]['explain_json'], true);

        $this->assertSame('oos_risk', $explain['reason']);
        $this->assertTrue($explain['planning_decision']['selected']);
        $this->assertContains('has_deficit', $explain['planning_decision']['score_basis']);
        $this->assertContains('есть дефицит', $explain['planning_decision']['score_basis_labels']);
        $this->assertNotEmpty($explain['planning_decision']['score_components']);
        $this->assertNotEmpty($explain['planning_decision']['main_score_factors_ru']);
        $this->assertStringContainsString('Главные факторы решения', $explain['planning_decision']['score_component_explanation_ru']);
        $this->assertSame(20, $explain['facts']['deficit_qty']);
    }

    public function test_improve_locality_mode_prefers_higher_territorial_score(): void
    {
        $plan = new AutoSupplyPlan([
            'mode' => AutoSupplyPlan::MODE_IMPROVE_LOCALITY,
            'params' => ['planning_mode' => AutoSupplyPlan::MODE_IMPROVE_LOCALITY],
            'budget_limit' => 100,
        ]);

        $result = (new PlanLineOptimizer())->optimize([
            $this->line('CHEAP-SLOW', supplyCost: 90, expectedProfit: 120, roi: 30, territorialScore: 20),
            $this->line('FAST-REGION', supplyCost: 90, expectedProfit: 110, roi: 25, territorialScore: 110),
        ], $plan);

        $this->assertCount(1, $result['lines']);
        $this->assertSame('FAST-REGION', $result['lines'][0]['sku']);

        $explain = json_decode($result['lines'][0]['explain_json'], true);
        $this->assertSame('locality_improvement', $explain['reason']);
        $this->assertContains('territorial_score_accounted', $explain['planning_decision']['score_basis']);
    }

    public function test_balanced_mode_prefers_real_deficit_over_surplus_even_with_lower_roi(): void
    {
        $plan = new AutoSupplyPlan([
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => ['planning_mode' => AutoSupplyPlan::MODE_BALANCED],
            'budget_limit' => 100,
        ]);

        $result = (new PlanLineOptimizer())->optimize([
            $this->line('SURPLUS-HIGH-ROI', supplyCost: 90, expectedProfit: 1000, roi: 200, currentStock: 100, dailyDemand: 1),
            $this->line('DEFICIT-A', supplyCost: 90, expectedProfit: 100, roi: 5, currentStock: 0, dailyDemand: 5, abc: 'A'),
        ], $plan);

        $this->assertCount(1, $result['lines']);
        $this->assertSame('DEFICIT-A', $result['lines'][0]['sku']);
        $this->assertSame('optimizer-5', $result['summary']['score_policy']['version']);
        $this->assertSame(1, $result['summary']['selected_deficit_lines']);
        $this->assertSame('optimizer-audit-2', $result['summary']['candidate_audit']['version']);
        $this->assertSame(2, $result['summary']['candidate_audit']['evaluated_lines']);
        $this->assertSame(1, $result['summary']['candidate_audit']['selected_lines']);
        $this->assertSame('all_candidates', $result['summary']['candidate_audit']['funnel_stages'][0]['key']);
        $this->assertSame('final_selection', $result['summary']['candidate_audit']['funnel_stages'][4]['key']);
        $this->assertSame('DEFICIT-A', $result['summary']['candidate_audit']['top_selected'][0]['sku']);
        $this->assertSame('SURPLUS-HIGH-ROI', $result['summary']['candidate_audit']['top_rejected'][0]['sku']);
        $this->assertSame('budget_limit', $result['summary']['candidate_audit']['top_rejected'][0]['decision']);
        $this->assertContains('есть дефицит', $result['summary']['candidate_audit']['top_selected'][0]['score_basis_labels']);
        $this->assertNotEmpty($result['summary']['candidate_audit']['top_selected'][0]['score_components']);
        $this->assertNotEmpty($result['summary']['candidate_audit']['top_selected'][0]['main_score_factors_ru']);

        $explain = json_decode($result['lines'][0]['explain_json'], true);
        $this->assertContains('has_deficit', $explain['planning_decision']['score_basis']);
        $this->assertContains('abc_priority_accounted', $explain['planning_decision']['score_basis']);
        $this->assertStringContainsString('выбрана системой', mb_strtolower($explain['planning_decision']['decision_ru']));
    }

    public function test_in_transit_coverage_reduces_urgency_when_need_is_already_covered(): void
    {
        $plan = new AutoSupplyPlan([
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => ['planning_mode' => AutoSupplyPlan::MODE_BALANCED],
            'budget_limit' => 100,
        ]);

        $result = (new PlanLineOptimizer())->optimize([
            $this->line('ALREADY-ON-WAY', supplyCost: 90, expectedProfit: 1000, roi: 100, currentStock: 0, inTransit: 100, dailyDemand: 2),
            $this->line('OPEN-DEFICIT', supplyCost: 90, expectedProfit: 100, roi: 10, currentStock: 0, inTransit: 0, dailyDemand: 2),
        ], $plan);

        $this->assertCount(1, $result['lines']);
        $this->assertSame('OPEN-DEFICIT', $result['lines'][0]['sku']);

        $candidate = (new PlanLineOptimizer())->optimize([
            $this->line('ALREADY-ON-WAY', supplyCost: 90, expectedProfit: 1000, roi: 100, currentStock: 0, inTransit: 100, dailyDemand: 2),
        ], new AutoSupplyPlan(['mode' => AutoSupplyPlan::MODE_BALANCED, 'params' => ['planning_mode' => AutoSupplyPlan::MODE_BALANCED]]));
        $explain = json_decode($candidate['lines'][0]['explain_json'], true);

        $this->assertContains('in_transit_covers_need', $explain['planning_decision']['score_basis']);
        $this->assertEquals(50.0, $explain['facts']['in_transit_coverage_days']);
    }

    public function test_candidate_audit_records_negative_profit_and_budget_rejections_in_russian(): void
    {
        $plan = new AutoSupplyPlan([
            'mode' => AutoSupplyPlan::MODE_MAX_PROFIT,
            'params' => [
                'planning_mode' => AutoSupplyPlan::MODE_MAX_PROFIT,
                'skip_negative_profit' => true,
            ],
            'budget_limit' => 100,
        ]);

        $result = (new PlanLineOptimizer())->optimize([
            $this->line('NEGATIVE', supplyCost: 10, expectedProfit: -50, roi: -20),
            $this->line('FIRST', supplyCost: 90, expectedProfit: 300, roi: 120),
            $this->line('BUDGET-OUT', supplyCost: 90, expectedProfit: 200, roi: 80),
        ], $plan);

        $audit = $result['summary']['candidate_audit'];

        $this->assertSame(3, $audit['evaluated_lines']);
        $this->assertSame(2, $audit['eligible_candidates']);
        $this->assertSame(2, $audit['skipped_lines']);
        $this->assertSame('FIRST', $audit['top_selected'][0]['sku']);

        $rejectedBySku = collect($audit['top_rejected'])->keyBy('sku')->all();
        $this->assertSame('negative_profit', $rejectedBySku['NEGATIVE']['decision']);
        $this->assertSame('budget_limit', $rejectedBySku['BUDGET-OUT']['decision']);
        $this->assertStringContainsString('ожидаемая прибыль отрицательная', $rejectedBySku['NEGATIVE']['decision_ru']);
        $this->assertStringContainsString('не помещается', $rejectedBySku['BUDGET-OUT']['decision_ru']);
        $this->assertStringContainsString('Убыточные строки исключаются', $audit['decision_ru']);
    }

    public function test_candidate_audit_funnel_records_constraints_economics_budget_and_final_selection(): void
    {
        $plan = new AutoSupplyPlan([
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => [
                'planning_mode' => AutoSupplyPlan::MODE_BALANCED,
                'skip_negative_profit' => true,
            ],
            'budget_limit' => 100,
        ]);

        $result = (new PlanLineOptimizer())->optimize([
            $this->line('NEGATIVE', supplyCost: 10, expectedProfit: -10, roi: -20),
            $this->line('SELECTED', supplyCost: 90, expectedProfit: 100, roi: 50),
            $this->line('BUDGET-OUT', supplyCost: 90, expectedProfit: 90, roi: 40),
        ], $plan, [
            'source_candidates_total' => 5,
            'source_qty_total' => 70,
            'constraints_summary' => [
                'blocked_lines' => 1,
                'capped_lines' => 1,
                'reduced_qty' => 20,
                'total_file_marketplace_need_qty' => 55,
                'unmatched_marketplace_need_count' => 1,
                'unmatched_marketplace_need_qty' => 35,
                'unmatched_marketplace_needs' => [
                    [
                        'sku' => 'MISSING-NEED',
                        'destination_name' => 'Самара',
                        'need_qty' => 35,
                    ],
                ],
            ],
        ]);

        $stages = collect($result['summary']['candidate_audit']['funnel_stages'])->keyBy('key')->all();

        $this->assertSame(5, $stages['all_candidates']['lines']);
        $this->assertSame(70, $stages['all_candidates']['qty']);
        $this->assertSame(3, $stages['marketplace_constraints']['lines']);
        $this->assertSame(5, $stages['marketplace_constraints']['lines_before']);
        $this->assertSame(1, $stages['marketplace_constraints']['removed_lines']);
        $this->assertSame(2, $stages['marketplace_constraints']['changed_lines']);
        $this->assertSame(70, $stages['marketplace_constraints']['qty_before']);
        $this->assertSame(50, $stages['marketplace_constraints']['qty_after']);
        $this->assertSame(1, $stages['marketplace_constraints']['blocked_lines']);
        $this->assertSame(1, $stages['marketplace_constraints']['capped_lines']);
        $this->assertSame(20, $stages['marketplace_constraints']['reduced_qty']);
        $this->assertSame(55, $stages['marketplace_constraints']['file_marketplace_need_qty']);
        $this->assertSame(1, $stages['marketplace_constraints']['unmatched_marketplace_need_count']);
        $this->assertSame(35, $stages['marketplace_constraints']['unmatched_marketplace_need_qty']);
        $this->assertSame('MISSING-NEED', $stages['marketplace_constraints']['unmatched_marketplace_needs'][0]['sku']);
        $this->assertStringContainsString('незакрытую потребность', $stages['marketplace_constraints']['decision_ru']);
        $this->assertSame(2, $stages['economics_filter']['lines']);
        $this->assertSame(1, $stages['economics_filter']['skipped_lines']);
        $this->assertSame(1, $stages['budget_filter']['lines']);
        $this->assertSame(1, $stages['budget_filter']['skipped_lines']);
        $this->assertSame(1, $stages['final_selection']['lines']);
        $this->assertStringContainsString('собрано 5 строк / 70 шт', $result['summary']['candidate_audit']['funnel_summary_ru']);
        $this->assertStringContainsString('ограничения оставили 3 строк / 50 шт', $result['summary']['candidate_audit']['funnel_summary_ru']);
        $this->assertStringContainsString('незакрытая потребность 35 шт', $result['summary']['candidate_audit']['funnel_summary_ru']);
    }

    public function test_marketplace_need_increases_priority_without_bypassing_budget(): void
    {
        $plan = new AutoSupplyPlan([
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => ['planning_mode' => AutoSupplyPlan::MODE_BALANCED],
            'budget_limit' => 100,
        ]);

        $result = (new PlanLineOptimizer())->optimize([
            $this->line('HIGH-ROI-NO-NEED', supplyCost: 90, expectedProfit: 100, roi: 100),
            $this->line('MARKETPLACE-NEED', supplyCost: 90, expectedProfit: 10, roi: 1, marketplaceNeedQty: 120),
        ], $plan);

        $this->assertCount(1, $result['lines']);
        $this->assertSame('MARKETPLACE-NEED', $result['lines'][0]['sku']);
        $this->assertSame(1, $result['summary']['budget_skipped_lines']);

        $explain = json_decode($result['lines'][0]['explain_json'], true);
        $this->assertSame('marketplace_need', $explain['reason']);
        $this->assertSame(120, $explain['facts']['marketplace_need_qty']);
        $this->assertSame(110, $explain['facts']['marketplace_need_gap_qty']);
        $this->assertContains('marketplace_need_accounted', $explain['planning_decision']['score_basis']);
        $this->assertStringContainsString('маркетплейса', $explain['planning_decision']['decision_ru']);

        $audit = $result['summary']['candidate_audit'];
        $this->assertSame('optimizer-audit-2', $audit['version']);
        $this->assertSame(120, $audit['top_selected'][0]['marketplace_need_qty']);
        $this->assertSame(110, $audit['top_selected'][0]['marketplace_need_gap_qty']);
    }

    public function test_balanced_mode_prefers_fast_destination_for_a_items_over_plain_roi(): void
    {
        $plan = new AutoSupplyPlan([
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => ['planning_mode' => AutoSupplyPlan::MODE_BALANCED],
            'budget_limit' => 100,
        ]);

        $result = (new PlanLineOptimizer())->optimize([
            $this->line('C-HIGH-ROI', supplyCost: 90, expectedProfit: 1000, roi: 60, abc: 'C'),
            $this->line(
                'A-FAST',
                supplyCost: 90,
                expectedProfit: 100,
                roi: 5,
                territorialScore: 35,
                abc: 'A',
                territorialDemandClosureScore: 95,
                abcPolicyStatus: 'a_speed_priority',
                fastForA: true,
            ),
        ], $plan);

        $this->assertCount(1, $result['lines']);
        $this->assertSame('A-FAST', $result['lines'][0]['sku']);

        $explain = json_decode($result['lines'][0]['explain_json'], true);
        $this->assertContains('fast_destination_for_a_items', $explain['planning_decision']['score_basis']);
        $this->assertContains('быстрое направление для A-товара', $explain['planning_decision']['score_basis_labels']);
        $this->assertStringContainsString('быстром направлении', $explain['planning_decision']['score_component_explanation_ru']);

        $components = collect($explain['planning_decision']['score_components'])->keyBy('key')->all();
        $this->assertSame('positive', $components['fast_destination_for_a_items']['effect']);
        $this->assertGreaterThan(0, $components['fast_destination_for_a_items']['value']);

        $rejectedBySku = collect($result['summary']['candidate_audit']['top_rejected'])->keyBy('sku')->all();
        $this->assertSame('budget_limit', $rejectedBySku['C-HIGH-ROI']['decision']);
    }

    public function test_budget_optimizer_reports_strategic_portfolio_for_fast_a_and_oos_lines(): void
    {
        $plan = new AutoSupplyPlan([
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => ['planning_mode' => AutoSupplyPlan::MODE_BALANCED],
            'budget_limit' => 120,
        ]);

        $result = (new PlanLineOptimizer())->optimize([
            $this->line(
                'A-FAST-OOS',
                qty: 12,
                supplyCost: 60,
                expectedProfit: 120,
                roi: 20,
                risk: 'high',
                abc: 'A',
                territorialDemandClosureScore: 95,
                abcPolicyStatus: 'a_speed_priority',
                fastForA: true,
            ),
            $this->line(
                'A-SLOW',
                qty: 12,
                supplyCost: 60,
                expectedProfit: 80,
                roi: 15,
                risk: 'high',
                abc: 'A',
                territorialDemandClosureScore: 10,
            ),
            $this->line('C-PROFIT', qty: 12, supplyCost: 60, expectedProfit: 300, roi: 80, abc: 'C'),
        ], $plan);

        $selectedSkus = array_column($result['lines'], 'sku');
        $this->assertContains('A-FAST-OOS', $selectedSkus);

        $portfolio = $result['summary']['portfolio_summary'];
        $this->assertSame('optimizer-portfolio-1', $portfolio['version']);
        $this->assertSame(2, $portfolio['selected_lines']);
        $this->assertGreaterThan(0, $portfolio['abc_a_fast_qty']);
        $this->assertGreaterThan(0, $portfolio['high_risk_fast_qty']);
        $this->assertStringContainsString('A-товары в быстрых направлениях', $portfolio['decision_ru']);
        $this->assertStringContainsString('Модуль выбора собрал финальный набор', $result['summary']['candidate_audit']['portfolio_summary_ru']);

        $selectedAudit = collect($result['summary']['candidate_audit']['top_selected'])->keyBy('sku')->all();
        $this->assertGreaterThan(1.0, $selectedAudit['A-FAST-OOS']['strategic_multiplier']);
        $this->assertContains('strategic_portfolio', array_keys($result['summary']['score_policy']));
    }

    public function test_balanced_mode_accounts_for_regional_demand_closure_as_own_factor(): void
    {
        $plan = new AutoSupplyPlan([
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => ['planning_mode' => AutoSupplyPlan::MODE_BALANCED],
            'budget_limit' => 100,
        ]);

        $result = (new PlanLineOptimizer())->optimize([
            $this->line('C-ROI-ONLY', supplyCost: 90, expectedProfit: 1000, roi: 60, abc: 'C'),
            $this->line(
                'B-CLOSES-DEMAND',
                supplyCost: 90,
                expectedProfit: 100,
                roi: 5,
                abc: 'B',
                territorialDemandClosureScore: 100,
            ),
        ], $plan);

        $this->assertCount(1, $result['lines']);
        $this->assertSame('B-CLOSES-DEMAND', $result['lines'][0]['sku']);
        $this->assertSame('optimizer-5', $result['summary']['score_policy']['version']);
        $this->assertArrayHasKey('demand_closure', $result['summary']['score_policy']);

        $explain = json_decode($result['lines'][0]['explain_json'], true);
        $this->assertContains('demand_closure_accounted', $explain['planning_decision']['score_basis']);
        $this->assertContains('скорость закрытия спроса', $explain['planning_decision']['score_basis_labels']);
        $this->assertStringContainsString('быстрее закрывает региональный спрос', $explain['planning_decision']['score_component_explanation_ru']);

        $components = collect($explain['planning_decision']['score_components'])->keyBy('key')->all();
        $this->assertSame('positive', $components['demand_closure']['effect']);
        $this->assertGreaterThan(0, $components['demand_closure']['value']);
    }

    public function test_marketplace_need_candidate_keeps_reason_when_need_is_fully_covered(): void
    {
        $plan = new AutoSupplyPlan([
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => ['planning_mode' => AutoSupplyPlan::MODE_BALANCED],
        ]);

        $result = (new PlanLineOptimizer())->optimize([
            $this->line('MATERIALIZED-NEED', qty: 35, supplyCost: 100, expectedProfit: 50, roi: 20, confidence: 'warning', marketplaceNeedQty: 35),
        ], $plan);

        $this->assertCount(1, $result['lines']);

        $explain = json_decode($result['lines'][0]['explain_json'], true);
        $this->assertSame('marketplace_need', $explain['reason']);
        $this->assertSame(35, $explain['facts']['marketplace_need_qty']);
        $this->assertSame(0, $explain['facts']['marketplace_need_gap_qty']);
        $this->assertContains('marketplace_need_accounted', $explain['planning_decision']['score_basis']);
        $this->assertStringContainsString('маркетплейса', $explain['planning_decision']['decision_ru']);
        $this->assertSame(1, $result['summary']['reason_breakdown']['marketplace_need']);
    }

    public function test_budget_guard_does_not_let_low_confidence_spike_beat_reliable_deficit(): void
    {
        $plan = new AutoSupplyPlan([
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => ['planning_mode' => AutoSupplyPlan::MODE_BALANCED],
            'budget_limit' => 100,
        ]);

        $result = (new PlanLineOptimizer())->optimize([
            $this->line('PROMO-SPIKE', supplyCost: 90, expectedProfit: 50000, roi: 1000, confidence: 'low', risk: 'low'),
            $this->line('RELIABLE-DEFICIT', supplyCost: 90, expectedProfit: 100, roi: 10, confidence: 'good', risk: 'high', dailyDemand: 5, abc: 'A'),
        ], $plan);

        $this->assertCount(1, $result['lines']);
        $this->assertSame('RELIABLE-DEFICIT', $result['lines'][0]['sku']);

        $rejectedBySku = collect($result['summary']['candidate_audit']['top_rejected'])->keyBy('sku')->all();
        $this->assertSame('budget_limit', $rejectedBySku['PROMO-SPIKE']['decision']);
        $this->assertContains('низкая достоверность', $rejectedBySku['PROMO-SPIKE']['score_basis_labels']);

        $spikeAudit = collect($result['summary']['candidate_audit']['audit_rows'])->firstWhere('sku', 'PROMO-SPIKE');
        $this->assertLessThan(100, $spikeAudit['score']);

        $candidate = (new PlanLineOptimizer())->optimize([
            $this->line('PROMO-SPIKE', supplyCost: 90, expectedProfit: 50000, roi: 1000, confidence: 'low', risk: 'low'),
        ], new AutoSupplyPlan(['mode' => AutoSupplyPlan::MODE_BALANCED, 'params' => ['planning_mode' => AutoSupplyPlan::MODE_BALANCED]]));
        $explain = json_decode($candidate['lines'][0]['explain_json'], true);

        $this->assertSame('not_recommended_low_confidence', $explain['reason']);
        $this->assertEquals(45.0, $explain['planning_decision']['score']);
        $this->assertStringContainsString('Защита данных ограничила балл', $explain['planning_decision']['guardrail_ru']);
        $this->assertGreaterThan($explain['planning_decision']['score'], $explain['planning_decision']['raw_score_before_guard']);
    }

    public function test_low_confidence_spike_quantity_is_capped_to_trial_supply(): void
    {
        $plan = new AutoSupplyPlan([
            'mode' => AutoSupplyPlan::MODE_POST_PROMO_CAREFUL,
            'params' => ['planning_mode' => AutoSupplyPlan::MODE_POST_PROMO_CAREFUL],
        ]);

        $result = (new PlanLineOptimizer())->optimize([
            $this->line('SPIKE-QTY', qty: 100, supplyCost: 1000, expectedProfit: 1000, roi: 100, confidence: 'low', dailyDemand: 5),
        ], $plan);

        $this->assertCount(1, $result['lines']);
        $this->assertSame(45, $result['lines'][0]['qty_rounded']);
        $this->assertSame(450.0, $result['lines'][0]['supply_cost_estimate']);
        $this->assertSame(1, $result['summary']['quantity_guard_capped_lines']);
        $this->assertSame(55, $result['summary']['quantity_guard_reduced_qty']);

        $explain = json_decode($result['lines'][0]['explain_json'], true);
        $this->assertSame('not_recommended_low_confidence', $explain['reason']);
        $this->assertTrue($explain['optimizer_quantity_guard']['applied']);
        $this->assertSame(100, $explain['optimizer_quantity_guard']['qty_before']);
        $this->assertSame(45, $explain['optimizer_quantity_guard']['qty_after']);
        $this->assertSame(7, $explain['optimizer_quantity_guard']['trial_cover_days']);
        $this->assertContains('сработала защита количества', $explain['planning_decision']['score_basis_labels']);
        $this->assertContains('optimizer_protective_trial_quantity_cap', $explain['confidence']['confidence_reasons']);

        $audit = $result['summary']['candidate_audit'];
        $this->assertSame(1, $audit['quantity_guard_capped_lines']);
        $this->assertSame(55, $audit['quantity_guard_reduced_qty']);
        $this->assertTrue($audit['top_selected'][0]['quantity_guard_applied']);
        $this->assertSame(55, $audit['top_selected'][0]['quantity_guard_reduced_by_qty']);
    }


    private function line(
        string $sku,
        int $qty = 10,
        float $supplyCost = 10,
        float $expectedProfit = 10,
        float $roi = 10,
        string $confidence = 'good',
        string $risk = 'low',
        int $currentStock = 0,
        int $inTransit = 0,
        float $dailyDemand = 1,
        float $territorialScore = 0,
        string $abc = 'C',
        int $marketplaceNeedQty = 0,
        float $territorialDemandClosureScore = 0,
        ?string $abcPolicyStatus = null,
        bool $fastForA = false,
    ): array {
        return [
            'sku' => $sku,
            'qty_rounded' => $qty,
            'current_stock' => $currentStock,
            'in_transit' => $inTransit,
            'demand_daily' => $dailyDemand,
            'cover_days_before' => $dailyDemand > 0 ? ($currentStock + $inTransit) / $dailyDemand : 0,
            'risk_level' => $risk,
            'priority_score' => 50,
            'supply_cost_estimate' => $supplyCost,
            'expected_profit' => $expectedProfit,
            'roi_percent' => $roi,
            'lost_revenue_daily' => 100,
            'explain_json' => json_encode([
                'inputs' => [
                    'daily_demand' => $dailyDemand,
                    'min_cover_days' => 7,
                    'target_cover_days' => 21,
                    'supply_type' => 'replenishment',
                    'abc_priority' => $abc,
                ],
                'confidence' => [
                    'confidence_level' => $confidence,
                    'confidence_reasons' => $confidence === 'low' ? ['promo_spike_suspected'] : [],
                ],
                'territorial' => [
                    'score' => $territorialScore,
                    'regional_demand_closure_score' => $territorialDemandClosureScore,
                    'abc_policy_status' => $abcPolicyStatus,
                    'is_fast_for_a_items' => $fastForA,
                ],
                'marketplace_needs' => $marketplaceNeedQty > 0 ? [
                    'applied' => true,
                    'source' => 'Файл потребностей или ограничений маркетплейса',
                    'source_type' => 'marketplace_need',
                    'need_qty' => $marketplaceNeedQty,
                    'planned_qty' => 10,
                    'delta_qty' => $marketplaceNeedQty - 10,
                    'interpretation_ru' => 'Маркетплейс/файл показывает потребность выше текущего плана.',
                ] : null,
            ]),
        ];
    }
}
