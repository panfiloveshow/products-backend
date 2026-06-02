<?php

namespace Tests\Feature\AutoSupplyPlanning;

use App\Models\AutoSupplyPlan;
use App\Models\Integration;
use App\Models\Product;
use App\Models\UnitEconomics;
use App\Models\WarehouseSlot;
use App\Services\AutoSupplyPlanning\MarketplaceConstraintService;
use App\Services\AutoSupplyPlanning\TerritorialPlanningService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TerritorialAndConstraintServicesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_constraints_cap_and_block_lines_with_russian_summary(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'ozon',
            'params' => [
                'constraint_metadata' => [
                    'file' => [
                        'name' => 'ozon_limits.csv',
                        'sha256' => 'test-hash',
                    ],
                    'summary' => [
                        'parser_version' => 'marketplace-constraints-2',
                        'warnings_count' => 1,
                    ],
                ],
                'cluster_constraints' => [
                    ['cluster_id' => 154, 'max_qty' => 5, 'cluster_name' => 'Москва'],
                    ['cluster_id' => 155, 'is_available' => false, 'cluster_name' => 'Самара'],
                ],
            ],
        ]);

        $result = (new MarketplaceConstraintService())->apply([
            $this->line('SKU-1', 10, clusterId: 154),
            $this->line('SKU-2', 4, clusterId: 155),
        ], $plan, 'ozon');

        $this->assertCount(1, $result['lines']);
        $this->assertSame(5, $result['lines'][0]['qty_rounded']);
        $this->assertSame('Файл или параметры ограничений', $result['summary']['source']);
        $this->assertSame('constraint_file', $result['summary']['source_kind']);
        $this->assertSame('applied_with_limits', $result['summary']['source_status']);
        $this->assertSame('Файл ограничений повлиял на итоговые количества', $result['summary']['human_status']);
        $this->assertStringContainsString('Система снизила', $result['summary']['decision_ru']);
        $this->assertStringContainsString('Покрытие строк: 100%', $result['summary']['coverage_summary_ru']);
        $this->assertSame(2, $result['summary']['matched_constraints_count']);
        $this->assertSame(0, $result['summary']['unmatched_constraints_count']);
        $this->assertSame(2, $result['summary']['source_type_counts']['marketplace_constraint']);
        $this->assertSame(2, $result['summary']['matched_lines']);
        $this->assertSame(1, $result['summary']['capped_lines']);
        $this->assertSame(1, $result['summary']['blocked_lines']);
        $this->assertSame(9, $result['summary']['reduced_qty']);
        $this->assertCount(2, $result['summary']['applied_examples']);
        $this->assertSame(['capped', 'blocked'], array_column($result['summary']['applied_examples'], 'action'));
        $this->assertStringContainsString('Количество снижено', $result['summary']['applied_examples'][0]['decision_ru']);
        $this->assertStringContainsString('Строка убрана', $result['summary']['applied_examples'][1]['decision_ru']);
        $this->assertTrue($result['summary']['applied']);
        $this->assertSame('ozon_limits.csv', $result['summary']['source_file']);
        $this->assertSame('test-hash', $result['summary']['source_hash']);
        $this->assertSame('marketplace-constraints-2', $result['summary']['parser_version']);
        $this->assertSame(1, $result['summary']['warnings_count']);
        $this->assertSame(100.0, $result['summary']['coverage']['match_percent']);
        $this->assertSame(64.29, $result['summary']['coverage']['reduced_qty_percent']);
        $this->assertTrue($result['summary']['planning_source']['used_as_constraints']);
        $this->assertTrue($result['summary']['planning_source']['used_for_quantity_caps']);
        $this->assertTrue($result['summary']['planning_source']['requires_review']);
    }

    public function test_constraints_apply_global_sku_limit_across_ozon_clusters(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'ozon',
            'params' => [
                'cluster_constraints' => [
                    ['sku' => 'SKU-GLOBAL', 'max_qty' => 12, 'reason' => 'Общий лимит товара'],
                ],
            ],
        ]);

        $result = (new MarketplaceConstraintService())->apply([
            $this->line('SKU-GLOBAL', 10, clusterId: 154),
            $this->line('SKU-GLOBAL', 10, clusterId: 155),
            $this->line('OTHER', 10, clusterId: 155),
        ], $plan, 'ozon');

        $this->assertCount(3, $result['lines']);
        $this->assertSame(10, $result['lines'][0]['qty_rounded']);
        $this->assertSame(2, $result['lines'][1]['qty_rounded']);
        $this->assertSame(10, $result['lines'][2]['qty_rounded']);
        $this->assertSame(2, $result['summary']['matched_lines']);
        $this->assertSame(1, $result['summary']['capped_lines']);
        $this->assertSame(8, $result['summary']['reduced_qty']);
        $this->assertSame(2, $result['summary']['matched_scopes']['SKU во всех направлениях']);
    }

    public function test_ozon_constraints_match_offer_id_when_internal_sku_differs(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'ozon',
            'params' => [
                'cluster_constraints' => [
                    ['cluster_id' => 154, 'sku' => 'OZ-OFFER-1', 'max_qty' => 4, 'reason' => 'Лимит по артикулу продавца'],
                ],
            ],
        ]);

        $line = $this->line('INTERNAL-SKU-1', 10, clusterId: 154);
        $line['offer_id'] = 'OZ-OFFER-1';

        $result = (new MarketplaceConstraintService())->apply([$line], $plan, 'ozon');

        $this->assertCount(1, $result['lines']);
        $this->assertSame(4, $result['lines'][0]['qty_rounded']);
        $this->assertSame(1, $result['summary']['matched_lines']);
        $this->assertSame(1, $result['summary']['capped_lines']);
        $this->assertSame(1, $result['summary']['matched_scopes']['кластер + SKU']);

        $explain = json_decode($result['lines'][0]['explain_json'], true);
        $this->assertSame('OZ-OFFER-1', $explain['constraints']['sku']);
        $this->assertStringContainsString('Файл ограничений снизил количество', $explain['constraints']['decision_ru']);
    }

    public function test_wb_constraints_match_barcode_when_sku_differs(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'wildberries',
            'params' => [
                'warehouse_constraints' => [
                    ['warehouse_id' => 'Коледино', 'sku' => '4601234567890', 'is_available' => false, 'reason' => 'Запрет по штрихкоду'],
                ],
            ],
        ]);

        $line = $this->line('INTERNAL-WB-SKU', 8, warehouseId: 'Коледино', warehouseName: 'Коледино');
        $line['barcode'] = '4601234567890';

        $result = (new MarketplaceConstraintService())->apply([$line], $plan, 'wildberries');

        $this->assertCount(0, $result['lines']);
        $this->assertSame(1, $result['summary']['matched_lines']);
        $this->assertSame(1, $result['summary']['blocked_lines']);
        $this->assertSame(8, $result['summary']['reduced_qty']);
        $this->assertSame('blocked', $result['summary']['applied_examples'][0]['action']);
    }

    public function test_constraints_apply_destination_coefficient_without_capping(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'wildberries',
            'params' => [
                'warehouse_constraints' => [
                    ['warehouse_id' => 'Электросталь', 'coefficient' => 3.5, 'reason' => 'Дорогая приёмка'],
                ],
            ],
        ]);

        $result = (new MarketplaceConstraintService())->apply([
            $this->line('WB-1', 10, warehouseId: 'Электросталь', warehouseName: 'Электросталь'),
        ], $plan, 'wildberries');

        $this->assertCount(1, $result['lines']);
        $this->assertSame(10, $result['lines'][0]['qty_rounded']);
        $this->assertSame(1, $result['summary']['matched_lines']);
        $this->assertSame(1, $result['summary']['coefficient_lines']);
        $this->assertSame(0, $result['summary']['capped_lines']);
        $this->assertTrue($result['summary']['applied']);

        $explain = json_decode($result['lines'][0]['explain_json'], true);
        $this->assertSame(3.5, $explain['constraints']['coefficient']);
        $this->assertSame('склад целиком', $explain['constraints']['scope']);
        $this->assertStringContainsString('коэффициенты направления', mb_strtolower($explain['constraints']['decision_ru']));
        $this->assertSame('coefficient', $result['summary']['applied_examples'][0]['action']);
    }

    public function test_constraints_keep_detailed_coefficients_for_territorial_ranking(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'wildberries',
            'params' => [
                'warehouse_constraints' => [
                    [
                        'warehouse_id' => 'Коледино',
                        'acceptance_coefficient' => 1.4,
                        'delivery_coefficient' => 2.2,
                        'storage_coefficient' => 3.1,
                        'reason' => 'Разные коэффициенты из файла WB',
                    ],
                ],
            ],
        ]);

        $result = (new MarketplaceConstraintService())->apply([
            $this->line('WB-COEF', 10, warehouseId: 'Коледино', warehouseName: 'Коледино'),
        ], $plan, 'wildberries');

        $this->assertSame(1, $result['summary']['coefficient_lines']);

        $explain = json_decode($result['lines'][0]['explain_json'], true);
        $this->assertSame(3.1, $explain['constraints']['coefficient']);
        $this->assertSame(1.4, $explain['constraints']['acceptance_coefficient']);
        $this->assertSame(2.2, $explain['constraints']['delivery_coefficient']);
        $this->assertSame(3.1, $explain['constraints']['storage_coefficient']);

        $enriched = (new TerritorialPlanningService())->enrichLines($result['lines'], $plan);
        $territorial = json_decode($enriched[0]['explain_json'], true)['territorial'];

        $this->assertSame('файл ограничений', $territorial['constraint_source']);
        $this->assertSame(2.2, $territorial['delivery_coefficient']);
        $this->assertSame(1.4, $territorial['warehouse_coefficient']);
        $this->assertSame(3.1, $territorial['storage_coefficient']);
        $this->assertGreaterThan(0, $territorial['coefficient_penalty']);
    }

    public function test_marketplace_need_raises_quantity_without_bypassing_optimizer_context(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'ozon',
            'params' => [
                'cluster_constraints' => [
                    [
                        'cluster_id' => 154,
                        'sku' => 'OZ-NEED',
                        'need_qty' => 25,
                        'coefficient' => 1.2,
                        'source_type' => 'marketplace_need',
                        'reason' => 'Потребность кластера из файла',
                    ],
                ],
            ],
        ]);

        $result = (new MarketplaceConstraintService())->apply([
            $this->line('OZ-NEED', 10, clusterId: 154, clusterName: 'Москва'),
        ], $plan, 'ozon');

        $this->assertCount(1, $result['lines']);
        $this->assertSame(25, $result['lines'][0]['qty_rounded']);
        $this->assertSame(25.0, $result['lines'][0]['qty_recommended']);
        $this->assertSame(250.0, $result['lines'][0]['supply_cost_estimate']);
        $this->assertSame(1, $result['summary']['marketplace_needs_count']);
        $this->assertSame('applied_as_marketplace_needs', $result['summary']['source_status']);
        $this->assertSame('Потребности маркетплейса учтены как отдельный источник', $result['summary']['human_status']);
        $this->assertStringContainsString('Потребности маркетплейса совпали', $result['summary']['decision_ru']);
        $this->assertSame(1, $result['summary']['source_type_counts']['marketplace_need']);
        $this->assertSame(1, $result['summary']['matched_marketplace_need_lines']);
        $this->assertSame(25, $result['summary']['total_marketplace_need_qty']);
        $this->assertSame(15, $result['summary']['marketplace_need_delta_qty']);
        $this->assertSame(0, $result['summary']['marketplace_need_remaining_delta_qty']);
        $this->assertSame(15, $result['summary']['marketplace_need_increased_qty']);
        $this->assertSame(1, $result['summary']['marketplace_need_raised_lines']);
        $this->assertSame(100.0, $result['summary']['coverage']['marketplace_need_match_percent']);
        $this->assertTrue($result['summary']['planning_source']['used_as_marketplace_needs']);
        $this->assertFalse($result['summary']['planning_source']['requires_review']);

        $explain = json_decode($result['lines'][0]['explain_json'], true);
        $this->assertSame(25, $explain['marketplace_needs']['need_qty']);
        $this->assertSame(25, $explain['marketplace_needs']['planned_qty']);
        $this->assertSame(10, $explain['marketplace_needs']['planned_before_need_qty']);
        $this->assertSame(15, $explain['marketplace_needs']['raised_by_qty']);
        $this->assertSame(0, $explain['marketplace_needs']['delta_qty']);
        $this->assertStringContainsString('Количество поднято', $explain['marketplace_needs']['quantity_adjustment_ru']);
        $this->assertStringContainsString('План поднят', $explain['marketplace_needs']['decision_ru']);
        $this->assertStringContainsString('совпадает', $explain['marketplace_needs']['interpretation_ru']);
    }

    public function test_unmatched_marketplace_need_is_reported_as_backlog(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'ozon',
            'params' => [
                'cluster_constraints' => [
                    [
                        'cluster_id' => 154,
                        'cluster_name' => 'Москва',
                        'sku' => 'OZ-MATCHED',
                        'need_qty' => 20,
                        'source_type' => 'marketplace_need',
                    ],
                    [
                        'cluster_id' => 155,
                        'cluster_name' => 'Самара',
                        'sku' => 'OZ-MISSING',
                        'need_qty' => 35,
                        'source_type' => 'marketplace_need',
                        'reason' => 'Потребность есть, строки плана нет',
                    ],
                ],
            ],
        ]);

        $result = (new MarketplaceConstraintService())->apply([
            $this->line('OZ-MATCHED', 10, clusterId: 154, clusterName: 'Москва'),
        ], $plan, 'ozon');

        $this->assertCount(1, $result['lines']);
        $this->assertSame(20, $result['lines'][0]['qty_rounded']);
        $this->assertSame(2, $result['summary']['file_marketplace_needs_count']);
        $this->assertSame(55, $result['summary']['total_file_marketplace_need_qty']);
        $this->assertSame(1, $result['summary']['matched_marketplace_need_lines']);
        $this->assertSame(20, $result['summary']['total_marketplace_need_qty']);
        $this->assertSame(1, $result['summary']['unmatched_marketplace_need_count']);
        $this->assertSame(35, $result['summary']['unmatched_marketplace_need_qty']);
        $this->assertSame('OZ-MISSING', $result['summary']['unmatched_marketplace_needs'][0]['sku']);
        $this->assertSame('Самара', $result['summary']['unmatched_marketplace_needs'][0]['destination_name']);
        $this->assertTrue($result['summary']['planning_source']['has_unmatched_marketplace_needs']);
        $this->assertTrue($result['summary']['planning_source']['requires_review']);
        $this->assertStringContainsString('незакрытые потребности', $result['summary']['decision_ru']);
        $this->assertStringContainsString('35 шт.', $result['summary']['coverage_summary_ru']);
    }

    public function test_unmatched_marketplace_need_can_be_materialized_as_candidate_line(): void
    {
        $plan = new AutoSupplyPlan([
            'id' => '00000000-0000-0000-0000-000000000123',
            'marketplace' => 'ozon',
            'params' => [
                'cluster_constraints' => [
                    [
                        'cluster_id' => 154,
                        'cluster_name' => 'Москва',
                        'sku' => 'OZ-MISSING',
                        'need_qty' => 35,
                        'source_type' => 'marketplace_need',
                        'reason' => 'Потребность кластера из файла Ozon',
                    ],
                ],
            ],
        ]);

        $service = new MarketplaceConstraintService();
        $lines = $service->appendMarketplaceNeedCandidates(
            [],
            $plan,
            'ozon',
            collect([
                'OZ-MISSING' => new Product([
                    'sku' => 'OZ-MISSING',
                    'name' => 'Товар из потребности Ozon',
                    'price' => 100,
                    'cost_price' => 40,
                    'barcode' => '4600000000012',
                ]),
            ]),
            collect([
                'OZ-MISSING' => new UnitEconomics([
                    'sku' => 'OZ-MISSING',
                    'price' => 100,
                    'cost_price' => 40,
                    'commission_percent' => 10,
                    'logistics_cost' => 2,
                    'storage_cost' => 30,
                ]),
            ])
        );

        $this->assertCount(1, $lines);
        $this->assertSame('OZ-MISSING', $lines[0]['sku']);
        $this->assertSame(154, $lines[0]['cluster_id']);
        $this->assertSame('Москва', $lines[0]['cluster_name']);
        $this->assertSame(35, $lines[0]['qty_rounded']);
        $this->assertSame('marketplace_need_file', json_decode($lines[0]['explain_json'], true)['candidate_source']['type']);

        $result = $service->apply($lines, $plan, 'ozon');

        $this->assertSame(1, $result['summary']['matched_marketplace_need_lines']);
        $this->assertSame(0, $result['summary']['unmatched_marketplace_need_count']);
        $this->assertSame('applied_as_marketplace_needs', $result['summary']['source_status']);
        $this->assertTrue($result['summary']['planning_source']['used_as_marketplace_needs']);
        $this->assertFalse($result['summary']['planning_source']['has_unmatched_marketplace_needs']);
    }

    public function test_ozon_marketplace_need_candidates_respect_selected_cluster_ids(): void
    {
        $plan = new AutoSupplyPlan([
            'id' => '00000000-0000-0000-0000-000000000124',
            'marketplace' => 'ozon',
            'params' => [
                'cluster_ids' => [154],
                'cluster_constraints' => [
                    [
                        'cluster_id' => 154,
                        'cluster_name' => 'Москва',
                        'sku' => 'OZ-SELECTED',
                        'need_qty' => 35,
                        'source_type' => 'marketplace_need',
                    ],
                    [
                        'cluster_id' => 999,
                        'cluster_name' => 'Другой кластер',
                        'sku' => 'OZ-OUTSIDE',
                        'need_qty' => 40,
                        'source_type' => 'marketplace_need',
                    ],
                ],
            ],
        ]);

        $service = new MarketplaceConstraintService();
        $lines = $service->appendMarketplaceNeedCandidates(
            [],
            $plan,
            'ozon',
            collect([
                'OZ-SELECTED' => new Product(['sku' => 'OZ-SELECTED', 'name' => 'Selected', 'price' => 100, 'cost_price' => 40]),
                'OZ-OUTSIDE' => new Product(['sku' => 'OZ-OUTSIDE', 'name' => 'Outside', 'price' => 100, 'cost_price' => 40]),
            ]),
            collect()
        );

        $this->assertCount(1, $lines);
        $this->assertSame('OZ-SELECTED', $lines[0]['sku']);
        $this->assertSame(154, $lines[0]['cluster_id']);

        $result = $service->apply($lines, $plan, 'ozon');

        $this->assertSame(1, $result['summary']['matched_marketplace_need_lines']);
        $this->assertSame(1, $result['summary']['unmatched_marketplace_need_count']);
        $this->assertSame('OZ-OUTSIDE', $result['summary']['unmatched_marketplace_needs'][0]['sku']);
        $this->assertSame('Другой кластер', $result['summary']['unmatched_marketplace_needs'][0]['destination_name']);
    }

    public function test_unmatched_wb_marketplace_need_is_reported_by_warehouse(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'wildberries',
            'params' => [
                'warehouse_constraints' => [
                    [
                        'warehouse_id' => 'Коледино',
                        'warehouse_name' => 'Коледино',
                        'sku' => 'WB-MISSING',
                        'need_qty' => 44,
                        'source_type' => 'marketplace_need',
                    ],
                ],
            ],
        ]);

        $result = (new MarketplaceConstraintService())->apply([
            $this->line('WB-OTHER', 5, warehouseId: 'Электросталь', warehouseName: 'Электросталь'),
        ], $plan, 'wildberries');

        $this->assertCount(1, $result['lines']);
        $this->assertSame(0, $result['summary']['matched_marketplace_need_lines']);
        $this->assertSame(1, $result['summary']['unmatched_marketplace_need_count']);
        $this->assertSame(44, $result['summary']['unmatched_marketplace_need_qty']);
        $this->assertSame('WB-MISSING', $result['summary']['unmatched_marketplace_needs'][0]['sku']);
        $this->assertSame('Коледино', $result['summary']['unmatched_marketplace_needs'][0]['destination_name']);
        $this->assertSame('marketplace_needs_unmatched', $result['summary']['source_status']);
        $this->assertSame('Потребности маркетплейса загружены, но не совпали со строками плана', $result['summary']['human_status']);
        $this->assertTrue($result['summary']['planning_source']['has_unmatched_marketplace_needs']);
    }

    public function test_marketplace_need_does_not_bypass_max_constraint(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'ozon',
            'params' => [
                'cluster_constraints' => [
                    [
                        'cluster_id' => 154,
                        'sku' => 'OZ-NEED-CAPPED',
                        'need_qty' => 25,
                        'max_qty' => 18,
                        'source_type' => 'constraint_and_need',
                        'reason' => 'Потребность выше, но лимит кластера ниже',
                    ],
                ],
            ],
        ]);

        $result = (new MarketplaceConstraintService())->apply([
            $this->line('OZ-NEED-CAPPED', 10, clusterId: 154, clusterName: 'Москва'),
        ], $plan, 'ozon');

        $this->assertCount(1, $result['lines']);
        $this->assertSame(18, $result['lines'][0]['qty_rounded']);
        $this->assertSame(1, $result['summary']['capped_lines']);
        $this->assertSame(8, $result['summary']['marketplace_need_increased_qty']);
        $this->assertSame(7, $result['summary']['marketplace_need_remaining_delta_qty']);

        $explain = json_decode($result['lines'][0]['explain_json'], true);
        $this->assertSame(25, $explain['marketplace_needs']['need_qty']);
        $this->assertSame(18, $explain['marketplace_needs']['planned_qty']);
        $this->assertSame(7, $explain['marketplace_needs']['remaining_gap_qty']);
        $this->assertStringContainsString('заблокирован лимитами', $explain['marketplace_needs']['decision_ru']);
        $this->assertSame(25, $explain['constraints']['capped_from_qty']);
        $this->assertSame(18, $explain['constraints']['capped_to_qty']);
        $this->assertStringContainsString('снизил количество', $explain['constraints']['decision_ru']);
        $this->assertContains('capped', array_column($result['summary']['applied_examples'], 'action'));
        $this->assertContains('marketplace_need', array_column($result['summary']['applied_examples'], 'action'));
    }

    public function test_separate_marketplace_need_and_destination_limit_are_applied_together(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'ozon',
            'params' => [
                'cluster_constraints' => [
                    [
                        'cluster_id' => 154,
                        'cluster_name' => 'Москва',
                        'max_qty' => 18,
                        'source_type' => 'marketplace_constraint',
                        'reason' => 'Лимит кластера из файла Ozon',
                    ],
                    [
                        'sku' => 'OZ-SPLIT-NEED',
                        'need_qty' => 25,
                        'source_type' => 'marketplace_need',
                        'reason' => 'Потребность SKU из отдельной строки файла',
                    ],
                ],
            ],
        ]);

        $result = (new MarketplaceConstraintService())->apply([
            $this->line('OZ-SPLIT-NEED', 10, clusterId: 154, clusterName: 'Москва'),
        ], $plan, 'ozon');

        $this->assertCount(1, $result['lines']);
        $this->assertSame(18, $result['lines'][0]['qty_rounded']);
        $this->assertSame(2, $result['summary']['matched_constraints_count']);
        $this->assertSame(1, $result['summary']['matched_lines']);
        $this->assertSame(1, $result['summary']['matched_marketplace_need_lines']);
        $this->assertSame(25, $result['summary']['total_marketplace_need_qty']);
        $this->assertSame(7, $result['summary']['marketplace_need_remaining_delta_qty']);
        $this->assertSame(1, $result['summary']['capped_lines']);
        $this->assertSame('applied_with_limits', $result['summary']['source_status']);
        $this->assertTrue($result['summary']['planning_source']['used_as_marketplace_needs']);
        $this->assertTrue($result['summary']['planning_source']['used_for_quantity_caps']);
        $this->assertSame(1, $result['summary']['source_type_counts']['marketplace_constraint']);
        $this->assertSame(1, $result['summary']['source_type_counts']['marketplace_need']);

        $explain = json_decode($result['lines'][0]['explain_json'], true);
        $this->assertSame('constraint_and_need', $explain['constraints']['source_type']);
        $this->assertContains('destination:154', $explain['constraints']['matched_constraint_keys']);
        $this->assertContains('sku:oz-split-need', $explain['constraints']['matched_constraint_keys']);
        $this->assertSame(25, $explain['marketplace_needs']['need_qty']);
        $this->assertSame(18, $explain['marketplace_needs']['planned_qty']);
        $this->assertSame(7, $explain['marketplace_needs']['remaining_gap_qty']);
        $this->assertStringContainsString('заблокирован лимитами', $explain['marketplace_needs']['decision_ru']);
        $this->assertStringContainsString('снизил количество', $explain['constraints']['decision_ru']);
    }

    public function test_ozon_constraints_match_macrocluster_name_when_line_has_numeric_cluster_id(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'ozon',
            'params' => [
                'cluster_constraints' => [
                    [
                        'cluster_name' => 'Москва, МО и Дальние регионы',
                        'sku' => 'OZ-1',
                        'max_qty' => 6,
                        'coefficient' => 1.5,
                        'reason' => 'Лимит из файла Ozon',
                    ],
                ],
            ],
        ]);

        $result = (new MarketplaceConstraintService())->apply([
            $this->line('OZ-1', 10, clusterId: 154, clusterName: 'Москва, МО и Дальние регионы'),
            $this->line('OZ-2', 10, clusterId: 154, clusterName: 'Москва, МО и Дальние регионы'),
        ], $plan, 'ozon');

        $this->assertCount(2, $result['lines']);
        $this->assertSame(6, $result['lines'][0]['qty_rounded']);
        $this->assertSame(10, $result['lines'][1]['qty_rounded']);
        $this->assertSame(1, $result['summary']['constraints_count']);
        $this->assertSame(1, $result['summary']['matched_lines']);
        $this->assertSame(1, $result['summary']['capped_lines']);
        $this->assertSame(4, $result['summary']['reduced_qty']);

        $explain = json_decode($result['lines'][0]['explain_json'], true);
        $this->assertSame('Файл или параметры ограничений', $explain['constraints']['source']);
        $this->assertSame('Лимит из файла Ozon', $explain['constraints']['reason']);
        $this->assertSame(1.5, $explain['constraints']['coefficient']);
    }

    public function test_wb_constraints_match_warehouse_name_and_share_limit_across_aliases(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'wildberries',
            'params' => [
                'warehouse_constraints' => [
                    [
                        'warehouse_name' => 'Коледино',
                        'max_qty' => 15,
                        'reason' => 'Остаток лимита склада',
                    ],
                ],
            ],
        ]);

        $result = (new MarketplaceConstraintService())->apply([
            $this->line('WB-1', 10, warehouseId: '507', warehouseName: 'Коледино'),
            $this->line('WB-2', 10, warehouseId: 'Коледино', warehouseName: 'Коледино'),
        ], $plan, 'wildberries');

        $this->assertCount(2, $result['lines']);
        $this->assertSame(10, $result['lines'][0]['qty_rounded']);
        $this->assertSame(5, $result['lines'][1]['qty_rounded']);
        $this->assertSame(1, $result['summary']['constraints_count']);
        $this->assertSame(2, $result['summary']['matched_lines']);
        $this->assertSame(1, $result['summary']['capped_lines']);
        $this->assertSame(5, $result['summary']['reduced_qty']);
    }

    public function test_wb_territorial_summary_ranks_fast_warehouse_for_a_items_and_calculates_ktr(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 9901]);

        WarehouseSlot::query()->create([
            'marketplace' => 'wildberries',
            'warehouse_id' => 'fast-wh',
            'warehouse_name' => 'Быстрый склад',
            'date' => now()->addDay()->toDateString(),
            'time_from' => '09:00:00',
            'time_to' => '18:00:00',
            'coefficient' => 1,
            'delivery_coefficient' => 1,
            'storage_coefficient' => 1,
            'is_available' => true,
            'capacity' => 100,
            'capacity_used' => 10,
        ]);
        WarehouseSlot::query()->create([
            'marketplace' => 'wildberries',
            'warehouse_id' => 'slow-wh',
            'warehouse_name' => 'Медленный склад',
            'date' => now()->addDay()->toDateString(),
            'time_from' => '09:00:00',
            'time_to' => '18:00:00',
            'coefficient' => 9,
            'delivery_coefficient' => 9,
            'storage_coefficient' => 4,
            'is_available' => true,
            'capacity' => 100,
            'capacity_used' => 10,
        ]);

        $plan = new AutoSupplyPlan([
            'integration_id' => $integration->id,
            'marketplace' => 'wildberries',
        ]);

        $summary = (new TerritorialPlanningService())->summarize([
            $this->line('A-SKU', 20, warehouseId: 'fast-wh', warehouseName: 'Быстрый склад', abc: 'A', risk: 'high'),
            $this->line('C-SKU', 20, warehouseId: 'slow-wh', warehouseName: 'Медленный склад', abc: 'C', risk: 'low'),
        ], $plan);

        $this->assertSame('Wildberries', $summary['marketplace']);
        $this->assertSame('включено', $summary['status']);
        $this->assertSame('fast-wh', $summary['warehouse_ranking'][0]['warehouse_id']);
        $this->assertStringContainsString('A-товаров', $summary['warehouse_ranking'][0]['recommendation']);
        $this->assertGreaterThan(0, $summary['ktr']['value']);
        $this->assertStringContainsString('КТР', $summary['ktr']['label']);
        $this->assertSame(80.0, $summary['ktr']['target_value']);
        $this->assertArrayHasKey('priority_qty_share_percent', $summary['ktr']);
        $this->assertArrayHasKey('distribution_by_grade', $summary['ktr']);
        $this->assertArrayHasKey('abc_a_priority_share_percent', $summary['ktr']);
        $this->assertArrayHasKey('abc_a_fast_share_percent', $summary['ktr']);
        $this->assertArrayHasKey('high_risk_priority_share_percent', $summary['ktr']);
        $this->assertArrayHasKey('high_risk_fast_share_percent', $summary['ktr']);
        $this->assertArrayHasKey('coefficient_limited_qty_share_percent', $summary['ktr']);
        $this->assertSame('ktr-4', $summary['ktr']['metric_version']);
        $this->assertStringContainsString('скорость закрытия спроса', $summary['ktr']['formula_label']);
        $this->assertArrayHasKey('status', $summary['ktr']);
        $this->assertArrayHasKey('status_ru', $summary['ktr']);
        $this->assertArrayHasKey('needs_action', $summary['ktr']);
        $this->assertArrayHasKey('decision_ru', $summary['ktr']);
        $this->assertArrayHasKey('operational_plan', $summary['ktr']);
        $this->assertArrayHasKey('rules', $summary['ktr']['operational_plan']);
        $this->assertArrayHasKey('guardrails_ru', $summary['ktr']['operational_plan']);
        $this->assertStringContainsString('КТР', $summary['ktr']['operational_plan']['target_policy_ru']);
        $this->assertContains('Для A-товаров скорость закрытия спроса важнее небольшой разницы в коэффициентах.', $summary['ktr']['operational_plan']['guardrails_ru']);
        $this->assertArrayHasKey('total_qty', $summary['ktr']);
        $this->assertArrayHasKey('priority_qty', $summary['ktr']);
        $this->assertArrayHasKey('target_priority_qty', $summary['ktr']);
        $this->assertArrayHasKey('need_priority_qty', $summary['ktr']);
        $this->assertArrayHasKey('recommended_move_qty', $summary['ktr']);
        $this->assertArrayHasKey('best_destinations', $summary['ktr']);
        $this->assertArrayHasKey('weak_destinations', $summary['ktr']);
        $this->assertArrayHasKey('improvement_actions', $summary['ktr']);
        $this->assertArrayHasKey('fixation', $summary['ktr']);
        $this->assertSame('ktr-fixation-1', $summary['ktr']['fixation']['version']);
        $this->assertTrue($summary['ktr']['fixation']['can_fix_current_value']);
        $this->assertSame($summary['ktr']['value'], $summary['ktr']['fixation']['freeze_payload']['baseline_ktr']);
        $this->assertSame('not_fixed', $summary['ktr']['fixation']['tracking_status']);
        $this->assertStringContainsString('зафиксируйте', mb_strtolower($summary['ktr']['fixation']['next_action_ru']));
        $this->assertArrayHasKey('control_loop', $summary['ktr']);
        $this->assertSame('ktr-control-loop-1', $summary['ktr']['control_loop']['version']);
        $this->assertArrayHasKey('a_items_policy_status', $summary['ktr']);
        $this->assertArrayHasKey('oos_policy_status', $summary['ktr']);
        $this->assertArrayHasKey('constraint_policy_status', $summary['ktr']);
        $this->assertArrayHasKey('target_gap_pp', $summary['ktr']);
        $this->assertArrayHasKey('grade', $summary['warehouse_ranking'][0]);
        $this->assertArrayHasKey('demand_closure_score', $summary['warehouse_ranking'][0]);
        $this->assertArrayHasKey('coefficient_penalty', $summary['warehouse_ranking'][0]);
        $this->assertArrayHasKey('rank_reason', $summary['warehouse_ranking'][0]);
        $this->assertArrayHasKey('is_priority_destination', $summary['warehouse_ranking'][0]);
        $this->assertArrayHasKey('is_fast_for_a_items', $summary['warehouse_ranking'][0]);
        $this->assertSame('очень быстрый', $summary['warehouse_ranking'][0]['speed_tier']);
        $this->assertSame('выгодно', $summary['warehouse_ranking'][0]['cost_tier']);
        $this->assertSame('ёмкости достаточно', $summary['warehouse_ranking'][0]['capacity_status']);
        $this->assertSame('prioritize_a_items', $summary['warehouse_ranking'][0]['action_plan']['status']);
        $this->assertStringContainsString('A-товары', $summary['warehouse_ranking'][0]['action_plan']['title']);
        $this->assertContains('A-товары: 20 шт.', $summary['warehouse_ranking'][0]['action_plan']['evidence']);
        $this->assertArrayHasKey('закрытие_регионального_спроса', $summary['warehouse_ranking'][0]['score_factors']);
        $this->assertArrayHasKey('штраф_коэффициентов', $summary['warehouse_ranking'][0]['score_factors']);
        $this->assertArrayHasKey('доступность_емкости', $summary['warehouse_ranking'][0]['score_factors']);
        $this->assertArrayHasKey('срочность_отсутствия', $summary['warehouse_ranking'][0]['score_factors']);
        $this->assertSame('wb-territorial-3', $summary['scoring_policy']['version']);
        $this->assertArrayHasKey('components', $summary['scoring_policy']);
        $this->assertSame('territorial-ranking-audit-1', $summary['ranking_audit']['version']);
        $this->assertSame('склад', $summary['ranking_audit']['destination_label_ru']);
        $this->assertSame('fast-wh', $summary['ranking_audit']['top_destination']['id']);
        $this->assertSame('slow-wh', $summary['ranking_audit']['weak_destination']['id']);
        $this->assertContains('скорость закрытия спроса', $summary['ranking_audit']['weights_ru']);
        $this->assertStringContainsString('Лучший склад', $summary['ranking_audit']['decision_ru']);
        $this->assertNotEmpty($summary['ranking_audit']['next_actions_ru']);
        $this->assertSame(1, $summary['warehouse_ranking'][0]['rank']);
        $this->assertArrayHasKey('routing_recommendations', $summary);
        $this->assertNotEmpty($summary['routing_recommendations']);
        $this->assertSame('reroute_to_better_destination', $summary['routing_recommendations'][0]['type']);
        $this->assertSame('wildberries', $summary['routing_recommendations'][0]['marketplace']);
        $this->assertArrayHasKey('from', $summary['routing_recommendations'][0]);
        $this->assertArrayHasKey('to', $summary['routing_recommendations'][0]);
        $this->assertArrayHasKey('recommended_qty', $summary['routing_recommendations'][0]);
        $this->assertArrayHasKey('expected_ktr_uplift_pp', $summary['routing_recommendations'][0]);
        $this->assertStringContainsString('Рекомендация', $summary['routing_recommendations'][0]['decision_ru']);
    }

    public function test_ktr_uses_plan_target_and_baseline_for_improvement_tracking(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 9907]);

        foreach ([
            ['id' => 'fast-wh', 'coef' => 1],
            ['id' => 'slow-wh', 'coef' => 20],
        ] as $warehouse) {
            WarehouseSlot::query()->create([
                'marketplace' => 'wildberries',
                'warehouse_id' => $warehouse['id'],
                'warehouse_name' => $warehouse['id'],
                'date' => now()->addDay()->toDateString(),
                'time_from' => '09:00:00',
                'time_to' => '18:00:00',
                'coefficient' => $warehouse['coef'],
                'delivery_coefficient' => $warehouse['coef'],
                'storage_coefficient' => $warehouse['coef'],
                'is_available' => true,
                'capacity' => 100,
                'capacity_used' => 10,
            ]);
        }

        $plan = new AutoSupplyPlan([
            'integration_id' => $integration->id,
            'marketplace' => 'wildberries',
            'params' => [
                'target_ktr' => 90,
                'baseline_ktr' => 64.5,
            ],
        ]);

        $summary = (new TerritorialPlanningService())->summarize([
            $this->line('A-SKU', 25, warehouseId: 'fast-wh', warehouseName: 'Быстрый склад', abc: 'A', risk: 'high'),
            $this->line('C-SKU', 25, warehouseId: 'slow-wh', warehouseName: 'Медленный склад', abc: 'C', risk: 'low'),
        ], $plan);

        $this->assertSame(90.0, $summary['ktr']['target_value']);
        $this->assertSame(64.5, $summary['ktr']['baseline_value']);
        $this->assertSame(
            round($summary['ktr']['value'] - 64.5, 2),
            $summary['ktr']['improvement_vs_baseline_pp']
        );
        $this->assertSame(25.5, $summary['ktr']['baseline_gap_pp']);
        $this->assertStringContainsString('90%', $summary['ktr']['interpretation']);
        $this->assertSame('warning', $summary['ktr']['status']);
        $this->assertSame(true, $summary['ktr']['needs_action']);
        $this->assertSame('move_to_priority', $summary['ktr']['operational_plan']['first_action']['type']);
        $this->assertStringContainsString('Перенесите', $summary['ktr']['decision_ru']);
        $this->assertSame('gap', collect($summary['ktr']['operational_plan']['rules'])->firstWhere('key', 'target_ktr')['status']);
        $this->assertSame('ktr-fixation-1', $summary['ktr']['fixation']['version']);
        $this->assertSame(64.5, $summary['ktr']['fixation']['fixed_baseline_value']);
        $this->assertSame($summary['ktr']['improvement_vs_baseline_pp'], $summary['ktr']['fixation']['improvement_vs_fixed_pp']);
        $this->assertContains($summary['ktr']['fixation']['tracking_status'], ['improved', 'worse', 'unchanged']);
        $this->assertStringContainsString('КТР', $summary['ktr']['fixation']['state_ru']);
        $this->assertSame($summary['ktr']['value'], $summary['ktr']['control_loop']['current_value']);
        $this->assertSame(64.5, $summary['ktr']['control_loop']['fixed_baseline_value']);
        $this->assertSame(90.0, $summary['ktr']['control_loop']['target_value']);
        $this->assertNotEmpty($summary['ktr']['control_loop']['next_action_ru']);
    }

    public function test_wb_warehouse_score_factors_are_weighted_across_all_skus(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 9918]);

        $plan = new AutoSupplyPlan([
            'integration_id' => $integration->id,
            'marketplace' => 'wildberries',
        ]);

        $summary = (new TerritorialPlanningService())->summarize([
            $this->line('A-MAIN', 30, warehouseId: 'mixed-wh', warehouseName: 'Смешанный склад', abc: 'A', risk: 'high'),
            $this->line('C-SMALL', 10, warehouseId: 'mixed-wh', warehouseName: 'Смешанный склад', abc: 'C', risk: 'low'),
        ], $plan);

        $factors = $summary['warehouse_ranking'][0]['score_factors'];

        $this->assertSame(1.49, $factors['abc_приоритет']);
        $this->assertSame(84.5, $factors['abc_балл']);
        $this->assertSame(0.4, $factors['вес_скорости_для_abc']);
        $this->assertSame('скорость важнее стоимости', $factors['модель_быстрого_склада_для_a']);
    }

    public function test_ktr_protection_actions_include_route_for_a_items_even_when_total_target_reached(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 9912]);

        foreach ([
            ['id' => 'fast-wh', 'name' => 'Быстрый склад', 'delivery' => 1, 'storage' => 1],
            ['id' => 'slow-wh', 'name' => 'Медленный склад', 'delivery' => 9, 'storage' => 3],
        ] as $warehouse) {
            WarehouseSlot::query()->create([
                'marketplace' => 'wildberries',
                'warehouse_id' => $warehouse['id'],
                'warehouse_name' => $warehouse['name'],
                'date' => now()->addDay()->toDateString(),
                'time_from' => '09:00:00',
                'time_to' => '18:00:00',
                'coefficient' => $warehouse['storage'],
                'delivery_coefficient' => $warehouse['delivery'],
                'storage_coefficient' => $warehouse['storage'],
                'is_available' => true,
                'capacity' => 100,
                'capacity_used' => 10,
            ]);
        }

        $plan = new AutoSupplyPlan([
            'integration_id' => $integration->id,
            'marketplace' => 'wildberries',
            'params' => [
                'target_ktr' => 40,
            ],
        ]);

        $summary = (new TerritorialPlanningService())->summarize([
            $this->line('A-SLOW', 20, warehouseId: 'slow-wh', warehouseName: 'Медленный склад', abc: 'A', risk: 'high'),
            $this->line('C-FAST', 80, warehouseId: 'fast-wh', warehouseName: 'Быстрый склад', abc: 'C', risk: 'low'),
        ], $plan);

        $protectAction = collect($summary['ktr']['improvement_actions'])->firstWhere('type', 'protect_a_items');
        $this->assertNotNull($protectAction);
        $this->assertSame('Медленный склад', $protectAction['from']);
        $this->assertSame('Быстрый склад', $protectAction['to']);
        $this->assertGreaterThan(0, $protectAction['qty']);
        $this->assertGreaterThan(0, $protectAction['expected_ktr_uplift_pp']);

        $firstAction = $summary['ktr']['operational_plan']['first_action'];
        $this->assertSame('protect_a_items', $firstAction['type']);
        $this->assertSame('Медленный склад', $firstAction['from']['name']);
        $this->assertSame('Быстрый склад', $firstAction['to']['name']);
        $this->assertStringContainsString('A-товаров', $firstAction['description_ru']);
        $this->assertStringContainsString('ожидаемый вклад в КТР', $firstAction['expected_result_ru']);
    }

    public function test_ktr_financial_weight_reveals_expensive_items_in_weak_destination(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 9918]);

        foreach ([
            ['id' => 'fast-wh', 'coef' => 1],
            ['id' => 'slow-wh', 'coef' => 9],
        ] as $warehouse) {
            WarehouseSlot::query()->create([
                'marketplace' => 'wildberries',
                'warehouse_id' => $warehouse['id'],
                'warehouse_name' => $warehouse['id'],
                'date' => now()->addDay()->toDateString(),
                'time_from' => '09:00:00',
                'time_to' => '18:00:00',
                'coefficient' => $warehouse['coef'],
                'delivery_coefficient' => $warehouse['coef'],
                'storage_coefficient' => $warehouse['coef'],
                'is_available' => true,
                'capacity' => 100,
                'capacity_used' => 10,
            ]);
        }

        $plan = new AutoSupplyPlan([
            'integration_id' => $integration->id,
            'marketplace' => 'wildberries',
        ]);

        $summary = (new TerritorialPlanningService())->summarize([
            $this->line('CHEAP-FAST', 80, warehouseId: 'fast-wh', warehouseName: 'Быстрый склад', abc: 'C', expectedRevenuePerUnit: 10),
            $this->line('EXPENSIVE-SLOW', 5, warehouseId: 'slow-wh', warehouseName: 'Медленный склад', abc: 'C', risk: 'low', constraintCoefficient: 20, expectedRevenuePerUnit: 1000),
        ], $plan);

        $this->assertArrayHasKey('financial_value', $summary['ktr']);
        $this->assertArrayHasKey('financial_formula_label', $summary['ktr']);
        $this->assertLessThan($summary['ktr']['value'], $summary['ktr']['financial_value']);
        $this->assertStringContainsString('ценный товар', $summary['ktr']['financial_policy_status']);
    }

    public function test_wb_abc_policy_prefers_fast_warehouse_for_a_and_cost_safe_warehouse_for_c(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 9908]);

        WarehouseSlot::query()->create([
            'marketplace' => 'wildberries',
            'warehouse_id' => 'fast-expensive',
            'warehouse_name' => 'Быстрый дорогой склад',
            'date' => now()->addDay()->toDateString(),
            'time_from' => '09:00:00',
            'time_to' => '18:00:00',
            'coefficient' => 5,
            'delivery_coefficient' => 1,
            'storage_coefficient' => 5,
            'is_available' => true,
            'capacity' => 100,
            'capacity_used' => 10,
        ]);
        WarehouseSlot::query()->create([
            'marketplace' => 'wildberries',
            'warehouse_id' => 'slow-cheap',
            'warehouse_name' => 'Медленный дешёвый склад',
            'date' => now()->addDay()->toDateString(),
            'time_from' => '09:00:00',
            'time_to' => '18:00:00',
            'coefficient' => 1,
            'delivery_coefficient' => 4,
            'storage_coefficient' => 1,
            'is_available' => true,
            'capacity' => 100,
            'capacity_used' => 10,
        ]);

        $plan = new AutoSupplyPlan([
            'integration_id' => $integration->id,
            'marketplace' => 'wildberries',
        ]);
        $service = new TerritorialPlanningService();

        $aSummary = $service->summarize([
            $this->line('A-SKU', 20, warehouseId: 'fast-expensive', warehouseName: 'Быстрый дорогой склад', abc: 'A', risk: 'high'),
            $this->line('A-SKU', 20, warehouseId: 'slow-cheap', warehouseName: 'Медленный дешёвый склад', abc: 'A', risk: 'high'),
        ], $plan);
        $this->assertSame('fast-expensive', $aSummary['warehouse_ranking'][0]['warehouse_id']);
        $this->assertGreaterThan(0, $aSummary['warehouse_ranking'][0]['score_factors']['коррекция_abc_скорость_стоимость']);
        $this->assertStringContainsString('скорость', $aSummary['warehouse_ranking'][0]['rank_reason']);
        $this->assertSame('a_speed_priority', $aSummary['warehouse_ranking'][0]['abc_policy_status']);
        $this->assertContains('A-товары: скорость закрытия спроса имеет повышенный вес.', $aSummary['warehouse_ranking'][0]['priority_reasons']);
        $this->assertStringContainsString('A-товаров', $aSummary['warehouse_ranking'][0]['decision_ru']);

        $cSummary = $service->summarize([
            $this->line('C-SKU', 20, warehouseId: 'fast-expensive', warehouseName: 'Быстрый дорогой склад', abc: 'C', risk: 'low'),
            $this->line('C-SKU', 20, warehouseId: 'slow-cheap', warehouseName: 'Медленный дешёвый склад', abc: 'C', risk: 'low'),
        ], $plan);
        $this->assertSame('slow-cheap', $cSummary['warehouse_ranking'][0]['warehouse_id']);
        $this->assertSame('c_cost_priority', $cSummary['warehouse_ranking'][0]['abc_policy_status']);
        $this->assertContains('Коэффициенты и хранение выглядят комфортно.', $cSummary['warehouse_ranking'][0]['priority_reasons']);
        $fastExpensive = collect($cSummary['warehouse_ranking'])->firstWhere('warehouse_id', 'fast-expensive');
        $this->assertLessThan(0, $fastExpensive['score_factors']['коррекция_abc_скорость_стоимость']);
        $this->assertSame('coefficient_limited', $fastExpensive['abc_policy_status']);
    }

    public function test_wb_a_item_policy_normalizes_lowercase_and_cyrillic_abc_values(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 9932]);

        WarehouseSlot::query()->create([
            'marketplace' => 'wildberries',
            'warehouse_id' => 'fast-a',
            'warehouse_name' => 'Быстрый склад A',
            'date' => now()->addDay()->toDateString(),
            'time_from' => '09:00:00',
            'time_to' => '18:00:00',
            'coefficient' => 1,
            'delivery_coefficient' => 1,
            'storage_coefficient' => 1,
            'is_available' => true,
            'capacity' => 100,
            'capacity_used' => 5,
        ]);

        $plan = new AutoSupplyPlan([
            'integration_id' => $integration->id,
            'marketplace' => 'wildberries',
        ]);
        $service = new TerritorialPlanningService();

        $summary = $service->summarize([
            $this->line('LOWER-A', 12, warehouseId: 'fast-a', warehouseName: 'Быстрый склад A', abc: 'a', risk: 'high'),
        ], $plan);

        $warehouse = $summary['warehouse_ranking'][0];
        $this->assertSame(12, $warehouse['abc_a_qty']);
        $this->assertSame(12, $warehouse['fast_for_a_qty']);
        $this->assertTrue($warehouse['is_fast_for_a_items']);
        $this->assertSame('a_speed_priority', $warehouse['abc_policy_status']);
        $this->assertSame(100.0, $warehouse['score_factors']['abc_балл']);
        $this->assertSame('скорость важнее стоимости', $warehouse['score_factors']['модель_быстрого_склада_для_a']);

        $lines = $service->enrichLines([
            $this->line('CYR-A', 8, warehouseId: 'fast-a', warehouseName: 'Быстрый склад A', abc: 'а', risk: 'high'),
        ], $plan);
        $explain = json_decode($lines[0]['explain_json'], true);

        $this->assertSame('A', $explain['territorial']['abc_priority']);
        $this->assertSame('a_speed_priority', $explain['territorial']['abc_policy_status']);
        $this->assertSame('быстрый склад важнее стоимости', $explain['territorial']['a_item_speed_policy']);
    }

    public function test_wb_ranking_uses_nearest_available_slot_as_speed_factor(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 9933]);

        WarehouseSlot::query()->create([
            'marketplace' => 'wildberries',
            'warehouse_id' => 'soon-wh',
            'warehouse_name' => 'Склад с ближайшим слотом',
            'date' => now()->addDay()->toDateString(),
            'time_from' => '09:00:00',
            'time_to' => '18:00:00',
            'coefficient' => 1,
            'delivery_coefficient' => 1,
            'storage_coefficient' => 1,
            'is_available' => true,
            'capacity' => 100,
            'capacity_used' => 5,
        ]);
        WarehouseSlot::query()->create([
            'marketplace' => 'wildberries',
            'warehouse_id' => 'late-wh',
            'warehouse_name' => 'Склад с далёким слотом',
            'date' => now()->addDays(21)->toDateString(),
            'time_from' => '09:00:00',
            'time_to' => '18:00:00',
            'coefficient' => 1,
            'delivery_coefficient' => 1,
            'storage_coefficient' => 1,
            'is_available' => true,
            'capacity' => 100,
            'capacity_used' => 5,
        ]);
        WarehouseSlot::query()->create([
            'marketplace' => 'wildberries',
            'warehouse_id' => 'soon-wh',
            'warehouse_name' => 'Склад с ближайшим слотом',
            'date' => now()->addDays(14)->toDateString(),
            'time_from' => '09:00:00',
            'time_to' => '18:00:00',
            'coefficient' => 0.5,
            'delivery_coefficient' => 0.5,
            'storage_coefficient' => 0.5,
            'is_available' => true,
            'capacity' => 100,
            'capacity_used' => 5,
        ]);

        $plan = new AutoSupplyPlan([
            'integration_id' => $integration->id,
            'marketplace' => 'wildberries',
        ]);
        $service = new TerritorialPlanningService();

        $summary = $service->summarize([
            $this->line('A-SOON', 20, warehouseId: 'soon-wh', warehouseName: 'Склад с ближайшим слотом', abc: 'A', risk: 'high'),
            $this->line('A-LATE', 20, warehouseId: 'late-wh', warehouseName: 'Склад с далёким слотом', abc: 'A', risk: 'high'),
        ], $plan);

        $soon = collect($summary['warehouse_ranking'])->firstWhere('warehouse_id', 'soon-wh');
        $late = collect($summary['warehouse_ranking'])->firstWhere('warehouse_id', 'late-wh');

        $this->assertSame('soon-wh', $summary['warehouse_ranking'][0]['warehouse_id']);
        $this->assertSame(1.0, $soon['score_factors']['ближайший_слот_дней']);
        $this->assertSame(100.0, $soon['score_factors']['скорость_ближайшего_слота']);
        $this->assertSame(now()->addDay()->toDateString(), $soon['score_factors']['дата_ближайшего_слота']);
        $this->assertGreaterThan($late['score_factors']['скорость_ближайшего_слота'], $soon['score_factors']['скорость_ближайшего_слота']);
        $this->assertContains('Ближайший слот доступен быстро.', $soon['priority_reasons']);
        $this->assertContains('Ближайший слот далеко, скорость направления ниже.', $late['priority_reasons']);
        $this->assertContains('ближайший слот: 1 дн.', $soon['action_plan']['evidence']);
        $this->assertSame('good', $summary['source_coverage']['status']);

        $lines = $service->enrichLines([
            $this->line('A-SOON', 20, warehouseId: 'soon-wh', warehouseName: 'Склад с ближайшим слотом', abc: 'A', risk: 'high'),
        ], $plan);
        $explain = json_decode($lines[0]['explain_json'], true);

        $this->assertSame(now()->addDay()->toDateString(), $explain['territorial']['nearest_slot_date']);
        $this->assertSame(1, $explain['territorial']['slot_lead_time_days']);
        $this->assertEqualsWithDelta(100.0, (float) $explain['territorial']['slot_lead_time_score'], 0.01);
        $this->assertContains('Ближайший слот доступен быстро.', $explain['territorial']['priority_reasons']);
    }

    public function test_ozon_territorial_summary_ranks_clusters_and_calculates_ktr_details(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'ozon',
        ]);

        $summary = (new TerritorialPlanningService())->summarize([
            $this->line('OZON-FAST', 30, clusterId: 154, clusterName: 'Москва', expectedSavingsRub: 1200, avgDeliveryHours: 12, lostProfit: 50000),
            $this->line('OZON-SLOW', 30, clusterId: 155, clusterName: 'Дальний Восток', expectedSavingsRub: 0, avgDeliveryHours: 96, lostProfit: 0),
        ], $plan);

        $this->assertSame('Ozon', $summary['marketplace']);
        $this->assertSame('включено', $summary['status']);
        $this->assertSame('154', $summary['cluster_ranking'][0]['cluster_id']);
        $this->assertSame(1, $summary['cluster_ranking'][0]['rank']);
        $this->assertArrayHasKey('grade', $summary['cluster_ranking'][0]);
        $this->assertArrayHasKey('score_factors', $summary['cluster_ranking'][0]);
        $this->assertArrayHasKey('rank_reason', $summary['cluster_ranking'][0]);
        $this->assertArrayHasKey('decision_ru', $summary['cluster_ranking'][0]);
        $this->assertArrayHasKey('abc_policy_status', $summary['cluster_ranking'][0]);
        $this->assertArrayHasKey('priority_reasons', $summary['cluster_ranking'][0]);
        $this->assertArrayHasKey('demand_closure_score', $summary['cluster_ranking'][0]);
        $this->assertArrayHasKey('abc_a_qty', $summary['cluster_ranking'][0]);
        $this->assertArrayHasKey('high_risk_qty', $summary['cluster_ranking'][0]);
        $this->assertArrayHasKey('speed_tier', $summary['cluster_ranking'][0]);
        $this->assertArrayHasKey('constraint_status', $summary['cluster_ranking'][0]);
        $this->assertArrayHasKey('in_transit_status', $summary['cluster_ranking'][0]);
        $this->assertArrayHasKey('action_plan', $summary['cluster_ranking'][0]);
        $this->assertArrayHasKey('recommendation', $summary['cluster_ranking'][0]['action_plan']);
        $this->assertStringContainsString('Кластер', $summary['cluster_ranking'][0]['decision_ru']);
        $this->assertNotEmpty($summary['cluster_ranking'][0]['priority_reasons']);
        $this->assertArrayHasKey('закрытие_регионального_спроса', $summary['cluster_ranking'][0]['score_factors']);
        $this->assertArrayHasKey('штраф_ограничений', $summary['cluster_ranking'][0]['score_factors']);
        $this->assertSame('territorial-ranking-audit-1', $summary['ranking_audit']['version']);
        $this->assertSame('кластер', $summary['ranking_audit']['destination_label_ru']);
        $this->assertSame('154', $summary['ranking_audit']['top_destination']['id']);
        $this->assertContains('локальность', $summary['ranking_audit']['weights_ru']);
        $this->assertContains('товары в пути', $summary['ranking_audit']['weights_ru']);
        $this->assertStringContainsString('Лучший кластер', $summary['ranking_audit']['decision_ru']);
        $this->assertNotEmpty($summary['ranking_audit']['next_actions_ru']);
        $this->assertArrayHasKey('distribution_by_grade', $summary['ktr']);
        $this->assertArrayHasKey('target_gap_pp', $summary['ktr']);
        $this->assertSame('ktr-4', $summary['ktr']['metric_version']);
        $this->assertStringContainsString('КТР', $summary['ktr']['label']);
        $this->assertArrayHasKey('total_qty', $summary['ktr']);
        $this->assertArrayHasKey('priority_qty', $summary['ktr']);
        $this->assertArrayHasKey('target_priority_qty', $summary['ktr']);
        $this->assertArrayHasKey('need_priority_qty', $summary['ktr']);
        $this->assertArrayHasKey('recommended_move_qty', $summary['ktr']);
        $this->assertArrayHasKey('best_destinations', $summary['ktr']);
        $this->assertArrayHasKey('weak_destinations', $summary['ktr']);
        $this->assertArrayHasKey('improvement_actions', $summary['ktr']);
        $this->assertSame('Дальний Восток', $summary['ktr']['recommended_from_destination']);
        $this->assertSame('Москва', $summary['ktr']['recommended_to_destination']);
        $this->assertGreaterThan($summary['ktr']['value'], $summary['ktr']['projected_value_after_recommendation']);
        $this->assertSame(
            round($summary['ktr']['projected_value_after_recommendation'] - $summary['ktr']['value'], 2),
            $summary['ktr']['projected_uplift_pp']
        );
        $this->assertSame(
            max(0.0, round($summary['ktr']['target_value'] - $summary['ktr']['projected_value_after_recommendation'], 2)),
            $summary['ktr']['projected_target_gap_pp']
        );
        $this->assertSame($summary['ktr']['projected_value_after_recommendation'], $summary['ktr']['improvement_actions'][0]['projected_ktr_after_action']);
        $this->assertSame($summary['ktr']['projected_uplift_pp'], $summary['ktr']['improvement_actions'][0]['expected_ktr_uplift_pp']);
        $this->assertStringContainsString('прогноз КТР', $summary['ktr']['how_to_improve']);
        $this->assertSame('ozon-territorial-3', $summary['scoring_policy']['version']);
        $this->assertArrayHasKey('components', $summary['scoring_policy']);
        $this->assertArrayHasKey('routing_recommendations', $summary);
        $this->assertNotEmpty($summary['routing_recommendations']);
        $this->assertSame('ozon', $summary['routing_recommendations'][0]['marketplace']);
        $this->assertSame('Дальний Восток', $summary['routing_recommendations'][0]['from']['name']);
        $this->assertSame('Москва', $summary['routing_recommendations'][0]['to']['name']);
        $this->assertGreaterThan(0, $summary['routing_recommendations'][0]['recommended_qty']);
        $this->assertStringContainsString('кластер', $summary['routing_recommendations'][0]['decision_ru']);
    }

    public function test_ozon_cluster_ranking_explains_constraints_and_in_transit_as_operational_plan(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'ozon',
        ]);

        $summary = (new TerritorialPlanningService())->summarize([
            $this->line('OZ-LIMITED', 40, clusterId: 154, clusterName: 'Москва', abc: 'A', risk: 'high', constraintCoefficient: 4.0, avgDeliveryHours: 18, lostProfit: 25000),
            $this->line('OZ-IN-TRANSIT', 20, clusterId: 155, clusterName: 'Самара', abc: 'B', risk: 'low', avgDeliveryHours: 24, inTransit: 60),
        ], $plan);

        $clustersByName = collect($summary['cluster_ranking'])->keyBy('cluster_name');
        $moscow = $clustersByName->get('Москва');
        $samara = $clustersByName->get('Самара');

        $this->assertSame('ограничения влияют на объём', $moscow['constraint_status']);
        $this->assertSame('check_constraints', $moscow['action_plan']['status']);
        $this->assertStringContainsString('Проверить ограничения Ozon', $moscow['action_plan']['title']);
        $this->assertStringContainsString('ограничения влияют', implode(' ', $moscow['action_plan']['evidence']));

        $this->assertSame('потребность заметно закрыта товарами в пути', $samara['in_transit_status']);
        $this->assertSame('lower_urgency_in_transit', $samara['action_plan']['status']);
        $this->assertStringContainsString('в пути уже 60 шт.', implode(' ', $samara['action_plan']['evidence']));
    }

    public function test_ozon_territorial_ranking_uses_regional_demand_fit_from_marketplace_profile(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'ozon',
        ]);

        $regionalProfile = [
            'source' => 'unit_economics.marketplace_data',
            'sales_profile' => [
                ['cluster_id' => 154, 'cluster_name' => 'Москва', 'share_percent' => 82],
                ['cluster_id' => 155, 'cluster_name' => 'Самара', 'share_percent' => 18],
            ],
        ];

        $summary = (new TerritorialPlanningService())->summarize([
            $this->line('SKU-REGION', 20, clusterId: 154, clusterName: 'Москва', avgDeliveryHours: 24, regionalDemand: $regionalProfile),
            $this->line('SKU-REGION', 20, clusterId: 155, clusterName: 'Самара', avgDeliveryHours: 24, regionalDemand: $regionalProfile),
        ], $plan);

        $this->assertSame('154', $summary['cluster_ranking'][0]['cluster_id']);
        $this->assertSame(82.0, $summary['cluster_ranking'][0]['score_factors']['совпадение_с_региональным_спросом']);
        $this->assertSame(18.0, $summary['cluster_ranking'][1]['score_factors']['совпадение_с_региональным_спросом']);
        $this->assertArrayHasKey('demand_closure_ranking', $summary);
        $this->assertSame('Москва', $summary['demand_closure_ranking'][0]['name']);
        $this->assertSame('кластер', $summary['demand_closure_ranking'][0]['destination_type']);
        $this->assertSame('плановое пополнение', $summary['demand_closure_ranking'][0]['recommended_for_ru']);
        $this->assertStringContainsString('закрывает спрос', $summary['demand_closure_ranking'][0]['decision_ru']);
        $this->assertGreaterThan(
            $summary['cluster_ranking'][1]['demand_closure_score'],
            $summary['cluster_ranking'][0]['demand_closure_score']
        );
    }

    public function test_sku_level_routing_recommends_better_cluster_for_specific_ozon_sku(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'ozon',
        ]);
        $regionalProfile = [
            'source' => 'unit_economics.marketplace_data',
            'sales_profile' => [
                ['cluster_id' => 154, 'cluster_name' => 'Москва', 'share_percent' => 88],
                ['cluster_id' => 155, 'cluster_name' => 'Самара', 'share_percent' => 12],
            ],
        ];

        $service = new TerritorialPlanningService();
        $lines = $service->enrichLines([
            $this->line('OZ-A-REGION', 20, clusterId: 155, clusterName: 'Самара', abc: 'A', risk: 'high', avgDeliveryHours: 72, regionalDemand: $regionalProfile),
            $this->line('OZ-A-REGION', 20, clusterId: 154, clusterName: 'Москва', abc: 'A', risk: 'high', avgDeliveryHours: 12, expectedSavingsRub: 1200, lostProfit: 30000, regionalDemand: $regionalProfile),
        ], $plan);
        $summary = $service->summarize($lines, $plan);

        $this->assertArrayHasKey('sku_routing_recommendations', $summary);
        $this->assertNotEmpty($summary['sku_routing_recommendations']);
        $recommendation = $summary['sku_routing_recommendations'][0];

        $this->assertSame('sku_reroute_to_better_destination', $recommendation['type']);
        $this->assertSame('OZ-A-REGION', $recommendation['sku']);
        $this->assertSame('Самара', $recommendation['from']['name']);
        $this->assertSame('Москва', $recommendation['to']['name']);
        $this->assertSame('high', $recommendation['priority']);
        $this->assertSame('A', $recommendation['abc_priority']);
        $this->assertGreaterThan(0, $recommendation['recommended_qty']);
        $this->assertGreaterThan(0, $recommendation['score_gap']);
        $this->assertStringContainsString('Рекомендация по SKU', $recommendation['decision_ru']);
        $this->assertStringContainsString('кластер назначения', $recommendation['reason']);
    }

    public function test_sku_routing_marks_financially_important_cluster_move(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'ozon',
        ]);

        $service = new TerritorialPlanningService();
        $lines = $service->enrichLines([
            $this->line('OZ-C-EXPENSIVE', 10, clusterId: 155, clusterName: 'Самара', abc: 'C', risk: 'low', avgDeliveryHours: 96, expectedRevenuePerUnit: 2500),
            $this->line('OZ-C-EXPENSIVE', 1, clusterId: 154, clusterName: 'Москва', abc: 'C', risk: 'low', avgDeliveryHours: 12, expectedSavingsRub: 1500, expectedRevenuePerUnit: 2500),
        ], $plan);
        $summary = $service->summarize($lines, $plan);

        $this->assertNotEmpty($summary['sku_routing_recommendations']);
        $recommendation = $summary['sku_routing_recommendations'][0];

        $this->assertSame('OZ-C-EXPENSIVE', $recommendation['sku']);
        $this->assertSame('high', $recommendation['financial_priority']);
        $this->assertSame('high', $recommendation['priority']);
        $this->assertGreaterThan(0, $recommendation['financial_weight']);
        $this->assertGreaterThan(0, $recommendation['financial_share_percent']);
        $this->assertStringContainsString('Финансовый вес переноса', $recommendation['decision_ru']);
    }

    public function test_wb_territorial_ranking_uses_delivery_fo_demand_profile(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 9905]);

        foreach ([
            ['id' => 'koledino', 'name' => 'Коледино'],
            ['id' => 'novosibirsk', 'name' => 'Новосибирск'],
        ] as $warehouse) {
            WarehouseSlot::query()->create([
                'marketplace' => 'wildberries',
                'warehouse_id' => $warehouse['id'],
                'warehouse_name' => $warehouse['name'],
                'date' => now()->addDay()->toDateString(),
                'time_from' => '09:00:00',
                'time_to' => '18:00:00',
                'coefficient' => 1,
                'delivery_coefficient' => 1,
                'storage_coefficient' => 1,
                'is_available' => true,
                'capacity' => 100,
                'capacity_used' => 10,
            ]);
        }

        $plan = new AutoSupplyPlan([
            'integration_id' => $integration->id,
            'marketplace' => 'wildberries',
        ]);
        $regionalProfile = [
            'source' => 'unit_economics.marketplace_data',
            'by_delivery_fo' => [
                'Центральный федеральный округ' => 82,
                'Сибирский федеральный округ' => 18,
            ],
        ];

        $summary = (new TerritorialPlanningService())->summarize([
            $this->line('WB-REGION', 20, warehouseId: 'koledino', warehouseName: 'Коледино', abc: 'A', risk: 'high', regionalDemand: $regionalProfile),
            $this->line('WB-REGION', 20, warehouseId: 'novosibirsk', warehouseName: 'Новосибирск', abc: 'A', risk: 'high', regionalDemand: $regionalProfile),
        ], $plan);

        $this->assertSame('koledino', $summary['warehouse_ranking'][0]['warehouse_id']);
        $this->assertSame(82.0, $summary['warehouse_ranking'][0]['score_factors']['совпадение_с_региональным_спросом']);
        $this->assertSame(18.0, $summary['warehouse_ranking'][1]['score_factors']['совпадение_с_региональным_спросом']);
        $this->assertArrayHasKey('demand_closure_ranking', $summary);
        $this->assertSame('Коледино', $summary['demand_closure_ranking'][0]['name']);
        $this->assertSame('склад', $summary['demand_closure_ranking'][0]['destination_type']);
        $this->assertSame('A-товары и товары с высоким риском отсутствия', $summary['demand_closure_ranking'][0]['recommended_for_ru']);
        $this->assertStringContainsString('A-товаров', $summary['demand_closure_ranking'][0]['decision_ru']);
        $this->assertSame('good', $summary['confidence_status']);
        $this->assertSame('territorial-source-coverage-1', $summary['source_coverage']['version']);
        $this->assertSame(100.0, $summary['source_coverage']['metrics']['regional_demand_profile']['coverage_percent']);
        $this->assertSame(100.0, $summary['source_coverage']['metrics']['speed_source']['coverage_percent']);
        $this->assertGreaterThan(
            $summary['warehouse_ranking'][1]['demand_closure_score'],
            $summary['warehouse_ranking'][0]['demand_closure_score']
        );
    }

    public function test_territorial_summary_marks_low_confidence_when_sources_are_missing(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'ozon',
        ]);

        $summary = (new TerritorialPlanningService())->summarize([
            $this->line('OZ-NO-SOURCES', 10, clusterId: 154, clusterName: 'Москва'),
        ], $plan);

        $this->assertSame('bad', $summary['confidence_status']);
        $this->assertSame('Территориальное ранжирование низкой достоверности', $summary['source_coverage']['human_status']);
        $this->assertSame(0.0, $summary['source_coverage']['metrics']['regional_demand_profile']['coverage_percent']);
        $this->assertSame(0.0, $summary['source_coverage']['metrics']['delivery_speed_source']['coverage_percent']);
        $this->assertContains('мало данных: региональный спрос покрывает 0% строк', $summary['confidence_reasons']);
    }

    public function test_sku_level_routing_recommends_better_destination_for_specific_wb_sku(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 9906]);

        foreach ([
            ['id' => 'koledino', 'name' => 'Коледино', 'coef' => 1],
            ['id' => 'novosibirsk', 'name' => 'Новосибирск', 'coef' => 8],
        ] as $warehouse) {
            WarehouseSlot::query()->create([
                'marketplace' => 'wildberries',
                'warehouse_id' => $warehouse['id'],
                'warehouse_name' => $warehouse['name'],
                'date' => now()->addDay()->toDateString(),
                'time_from' => '09:00:00',
                'time_to' => '18:00:00',
                'coefficient' => $warehouse['coef'],
                'delivery_coefficient' => $warehouse['coef'],
                'storage_coefficient' => $warehouse['coef'],
                'is_available' => true,
                'capacity' => 100,
                'capacity_used' => 10,
            ]);
        }

        $plan = new AutoSupplyPlan([
            'integration_id' => $integration->id,
            'marketplace' => 'wildberries',
        ]);
        $regionalProfile = [
            'by_delivery_fo' => [
                'Центральный федеральный округ' => 90,
                'Сибирский федеральный округ' => 10,
            ],
        ];

        $service = new TerritorialPlanningService();
        $lines = $service->enrichLines([
            $this->line('WB-A-REGION', 20, warehouseId: 'novosibirsk', warehouseName: 'Новосибирск', abc: 'A', risk: 'high', regionalDemand: $regionalProfile),
            $this->line('WB-A-REGION', 20, warehouseId: 'koledino', warehouseName: 'Коледино', abc: 'A', risk: 'high', regionalDemand: $regionalProfile),
        ], $plan);
        $summary = $service->summarize($lines, $plan);

        $this->assertArrayHasKey('sku_routing_recommendations', $summary);
        $this->assertNotEmpty($summary['sku_routing_recommendations']);
        $recommendation = $summary['sku_routing_recommendations'][0];

        $this->assertSame('sku_reroute_to_better_destination', $recommendation['type']);
        $this->assertSame('WB-A-REGION', $recommendation['sku']);
        $this->assertSame('Новосибирск', $recommendation['from']['name']);
        $this->assertSame('Коледино', $recommendation['to']['name']);
        $this->assertSame('high', $recommendation['priority']);
        $this->assertSame('A', $recommendation['abc_priority']);
        $this->assertGreaterThan(0, $recommendation['recommended_qty']);
        $this->assertGreaterThan(0, $recommendation['score_gap']);
        $this->assertStringContainsString('Рекомендация по SKU', $recommendation['decision_ru']);
        $this->assertStringContainsString('A-товаров', $recommendation['reason']);
    }

    public function test_ozon_territorial_enrichment_explains_abc_oos_constraints_and_in_transit(): void
    {
        $plan = new AutoSupplyPlan([
            'marketplace' => 'ozon',
        ]);

        $lines = (new TerritorialPlanningService())->enrichLines([
            $this->line('OZON-A', 20, clusterId: 154, clusterName: 'Москва', abc: 'A', risk: 'high', constraintCoefficient: 2.5, expectedSavingsRub: 2500, avgDeliveryHours: 12, lostProfit: 30000, inTransit: 10),
        ], $plan);

        $explain = json_decode($lines[0]['explain_json'], true);

        $this->assertSame('Ozon', $explain['territorial']['marketplace']);
        $this->assertSame('A', $explain['territorial']['abc_priority']);
        $this->assertArrayHasKey('regional_demand_closure_score', $explain['territorial']);
        $this->assertArrayHasKey('abc_priority_score', $explain['territorial']);
        $this->assertArrayHasKey('oos_urgency_score', $explain['territorial']);
        $this->assertArrayHasKey('constraint_penalty', $explain['territorial']);
        $this->assertArrayHasKey('in_transit_relief', $explain['territorial']);
        $this->assertArrayHasKey('rank_reason', $explain['territorial']);
        $this->assertArrayHasKey('abc_policy_status', $explain['territorial']);
        $this->assertArrayHasKey('priority_reasons', $explain['territorial']);
        $this->assertArrayHasKey('decision_ru', $explain['territorial']);
        $this->assertArrayHasKey('recommended_action_ru', $explain['territorial']);
        $this->assertSame('a_speed_priority', $explain['territorial']['abc_policy_status']);
        $this->assertStringContainsString('A-товар', $explain['territorial']['rank_reason']);
        $this->assertContains('A-товар: быстрый кластер повышает приоритет поставки.', $explain['territorial']['priority_reasons']);
        $this->assertStringContainsString('быстрый кластер', $explain['territorial']['decision_ru']);
        $this->assertGreaterThan(0, $explain['territorial']['constraint_penalty']);
        $this->assertGreaterThan(0, $explain['territorial']['in_transit_relief']);
    }

    public function test_wb_territorial_enrichment_writes_russian_score_before_optimizer(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 9902]);

        WarehouseSlot::query()->create([
            'marketplace' => 'wildberries',
            'warehouse_id' => 'fast-wh',
            'warehouse_name' => 'Быстрый склад',
            'date' => now()->addDay()->toDateString(),
            'time_from' => '09:00:00',
            'time_to' => '18:00:00',
            'coefficient' => 1,
            'delivery_coefficient' => 1,
            'storage_coefficient' => 1,
            'is_available' => true,
            'capacity' => 100,
            'capacity_used' => 10,
        ]);
        WarehouseSlot::query()->create([
            'marketplace' => 'wildberries',
            'warehouse_id' => 'slow-wh',
            'warehouse_name' => 'Медленный склад',
            'date' => now()->addDay()->toDateString(),
            'time_from' => '09:00:00',
            'time_to' => '18:00:00',
            'coefficient' => 8,
            'delivery_coefficient' => 8,
            'storage_coefficient' => 3,
            'is_available' => true,
            'capacity' => 100,
            'capacity_used' => 10,
        ]);

        $plan = new AutoSupplyPlan([
            'integration_id' => $integration->id,
            'marketplace' => 'wildberries',
        ]);

        $lines = (new TerritorialPlanningService())->enrichLines([
            $this->line('A-SKU', 20, warehouseId: 'fast-wh', warehouseName: 'Быстрый склад', abc: 'A', risk: 'high'),
            $this->line('A-SKU', 20, warehouseId: 'slow-wh', warehouseName: 'Медленный склад', abc: 'A', risk: 'high'),
        ], $plan);

        $fastExplain = json_decode($lines[0]['explain_json'], true);
        $slowExplain = json_decode($lines[1]['explain_json'], true);

        $this->assertSame('Wildberries', $fastExplain['territorial']['marketplace']);
        $this->assertStringContainsString('территориальный балл', $fastExplain['territorial']['score_label']);
        $this->assertStringContainsString('A-товар', $fastExplain['territorial']['why']);
        $this->assertSame(0.46, $fastExplain['territorial']['speed_weight']);
        $this->assertSame('быстрый склад важнее стоимости', $fastExplain['territorial']['a_item_speed_policy']);
        $this->assertArrayHasKey('regional_demand_closure_score', $fastExplain['territorial']);
        $this->assertArrayHasKey('coefficient_penalty', $fastExplain['territorial']);
        $this->assertArrayHasKey('capacity_score', $fastExplain['territorial']);
        $this->assertArrayHasKey('abc_priority_score', $fastExplain['territorial']);
        $this->assertArrayHasKey('oos_urgency_score', $fastExplain['territorial']);
        $this->assertArrayHasKey('rank_reason', $fastExplain['territorial']);
        $this->assertArrayHasKey('abc_policy_status', $fastExplain['territorial']);
        $this->assertArrayHasKey('priority_reasons', $fastExplain['territorial']);
        $this->assertArrayHasKey('decision_ru', $fastExplain['territorial']);
        $this->assertArrayHasKey('recommended_action_ru', $fastExplain['territorial']);
        $this->assertArrayHasKey('action_status', $fastExplain['territorial']);
        $this->assertSame('a_speed_priority', $fastExplain['territorial']['abc_policy_status']);
        $this->assertSame('prioritize_a_items', $fastExplain['territorial']['action_status']);
        $this->assertContains('A-товар: скорость склада повышает приоритет поставки.', $fastExplain['territorial']['priority_reasons']);
        $this->assertStringContainsString('A-товар', $fastExplain['territorial']['rank_reason']);
        $this->assertStringContainsString('быстрый склад', $fastExplain['territorial']['decision_ru']);
        $this->assertGreaterThan($slowExplain['territorial']['delivery_speed_score'], $fastExplain['territorial']['delivery_speed_score']);
        $this->assertGreaterThan($slowExplain['territorial']['regional_demand_closure_score'], $fastExplain['territorial']['regional_demand_closure_score']);
        $this->assertGreaterThan($slowExplain['territorial']['score'], $fastExplain['territorial']['score']);
    }

    public function test_wb_territorial_enrichment_uses_constraint_file_coefficient(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 9903]);

        $plan = new AutoSupplyPlan([
            'integration_id' => $integration->id,
            'marketplace' => 'wildberries',
        ]);

        $lines = (new TerritorialPlanningService())->enrichLines([
            $this->line('A-SKU', 20, warehouseId: 'limited-wh', warehouseName: 'Склад с ограничением', abc: 'A', risk: 'high', constraintCoefficient: 7.5),
        ], $plan);

        $explain = json_decode($lines[0]['explain_json'], true);

        $this->assertSame('файл ограничений', $explain['territorial']['constraint_source']);
        $this->assertSame(7.5, $explain['territorial']['warehouse_coefficient']);
        $this->assertGreaterThan(0, $explain['territorial']['coefficient_penalty']);
        $this->assertLessThan(100, $explain['territorial']['cost_score']);
    }

    private function line(
        string $sku,
        int $qty,
        ?int $clusterId = null,
        ?string $clusterName = null,
        ?string $warehouseId = null,
        ?string $warehouseName = null,
        string $abc = 'B',
        string $risk = 'low',
        ?float $constraintCoefficient = null,
        float $expectedSavingsRub = 0.0,
        ?float $avgDeliveryHours = null,
        float $lostProfit = 0.0,
        int $inTransit = 0,
        ?array $regionalDemand = null,
        float $expectedRevenuePerUnit = 20.0,
        float $expectedProfitPerUnit = 5.0,
    ): array {
        return [
            'sku' => $sku,
            'qty_rounded' => $qty,
            'qty_recommended' => $qty,
            'cluster_id' => $clusterId,
            'cluster_name' => $clusterName,
            'warehouse_id' => $warehouseId,
            'warehouse_name' => $warehouseName,
            'risk_level' => $risk,
            'priority_score' => 50,
            'expected_savings_rub' => $expectedSavingsRub,
            'supply_cost_estimate' => $qty * 10,
            'expected_revenue' => $qty * $expectedRevenuePerUnit,
            'expected_profit' => $qty * $expectedProfitPerUnit,
            'in_transit' => $inTransit,
            'explain_json' => json_encode(array_filter([
                'inputs' => [
                    'abc_priority' => $abc,
                    'daily_demand' => 1,
                    'min_cover_days' => 7,
                    'target_cover_days' => 21,
                ],
                'constraints' => $constraintCoefficient !== null
                    ? ['coefficient' => $constraintCoefficient, 'source' => 'Файл или параметры ограничений']
                    : null,
                'ozon_analytics' => $avgDeliveryHours !== null || $lostProfit > 0
                    ? ['avg_delivery_time' => $avgDeliveryHours, 'lost_profit' => $lostProfit]
                    : null,
                'regional_demand' => $regionalDemand,
            ])),
        ];
    }
}
