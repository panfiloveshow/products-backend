<?php

namespace Tests\Unit\AutoSupplyPlanning;

use App\Models\AutoSupplyPlan;
use App\Services\AutoSupplyPlanning\PlanningFactSnapshotService;
use App\Services\AutoSupplyPlanning\PlanningReadinessChecklistService;
use PHPUnit\Framework\TestCase;

class PlanningReadinessChecklistServiceTest extends TestCase
{
    public function test_readiness_treats_constraint_summary_as_full_planning_source(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'ozon',
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'horizon_days' => 28,
            'total_qty' => 10,
            'params' => [
                'planning_mode' => AutoSupplyPlan::MODE_BALANCED,
                'analysis_period_days' => 28,
                'cluster_ids' => [154],
            ],
            'data_quality_json' => [
                'meta' => [
                    'planning_fact_sources' => [
                        'demand' => 'posting_fbo_v3',
                        'stock' => 'analytics_stocks',
                        'in_transit' => 'supply_orders',
                        'turnover' => 'turnover_stocks',
                        'delivery_health' => 'average_delivery_time_summary',
                        'constraint_coefficients' => 'constraint_file',
                    ],
                ],
            ],
            'result_json' => [
                'facts_freshness' => ['unit_economics' => ['items' => 10]],
                'deficit_summary' => ['lines' => 1, 'qty' => 5, 'lost_revenue_daily' => 120],
                'surplus_summary' => ['lines' => 1, 'qty' => 3],
                'deficit_surplus_summary' => ['redistribution' => ['policy' => 'Для FBO Ozon/WB физическое перераспределение между складами маркетплейса недоступно продавцу']],
                'selection_summary' => ['selected_lines' => 1],
                'economics_summary' => ['total_expected_profit' => 5000],
                'constraints_summary' => [
                    'source_kind' => 'constraint_file',
                    'source_file' => 'ozon-limits.csv',
                    'constraints_count' => 2,
                    'matched_constraints_count' => 2,
                    'file_marketplace_needs_count' => 1,
                    'matched_marketplace_need_lines' => 1,
                    'total_file_marketplace_need_qty' => 42,
                    'total_marketplace_need_qty' => 42,
                    'coefficient_lines' => 3,
                    'source_type_counts' => [
                        'marketplace_constraint' => 1,
                        'marketplace_need' => 1,
                    ],
                    'planning_source' => [
                        'used_as_constraints' => true,
                        'used_as_marketplace_needs' => true,
                        'used_as_coefficients' => true,
                        'requires_review' => false,
                    ],
                ],
                'territorial_summary' => [
                    'status' => 'включено',
                    'method' => 'Ранжирование кластеров',
                    'source_coverage' => [
                        'metrics' => [
                            'delivery_speed_source' => ['coverage_percent' => 100],
                        ],
                    ],
                    'demand_closure_ranking' => [
                        ['name' => 'Москва', 'demand_closure_score' => 90],
                    ],
                    'ktr' => [
                        'value' => 82.3,
                        'label' => 'КТР 82.3%',
                        'explanation' => 'КТР — текущий коэффициент территориального распределения',
                        'abc_a_fast_share_percent' => 90,
                        'fixation' => [
                            'tracking_status' => 'unchanged',
                            'fixed_baseline_value' => 82.3,
                        ],
                    ],
                ],
                'marketplace_capabilities' => [
                    'supports_draft_creation' => true,
                    'supports_autobooking' => false,
                    'planning_flow' => 'preview → подтверждение → draft',
                    'autobooking_policy' => 'Автобронирование не выполняется',
                    'territorial_distribution' => [
                        'supported' => true,
                        'score_kind' => 'локальность и скорость доставки',
                    ],
                ],
            ],
        ]);

        $checklist = (new PlanningReadinessChecklistService())->build($plan);
        $apiSources = collect($checklist['sections'])->firstWhere('key', 'api_sources');
        $items = collect($apiSources['items'])->keyBy('key');

        $this->assertSame('ready', $items['constraints']['status']);
        $this->assertStringContainsString('Ограничения подключены', $items['constraints']['details_ru']);
        $this->assertStringContainsString('2 правил', $items['constraints']['value']);
        $this->assertStringContainsString('2 совпавших правил', $items['constraints']['value']);
        $this->assertSame('ready', $items['delivery_speed']['status']);
        $this->assertSame('ready', $items['marketplace_needs']['status']);
        $this->assertStringContainsString('Потребности маркетплейса загружены', $items['marketplace_needs']['details_ru']);
        $this->assertStringContainsString('42 шт. потребности', $items['marketplace_needs']['value']);
        $this->assertSame('ready', $items['warehouse_coefficients']['status']);
        $this->assertStringContainsString('Коэффициенты направлений подключены', $items['warehouse_coefficients']['details_ru']);
        $this->assertStringContainsString('3 строк с коэффициентами', $items['warehouse_coefficients']['value']);

        $policy = collect($checklist['sections'])->firstWhere('key', 'marketplace_policy');
        $policyItems = collect($policy['items'])->keyBy('key');
        $this->assertSame('ready', $policyItems['ktr_baseline']['status']);
        $this->assertStringContainsString('база 82.3%', $policyItems['ktr_baseline']['value']);

        $this->assertSame('Покрытие требований умного автопланирования', $checklist['requirements_summary']['title_ru']);
        $this->assertGreaterThanOrEqual(85, $checklist['requirements_summary']['coverage_percent']);
        $this->assertSame([], $checklist['critical_gaps_ru']);

        $matrix = collect($checklist['requirements_matrix'])->keyBy('key');
        $this->assertSame('Параметры расчёта', $matrix['parameters']['title_ru']);
        $this->assertSame('Источники данных API', $matrix['api_sources']['title_ru']);
        $this->assertSame('Что считает сервис', $matrix['calculations']['title_ru']);
        $this->assertSame('Логика площадки', $matrix['marketplace_policy']['title_ru']);

        $apiMatrixItems = collect($matrix['api_sources']['items'])->keyBy('key');
        $this->assertSame('Потребности складов/кластеров', $apiMatrixItems['marketplace_needs']['requirement_ru']);
        $this->assertSame('ready', $apiMatrixItems['marketplace_needs']['status']);
        $this->assertStringContainsString('42 шт. потребности', $apiMatrixItems['marketplace_needs']['evidence_ru']);
        $this->assertSame('Действий не требуется: пункт включён.', $apiMatrixItems['marketplace_needs']['next_action_ru']);
        $this->assertSame('ready', $apiMatrixItems['warehouse_coefficients']['status']);
        $this->assertStringContainsString('3 строк с коэффициентами', $apiMatrixItems['warehouse_coefficients']['evidence_ru']);
    }

    public function test_fact_sources_expose_constraint_coefficients_as_planning_source(): void
    {
        $sources = (new PlanningFactSnapshotService())->withConstraintSources([
            'demand' => 'posting_fbo_v3',
        ], [
            'source_kind' => 'constraint_file',
            'source_file' => 'wb-coefficients.xlsx',
            'source_status' => 'applied_as_coefficients',
            'parser_version' => 'marketplace-constraints-4',
            'total_file_marketplace_need_qty' => 0,
            'planning_source' => [
                'used_as_constraints' => false,
                'used_as_marketplace_needs' => false,
                'used_as_coefficients' => true,
                'requires_review' => false,
            ],
        ]);

        $this->assertSame('constraint_file', $sources['constraint_coefficients']);
        $this->assertSame('applied_as_coefficients', $sources['constraints_status']);
        $this->assertSame('wb-coefficients.xlsx', $sources['constraint_source_file']);
        $this->assertTrue($sources['constraints_used_as_coefficients']);
    }

    public function test_readiness_marks_constraint_source_partial_when_file_has_unmatched_needs(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'ozon',
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'horizon_days' => 28,
            'params' => ['planning_mode' => AutoSupplyPlan::MODE_BALANCED],
            'result_json' => [
                'constraints_summary' => [
                    'source_file' => 'ozon-needs.csv',
                    'constraints_count' => 3,
                    'matched_constraints_count' => 2,
                    'file_marketplace_needs_count' => 2,
                    'total_file_marketplace_need_qty' => 140,
                    'unmatched_marketplace_need_qty' => 40,
                    'planning_source' => [
                        'used_as_constraints' => true,
                        'used_as_marketplace_needs' => true,
                        'requires_review' => true,
                    ],
                ],
            ],
        ]);

        $checklist = (new PlanningReadinessChecklistService())->build($plan);
        $apiSources = collect($checklist['sections'])->firstWhere('key', 'api_sources');
        $items = collect($apiSources['items'])->keyBy('key');

        $this->assertSame('partial', $items['constraints']['status']);
        $this->assertStringContainsString('требует проверки', $items['constraints']['details_ru']);
        $this->assertStringContainsString('40 шт. потребности на проверку', $items['constraints']['value']);
        $this->assertSame('partial', $items['marketplace_needs']['status']);
        $this->assertStringContainsString('требует проверки', $items['marketplace_needs']['details_ru']);
        $this->assertStringContainsString('40 шт. потребности на проверку', $items['marketplace_needs']['value']);

        $this->assertNotEmpty($checklist['critical_gaps_ru']);
        $this->assertContains(
            'Ограничения складов/кластеров: Загрузить или проверить файл ограничений складов/кластеров.',
            $checklist['critical_gaps_ru']
        );
        $this->assertContains(
            'Проверить несовпавшие потребности маркетплейса и привязку SKU/направлений.',
            $checklist['next_actions_ru']
        );
    }

    public function test_readiness_requires_ktr_baseline_for_controlled_ktr_metric(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'wildberries',
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'horizon_days' => 28,
            'params' => ['planning_mode' => AutoSupplyPlan::MODE_BALANCED],
            'result_json' => [
                'territorial_summary' => [
                    'status' => 'включено',
                    'ktr' => [
                        'value' => 71.8,
                        'label' => 'КТР 71.8%',
                        'fixation' => [
                            'tracking_status' => 'not_fixed',
                            'fixed_baseline_value' => null,
                        ],
                    ],
                ],
                'marketplace_capabilities' => [
                    'supports_draft_creation' => false,
                    'supports_autobooking' => false,
                    'autobooking_policy' => 'Автобронирование не выполняется',
                    'territorial_distribution' => [
                        'supported' => true,
                        'score_kind' => 'скорость доставки, стоимость, коэффициенты и ABC',
                    ],
                ],
            ],
        ]);

        $checklist = (new PlanningReadinessChecklistService())->build($plan);
        $policy = collect($checklist['sections'])->firstWhere('key', 'marketplace_policy');
        $items = collect($policy['items'])->keyBy('key');

        $this->assertSame('ready', $items['ktr']['status']);
        $this->assertSame('partial', $items['ktr_baseline']['status']);
        $this->assertSame('ожидает фиксации', $items['ktr_baseline']['value']);

        $matrix = collect($checklist['requirements_matrix'])->firstWhere('key', 'marketplace_policy');
        $matrixItems = collect($matrix['items'])->keyBy('key');
        $this->assertTrue($matrixItems['ktr_baseline']['is_blocking']);
        $this->assertSame(
            'Зафиксировать текущий КТР как базу сравнения, чтобы следующие планы показывали улучшение или ухудшение.',
            $matrixItems['ktr_baseline']['next_action_ru']
        );
    }
}
