<?php

namespace Tests\Unit\AutoSupplyPlanning;

use App\Models\AutoSupplyPlan;
use App\Services\AutoSupplyPlanning\PlanQualityAuditService;
use Tests\TestCase;

class PlanQualityAuditServiceTest extends TestCase
{
    public function test_audit_flags_promo_spike_overplanning_and_blocks_draft_gate(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'ozon',
            'target_cover_days' => 30,
        ]);

        $audit = (new PlanQualityAuditService())->audit([
            $this->line(
                sku: 'SPIKE-SKU',
                qty: 120,
                dailyDemand: 1.2,
                confidence: 'low',
                confidenceReasons: ['promo_spike_peak_vs_median', 'post_promo_cooldown'],
                expectedProfit: 500,
                clusterName: 'Москва'
            ),
        ], $plan, [
            'territorial_summary' => [
                'ktr' => [
                    'value' => 42,
                    'target_value' => 80,
                    'abc_a_fast_share_percent' => 30,
                    'high_risk_fast_share_percent' => 40,
                ],
            ],
        ]);

        $this->assertSame('bad', $audit['status']);
        $this->assertSame('Нужна ручная проверка', $audit['status_label']);
        $this->assertSame(1, $audit['risk_counters']['low_confidence_lines']);
        $this->assertSame(1, $audit['risk_counters']['promo_spike_lines']);
        $this->assertSame(1, $audit['risk_counters']['overplanned_lines']);
        $this->assertTrue($audit['guards_applied']['promo_spike_guard']);
        $this->assertFalse($audit['acceptance_gates']['can_create_ozon_draft']);
        $this->assertTrue($audit['acceptance_gates']['requires_manual_review']);
        $this->assertNotEmpty($audit['actions']);
        $this->assertSame('review_demand', $audit['actions'][0]['type']);
        $this->assertSame('SPIKE-SKU', $audit['examples'][0]['sku']);
    }

    public function test_audit_allows_stable_ozon_preview_when_no_critical_risks(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'ozon',
            'target_cover_days' => 30,
        ]);

        $audit = (new PlanQualityAuditService())->audit([
            $this->line(
                sku: 'STABLE-SKU',
                qty: 20,
                dailyDemand: 1.0,
                confidence: 'good',
                confidenceReasons: [],
                expectedProfit: 1200,
                clusterName: 'Москва'
            ),
        ], $plan, [
            'territorial_summary' => [
                'ktr' => [
                    'value' => 86,
                    'target_value' => 80,
                    'abc_a_fast_share_percent' => 90,
                    'high_risk_fast_share_percent' => 85,
                ],
            ],
        ]);

        $this->assertSame('good', $audit['status']);
        $this->assertTrue($audit['acceptance_gates']['can_export']);
        $this->assertTrue($audit['acceptance_gates']['can_create_ozon_draft']);
        $this->assertFalse($audit['acceptance_gates']['requires_manual_review']);
        $this->assertSame('ready_for_manual_check', $audit['actions'][0]['type']);
    }

    public function test_audit_tracks_marketplace_need_raised_and_remaining_gap(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'wildberries',
            'target_cover_days' => 21,
        ]);

        $audit = (new PlanQualityAuditService())->audit([
            $this->line(
                sku: 'NEED-SKU',
                qty: 18,
                dailyDemand: 1.0,
                confidence: 'good',
                confidenceReasons: [],
                expectedProfit: 900,
                warehouseName: 'Коледино',
                marketplaceNeeds: [
                    'need_qty' => 25,
                    'planned_qty' => 18,
                    'planned_before_need_qty' => 10,
                    'raised_by_qty' => 8,
                    'remaining_gap_qty' => 7,
                ]
            ),
        ], $plan, [
            'constraints_summary' => [
                'total_marketplace_need_qty' => 25,
            ],
            'territorial_summary' => [
                'ktr' => [
                    'value' => 82,
                    'target_value' => 80,
                ],
            ],
        ]);

        $this->assertSame('warning', $audit['status']);
        $this->assertSame(1, $audit['risk_counters']['marketplace_need_raised_lines']);
        $this->assertSame(8, $audit['risk_counters']['marketplace_need_raised_qty']);
        $this->assertSame(1, $audit['risk_counters']['remaining_marketplace_need_lines']);
        $this->assertSame(7, $audit['risk_counters']['remaining_marketplace_need_qty']);
        $this->assertSame(28.0, $audit['risk_share_percent']['remaining_marketplace_need_qty']);
        $this->assertSame('review_uncovered_marketplace_need', $audit['actions'][0]['type']);
    }

    public function test_audit_flags_unmatched_marketplace_needs_from_constraint_file(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'ozon',
            'target_cover_days' => 21,
        ]);

        $audit = (new PlanQualityAuditService())->audit([
            $this->line(
                sku: 'PLANNED-SKU',
                qty: 20,
                dailyDemand: 1.0,
                confidence: 'good',
                confidenceReasons: [],
                expectedProfit: 900,
                clusterName: 'Москва'
            ),
        ], $plan, [
            'constraints_summary' => [
                'total_file_marketplace_need_qty' => 32,
                'unmatched_marketplace_need_count' => 1,
                'unmatched_marketplace_need_qty' => 12,
                'unmatched_marketplace_needs' => [
                    [
                        'canonical_key' => 'destination:155|sku:oz-missing',
                        'sku' => 'OZ-MISSING',
                        'destination_name' => 'Самара',
                        'need_qty' => 12,
                        'scope' => 'кластер + SKU',
                    ],
                ],
            ],
            'territorial_summary' => [
                'ktr' => [
                    'value' => 82,
                    'target_value' => 80,
                ],
            ],
        ]);

        $this->assertSame('warning', $audit['status']);
        $this->assertSame(1, $audit['risk_counters']['unmatched_marketplace_need_count']);
        $this->assertSame(12, $audit['risk_counters']['unmatched_marketplace_need_qty']);
        $this->assertSame(37.5, $audit['risk_share_percent']['unmatched_marketplace_need_qty']);
        $this->assertTrue($audit['guards_applied']['marketplace_need_backlog']);
        $this->assertSame('create_candidates_for_unmatched_marketplace_needs', $audit['actions'][0]['type']);
        $this->assertSame('high', $audit['actions'][0]['priority']);
        $this->assertSame(12, $audit['actions'][0]['affected_qty']);
        $this->assertSame('OZ-MISSING', $audit['examples'][0]['sku']);
        $this->assertSame('Самара', $audit['examples'][0]['destination']);
        $this->assertStringContainsString('не попали в строки плана', $audit['summary_ru']);
        $this->assertTrue($audit['guards_applied']['constraints_require_review']);
        $this->assertFalse($audit['acceptance_gates']['can_create_ozon_draft']);
        $this->assertTrue($audit['acceptance_gates']['constraints_require_review']);
        $this->assertStringContainsString('разберите файл ограничений', $audit['acceptance_gates']['manual_review_reason_ru']);
    }

    public function test_audit_blocks_ozon_draft_when_constraint_source_requires_review_even_without_bad_status(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'ozon',
            'target_cover_days' => 30,
        ]);

        $audit = (new PlanQualityAuditService())->audit([
            $this->line(
                sku: 'STABLE-WITH-CONSTRAINT-REVIEW',
                qty: 20,
                dailyDemand: 1.0,
                confidence: 'good',
                confidenceReasons: [],
                expectedProfit: 1200,
                clusterName: 'Москва'
            ),
        ], $plan, [
            'constraints_summary' => [
                'source_file' => 'ozon-limits.csv',
                'constraints_count' => 3,
                'matched_constraints_count' => 2,
                'planning_source' => [
                    'used_as_constraints' => true,
                    'requires_review' => true,
                ],
            ],
            'territorial_summary' => [
                'ktr' => [
                    'value' => 86,
                    'target_value' => 80,
                    'abc_a_fast_share_percent' => 90,
                    'high_risk_fast_share_percent' => 85,
                ],
            ],
        ]);

        $this->assertSame('good', $audit['status']);
        $this->assertTrue($audit['guards_applied']['constraints_require_review']);
        $this->assertFalse($audit['acceptance_gates']['can_create_ozon_draft']);
        $this->assertTrue($audit['acceptance_gates']['requires_manual_review']);
        $this->assertTrue($audit['acceptance_gates']['constraints_require_review']);
        $this->assertStringContainsString('разберите файл ограничений', $audit['acceptance_gates']['manual_review_reason_ru']);
    }

    public function test_audit_treats_quantity_guard_as_protection_not_only_bad_data(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'ozon',
            'target_cover_days' => 30,
        ]);

        $audit = (new PlanQualityAuditService())->audit([
            $this->line(
                sku: 'PROTECTED-SPIKE',
                qty: 45,
                dailyDemand: 5.0,
                confidence: 'low',
                confidenceReasons: ['promo_spike_suspected', 'optimizer_protective_trial_quantity_cap'],
                expectedProfit: 1500,
                clusterName: 'Москва',
                optimizerQuantityGuard: [
                    'applied' => true,
                    'qty_before' => 100,
                    'qty_after' => 45,
                    'reduced_by_qty' => 55,
                    'trial_cover_days' => 7,
                    'reason' => 'promo_or_low_confidence',
                ]
            ),
        ], $plan, [
            'territorial_summary' => [
                'ktr' => [
                    'value' => 86,
                    'target_value' => 80,
                    'abc_a_fast_share_percent' => 90,
                    'high_risk_fast_share_percent' => 85,
                ],
            ],
            'selection_summary' => [
                'quantity_guard_capped_lines' => 1,
                'quantity_guard_reduced_qty' => 55,
            ],
        ]);

        $this->assertSame('warning', $audit['status']);
        $this->assertSame('План ограничен защитой данных', $audit['status_label']);
        $this->assertSame(1, $audit['risk_counters']['low_confidence_lines']);
        $this->assertSame(1, $audit['risk_counters']['promo_spike_lines']);
        $this->assertSame(1, $audit['risk_counters']['quantity_guard_lines']);
        $this->assertSame(45, $audit['risk_counters']['quantity_guard_final_qty']);
        $this->assertSame(55, $audit['risk_counters']['quantity_guard_reduced_qty']);
        $this->assertSame(55.0, $audit['risk_share_percent']['quantity_guard_reduced_qty']);
        $this->assertTrue($audit['guards_applied']['quantity_guard']);
        $this->assertTrue($audit['acceptance_gates']['can_create_ozon_draft']);
        $this->assertSame('review_protected_trial_quantities', $audit['actions'][0]['type']);
        $this->assertSame('medium', $audit['actions'][0]['priority']);
        $this->assertStringContainsString('защитой данных', $audit['summary_ru']);
    }

    public function test_audit_warns_when_financial_ktr_is_weaker_than_qty_ktr(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'wildberries',
            'target_cover_days' => 21,
        ]);

        $audit = (new PlanQualityAuditService())->audit([
            $this->line(
                sku: 'EXPENSIVE-SLOW',
                qty: 10,
                dailyDemand: 1.0,
                confidence: 'good',
                confidenceReasons: [],
                expectedProfit: 5000,
                warehouseName: 'Медленный склад'
            ),
        ], $plan, [
            'territorial_summary' => [
                'ktr' => [
                    'value' => 86,
                    'target_value' => 80,
                    'financial_value' => 68,
                    'financial_priority_share_percent' => 52,
                    'abc_a_fast_share_percent' => 90,
                    'high_risk_fast_share_percent' => 85,
                ],
            ],
        ]);

        $this->assertSame('warning', $audit['status']);
        $this->assertSame(68.0, $audit['territorial_checks']['financial_ktr_value']);
        $this->assertSame(52.0, $audit['territorial_checks']['financial_priority_share_percent']);
        $this->assertStringContainsString('дорогие товары', $audit['territorial_checks']['financial_status_ru']);
        $this->assertSame('review_financial_distribution', $audit['actions'][0]['type']);
        $this->assertSame('high', $audit['actions'][0]['priority']);
        $this->assertSame(18.0, $audit['actions'][0]['financial_lag_pp']);
        $this->assertTrue($audit['acceptance_gates']['can_export']);
        $this->assertTrue($audit['acceptance_gates']['requires_manual_review']);
    }

    /**
     * @param list<string> $confidenceReasons
     * @param array<string, mixed> $marketplaceNeeds
     * @param array<string, mixed> $optimizerQuantityGuard
     */
    private function line(
        string $sku,
        int $qty,
        float $dailyDemand,
        string $confidence,
        array $confidenceReasons,
        float $expectedProfit,
        ?string $clusterName = null,
        ?string $warehouseName = null,
        array $marketplaceNeeds = [],
        array $optimizerQuantityGuard = [],
    ): array {
        $explain = [
            'inputs' => [
                'daily_demand' => $dailyDemand,
                'target_cover_days' => 30,
            ],
            'confidence' => [
                'confidence_level' => $confidence,
                'confidence_reasons' => $confidenceReasons,
            ],
            'marketplace_needs' => $marketplaceNeeds,
        ];

        if ($optimizerQuantityGuard !== []) {
            $explain['optimizer_quantity_guard'] = $optimizerQuantityGuard;
        }

        return [
            'sku' => $sku,
            'product_name' => "{$sku} product",
            'qty_rounded' => $qty,
            'cluster_name' => $clusterName,
            'warehouse_name' => $warehouseName,
            'expected_profit' => $expectedProfit,
            'explain_json' => json_encode($explain, JSON_UNESCAPED_UNICODE),
        ];
    }
}
