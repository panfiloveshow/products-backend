<?php

namespace App\Services\AutoSupplyPlanning;

use App\Models\AutoSupplyPlan;
use App\Models\InventoryWarehouse;
use App\Models\WarehouseSlot;
use App\Models\WildberriesTariffSnapshot;
use Illuminate\Support\Carbon;

class TerritorialPlanningService
{
    /**
     * Добавляет в строки территориальный балл до оптимизации.
     *
     * Это важно: склад/кластер должен влиять на выбор строк, а не только
     * появляться в итоговом summary после расчёта.
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    public function enrichLines(array $lines, AutoSupplyPlan $plan): array
    {
        $marketplace = (string) $plan->marketplace;

        if ($lines === []) {
            return [];
        }

        return match ($marketplace) {
            'wildberries' => $this->enrichWildberriesLines($lines, $plan),
            'ozon' => $this->enrichOzonLines($lines),
            default => $lines,
        };
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    public function summarize(array $lines, AutoSupplyPlan $plan): array
    {
        $marketplace = (string) $plan->marketplace;

        if ($lines === []) {
            return $this->emptySummary($marketplace);
        }

        return match ($marketplace) {
            'wildberries' => $this->summarizeWildberries($lines, $plan),
            'ozon' => $this->summarizeOzon($lines, $plan),
            default => $this->emptySummary($marketplace),
        };
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    private function summarizeWildberries(array $lines, AutoSupplyPlan $plan): array
    {
        $warehouseIds = array_values(array_unique(array_filter(array_map(
            static fn (array $line): ?string => isset($line['warehouse_id']) ? (string) $line['warehouse_id'] : null,
            $lines
        ))));

        $inventoryCoefficients = InventoryWarehouse::query()
            ->where('integration_id', $plan->integration_id)
            ->where('marketplace', 'wildberries')
            ->whereIn('warehouse_id', $warehouseIds)
            ->selectRaw('warehouse_id, AVG(warehouse_coefficient) as coefficient')
            ->groupBy('warehouse_id')
            ->pluck('coefficient', 'warehouse_id')
            ->map(fn ($value) => $value !== null ? (float) $value : null)
            ->all();

        $slotFacts = $this->wildberriesSlotFacts($warehouseIds);

        $tariffFacts = WildberriesTariffSnapshot::query()
            ->where('integration_id', $plan->integration_id)
            ->whereIn('warehouse_id', $warehouseIds)
            ->orderByDesc('effective_date')
            ->get()
            ->groupBy('warehouse_id')
            ->map(function ($rows) {
                $row = $rows->first();
                $payload = is_array($row?->payload) ? $row->payload : [];

                return [
                    'warehouse_name' => $row?->warehouse_name,
                    'delivery_coefficient' => $this->firstNumeric($payload, ['delivery_coefficient', 'boxDeliveryCoefExpr', 'deliveryCoef']),
                    'storage_coefficient' => $this->firstNumeric($payload, ['storage_coefficient', 'boxStorageCoefExpr', 'storageCoef']),
                ];
            })
            ->all();

        $warehouses = [];
        $sourceCoverage = ['lines_total' => 0];
        foreach ($lines as $line) {
            $warehouseId = (string) ($line['warehouse_id'] ?? '');
            if ($warehouseId === '') {
                continue;
            }

            $explain = $this->decodeExplain($line);
            $abc = $this->normalizeAbcPriority($explain['inputs']['abc_priority'] ?? 'C');
            $qty = (int) ($line['qty_rounded'] ?? 0);
            $risk = (string) ($line['risk_level'] ?? 'low');

            $warehouses[$warehouseId] ??= [
                'warehouse_id' => $warehouseId,
                'warehouse_name' => $line['warehouse_name'] ?? $tariffFacts[$warehouseId]['warehouse_name'] ?? null,
                'qty' => 0,
                'sku_count' => 0,
                'abc_a_qty' => 0,
                'high_risk_qty' => 0,
                'score' => 0.0,
                'score_factors' => [],
                'demand_closure_score' => 0.0,
                'coefficient_penalty' => 0.0,
                'fast_for_a_qty' => 0,
                'coefficient_limited_qty' => 0,
                'financial_weight' => 0.0,
                'expected_profit' => 0.0,
                'expected_revenue' => 0.0,
                'supply_cost' => 0.0,
                '_score_factor_numeric_totals' => [],
                '_score_factor_weights' => [],
                '_score_factor_values' => [],
            ];

            $constraintDeliveryCoefficient = $this->constraintDetailCoefficient($explain, 'delivery_coefficient')
                ?? $this->constraintDetailCoefficient($explain, 'logistics_coefficient');
            $constraintAcceptanceCoefficient = $this->constraintDetailCoefficient($explain, 'acceptance_coefficient')
                ?? $this->constraintCoefficient($explain);
            $constraintStorageCoefficient = $this->constraintDetailCoefficient($explain, 'storage_coefficient');
            $sourceCoverage['lines_total']++;
            $this->incrementCoverage($sourceCoverage, 'regional_demand_profile', $this->hasRegionalDemandProfile($line, $explain));
            $this->incrementCoverage($sourceCoverage, 'speed_source', $constraintDeliveryCoefficient !== null
                || ($slotFacts[$warehouseId]['delivery_coefficient'] ?? null) !== null
                || ($slotFacts[$warehouseId]['coefficient'] ?? null) !== null
                || ($slotFacts[$warehouseId]['date'] ?? null) !== null
                || ($tariffFacts[$warehouseId]['delivery_coefficient'] ?? null) !== null);
            $this->incrementCoverage($sourceCoverage, 'cost_source', $constraintAcceptanceCoefficient !== null
                || $constraintStorageCoefficient !== null
                || ($slotFacts[$warehouseId]['coefficient'] ?? null) !== null
                || ($slotFacts[$warehouseId]['storage_coefficient'] ?? null) !== null
                || ($inventoryCoefficients[$warehouseId] ?? null) !== null
                || ($tariffFacts[$warehouseId]['storage_coefficient'] ?? null) !== null);
            $this->incrementCoverage($sourceCoverage, 'capacity_source', ($slotFacts[$warehouseId]['capacity_remaining'] ?? null) !== null);
            $this->incrementCoverage($sourceCoverage, 'constraint_source', $this->hasConstraintSource($explain));
            $this->incrementCoverage($sourceCoverage, 'abc_source', in_array(strtoupper($abc), ['A', 'B', 'C'], true));

            $coefficient = $constraintDeliveryCoefficient
                ?? $slotFacts[$warehouseId]['delivery_coefficient']
                ?? $slotFacts[$warehouseId]['coefficient']
                ?? $tariffFacts[$warehouseId]['delivery_coefficient']
                ?? 1.0;
            $acceptanceCoefficient = $constraintAcceptanceCoefficient
                ?? $slotFacts[$warehouseId]['coefficient']
                ?? $inventoryCoefficients[$warehouseId]
                ?? 1.0;
            $storageCoefficient = $constraintStorageCoefficient
                ?? $slotFacts[$warehouseId]['storage_coefficient']
                ?? $tariffFacts[$warehouseId]['storage_coefficient']
                ?? 1.0;
            $slotDate = $slotFacts[$warehouseId]['date'] ?? null;
            $slotLeadTimeDays = $slotFacts[$warehouseId]['lead_time_days'] ?? $this->slotLeadTimeDays($slotDate);
            $slotLeadTimeScore = $this->slotLeadTimeScore($slotLeadTimeDays);
            $abcWeight = $this->abcWeight($abc);
            $riskWeight = $this->riskWeight($risk);
            $speedScore = $this->deliverySpeedScore($coefficient, $slotLeadTimeDays);
            $costScore = 100 / max(1.0, max((float) $storageCoefficient, (float) $acceptanceCoefficient));
            $capacityScore = $this->capacityScore($slotFacts[$warehouseId]['capacity_remaining'] ?? null, $qty);
            $regionalDemandFitScore = $this->regionalDemandFitScore($line, $explain);
            $abcPriorityScore = $this->abcPriorityScore($abc);
            $oosUrgencyScore = $this->oosUrgencyScore($risk, (float) ($line['priority_score'] ?? 0));
            $scoreWeights = $this->scoreWeightsByAbc($abc);
            $coefficientPenalty = $this->coefficientPenalty($acceptanceCoefficient, $storageCoefficient);
            $demandClosureScore = $this->demandClosureScore(
                speedScore: $speedScore,
                regionalDemandFitScore: $regionalDemandFitScore,
                abc: $abc,
                risk: $risk,
                priorityScore: (float) ($line['priority_score'] ?? 0),
                coefficientPenalty: $coefficientPenalty,
                capacityScore: $capacityScore,
            );
            $abcSpeedCostAdjustment = $this->abcSpeedCostAdjustment($abc, $speedScore, $demandClosureScore, $coefficientPenalty, $costScore);
            $lineScore = $this->calculateWarehouseScore(
                speedScore: $speedScore,
                costScore: $costScore,
                capacityScore: $capacityScore,
                demandClosureScore: $demandClosureScore,
                abcPriorityScore: $abcPriorityScore,
                oosUrgencyScore: $oosUrgencyScore,
                coefficientPenalty: $coefficientPenalty,
                priorityScore: (float) ($line['priority_score'] ?? 0),
                abc: $abc,
                abcWeight: $abcWeight,
                riskWeight: $riskWeight,
                abcSpeedCostAdjustment: $abcSpeedCostAdjustment,
            );

            $warehouses[$warehouseId]['qty'] += $qty;
            $warehouses[$warehouseId]['sku_count']++;
            $warehouses[$warehouseId]['abc_a_qty'] += $abc === 'A' ? $qty : 0;
            $warehouses[$warehouseId]['high_risk_qty'] += $risk === 'high' ? $qty : 0;
            $warehouses[$warehouseId]['score'] += $lineScore * max(1, $qty);
            $warehouses[$warehouseId]['demand_closure_score'] += $demandClosureScore * max(1, $qty);
            $warehouses[$warehouseId]['coefficient_penalty'] += $coefficientPenalty * max(1, $qty);
            $warehouses[$warehouseId]['fast_for_a_qty'] += $abc === 'A' && $demandClosureScore >= 80 ? $qty : 0;
            $warehouses[$warehouseId]['coefficient_limited_qty'] += $coefficientPenalty >= 35 ? $qty : 0;
            $warehouses[$warehouseId]['financial_weight'] += $this->lineFinancialWeight($line, $qty);
            $warehouses[$warehouseId]['expected_profit'] += (float) ($line['expected_profit'] ?? 0);
            $warehouses[$warehouseId]['expected_revenue'] += (float) ($line['expected_revenue'] ?? 0);
            $warehouses[$warehouseId]['supply_cost'] += (float) ($line['supply_cost_estimate'] ?? 0);
            $scoreFactors = [
                'скорость_закрытия_спроса' => round($speedScore, 2),
                'скорость_ближайшего_слота' => round($slotLeadTimeScore, 2),
                'ближайший_слот_дней' => $slotLeadTimeDays,
                'дата_ближайшего_слота' => $slotDate,
                'закрытие_регионального_спроса' => round($demandClosureScore, 2),
                'стоимость_и_коэффициенты' => round($costScore, 2),
                'совпадение_с_региональным_спросом' => round($regionalDemandFitScore, 2),
                'штраф_коэффициентов' => round($coefficientPenalty, 2),
                'доступность_емкости' => round($capacityScore, 2),
                'abc_приоритет' => $abcWeight,
                'abc_балл' => round($abcPriorityScore, 2),
                'риск_отсутствия' => $riskWeight,
                'срочность_отсутствия' => round($oosUrgencyScore, 2),
                'коррекция_abc_скорость_стоимость' => round($abcSpeedCostAdjustment, 2),
                'вес_скорости_для_abc' => $scoreWeights['speed'],
                'вес_стоимости_для_abc' => $scoreWeights['cost'],
                'вес_емкости_для_abc' => $scoreWeights['capacity'],
                'вес_риска_отсутствия_для_abc' => $scoreWeights['oos'],
                'модель_быстрого_склада_для_a' => $abc === 'A' ? 'скорость важнее стоимости' : 'баланс скорости и стоимости',
                'коэффициент_доставки' => $coefficient,
                'коэффициент_приёмки' => $acceptanceCoefficient,
                'коэффициент_хранения' => $storageCoefficient,
                'источник_коэффициентов' => $constraintDeliveryCoefficient !== null || $constraintAcceptanceCoefficient !== null || $constraintStorageCoefficient !== null
                    ? 'файл ограничений'
                    : 'слоты/тарифы/остатки',
                'доступная_ёмкость' => $slotFacts[$warehouseId]['capacity_remaining'] ?? null,
            ];
            $this->accumulateScoreFactors($warehouses[$warehouseId], $scoreFactors, max(1, $qty));
        }

        foreach ($warehouses as &$warehouse) {
            $warehouse['score'] = round($warehouse['score'] / max(1, (int) $warehouse['qty']), 2);
            $warehouse['demand_closure_score'] = round($warehouse['demand_closure_score'] / max(1, (int) $warehouse['qty']), 2);
            $warehouse['coefficient_penalty'] = round($warehouse['coefficient_penalty'] / max(1, (int) $warehouse['qty']), 2);
            $warehouse['score_factors'] = $this->finalizeScoreFactors($warehouse);
            unset($warehouse['_score_factor_numeric_totals'], $warehouse['_score_factor_weights'], $warehouse['_score_factor_values']);
            $warehouse['grade'] = $this->gradeForScore((float) $warehouse['score']);
            $warehouse['is_priority_destination'] = (float) $warehouse['score'] >= 75;
            $warehouse['is_fast_for_a_items'] = (int) $warehouse['abc_a_qty'] > 0
                && (int) $warehouse['fast_for_a_qty'] >= max(1, (int) ceil(((int) $warehouse['abc_a_qty']) * 0.5));
            $warehouse['recommendation'] = $warehouse['abc_a_qty'] > 0
                ? 'Приоритетный склад для A-товаров: скорость закрытия регионального спроса важнее небольшой разницы в стоимости.'
                : 'Склад подходит для планового пополнения с учётом коэффициентов и риска отсутствия товара.';
            $warehouse['rank_reason'] = $this->warehouseRankReason($warehouse);
            $warehouse['abc_policy_status'] = $this->warehouseAbcPolicyStatus($warehouse);
            $warehouse['priority_reasons'] = $this->warehousePriorityReasons($warehouse);
            $warehouse['decision_ru'] = $this->warehouseDecisionText($warehouse);
            $warehouse['speed_tier'] = $this->warehouseSpeedTier($warehouse);
            $warehouse['cost_tier'] = $this->warehouseCostTier($warehouse);
            $warehouse['capacity_status'] = $this->warehouseCapacityStatus($warehouse);
            $warehouse['action_plan'] = $this->warehouseActionPlan($warehouse);
        }
        unset($warehouse);

        usort($warehouses, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        foreach ($warehouses as $index => &$warehouse) {
            $warehouse['rank'] = $index + 1;
        }
        unset($warehouse);

        $ktr = $this->calculateKtr(
            $warehouses,
            $this->targetKtr($plan),
            $this->baselineKtr($plan)
        );
        $sourceCoverageSummary = $this->sourceCoverageSummary($sourceCoverage, 'wildberries');

        return [
            'marketplace' => 'Wildberries',
            'status' => 'включено',
            'method' => 'Ранжирование складов по скорости, коэффициентам, ABC и риску отсутствия товара',
            'confidence_status' => $sourceCoverageSummary['status'],
            'confidence_reasons' => $sourceCoverageSummary['reasons'],
            'source_coverage' => $sourceCoverageSummary,
            'ktr' => $ktr,
            'warehouse_ranking' => array_values($warehouses),
            'ranking_audit' => $this->buildRankingAudit($warehouses, 'wildberries', $sourceCoverageSummary),
            'demand_closure_ranking' => $this->buildDemandClosureRanking($warehouses, 'wildberries'),
            'routing_recommendations' => $this->buildRoutingRecommendations($warehouses, 'wildberries'),
            'sku_routing_recommendations' => $this->buildSkuRoutingRecommendations($lines, 'wildberries'),
            'scoring_policy' => [
                'version' => 'wb-territorial-3',
                'a_items' => 'Для A-товаров скорость закрытия регионального спроса имеет максимальный вес.',
                'b_items' => 'Для B-товаров скорость и стоимость сбалансированы.',
                'c_items' => 'Для C-товаров сильнее учитываются стоимость, коэффициенты и риск лишнего запаса.',
                'constraints' => 'Коэффициенты из файла ограничений снижают балл и могут ограничивать количество.',
                'components' => 'Балл склада = скорость закрытия спроса + стоимость/коэффициенты + ёмкость + ABC + риск отсутствия товара, затем штраф за дорогую или закрытую приёмку.',
            ],
            'notes' => [
                'Быстрые склады получают больший вес для A-товаров.',
                'Автобронирование не выполняется: результат используется как рекомендация и экспорт.',
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    private function summarizeOzon(array $lines, AutoSupplyPlan $plan): array
    {
        $clusters = [];
        $sourceCoverage = ['lines_total' => 0];
        foreach ($lines as $line) {
            $clusterId = isset($line['cluster_id']) ? (string) $line['cluster_id'] : '';
            if ($clusterId === '') {
                continue;
            }

            $explain = $this->decodeExplain($line);
            $abc = $this->normalizeAbcPriority($explain['inputs']['abc_priority'] ?? 'C');
            $risk = (string) ($line['risk_level'] ?? 'low');
            $avgDelivery = (float) ($explain['ozon_analytics']['avg_delivery_time'] ?? 0);
            $lostProfit = (float) ($explain['ozon_analytics']['lost_profit'] ?? 0);
            $qty = (int) ($line['qty_rounded'] ?? 0);
            $speedScore = $avgDelivery > 0 ? 100 / max(1.0, $avgDelivery / 24) : 50;
            $localityScore = $this->ozonLocalityScore((float) ($line['expected_savings_rub'] ?? 0), $lostProfit, $qty);
            $regionalDemandFitScore = $this->regionalDemandFitScore($line, $explain);
            $constraintPenalty = $this->ozonConstraintPenalty($explain);
            $inTransitRelief = $this->ozonInTransitRelief($line, $explain);
            $abcPriorityScore = $this->abcPriorityScore($abc);
            $oosUrgencyScore = $this->oosUrgencyScore($risk, (float) ($line['priority_score'] ?? 0));
            $sourceCoverage['lines_total']++;
            $this->incrementCoverage($sourceCoverage, 'regional_demand_profile', $this->hasRegionalDemandProfile($line, $explain));
            $this->incrementCoverage($sourceCoverage, 'delivery_speed_source', $avgDelivery > 0);
            $this->incrementCoverage($sourceCoverage, 'locality_source', (float) ($line['expected_savings_rub'] ?? 0) > 0 || $lostProfit > 0);
            $this->incrementCoverage($sourceCoverage, 'constraint_source', $this->hasConstraintSource($explain));
            $this->incrementCoverage($sourceCoverage, 'in_transit_source', (int) ($line['in_transit'] ?? 0) > 0);
            $this->incrementCoverage($sourceCoverage, 'abc_source', in_array(strtoupper($abc), ['A', 'B', 'C'], true));
            $this->incrementCoverage($sourceCoverage, 'lost_profit_source', $lostProfit > 0);
            $demandClosureScore = $this->ozonDemandClosureScore(
                speedScore: $speedScore,
                localityScore: $localityScore,
                regionalDemandFitScore: $regionalDemandFitScore,
                abc: $abc,
                risk: $risk,
                lostProfit: $lostProfit,
                constraintPenalty: $constraintPenalty,
                inTransitRelief: $inTransitRelief,
            );
            $score = $this->calculateOzonClusterScore(
                speedScore: $speedScore,
                localityScore: $localityScore,
                demandClosureScore: $demandClosureScore,
                abcPriorityScore: $abcPriorityScore,
                oosUrgencyScore: $oosUrgencyScore,
                lostProfit: $lostProfit,
                constraintPenalty: $constraintPenalty,
                inTransitRelief: $inTransitRelief,
            );

            $clusters[$clusterId] ??= [
                'cluster_id' => $clusterId,
                'cluster_name' => $line['cluster_name'] ?? null,
                'qty' => 0,
                'sku_count' => 0,
                'abc_a_qty' => 0,
                'high_risk_qty' => 0,
                'score' => 0.0,
                'demand_closure_score' => 0.0,
                'coefficient_penalty' => 0.0,
                'fast_for_a_qty' => 0,
                'coefficient_limited_qty' => 0,
                'lost_profit' => 0.0,
                'in_transit_qty' => 0,
                'in_transit_relief' => 0.0,
                'financial_weight' => 0.0,
                'expected_profit' => 0.0,
                'expected_revenue' => 0.0,
                'supply_cost' => 0.0,
                'score_factors' => [],
                '_score_factor_numeric_totals' => [],
                '_score_factor_weights' => [],
                '_score_factor_values' => [],
            ];

            $clusters[$clusterId]['qty'] += $qty;
            $clusters[$clusterId]['sku_count']++;
            $clusters[$clusterId]['abc_a_qty'] += strtoupper($abc) === 'A' ? $qty : 0;
            $clusters[$clusterId]['high_risk_qty'] += $risk === 'high' ? $qty : 0;
            $clusters[$clusterId]['score'] += $score * max(1, $qty);
            $clusters[$clusterId]['demand_closure_score'] += $demandClosureScore * max(1, $qty);
            $clusters[$clusterId]['coefficient_penalty'] += $constraintPenalty * max(1, $qty);
            $clusters[$clusterId]['fast_for_a_qty'] += strtoupper($abc) === 'A' && $demandClosureScore >= 80 ? $qty : 0;
            $clusters[$clusterId]['coefficient_limited_qty'] += $constraintPenalty >= 35 ? $qty : 0;
            $clusters[$clusterId]['lost_profit'] += $lostProfit;
            $clusters[$clusterId]['in_transit_qty'] += (int) ($line['in_transit'] ?? 0);
            $clusters[$clusterId]['in_transit_relief'] += $inTransitRelief * max(1, $qty);
            $clusters[$clusterId]['financial_weight'] += $this->lineFinancialWeight($line, $qty);
            $clusters[$clusterId]['expected_profit'] += (float) ($line['expected_profit'] ?? 0);
            $clusters[$clusterId]['expected_revenue'] += (float) ($line['expected_revenue'] ?? 0);
            $clusters[$clusterId]['supply_cost'] += (float) ($line['supply_cost_estimate'] ?? 0);
            $scoreFactors = [
                'скорость_доставки' => round($speedScore, 2),
                'закрытие_регионального_спроса' => round($demandClosureScore, 2),
                'локальность' => round($localityScore, 2),
                'совпадение_с_региональным_спросом' => round($regionalDemandFitScore, 2),
                'упущенная_маржа' => round($lostProfit, 2),
                'abc_балл' => round($abcPriorityScore, 2),
                'срочность_отсутствия' => round($oosUrgencyScore, 2),
                'штраф_ограничений' => round($constraintPenalty, 2),
                'снижение_срочности_из_за_пути' => round($inTransitRelief, 2),
                'среднее_время_доставки_часов' => $avgDelivery > 0 ? round($avgDelivery, 2) : null,
            ];
            $this->accumulateScoreFactors($clusters[$clusterId], $scoreFactors, max(1, $qty));
        }

        foreach ($clusters as &$cluster) {
            $cluster['score'] = round($cluster['score'] / max(1, (int) $cluster['qty']), 2);
            $cluster['demand_closure_score'] = round($cluster['demand_closure_score'] / max(1, (int) $cluster['qty']), 2);
            $cluster['coefficient_penalty'] = round($cluster['coefficient_penalty'] / max(1, (int) $cluster['qty']), 2);
            $cluster['in_transit_relief'] = round($cluster['in_transit_relief'] / max(1, (int) $cluster['qty']), 2);
            $cluster['lost_profit'] = round($cluster['lost_profit'], 2);
            $cluster['score_factors'] = $this->finalizeScoreFactors($cluster);
            unset($cluster['_score_factor_numeric_totals'], $cluster['_score_factor_weights'], $cluster['_score_factor_values']);
            $cluster['grade'] = $this->gradeForScore((float) $cluster['score']);
            $cluster['is_priority_destination'] = (float) $cluster['score'] >= 75;
            $cluster['is_fast_for_a_items'] = (int) $cluster['abc_a_qty'] > 0
                && (int) $cluster['fast_for_a_qty'] >= max(1, (int) ceil(((int) $cluster['abc_a_qty']) * 0.5));
            $cluster['recommendation'] = $cluster['lost_profit'] > 0
                ? 'Приоритетный кластер: поставка может быстрее закрыть спрос и снизить потерю маржи.'
                : 'Кластер подходит для планового пополнения с учётом скорости доставки и локальности.';
            $cluster['rank_reason'] = $this->ozonClusterRankReason($cluster);
            $cluster['abc_policy_status'] = $this->ozonDestinationPolicyStatus($cluster);
            $cluster['priority_reasons'] = $this->ozonDestinationPriorityReasons($cluster);
            $cluster['speed_tier'] = $this->ozonClusterSpeedTier($cluster);
            $cluster['constraint_status'] = $this->ozonClusterConstraintStatus($cluster);
            $cluster['in_transit_status'] = $this->ozonClusterInTransitStatus($cluster);
            $cluster['action_plan'] = $this->ozonClusterActionPlan($cluster);
            $cluster['decision_ru'] = $this->ozonDestinationDecisionText($cluster);
        }
        unset($cluster);

        usort($clusters, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        foreach ($clusters as $index => &$cluster) {
            $cluster['rank'] = $index + 1;
        }
        unset($cluster);

        $ktr = $this->calculateKtr(
            $clusters,
            $this->targetKtr($plan),
            $this->baselineKtr($plan)
        );
        $sourceCoverageSummary = $this->sourceCoverageSummary($sourceCoverage, 'ozon');

        return [
            'marketplace' => 'Ozon',
            'status' => 'включено',
            'method' => 'Ранжирование кластеров по скорости доставки, локальности, ABC, риску отсутствия товара, ограничениям и потере маржи',
            'confidence_status' => $sourceCoverageSummary['status'],
            'confidence_reasons' => $sourceCoverageSummary['reasons'],
            'source_coverage' => $sourceCoverageSummary,
            'ktr' => $ktr,
            'cluster_ranking' => array_values($clusters),
            'ranking_audit' => $this->buildRankingAudit($clusters, 'ozon', $sourceCoverageSummary),
            'demand_closure_ranking' => $this->buildDemandClosureRanking($clusters, 'ozon'),
            'routing_recommendations' => $this->buildRoutingRecommendations($clusters, 'ozon'),
            'sku_routing_recommendations' => $this->buildSkuRoutingRecommendations($lines, 'ozon'),
            'scoring_policy' => [
                'version' => 'ozon-territorial-3',
                'a_items' => 'Для A-товаров быстрые кластеры с высоким спросом получают повышенный вес.',
                'b_items' => 'Для B-товаров скорость и локальность сбалансированы.',
                'c_items' => 'Для C-товаров сильнее учитывается риск лишнего запаса и ограничения.',
                'constraints' => 'Файлы ограничений по товарам/кластерам снижают балл и могут обрезать количество до черновика.',
                'components' => 'Балл кластера = скорость доставки + локальность + закрытие спроса + ABC + риск отсутствия товара + потеря маржи - ограничения - уже в пути.',
            ],
            'notes' => [
                'Черновик поставки создаётся только после предварительного просмотра и ручного подтверждения.',
                'Выбранные пользователем кластеры остаются жёстким ограничением.',
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private function enrichWildberriesLines(array $lines, AutoSupplyPlan $plan): array
    {
        $warehouseIds = array_values(array_unique(array_filter(array_map(
            static fn (array $line): ?string => isset($line['warehouse_id']) ? (string) $line['warehouse_id'] : null,
            $lines
        ))));

        if ($warehouseIds === []) {
            return $lines;
        }

        $inventoryCoefficients = InventoryWarehouse::query()
            ->where('integration_id', $plan->integration_id)
            ->where('marketplace', 'wildberries')
            ->whereIn('warehouse_id', $warehouseIds)
            ->selectRaw('warehouse_id, AVG(warehouse_coefficient) as coefficient')
            ->groupBy('warehouse_id')
            ->pluck('coefficient', 'warehouse_id')
            ->map(fn ($value) => $value !== null ? (float) $value : null)
            ->all();

        $slotFacts = $this->wildberriesSlotFacts($warehouseIds);

        $tariffFacts = WildberriesTariffSnapshot::query()
            ->where('integration_id', $plan->integration_id)
            ->whereIn('warehouse_id', $warehouseIds)
            ->orderByDesc('effective_date')
            ->get()
            ->groupBy('warehouse_id')
            ->map(function ($rows) {
                $row = $rows->first();
                $payload = is_array($row?->payload) ? $row->payload : [];

                return [
                    'delivery_coefficient' => $this->firstNumeric($payload, ['delivery_coefficient', 'boxDeliveryCoefExpr', 'deliveryCoef']),
                    'storage_coefficient' => $this->firstNumeric($payload, ['storage_coefficient', 'boxStorageCoefExpr', 'storageCoef']),
                ];
            })
            ->all();

        foreach ($lines as &$line) {
            $warehouseId = isset($line['warehouse_id']) ? (string) $line['warehouse_id'] : '';
            if ($warehouseId === '') {
                continue;
            }

            $explain = $this->decodeExplain($line);
            $abc = $this->normalizeAbcPriority($explain['inputs']['abc_priority'] ?? 'C');
            $risk = (string) ($line['risk_level'] ?? 'low');
            $qty = (int) ($line['qty_rounded'] ?? 0);
            $constraintDeliveryCoefficient = $this->constraintDetailCoefficient($explain, 'delivery_coefficient')
                ?? $this->constraintDetailCoefficient($explain, 'logistics_coefficient');
            $constraintAcceptanceCoefficient = $this->constraintDetailCoefficient($explain, 'acceptance_coefficient')
                ?? $this->constraintCoefficient($explain);
            $constraintStorageCoefficient = $this->constraintDetailCoefficient($explain, 'storage_coefficient');
            $coefficient = $constraintDeliveryCoefficient
                ?? $slotFacts[$warehouseId]['delivery_coefficient']
                ?? $slotFacts[$warehouseId]['coefficient']
                ?? $tariffFacts[$warehouseId]['delivery_coefficient']
                ?? 1.0;
            $acceptanceCoefficient = $constraintAcceptanceCoefficient
                ?? $slotFacts[$warehouseId]['coefficient']
                ?? $inventoryCoefficients[$warehouseId]
                ?? 1.0;
            $storageCoefficient = $constraintStorageCoefficient
                ?? $slotFacts[$warehouseId]['storage_coefficient']
                ?? $tariffFacts[$warehouseId]['storage_coefficient']
                ?? 1.0;
            $slotDate = $slotFacts[$warehouseId]['date'] ?? null;
            $slotLeadTimeDays = $slotFacts[$warehouseId]['lead_time_days'] ?? $this->slotLeadTimeDays($slotDate);
            $slotLeadTimeScore = $this->slotLeadTimeScore($slotLeadTimeDays);
            $speedScore = $this->deliverySpeedScore($coefficient, $slotLeadTimeDays);
            $costScore = 100 / max(1.0, max((float) $storageCoefficient, (float) $acceptanceCoefficient));
            $capacityScore = $this->capacityScore($slotFacts[$warehouseId]['capacity_remaining'] ?? null, $qty);
            $regionalDemandFitScore = $this->regionalDemandFitScore($line, $explain);
            $abcPriorityScore = $this->abcPriorityScore($abc);
            $oosUrgencyScore = $this->oosUrgencyScore($risk, (float) ($line['priority_score'] ?? 0));
            $scoreWeights = $this->scoreWeightsByAbc($abc);
            $coefficientPenalty = $this->coefficientPenalty($acceptanceCoefficient, $storageCoefficient);
            $demandClosureScore = $this->demandClosureScore(
                speedScore: $speedScore,
                regionalDemandFitScore: $regionalDemandFitScore,
                abc: $abc,
                risk: $risk,
                priorityScore: (float) ($line['priority_score'] ?? 0),
                coefficientPenalty: $coefficientPenalty,
                capacityScore: $capacityScore,
            );
            $abcSpeedCostAdjustment = $this->abcSpeedCostAdjustment($abc, $speedScore, $demandClosureScore, $coefficientPenalty, $costScore);
            $score = $this->calculateWarehouseScore(
                speedScore: $speedScore,
                costScore: $costScore,
                capacityScore: $capacityScore,
                demandClosureScore: $demandClosureScore,
                abcPriorityScore: $abcPriorityScore,
                oosUrgencyScore: $oosUrgencyScore,
                coefficientPenalty: $coefficientPenalty,
                priorityScore: (float) ($line['priority_score'] ?? 0),
                abc: $abc,
                abcWeight: $this->abcWeight($abc),
                riskWeight: $this->riskWeight($risk),
                abcSpeedCostAdjustment: $abcSpeedCostAdjustment,
            );
            $priorityReasons = $this->linePriorityReasons(
                abc: $abc,
                speedScore: $speedScore,
                demandClosureScore: $demandClosureScore,
                costScore: $costScore,
                coefficientPenalty: $coefficientPenalty,
                capacityScore: $capacityScore,
                risk: $risk,
                regionalDemandFitScore: $regionalDemandFitScore,
                slotLeadTimeDays: $slotLeadTimeDays,
            );
            $abcPolicyStatus = $this->lineAbcPolicyStatus(
                abc: $abc,
                speedScore: $speedScore,
                costScore: $costScore,
                coefficientPenalty: $coefficientPenalty,
                risk: $risk,
            );

            $explain['territorial'] = [
                'marketplace' => 'Wildberries',
                'score' => $score,
                'score_label' => "территориальный балл {$score}",
                'warehouse_id' => $warehouseId,
                'warehouse_name' => $line['warehouse_name'] ?? null,
                'abc_priority' => $abc,
                'delivery_speed_score' => round($speedScore, 2),
                'nearest_slot_date' => $slotDate,
                'slot_lead_time_days' => $slotLeadTimeDays,
                'slot_lead_time_score' => round($slotLeadTimeScore, 2),
                'regional_demand_closure_score' => round($demandClosureScore, 2),
                'cost_score' => round($costScore, 2),
                'capacity_score' => round($capacityScore, 2),
                'regional_demand_fit_score' => round($regionalDemandFitScore, 2),
                'abc_priority_score' => round($abcPriorityScore, 2),
                'oos_urgency_score' => round($oosUrgencyScore, 2),
                'coefficient_penalty' => round($coefficientPenalty, 2),
                'abc_speed_cost_adjustment' => round($abcSpeedCostAdjustment, 2),
                'speed_weight' => $scoreWeights['speed'],
                'cost_weight' => $scoreWeights['cost'],
                'capacity_weight' => $scoreWeights['capacity'],
                'oos_weight' => $scoreWeights['oos'],
                'priority_weight' => $scoreWeights['priority'],
                'a_item_speed_policy' => $abc === 'A' ? 'быстрый склад важнее стоимости' : null,
                'delivery_coefficient' => $coefficient,
                'warehouse_coefficient' => $acceptanceCoefficient,
                'storage_coefficient' => $storageCoefficient,
                'logistics_coefficient' => $this->constraintDetailCoefficient($explain, 'logistics_coefficient'),
                'capacity_remaining' => $slotFacts[$warehouseId]['capacity_remaining'] ?? null,
                'constraint_source' => $this->constraintCoefficient($explain) !== null ? 'файл ограничений' : null,
                'rank_reason' => $this->lineRankReason($abc, $speedScore, $costScore, $coefficientPenalty, $capacityScore, $risk),
                'abc_policy_status' => $abcPolicyStatus,
                'priority_reasons' => $priorityReasons,
                'action_status' => $this->lineActionStatus($score, $coefficientPenalty, $capacityScore, $risk, $abc),
                'decision_ru' => $this->lineDecisionText(
                    warehouseName: (string) ($line['warehouse_name'] ?? $warehouseId),
                    abc: $abc,
                    score: $score,
                    qty: $qty,
                    policyStatus: $abcPolicyStatus,
                    priorityReasons: $priorityReasons,
                ),
                'recommended_action_ru' => $this->lineRecommendedAction($score, $coefficientPenalty, $capacityScore, $risk),
                'why' => $abc === 'A'
                    ? 'A-товар: быстрый склад получает повышенный приоритет, чтобы быстрее закрыть региональный спрос.'
                    : 'Склад оценён по скорости доставки, коэффициентам, стоимости и риску отсутствия товара.',
            ];
            $line['explain_json'] = $this->encodeExplain($explain);
        }
        unset($line);

        return $lines;
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private function enrichOzonLines(array $lines): array
    {
        foreach ($lines as &$line) {
            $clusterId = isset($line['cluster_id']) ? (string) $line['cluster_id'] : '';
            if ($clusterId === '') {
                continue;
            }

            $explain = $this->decodeExplain($line);
            $abc = $this->normalizeAbcPriority($explain['inputs']['abc_priority'] ?? 'C');
            $risk = (string) ($line['risk_level'] ?? 'low');
            $avgDelivery = (float) ($explain['ozon_analytics']['avg_delivery_time'] ?? 0);
            $lostProfit = (float) ($explain['ozon_analytics']['lost_profit'] ?? 0);
            $speedScore = $avgDelivery > 0 ? 100 / max(1.0, $avgDelivery / 24) : 50;
            $localityScore = $this->ozonLocalityScore((float) ($line['expected_savings_rub'] ?? 0), $lostProfit, (int) ($line['qty_rounded'] ?? 0));
            $regionalDemandFitScore = $this->regionalDemandFitScore($line, $explain);
            $constraintPenalty = $this->ozonConstraintPenalty($explain);
            $inTransitRelief = $this->ozonInTransitRelief($line, $explain);
            $abcPriorityScore = $this->abcPriorityScore($abc);
            $oosUrgencyScore = $this->oosUrgencyScore($risk, (float) ($line['priority_score'] ?? 0));
            $demandClosureScore = $this->ozonDemandClosureScore(
                speedScore: $speedScore,
                localityScore: $localityScore,
                regionalDemandFitScore: $regionalDemandFitScore,
                abc: $abc,
                risk: $risk,
                lostProfit: $lostProfit,
                constraintPenalty: $constraintPenalty,
                inTransitRelief: $inTransitRelief,
            );
            $score = $this->calculateOzonClusterScore(
                speedScore: $speedScore,
                localityScore: $localityScore,
                demandClosureScore: $demandClosureScore,
                abcPriorityScore: $abcPriorityScore,
                oosUrgencyScore: $oosUrgencyScore,
                lostProfit: $lostProfit,
                constraintPenalty: $constraintPenalty,
                inTransitRelief: $inTransitRelief,
            );
            $priorityReasons = $this->ozonLinePriorityReasons(
                abc: $abc,
                speedScore: $speedScore,
                demandClosureScore: $demandClosureScore,
                localityScore: $localityScore,
                lostProfit: $lostProfit,
                constraintPenalty: $constraintPenalty,
                inTransitRelief: $inTransitRelief,
                risk: $risk,
            );
            $abcPolicyStatus = $this->ozonLinePolicyStatus(
                abc: $abc,
                speedScore: $speedScore,
                demandClosureScore: $demandClosureScore,
                constraintPenalty: $constraintPenalty,
                inTransitRelief: $inTransitRelief,
                risk: $risk,
            );

            $explain['territorial'] = [
                'marketplace' => 'Ozon',
                'score' => $score,
                'score_label' => "территориальный балл {$score}",
                'cluster_id' => $clusterId,
                'cluster_name' => $line['cluster_name'] ?? null,
                'abc_priority' => $abc,
                'delivery_speed_score' => round($speedScore, 2),
                'regional_demand_closure_score' => round($demandClosureScore, 2),
                'locality_score' => round($localityScore, 2),
                'regional_demand_fit_score' => round($regionalDemandFitScore, 2),
                'abc_priority_score' => round($abcPriorityScore, 2),
                'oos_urgency_score' => round($oosUrgencyScore, 2),
                'constraint_penalty' => round($constraintPenalty, 2),
                'in_transit_relief' => round($inTransitRelief, 2),
                'lost_profit' => round($lostProfit, 2),
                'rank_reason' => $this->ozonLineRankReason($abc, $speedScore, $localityScore, $lostProfit, $constraintPenalty, $inTransitRelief, $risk),
                'abc_policy_status' => $abcPolicyStatus,
                'priority_reasons' => $priorityReasons,
                'decision_ru' => $this->ozonLineDecisionText(
                    clusterName: (string) ($line['cluster_name'] ?? $clusterId),
                    abc: $abc,
                    score: $score,
                    qty: (int) ($line['qty_rounded'] ?? 0),
                    policyStatus: $abcPolicyStatus,
                    priorityReasons: $priorityReasons,
                ),
                'recommended_action_ru' => $this->ozonLineRecommendedAction($score, $constraintPenalty, $inTransitRelief, $risk),
                'why' => 'Кластер оценён по скорости доставки, локальности, ABC, риску отсутствия товара, ограничениям и потенциальной потере маржи.',
            ];
            $line['explain_json'] = $this->encodeExplain($explain);
        }
        unset($line);

        return $lines;
    }

    /**
     * @param list<array<string, mixed>> $rankedItems
     * @return array<string, mixed>
     */
    private function calculateKtr(array $rankedItems, float $targetValue = 80.0, ?float $baselineValue = null): array
    {
        $totalQty = array_sum(array_map(static fn (array $item): int => (int) ($item['qty'] ?? 0), $rankedItems));
        if ($totalQty <= 0) {
            return [
                'value' => 0.0,
                'label' => 'КТР 0%',
                'target_value' => $targetValue,
                'baseline_value' => $baselineValue,
                'explanation' => 'Нет количества для расчёта территориального распределения.',
            ];
        }

        $weightedScore = 0.0;
        foreach ($rankedItems as $item) {
            $weightedScore += (float) ($item['score'] ?? 0) * (int) ($item['qty'] ?? 0);
        }

        $value = max(0.0, min(100.0, round($weightedScore / $totalQty, 2)));
        $priorityQty = 0;
        $excellentQty = 0;
        $financialWeightTotal = 0.0;
        $financialWeightedScore = 0.0;
        $financialPriorityWeight = 0.0;
        $financialExcellentWeight = 0.0;
        $abcAQty = 0;
        $abcAInPriorityQty = 0;
        $abcAFastQty = 0;
        $highRiskQty = 0;
        $highRiskInPriorityQty = 0;
        $highRiskFastQty = 0;
        $coefficientLimitedQty = 0;
        $distribution = [
            'отлично' => ['qty' => 0, 'percent' => 0.0],
            'хорошо' => ['qty' => 0, 'percent' => 0.0],
            'средне' => ['qty' => 0, 'percent' => 0.0],
            'требует улучшения' => ['qty' => 0, 'percent' => 0.0],
        ];
        foreach ($rankedItems as $item) {
            $qty = (int) ($item['qty'] ?? 0);
            $score = (float) ($item['score'] ?? 0);
            $financialWeight = max(0.0, (float) ($item['financial_weight'] ?? 0));
            $grade = $this->gradeForScore($score);
            $distribution[$grade]['qty'] += $qty;
            $financialWeightTotal += $financialWeight;
            $financialWeightedScore += $score * $financialWeight;
            if ($score >= 75) {
                $priorityQty += $qty;
                $financialPriorityWeight += $financialWeight;
            }
            if ($score >= 90) {
                $excellentQty += $qty;
                $financialExcellentWeight += $financialWeight;
            }
            $itemAbcAQty = (int) ($item['abc_a_qty'] ?? 0);
            $itemHighRiskQty = (int) ($item['high_risk_qty'] ?? 0);
            $abcAQty += $itemAbcAQty;
            $highRiskQty += $itemHighRiskQty;
            if ($score >= 75) {
                $abcAInPriorityQty += $itemAbcAQty;
                $highRiskInPriorityQty += $itemHighRiskQty;
            }
            if ((float) ($item['demand_closure_score'] ?? 0) >= 80 || ! empty($item['is_fast_for_a_items'])) {
                $abcAFastQty += $itemAbcAQty;
                $highRiskFastQty += $itemHighRiskQty;
            }
            $coefficientLimitedQty += (int) ($item['coefficient_limited_qty'] ?? 0);
        }

        foreach ($distribution as &$bucket) {
            $bucket['percent'] = round($bucket['qty'] / $totalQty * 100, 2);
        }
        unset($bucket);

        $targetGap = round(max(0.0, $targetValue - $value), 2);
        $financialValue = $financialWeightTotal > 0 ? max(0.0, min(100.0, round($financialWeightedScore / $financialWeightTotal, 2))) : null;
        $financialTargetGap = $financialValue !== null ? round(max(0.0, $targetValue - $financialValue), 2) : null;
        $financialPriorityShare = $financialWeightTotal > 0 ? round($financialPriorityWeight / $financialWeightTotal * 100, 2) : null;
        $financialExcellentShare = $financialWeightTotal > 0 ? round($financialExcellentWeight / $financialWeightTotal * 100, 2) : null;
        $targetPriorityQty = (int) ceil($totalQty * ($targetValue / 100));
        $needPriorityQty = max(0, $targetPriorityQty - $priorityQty);
        $baselineGap = $baselineValue !== null ? round($targetValue - $baselineValue, 2) : null;
        $improvementVsBaseline = $baselineValue !== null ? round($value - $baselineValue, 2) : null;
        $slowItems = array_values(array_filter(
            $rankedItems,
            static fn (array $item): bool => (float) ($item['score'] ?? 0) < 75 && (int) ($item['qty'] ?? 0) > 0
        ));
        usort($slowItems, static fn (array $a, array $b): int => ((float) ($a['score'] ?? 0)) <=> ((float) ($b['score'] ?? 0)));

        $priorityItems = array_values(array_filter(
            $rankedItems,
            static fn (array $item): bool => (float) ($item['score'] ?? 0) >= 75 && (int) ($item['qty'] ?? 0) > 0
        ));
        usort($priorityItems, static fn (array $a, array $b): int => ((float) ($b['score'] ?? 0)) <=> ((float) ($a['score'] ?? 0)));
        $fastItems = $this->fastTerritorialDestinations($rankedItems);
        $aProtectionFrom = $this->firstDestinationNeedingProtection($rankedItems, 'abc_a_qty');
        $aProtectionTo = $this->bestProtectionDestination($fastItems, $aProtectionFrom);
        $highRiskProtectionFrom = $this->firstDestinationNeedingProtection($rankedItems, 'high_risk_qty');
        $highRiskProtectionTo = $this->bestProtectionDestination($fastItems, $highRiskProtectionFrom);

        $recommendedMoveQty = $needPriorityQty > 0 && $slowItems !== [] && $priorityItems !== []
            ? min($needPriorityQty, (int) ($slowItems[0]['qty'] ?? 0))
            : 0;
        $recommendedFrom = $slowItems[0] ?? null;
        $recommendedTo = $priorityItems[0] ?? null;
        $projectedValue = $value;
        $projectedUplift = 0.0;
        if ($recommendedMoveQty > 0 && $recommendedFrom !== null && $recommendedTo !== null) {
            $fromScore = (float) ($recommendedFrom['score'] ?? 0);
            $toScore = (float) ($recommendedTo['score'] ?? 0);
            if ($toScore > $fromScore) {
                $projectedWeightedScore = $weightedScore - ($fromScore * $recommendedMoveQty) + ($toScore * $recommendedMoveQty);
                $projectedValue = max(0.0, min(100.0, round($projectedWeightedScore / $totalQty, 2)));
                $projectedUplift = round($projectedValue - $value, 2);
            }
        }
        $projectedTargetGap = round(max(0.0, $targetValue - $projectedValue), 2);
        $improvementActions = $this->buildKtrImprovementActions(
            slowItems: $slowItems,
            priorityItems: $priorityItems,
            needPriorityQty: $needPriorityQty,
            recommendedMoveQty: $recommendedMoveQty,
            abcAQty: $abcAQty,
            abcAFastQty: $abcAFastQty,
            highRiskQty: $highRiskQty,
            highRiskFastQty: $highRiskFastQty,
            coefficientLimitedQty: $coefficientLimitedQty,
            totalQty: $totalQty,
            aProtectionFrom: $aProtectionFrom,
            aProtectionTo: $aProtectionTo,
            highRiskProtectionFrom: $highRiskProtectionFrom,
            highRiskProtectionTo: $highRiskProtectionTo,
        );
        if (($improvementActions[0]['type'] ?? null) === 'move_to_priority') {
            $improvementActions[0]['current_ktr'] = $value;
            $improvementActions[0]['projected_ktr_after_action'] = $projectedValue;
            $improvementActions[0]['expected_ktr_uplift_pp'] = $projectedUplift;
            $improvementActions[0]['projected_target_gap_pp'] = $projectedTargetGap;
        }
        $operationalPlan = $this->buildKtrOperationalPlan(
            value: $value,
            targetValue: $targetValue,
            targetGap: $targetGap,
            projectedValue: $projectedValue,
            projectedUplift: $projectedUplift,
            projectedTargetGap: $projectedTargetGap,
            totalQty: $totalQty,
            priorityQty: $priorityQty,
            targetPriorityQty: $targetPriorityQty,
            needPriorityQty: $needPriorityQty,
            recommendedMoveQty: $recommendedMoveQty,
            abcAQty: $abcAQty,
            abcAFastQty: $abcAFastQty,
            highRiskQty: $highRiskQty,
            highRiskFastQty: $highRiskFastQty,
            coefficientLimitedQty: $coefficientLimitedQty,
            recommendedFrom: $recommendedFrom,
            recommendedTo: $recommendedTo,
            aProtectionFrom: $aProtectionFrom,
            aProtectionTo: $aProtectionTo,
            highRiskProtectionFrom: $highRiskProtectionFrom,
            highRiskProtectionTo: $highRiskProtectionTo,
        );
        $fixation = $this->buildKtrFixation(
            value: $value,
            targetValue: $targetValue,
            baselineValue: $baselineValue,
            improvementVsBaseline: $improvementVsBaseline,
            targetGap: $targetGap,
            projectedValue: $projectedValue,
            projectedUplift: $projectedUplift,
            firstAction: $operationalPlan['first_action'] ?? null,
        );

        return [
            'value' => $value,
            'label' => "КТР {$value}%",
            'grade' => $this->gradeForScore($value),
            'status' => $operationalPlan['status'],
            'status_ru' => $operationalPlan['status_ru'],
            'needs_action' => $operationalPlan['needs_action'],
            'decision_ru' => $operationalPlan['decision_ru'],
            'total_qty' => $totalQty,
            'priority_qty' => $priorityQty,
            'target_value' => $targetValue,
            'target_gap_pp' => $targetGap,
            'target_priority_share_percent' => round($targetPriorityQty / $totalQty * 100, 2),
            'baseline_value' => $baselineValue,
            'baseline_gap_pp' => $baselineGap,
            'improvement_vs_baseline_pp' => $improvementVsBaseline,
            'target_priority_qty' => $targetPriorityQty,
            'need_priority_qty' => $needPriorityQty,
            'recommended_move_qty' => $recommendedMoveQty,
            'recommended_from_destination' => $recommendedFrom ? $this->destinationName($recommendedFrom) : null,
            'recommended_to_destination' => $recommendedTo ? $this->destinationName($recommendedTo) : null,
            'projected_value_after_recommendation' => $projectedValue,
            'projected_uplift_pp' => $projectedUplift,
            'projected_target_gap_pp' => $projectedTargetGap,
            'priority_qty_share_percent' => round($priorityQty / $totalQty * 100, 2),
            'excellent_qty_share_percent' => round($excellentQty / $totalQty * 100, 2),
            'financial_value' => $financialValue,
            'financial_label' => $financialValue !== null ? "Финансовый КТР {$financialValue}%" : null,
            'financial_weight_total' => $financialWeightTotal > 0 ? round($financialWeightTotal, 2) : null,
            'financial_target_gap_pp' => $financialTargetGap,
            'financial_priority_share_percent' => $financialPriorityShare,
            'financial_excellent_share_percent' => $financialExcellentShare,
            'financial_policy_status' => $this->financialKtrPolicyStatus($financialValue, $value, $financialPriorityShare),
            'abc_a_priority_share_percent' => $abcAQty > 0 ? round($abcAInPriorityQty / $abcAQty * 100, 2) : null,
            'abc_a_fast_share_percent' => $abcAQty > 0 ? round($abcAFastQty / $abcAQty * 100, 2) : null,
            'high_risk_priority_share_percent' => $highRiskQty > 0 ? round($highRiskInPriorityQty / $highRiskQty * 100, 2) : null,
            'high_risk_fast_share_percent' => $highRiskQty > 0 ? round($highRiskFastQty / $highRiskQty * 100, 2) : null,
            'coefficient_limited_qty_share_percent' => round($coefficientLimitedQty / $totalQty * 100, 2),
            'a_items_policy_status' => $abcAQty <= 0
                ? 'нет A-товаров в плане'
                : ($abcAFastQty / max(1, $abcAQty) >= 0.8 ? 'A-товары в основном ведутся в быстрые направления' : 'часть A-товаров нужно перенести в более быстрые направления'),
            'oos_policy_status' => $highRiskQty <= 0
                ? 'нет строк с высоким риском отсутствия'
                : ($highRiskFastQty / max(1, $highRiskQty) >= 0.75 ? 'товары с высоким риском отсутствия в основном закрываются быстрыми направлениями' : 'часть товаров с высоким риском отсутствия нужно перенести в быстрые направления'),
            'constraint_policy_status' => $coefficientLimitedQty > 0
                ? 'часть количества идёт в направления с дорогими коэффициентами или ограничениями'
                : 'сильных коэффициентных ограничений не найдено',
            'best_destinations' => array_map(fn (array $item): array => [
                'name' => $this->destinationName($item),
                'qty' => (int) ($item['qty'] ?? 0),
                'score' => round((float) ($item['score'] ?? 0), 2),
                'rank_reason' => $item['rank_reason'] ?? null,
            ], array_slice($priorityItems, 0, 3)),
            'weak_destinations' => array_map(fn (array $item): array => [
                'name' => $this->destinationName($item),
                'qty' => (int) ($item['qty'] ?? 0),
                'score' => round((float) ($item['score'] ?? 0), 2),
                'rank_reason' => $item['rank_reason'] ?? null,
            ], array_slice($slowItems, 0, 3)),
            'fixation' => $fixation,
            'control_loop' => [
                'version' => 'ktr-control-loop-1',
                'current_value' => $value,
                'fixed_baseline_value' => $baselineValue,
                'target_value' => $targetValue,
                'projected_value_after_first_action' => $projectedValue,
                'projected_uplift_pp' => $projectedUplift,
                'state_ru' => $fixation['state_ru'],
                'next_action_ru' => $fixation['next_action_ru'],
            ],
            'operational_plan' => $operationalPlan,
            'improvement_actions' => $improvementActions,
            'distribution_by_grade' => $distribution,
            'metric_version' => 'ktr-4',
            'formula_label' => 'КТР = средневзвешенный балл направлений по количеству: скорость закрытия спроса + стоимость/коэффициенты + ёмкость + ABC + риск отсутствия товара + ограничения',
            'financial_formula_label' => 'Финансовый КТР = тот же территориальный балл, но взвешенный по ожидаемой выручке/стоимости строк, чтобы дорогие товары сильнее влияли на оценку.',
            'explanation' => 'КТР — текущий коэффициент территориального распределения: доля и качество количества, которое план ведёт в приоритетные склады/кластеры с учётом скорости, стоимости, ABC и риска отсутствия товара.',
            'interpretation' => $targetGap <= 0
                ? 'Распределение уже достигает целевого уровня.'
                : "До целевого КТР {$targetValue}% не хватает {$targetGap} п.п.; ориентир — перенести ещё {$needPriorityQty} шт. в приоритетные направления.",
            'how_to_improve' => $targetGap <= 0
                ? 'Распределение близко к целевому: можно проверять ограничения и экономику.'
                : ($recommendedMoveQty > 0
                    ? "Чтобы улучшить КТР, начните с переноса {$recommendedMoveQty} шт. из \"{$this->destinationName($recommendedFrom)}\" в \"{$this->destinationName($recommendedTo)}\": прогноз КТР {$projectedValue}% (+{$projectedUplift} п.п.)."
                    : 'Чтобы улучшить КТР, перенесите больше количества в быстрые склады/кластеры, особенно для A-товаров и товаров с высоким риском отсутствия.'),
        ];
    }

    /**
     * КТР должен быть управляемой метрикой: его можно зафиксировать как базу
     * и дальше смотреть, улучшает ли план территориальное распределение.
     *
     * @param array<string, mixed>|null $firstAction
     * @return array<string, mixed>
     */
    private function buildKtrFixation(
        float $value,
        float $targetValue,
        ?float $baselineValue,
        ?float $improvementVsBaseline,
        float $targetGap,
        float $projectedValue,
        float $projectedUplift,
        ?array $firstAction,
    ): array {
        $trackingStatus = match (true) {
            $baselineValue === null => 'not_fixed',
            ($improvementVsBaseline ?? 0) > 0 => 'improved',
            ($improvementVsBaseline ?? 0) < 0 => 'worse',
            default => 'unchanged',
        };
        $stateRu = match ($trackingStatus) {
            'improved' => 'КТР улучшился относительно зафиксированной базы на ' . $this->formatNumberRu((float) $improvementVsBaseline) . ' п.п.',
            'worse' => 'КТР ниже зафиксированной базы на ' . $this->formatNumberRu(abs((float) $improvementVsBaseline)) . ' п.п.',
            'unchanged' => 'КТР равен зафиксированной базе.',
            default => 'КТР ещё не зафиксирован как база сравнения.',
        };

        $firstActionText = is_array($firstAction)
            ? (string) ($firstAction['description_ru'] ?? $firstAction['title_ru'] ?? '')
            : '';
        $nextActionRu = match (true) {
            $baselineValue === null => 'Зафиксируйте текущий КТР как базу, чтобы видеть реальное улучшение следующих планов.',
            $targetGap <= 0 => 'Цель КТР достигнута: можно сохранить результат и контролировать экономику/ограничения.',
            $firstActionText !== '' => $firstActionText,
            $projectedUplift > 0 => "Выполните первое улучшение: прогноз КТР {$projectedValue}% (+{$projectedUplift} п.п.).",
            default => 'Добавьте быстрые направления или пересмотрите ограничения, чтобы поднять КТР до цели.',
        };

        return [
            'version' => 'ktr-fixation-1',
            'can_fix_current_value' => true,
            'current_value' => $value,
            'fixed_baseline_value' => $baselineValue,
            'target_value' => $targetValue,
            'tracking_status' => $trackingStatus,
            'tracking_status_ru' => match ($trackingStatus) {
                'improved' => 'улучшился',
                'worse' => 'ухудшился',
                'unchanged' => 'без изменений',
                default => 'не зафиксирован',
            },
            'improvement_vs_fixed_pp' => $improvementVsBaseline,
            'target_gap_pp' => $targetGap,
            'projected_value_after_first_action' => $projectedValue,
            'projected_uplift_pp' => $projectedUplift,
            'freeze_payload' => [
                'baseline_ktr' => $value,
                'target_ktr' => $targetValue,
            ],
            'state_ru' => $stateRu,
            'next_action_ru' => $nextActionRu,
            'explanation_ru' => 'Фиксация КТР сохраняет текущее территориальное распределение как базу. Следующие планы сравниваются с этой базой, чтобы показывать реальное улучшение или ухудшение.',
        ];
    }

    /**
     * @param array<string, int> $coverage
     */
    private function incrementCoverage(array &$coverage, string $key, bool $matched): void
    {
        if (! $matched) {
            return;
        }

        $coverage[$key] = ($coverage[$key] ?? 0) + 1;
    }

    /**
     * @param array<string, mixed> $destination
     * @param array<string, mixed> $factors
     */
    private function accumulateScoreFactors(array &$destination, array $factors, int $weight): void
    {
        $weight = max(1, $weight);
        $destination['_score_factor_numeric_totals'] ??= [];
        $destination['_score_factor_weights'] ??= [];
        $destination['_score_factor_values'] ??= [];

        foreach ($factors as $key => $value) {
            if (is_numeric($value)) {
                $destination['_score_factor_numeric_totals'][$key] = (float) ($destination['_score_factor_numeric_totals'][$key] ?? 0.0) + (float) $value * $weight;
                $destination['_score_factor_weights'][$key] = (int) ($destination['_score_factor_weights'][$key] ?? 0) + $weight;
                continue;
            }

            if ($value === null || trim((string) $value) === '') {
                continue;
            }

            $textValue = (string) $value;
            $destination['_score_factor_values'][$key][$textValue] = (int) ($destination['_score_factor_values'][$key][$textValue] ?? 0) + $weight;
        }
    }

    /**
     * @param array<string, mixed> $destination
     * @return array<string, mixed>
     */
    private function finalizeScoreFactors(array $destination): array
    {
        $factors = [];
        $numericTotals = is_array($destination['_score_factor_numeric_totals'] ?? null)
            ? $destination['_score_factor_numeric_totals']
            : [];
        $numericWeights = is_array($destination['_score_factor_weights'] ?? null)
            ? $destination['_score_factor_weights']
            : [];
        $textValues = is_array($destination['_score_factor_values'] ?? null)
            ? $destination['_score_factor_values']
            : [];

        foreach ($numericTotals as $key => $total) {
            $weight = max(1, (int) ($numericWeights[$key] ?? 0));
            $factors[$key] = round((float) $total / $weight, 2);
        }

        foreach ($textValues as $key => $values) {
            if (! is_array($values) || $values === []) {
                continue;
            }

            arsort($values);
            $factors[$key] = (string) array_key_first($values);
        }

        return $factors;
    }

    /**
     * @param array<string, int> $coverage
     * @return array<string, mixed>
     */
    private function sourceCoverageSummary(array $coverage, string $marketplace): array
    {
        $total = max(0, (int) ($coverage['lines_total'] ?? 0));
        $metricDefinitions = $marketplace === 'wildberries'
            ? [
                'regional_demand_profile' => 'региональный спрос',
                'speed_source' => 'скорость/коэффициент доставки',
                'cost_source' => 'стоимость и коэффициенты',
                'capacity_source' => 'ёмкость склада',
                'constraint_source' => 'файл ограничений',
                'abc_source' => 'ABC товара',
            ]
            : [
                'regional_demand_profile' => 'региональный спрос',
                'delivery_speed_source' => 'скорость доставки',
                'locality_source' => 'локальность/потеря маржи',
                'constraint_source' => 'файл ограничений',
                'in_transit_source' => 'товары в пути',
                'abc_source' => 'ABC товара',
                'lost_profit_source' => 'упущенная маржа Ozon',
            ];
        $criticalKeys = $marketplace === 'wildberries'
            ? ['regional_demand_profile', 'speed_source', 'cost_source', 'abc_source']
            : ['regional_demand_profile', 'delivery_speed_source', 'locality_source', 'abc_source'];

        $metrics = [];
        foreach ($metricDefinitions as $key => $label) {
            $matched = (int) ($coverage[$key] ?? 0);
            $percent = $total > 0 ? round($matched / $total * 100, 2) : 0.0;
            $metrics[$key] = [
                'label' => $label,
                'matched_lines' => $matched,
                'total_lines' => $total,
                'coverage_percent' => $percent,
            ];
        }

        $criticalPercents = array_map(
            static fn (string $key): float => (float) ($metrics[$key]['coverage_percent'] ?? 0.0),
            $criticalKeys
        );
        $criticalAverage = count($criticalPercents) > 0
            ? round(array_sum($criticalPercents) / count($criticalPercents), 2)
            : 0.0;
        $status = match (true) {
            $total <= 0 => 'bad',
            $criticalAverage >= 75 => 'good',
            $criticalAverage >= 45 => 'warning',
            default => 'bad',
        };

        $reasons = [];
        foreach ($criticalKeys as $key) {
            $metric = $metrics[$key] ?? null;
            if ($metric !== null && (float) $metric['coverage_percent'] < 50) {
                $reasons[] = 'мало данных: ' . $metric['label'] . ' покрывает ' . $metric['coverage_percent'] . '% строк';
            }
        }
        if ($reasons === []) {
            $reasons[] = 'источники территориального ранжирования покрывают достаточно строк';
        }

        return [
            'version' => 'territorial-source-coverage-1',
            'status' => $status,
            'human_status' => match ($status) {
                'good' => 'Территориальное ранжирование достаточно надёжно',
                'warning' => 'Территориальное ранжирование частично ограничено источниками',
                default => 'Территориальное ранжирование низкой достоверности',
            },
            'lines_total' => $total,
            'critical_coverage_percent' => $criticalAverage,
            'metrics' => $metrics,
            'reasons' => $reasons,
        ];
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, mixed> $explain
     */
    private function hasRegionalDemandProfile(array $line, array $explain): bool
    {
        $profile = $explain['regional_demand']
            ?? $line['regional_demand']
            ?? null;

        if (! is_array($profile)) {
            return false;
        }

        foreach (['sales_profile', 'delivery_fo_profile', 'by_delivery_fo', 'warehouse_sales_profile', 'clusters_summary', 'demand_profile', 'stock_profile'] as $key) {
            if (! empty($profile[$key]) && is_array($profile[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $explain
     */
    private function hasConstraintSource(array $explain): bool
    {
        $constraints = is_array($explain['constraints'] ?? null) ? $explain['constraints'] : [];

        return (bool) ($constraints['applied'] ?? false)
            || ($constraints['source'] ?? null) !== null
            || ($constraints['coefficient'] ?? null) !== null
            || ($constraints['acceptance_coefficient'] ?? null) !== null
            || ($constraints['delivery_coefficient'] ?? null) !== null
            || ($constraints['storage_coefficient'] ?? null) !== null
            || ($constraints['logistics_coefficient'] ?? null) !== null;
    }

    private function targetKtr(AutoSupplyPlan $plan): float
    {
        $params = is_array($plan->params ?? null) ? $plan->params : [];
        $value = $params['target_ktr'] ?? $params['territorial_target_ktr'] ?? null;

        if (is_numeric($value)) {
            return round(max(1.0, min(100.0, (float) $value)), 2);
        }

        return 80.0;
    }

    private function baselineKtr(AutoSupplyPlan $plan): ?float
    {
        $params = is_array($plan->params ?? null) ? $plan->params : [];
        $value = $params['baseline_ktr'] ?? $params['fixed_ktr'] ?? $params['current_ktr'] ?? null;

        if (is_numeric($value)) {
            return round(max(0.0, min(100.0, (float) $value)), 2);
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $slowItems
     * @param list<array<string, mixed>> $priorityItems
     * @return list<array<string, mixed>>
     */
    private function buildKtrImprovementActions(
        array $slowItems,
        array $priorityItems,
        int $needPriorityQty,
        int $recommendedMoveQty,
        int $abcAQty,
        int $abcAFastQty,
        int $highRiskQty,
        int $highRiskFastQty,
        int $coefficientLimitedQty,
        int $totalQty,
        ?array $aProtectionFrom = null,
        ?array $aProtectionTo = null,
        ?array $highRiskProtectionFrom = null,
        ?array $highRiskProtectionTo = null,
    ): array {
        $actions = [];

        if ($needPriorityQty > 0) {
            $from = $slowItems[0] ?? null;
            $to = $priorityItems[0] ?? null;
            $actions[] = [
                'type' => 'move_to_priority',
                'title' => 'Перенести количество в приоритетные направления',
                'description' => $from && $to
                    ? 'Начните с переноса из "' . $this->destinationName($from) . '" в "' . $this->destinationName($to) . '": так быстрее подтянется КТР.'
                    : 'В плане мало количества в приоритетных направлениях: добавьте быстрые склады/кластеры или снимите лишние ограничения.',
                'qty' => $recommendedMoveQty > 0 ? $recommendedMoveQty : $needPriorityQty,
                'from' => $from ? $this->destinationName($from) : null,
                'to' => $to ? $this->destinationName($to) : null,
                'priority' => 'high',
            ];
        }

        if ($abcAQty > 0 && ($abcAFastQty / max(1, $abcAQty)) < 0.8) {
            $neededQty = max(0, (int) ceil($abcAQty * 0.8) - $abcAFastQty);
            $routeQty = $this->protectedMoveQty($aProtectionFrom, $neededQty, 'abc_a_qty');
            $uplift = $this->ktrMoveUplift($aProtectionFrom, $aProtectionTo, $routeQty, $totalQty);
            $actions[] = [
                'type' => 'protect_a_items',
                'title' => 'A-товары вести в быстрые склады/кластеры',
                'description' => $aProtectionFrom && $aProtectionTo
                    ? 'Перенесите A-товары из "' . $this->destinationName($aProtectionFrom) . '" в "' . $this->destinationName($aProtectionTo) . '": скорость закрытия спроса важнее небольшой экономии на коэффициентах.'
                    : 'Для A-товаров скорость закрытия регионального спроса важнее небольшой экономии на коэффициентах.',
                'qty' => $routeQty > 0 ? $routeQty : $neededQty,
                'from' => $aProtectionFrom ? $this->destinationName($aProtectionFrom) : null,
                'to' => $aProtectionTo ? $this->destinationName($aProtectionTo) : null,
                'expected_ktr_uplift_pp' => $uplift,
                'priority' => 'high',
            ];
        }

        if ($highRiskQty > 0 && ($highRiskFastQty / max(1, $highRiskQty)) < 0.75) {
            $neededQty = max(0, (int) ceil($highRiskQty * 0.75) - $highRiskFastQty);
            $routeQty = $this->protectedMoveQty($highRiskProtectionFrom, $neededQty, 'high_risk_qty');
            $uplift = $this->ktrMoveUplift($highRiskProtectionFrom, $highRiskProtectionTo, $routeQty, $totalQty);
            $actions[] = [
                'type' => 'protect_oos',
                'title' => 'Высокий риск отсутствия закрывать быстрыми направлениями',
                'description' => $highRiskProtectionFrom && $highRiskProtectionTo
                    ? 'Перенесите количество с высоким риском отсутствия товара из "' . $this->destinationName($highRiskProtectionFrom) . '" в "' . $this->destinationName($highRiskProtectionTo) . '", чтобы быстрее закрыть риск отсутствия.'
                    : 'Строки с высоким риском отсутствия товара должны получать быстрый склад/кластер, иначе план сохраняет риск упущенной выручки.',
                'qty' => $routeQty > 0 ? $routeQty : $neededQty,
                'from' => $highRiskProtectionFrom ? $this->destinationName($highRiskProtectionFrom) : null,
                'to' => $highRiskProtectionTo ? $this->destinationName($highRiskProtectionTo) : null,
                'expected_ktr_uplift_pp' => $uplift,
                'priority' => 'high',
            ];
        }

        if ($coefficientLimitedQty > 0) {
            $actions[] = [
                'type' => 'check_constraints',
                'title' => 'Проверить дорогие коэффициенты и ограничения',
                'description' => 'Часть количества попала в направления с ограничениями или дорогими коэффициентами. Перед поставкой стоит проверить файл лимитов и альтернативные направления.',
                'qty' => $coefficientLimitedQty,
                'priority' => 'medium',
            ];
        }

        return array_slice($actions, 0, 4);
    }

    /**
     * Операционный слой КТР: не просто "сколько получилось", а что делать дальше.
     *
     * @param array<string, mixed>|null $recommendedFrom
     * @param array<string, mixed>|null $recommendedTo
     * @return array<string, mixed>
     */
    private function buildKtrOperationalPlan(
        float $value,
        float $targetValue,
        float $targetGap,
        float $projectedValue,
        float $projectedUplift,
        float $projectedTargetGap,
        int $totalQty,
        int $priorityQty,
        int $targetPriorityQty,
        int $needPriorityQty,
        int $recommendedMoveQty,
        int $abcAQty,
        int $abcAFastQty,
        int $highRiskQty,
        int $highRiskFastQty,
        int $coefficientLimitedQty,
        ?array $recommendedFrom,
        ?array $recommendedTo,
        ?array $aProtectionFrom = null,
        ?array $aProtectionTo = null,
        ?array $highRiskProtectionFrom = null,
        ?array $highRiskProtectionTo = null,
    ): array {
        $abcAFastShare = $abcAQty > 0 ? round($abcAFastQty / max(1, $abcAQty) * 100, 2) : null;
        $highRiskFastShare = $highRiskQty > 0 ? round($highRiskFastQty / max(1, $highRiskQty) * 100, 2) : null;
        $coefficientLimitedShare = round($coefficientLimitedQty / max(1, $totalQty) * 100, 2);
        $priorityShare = round($priorityQty / max(1, $totalQty) * 100, 2);
        $targetPriorityShare = round($targetPriorityQty / max(1, $totalQty) * 100, 2);

        $status = match (true) {
            $targetGap <= 0 && ($abcAFastShare === null || $abcAFastShare >= 80) && ($highRiskFastShare === null || $highRiskFastShare >= 75) => 'good',
            $targetGap <= 10 || $recommendedMoveQty > 0 => 'warning',
            default => 'bad',
        };
        $needsAction = $status !== 'good';

        $firstAction = null;
        if ($recommendedMoveQty > 0 && $recommendedFrom !== null && $recommendedTo !== null) {
            $firstAction = [
                'type' => 'move_to_priority',
                'title_ru' => 'Сначала перенести объём в лучшее направление',
                'description_ru' => 'Перенесите ' . $recommendedMoveQty . ' шт. из «'
                    . $this->destinationName($recommendedFrom) . '» в «'
                    . $this->destinationName($recommendedTo) . '».',
                'qty' => $recommendedMoveQty,
                'from' => [
                    'name' => $this->destinationName($recommendedFrom),
                    'score' => round((float) ($recommendedFrom['score'] ?? 0), 2),
                    'qty' => (int) ($recommendedFrom['qty'] ?? 0),
                ],
                'to' => [
                    'name' => $this->destinationName($recommendedTo),
                    'score' => round((float) ($recommendedTo['score'] ?? 0), 2),
                    'qty' => (int) ($recommendedTo['qty'] ?? 0),
                ],
                'expected_result_ru' => "прогноз КТР {$projectedValue}% (+{$projectedUplift} п.п.)",
            ];
        } elseif ($abcAQty > 0 && ($abcAFastShare ?? 100.0) < 80) {
            $qty = max(0, (int) ceil($abcAQty * 0.8) - $abcAFastQty);
            $routeQty = $this->protectedMoveQty($aProtectionFrom, $qty, 'abc_a_qty');
            $firstAction = [
                'type' => 'protect_a_items',
                'title_ru' => 'Сначала защитить A-товары быстрыми направлениями',
                'description_ru' => $aProtectionFrom && $aProtectionTo
                    ? 'Перенесите ' . ($routeQty > 0 ? $routeQty : $qty) . ' шт. A-товаров из «'
                        . $this->destinationName($aProtectionFrom) . '» в «'
                        . $this->destinationName($aProtectionTo) . '».'
                    : 'Для A-товаров скорость закрытия спроса важнее небольшой экономии на коэффициентах.',
                'qty' => $routeQty > 0 ? $routeQty : $qty,
                'from' => $aProtectionFrom ? [
                    'name' => $this->destinationName($aProtectionFrom),
                    'score' => round((float) ($aProtectionFrom['score'] ?? 0), 2),
                    'qty' => (int) ($aProtectionFrom['qty'] ?? 0),
                ] : null,
                'to' => $aProtectionTo ? [
                    'name' => $this->destinationName($aProtectionTo),
                    'score' => round((float) ($aProtectionTo['score'] ?? 0), 2),
                    'qty' => (int) ($aProtectionTo['qty'] ?? 0),
                ] : null,
                'expected_result_ru' => $aProtectionFrom && $aProtectionTo
                    ? 'ожидаемый вклад в КТР +' . $this->ktrMoveUplift($aProtectionFrom, $aProtectionTo, $routeQty > 0 ? $routeQty : $qty, $totalQty) . ' п.п.'
                    : null,
            ];
        } elseif ($highRiskQty > 0 && ($highRiskFastShare ?? 100.0) < 75) {
            $qty = max(0, (int) ceil($highRiskQty * 0.75) - $highRiskFastQty);
            $routeQty = $this->protectedMoveQty($highRiskProtectionFrom, $qty, 'high_risk_qty');
            $firstAction = [
                'type' => 'protect_oos',
                'title_ru' => 'Сначала закрыть высокий риск отсутствия',
                'description_ru' => $highRiskProtectionFrom && $highRiskProtectionTo
                    ? 'Перенесите ' . ($routeQty > 0 ? $routeQty : $qty) . ' шт. количества с высоким риском отсутствия из «'
                        . $this->destinationName($highRiskProtectionFrom) . '» в «'
                        . $this->destinationName($highRiskProtectionTo) . '».'
                    : 'Товары с высоким риском отсутствия нужно вести в быстрые направления.',
                'qty' => $routeQty > 0 ? $routeQty : $qty,
                'from' => $highRiskProtectionFrom ? [
                    'name' => $this->destinationName($highRiskProtectionFrom),
                    'score' => round((float) ($highRiskProtectionFrom['score'] ?? 0), 2),
                    'qty' => (int) ($highRiskProtectionFrom['qty'] ?? 0),
                ] : null,
                'to' => $highRiskProtectionTo ? [
                    'name' => $this->destinationName($highRiskProtectionTo),
                    'score' => round((float) ($highRiskProtectionTo['score'] ?? 0), 2),
                    'qty' => (int) ($highRiskProtectionTo['qty'] ?? 0),
                ] : null,
                'expected_result_ru' => $highRiskProtectionFrom && $highRiskProtectionTo
                    ? 'ожидаемый вклад в КТР +' . $this->ktrMoveUplift($highRiskProtectionFrom, $highRiskProtectionTo, $routeQty > 0 ? $routeQty : $qty, $totalQty) . ' п.п.'
                    : null,
            ];
        } elseif ($coefficientLimitedQty > 0) {
            $firstAction = [
                'type' => 'check_constraints',
                'title_ru' => 'Проверить дорогие коэффициенты и ограничения',
                'description_ru' => 'Часть количества попала в направления с дорогими коэффициентами или ограничениями.',
                'qty' => $coefficientLimitedQty,
            ];
        }

        $rules = [
            [
                'key' => 'target_ktr',
                'label_ru' => 'Цель КТР',
                'status' => $targetGap <= 0 ? 'passed' : 'gap',
                'fact_ru' => "текущий КТР {$value}%, цель {$targetValue}%",
            ],
            [
                'key' => 'priority_share',
                'label_ru' => 'Доля количества в приоритетных направлениях',
                'status' => $needPriorityQty <= 0 ? 'passed' : 'gap',
                'fact_ru' => "{$priorityShare}% сейчас, нужно около {$targetPriorityShare}%",
            ],
            [
                'key' => 'a_items_fast',
                'label_ru' => 'A-товары в быстрых направлениях',
                'status' => $abcAQty <= 0 || ($abcAFastShare ?? 0) >= 80 ? 'passed' : 'gap',
                'fact_ru' => $abcAQty <= 0
                    ? 'A-товаров в плане нет'
                    : "{$abcAFastShare}% A-товаров в быстрых направлениях",
            ],
            [
                'key' => 'oos_fast',
                'label_ru' => 'Высокий риск отсутствия закрывается быстро',
                'status' => $highRiskQty <= 0 || ($highRiskFastShare ?? 0) >= 75 ? 'passed' : 'gap',
                'fact_ru' => $highRiskQty <= 0
                    ? 'строк с высоким риском отсутствия нет'
                    : "{$highRiskFastShare}% количества с высоким риском отсутствия в быстрых направлениях",
            ],
            [
                'key' => 'constraints',
                'label_ru' => 'Ограничения и дорогие коэффициенты',
                'status' => $coefficientLimitedQty <= 0 ? 'passed' : 'warning',
                'fact_ru' => $coefficientLimitedQty <= 0
                    ? 'сильных ограничений не найдено'
                    : "{$coefficientLimitedShare}% количества затронуто дорогими коэффициентами или ограничениями",
            ],
        ];

        return [
            'status' => $status,
            'status_ru' => match ($status) {
                'good' => 'КТР в целевом состоянии',
                'warning' => 'КТР можно улучшить точечным переносом',
                default => 'КТР требует перераспределения',
            },
            'needs_action' => $needsAction,
            'target_policy_ru' => 'Цель КТР: держать не меньше ' . $targetValue . '% количества в направлениях, которые быстро закрывают спрос, особенно для A-товаров и артикулов с высоким риском отсутствия.',
            'decision_ru' => $needsAction
                ? ($firstAction['description_ru'] ?? "До цели КТР не хватает {$targetGap} п.п.; нужно перенести ещё {$needPriorityQty} шт. в приоритетные направления.")
                : 'Распределение уже достигает целевого уровня: можно проверять экономику, ограничения и финальный экспорт.',
            'current_priority_share_percent' => $priorityShare,
            'target_priority_share_percent' => $targetPriorityShare,
            'qty_gap_to_target' => $needPriorityQty,
            'recommended_move_qty' => $recommendedMoveQty,
            'projected_ktr_after_first_action' => $projectedValue,
            'projected_uplift_pp' => $projectedUplift,
            'projected_target_gap_pp' => $projectedTargetGap,
            'first_action' => $firstAction,
            'rules' => $rules,
            'guardrails_ru' => [
                'Не переносить количество в направление с отрицательной экономикой без ручного подтверждения.',
                'Для A-товаров скорость закрытия спроса важнее небольшой разницы в коэффициентах.',
                'Если есть товары в пути, не дублировать поставку без проверки покрытия.',
                'Если файл ограничений режет направление, считать его ограничением оптимизатора, а не просто подсказкой.',
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rankedItems
     * @return list<array<string, mixed>>
     */
    private function fastTerritorialDestinations(array $rankedItems): array
    {
        $items = array_values(array_filter(
            $rankedItems,
            static fn (array $item): bool => (int) ($item['qty'] ?? 0) > 0
                && (
                    (float) ($item['score'] ?? 0) >= 75
                    || (float) ($item['demand_closure_score'] ?? 0) >= 80
                    || ! empty($item['is_fast_for_a_items'])
                )
        ));

        if ($items === []) {
            $items = array_values(array_filter(
                $rankedItems,
                static fn (array $item): bool => (int) ($item['qty'] ?? 0) > 0
            ));
        }

        usort($items, static fn (array $a, array $b): int => [
            (float) ($b['demand_closure_score'] ?? 0),
            (float) ($b['score'] ?? 0),
        ] <=> [
            (float) ($a['demand_closure_score'] ?? 0),
            (float) ($a['score'] ?? 0),
        ]);

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $rankedItems
     */
    private function firstDestinationNeedingProtection(array $rankedItems, string $qtyKey): ?array
    {
        $items = array_values(array_filter(
            $rankedItems,
            static fn (array $item): bool => (int) ($item[$qtyKey] ?? 0) > 0
                && (int) ($item['qty'] ?? 0) > 0
                && (float) ($item['demand_closure_score'] ?? 0) < 80
        ));

        if ($items === []) {
            return null;
        }

        usort($items, static fn (array $a, array $b): int => [
            (float) ($a['demand_closure_score'] ?? 0),
            (float) ($a['score'] ?? 0),
        ] <=> [
            (float) ($b['demand_closure_score'] ?? 0),
            (float) ($b['score'] ?? 0),
        ]);

        return $items[0];
    }

    /**
     * @param list<array<string, mixed>> $fastItems
     */
    private function bestProtectionDestination(array $fastItems, ?array $from): ?array
    {
        foreach ($fastItems as $item) {
            if ($from === null || ! $this->sameDestination($from, $item)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function sameDestination(array $a, array $b): bool
    {
        $aId = (string) ($a['warehouse_id'] ?? $a['cluster_id'] ?? $this->destinationName($a));
        $bId = (string) ($b['warehouse_id'] ?? $b['cluster_id'] ?? $this->destinationName($b));

        return $aId !== '' && $aId === $bId;
    }

    /**
     * @param array<string, mixed>|null $from
     */
    private function protectedMoveQty(?array $from, int $neededQty, string $qtyKey): int
    {
        if ($neededQty <= 0) {
            return 0;
        }

        if ($from === null) {
            return $neededQty;
        }

        return max(0, min(
            $neededQty,
            (int) ($from[$qtyKey] ?? $from['qty'] ?? 0),
            (int) ($from['qty'] ?? 0)
        ));
    }

    /**
     * @param array<string, mixed>|null $from
     * @param array<string, mixed>|null $to
     */
    private function ktrMoveUplift(?array $from, ?array $to, int $qty, int $totalQty): float
    {
        if ($from === null || $to === null || $qty <= 0 || $totalQty <= 0) {
            return 0.0;
        }

        $scoreGap = (float) ($to['score'] ?? 0) - (float) ($from['score'] ?? 0);
        $demandClosureGap = (float) ($to['demand_closure_score'] ?? 0) - (float) ($from['demand_closure_score'] ?? 0);
        $scoreGap = max($scoreGap, $demandClosureGap * 0.45);
        if ($scoreGap <= 0) {
            return 0.0;
        }

        return round(($scoreGap * $qty) / $totalQty, 2);
    }

    /**
     * @param list<array<string, mixed>> $rankedItems
     * @param array<string, mixed> $sourceCoverageSummary
     * @return array<string, mixed>
     */
    private function buildRankingAudit(array $rankedItems, string $marketplace, array $sourceCoverageSummary): array
    {
        $items = array_values($rankedItems);
        $label = $marketplace === 'ozon' ? 'кластер' : 'склад';
        $top = $items[0] ?? null;
        $weak = $items !== [] ? $items[array_key_last($items)] : null;
        $topName = $top ? $this->destinationName($top) : null;
        $weakName = $weak ? $this->destinationName($weak) : null;
        $topScore = $top ? round((float) ($top['score'] ?? 0), 2) : null;
        $weakScore = $weak ? round((float) ($weak['score'] ?? 0), 2) : null;
        $scoreGap = $topScore !== null && $weakScore !== null ? round($topScore - $weakScore, 2) : null;
        $criticalCoverage = (float) ($sourceCoverageSummary['critical_coverage_percent'] ?? 0);
        $confidenceStatus = (string) ($sourceCoverageSummary['status'] ?? 'unknown');

        return [
            'version' => 'territorial-ranking-audit-1',
            'marketplace' => $marketplace,
            'destination_label_ru' => $label,
            'ranked_destinations_count' => count($items),
            'confidence_status' => $confidenceStatus,
            'confidence_status_ru' => $sourceCoverageSummary['human_status'] ?? null,
            'critical_coverage_percent' => round($criticalCoverage, 2),
            'top_destination' => $top ? $this->rankingAuditDestination($top, $label) : null,
            'weak_destination' => $weak ? $this->rankingAuditDestination($weak, $label) : null,
            'score_gap' => $scoreGap,
            'weights_ru' => $this->rankingWeightsText($marketplace),
            'decision_ru' => $this->rankingAuditDecisionText($label, $topName, $weakName, $scoreGap, $confidenceStatus, $criticalCoverage),
            'next_actions_ru' => $this->rankingAuditNextActions($top, $weak, $sourceCoverageSummary, $label),
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function rankingAuditDestination(array $item, string $label): array
    {
        return [
            'type_ru' => $label,
            'id' => (string) ($item['warehouse_id'] ?? $item['cluster_id'] ?? ''),
            'name' => $this->destinationName($item),
            'rank' => (int) ($item['rank'] ?? 0),
            'score' => round((float) ($item['score'] ?? 0), 2),
            'grade' => $item['grade'] ?? null,
            'qty' => (int) ($item['qty'] ?? 0),
            'abc_a_qty' => (int) ($item['abc_a_qty'] ?? 0),
            'high_risk_qty' => (int) ($item['high_risk_qty'] ?? 0),
            'demand_closure_score' => round((float) ($item['demand_closure_score'] ?? 0), 2),
            'coefficient_penalty' => round((float) ($item['coefficient_penalty'] ?? 0), 2),
            'priority_reasons' => array_values((array) ($item['priority_reasons'] ?? [])),
            'rank_reason' => $item['rank_reason'] ?? null,
            'decision_ru' => $item['decision_ru'] ?? null,
            'score_factors' => $item['score_factors'] ?? [],
        ];
    }

    /**
     * @return list<string>
     */
    private function rankingWeightsText(string $marketplace): array
    {
        if ($marketplace === 'ozon') {
            return [
                'скорость доставки',
                'локальность',
                'закрытие регионального спроса',
                'ABC товара',
                'риск отсутствия товара',
                'потеря маржи',
                'ограничения кластера',
                'товары в пути',
            ];
        }

        return [
            'скорость закрытия спроса',
            'стоимость и коэффициенты',
            'ёмкость склада',
            'ABC товара',
            'риск отсутствия товара',
            'совпадение с региональным спросом',
            'ограничения и коэффициенты приёмки',
        ];
    }

    private function rankingAuditDecisionText(string $label, ?string $topName, ?string $weakName, ?float $scoreGap, string $confidenceStatus, float $criticalCoverage): string
    {
        if ($topName === null) {
            return 'Территориальное ранжирование не построено: нет строк с выбранными направлениями.';
        }

        $base = "Лучший {$label}: «{$topName}».";
        if ($weakName !== null && $weakName !== $topName && $scoreGap !== null) {
            $base .= " Самое слабое направление: «{$weakName}», разница {$this->formatNumberRu($scoreGap)} баллов.";
        }

        if ($confidenceStatus !== 'ready' && $criticalCoverage < 70) {
            $base .= " Достоверность ограничена: критическое покрытие источников {$this->formatNumberRu($criticalCoverage)}%.";
        }

        return $base;
    }

    /**
     * @param array<string, mixed>|null $top
     * @param array<string, mixed>|null $weak
     * @param array<string, mixed> $sourceCoverageSummary
     * @return list<string>
     */
    private function rankingAuditNextActions(?array $top, ?array $weak, array $sourceCoverageSummary, string $label): array
    {
        $actions = [];
        $criticalCoverage = (float) ($sourceCoverageSummary['critical_coverage_percent'] ?? 0);

        if ($criticalCoverage < 70) {
            $actions[] = 'Обновить территориальные источники: спрос по регионам, скорость доставки, ограничения и коэффициенты.';
        }

        if ($top !== null && (int) ($top['abc_a_qty'] ?? 0) > 0 && empty($top['is_fast_for_a_items'])) {
            $actions[] = "Проверить, достаточно ли A-товаров уходит в быстрый {$label}.";
        }

        if ($weak !== null && (int) ($weak['high_risk_qty'] ?? 0) > 0) {
            $actions[] = "Перенести часть объёма с высоким риском отсутствия товара из слабого направления «{$this->destinationName($weak)}», если ограничения позволяют.";
        }

        if ($weak !== null && (float) ($weak['coefficient_penalty'] ?? 0) >= 35) {
            $actions[] = "Проверить коэффициенты/лимиты слабого направления «{$this->destinationName($weak)}».";
        }

        return $actions !== [] ? array_values(array_unique($actions)) : ['Действий не требуется: ранжирование выглядит согласованно с текущими источниками.'];
    }

    /**
     * Отдельное ранжирование именно по способности направления быстро закрыть спрос.
     * Общий балл учитывает ещё стоимость, ёмкость и ограничения; этот список
     * отвечает на продуктовый вопрос "какие склады/кластеры быстрее закрывают спрос".
     *
     * @param list<array<string, mixed>> $rankedItems
     * @return list<array<string, mixed>>
     */
    private function buildDemandClosureRanking(array $rankedItems, string $marketplace): array
    {
        $items = array_values(array_filter(
            $rankedItems,
            static fn (array $item): bool => (int) ($item['qty'] ?? 0) > 0
        ));

        usort($items, static fn (array $a, array $b): int => [
            (float) ($b['demand_closure_score'] ?? 0),
            (int) ($b['abc_a_qty'] ?? 0),
            (int) ($b['high_risk_qty'] ?? 0),
            (float) ($b['score'] ?? 0),
        ] <=> [
            (float) ($a['demand_closure_score'] ?? 0),
            (int) ($a['abc_a_qty'] ?? 0),
            (int) ($a['high_risk_qty'] ?? 0),
            (float) ($a['score'] ?? 0),
        ]);

        $label = $marketplace === 'ozon' ? 'кластер' : 'склад';

        return array_values(array_map(function (array $item, int $index) use ($marketplace, $label): array {
            $demandClosureScore = round((float) ($item['demand_closure_score'] ?? 0), 2);
            $score = round((float) ($item['score'] ?? 0), 2);
            $abcAQty = (int) ($item['abc_a_qty'] ?? 0);
            $highRiskQty = (int) ($item['high_risk_qty'] ?? 0);
            $coefficientLimitedQty = (int) ($item['coefficient_limited_qty'] ?? 0);
            $qty = (int) ($item['qty'] ?? 0);
            $reasons = [];

            if ($demandClosureScore >= 80) {
                $reasons[] = 'быстро закрывает региональный спрос';
            } elseif ($demandClosureScore >= 55) {
                $reasons[] = 'средне закрывает региональный спрос';
            } else {
                $reasons[] = 'слабо закрывает региональный спрос';
            }
            if ($abcAQty > 0) {
                $reasons[] = 'есть A-товары';
            }
            if ($highRiskQty > 0) {
                $reasons[] = 'есть высокий риск отсутствия';
            }
            if ($coefficientLimitedQty > 0) {
                $reasons[] = 'есть ограничения или дорогие коэффициенты';
            }

            return [
                'rank' => $index + 1,
                'marketplace' => $marketplace,
                'destination_type' => $label,
                'id' => $item['warehouse_id'] ?? $item['cluster_id'] ?? null,
                'name' => $this->destinationName($item),
                'qty' => $qty,
                'sku_count' => (int) ($item['sku_count'] ?? 0),
                'demand_closure_score' => $demandClosureScore,
                'overall_score' => $score,
                'grade' => $this->gradeForScore($demandClosureScore),
                'speed_tier' => $item['speed_tier'] ?? null,
                'abc_a_qty' => $abcAQty,
                'high_risk_qty' => $highRiskQty,
                'coefficient_limited_qty' => $coefficientLimitedQty,
                'recommended_for_ru' => $this->demandClosureRecommendedFor($abcAQty, $highRiskQty, $coefficientLimitedQty),
                'reasons_ru' => $reasons,
                'decision_ru' => $this->demandClosureDecisionText(
                    destinationName: $this->destinationName($item),
                    label: $label,
                    demandClosureScore: $demandClosureScore,
                    qty: $qty,
                    abcAQty: $abcAQty,
                    highRiskQty: $highRiskQty,
                    coefficientLimitedQty: $coefficientLimitedQty,
                ),
            ];
        }, array_slice($items, 0, 10), array_keys(array_slice($items, 0, 10))));
    }

    private function demandClosureRecommendedFor(int $abcAQty, int $highRiskQty, int $coefficientLimitedQty): string
    {
        if ($abcAQty > 0 && $highRiskQty > 0) {
            return 'A-товары и товары с высоким риском отсутствия';
        }
        if ($abcAQty > 0) {
            return 'A-товары';
        }
        if ($highRiskQty > 0) {
            return 'товары с высоким риском отсутствия';
        }
        if ($coefficientLimitedQty > 0) {
            return 'после проверки ограничений и коэффициентов';
        }

        return 'плановое пополнение';
    }

    private function demandClosureDecisionText(
        string $destinationName,
        string $label,
        float $demandClosureScore,
        int $qty,
        int $abcAQty,
        int $highRiskQty,
        int $coefficientLimitedQty,
    ): string {
        $text = "Этот {$label} закрывает спрос с баллом {$demandClosureScore}; в плане сюда идёт {$qty} шт.";
        if ($abcAQty > 0) {
            $text .= " Для A-товаров здесь {$abcAQty} шт., поэтому скорость важнее небольшой разницы в стоимости.";
        }
        if ($highRiskQty > 0) {
            $text .= " Есть {$highRiskQty} шт. с высоким риском отсутствия: направление стоит проверять первым.";
        }
        if ($coefficientLimitedQty > 0) {
            $text .= " Но {$coefficientLimitedQty} шт. затронуто ограничениями или дорогими коэффициентами.";
        }

        return $destinationName !== ''
            ? "«{$destinationName}»: {$text}"
            : $text;
    }

    /**
     * Даёт уже не просто рейтинг, а практичный маршрутный слой:
     * какие направления стоит уменьшить, куда лучше направить объём и почему.
     *
     * @param list<array<string, mixed>> $rankedItems
     * @return list<array<string, mixed>>
     */
    private function buildRoutingRecommendations(array $rankedItems, string $marketplace): array
    {
        $rankedItems = array_values(array_filter(
            $rankedItems,
            static fn (array $item): bool => (int) ($item['qty'] ?? 0) > 0
        ));

        if (count($rankedItems) < 2) {
            return [];
        }

        $totalQty = array_sum(array_map(static fn (array $item): int => (int) ($item['qty'] ?? 0), $rankedItems));
        $priorityItems = array_values(array_filter(
            $rankedItems,
            static fn (array $item): bool => (float) ($item['score'] ?? 0) >= 75
        ));
        $weakItems = array_values(array_filter(
            $rankedItems,
            static fn (array $item): bool => (float) ($item['score'] ?? 0) < 75
        ));

        if ($weakItems === []) {
            return [];
        }

        if ($priorityItems === []) {
            $priorityItems = array_slice($rankedItems, 0, 1);
        }

        $recommendations = [];
        foreach (array_slice($weakItems, 0, 6) as $index => $weakItem) {
            $target = $priorityItems[$index % count($priorityItems)] ?? null;
            if (! $target) {
                continue;
            }

            $fromScore = (float) ($weakItem['score'] ?? 0);
            $toScore = (float) ($target['score'] ?? 0);
            if ($toScore <= $fromScore) {
                continue;
            }

            $weakQty = (int) ($weakItem['qty'] ?? 0);
            $targetQty = (int) ($target['qty'] ?? 0);
            $abcAQty = (int) ($weakItem['abc_a_qty'] ?? 0);
            $highRiskQty = (int) ($weakItem['high_risk_qty'] ?? 0);
            $coefficientLimitedQty = (int) ($weakItem['coefficient_limited_qty'] ?? 0);
            $moveQty = max(1, min(
                $weakQty,
                max(1, (int) ceil($weakQty * ($abcAQty > 0 || $highRiskQty > 0 ? 0.7 : 0.4))),
                max(1, (int) ceil(max($targetQty, $weakQty) * 0.5))
            ));
            $uplift = $totalQty > 0 ? round((($toScore - $fromScore) * $moveQty) / $totalQty, 2) : 0.0;

            $recommendations[] = [
                'type' => 'reroute_to_better_destination',
                'priority' => $abcAQty > 0 || $highRiskQty > 0 ? 'high' : ($coefficientLimitedQty > 0 ? 'medium' : 'normal'),
                'marketplace' => $marketplace,
                'from' => [
                    'id' => $weakItem['warehouse_id'] ?? $weakItem['cluster_id'] ?? null,
                    'name' => $this->destinationName($weakItem),
                    'score' => round($fromScore, 2),
                    'qty' => $weakQty,
                    'grade' => $weakItem['grade'] ?? $this->gradeForScore($fromScore),
                    'rank_reason' => $weakItem['rank_reason'] ?? null,
                ],
                'to' => [
                    'id' => $target['warehouse_id'] ?? $target['cluster_id'] ?? null,
                    'name' => $this->destinationName($target),
                    'score' => round($toScore, 2),
                    'qty' => $targetQty,
                    'grade' => $target['grade'] ?? $this->gradeForScore($toScore),
                    'rank_reason' => $target['rank_reason'] ?? null,
                ],
                'recommended_qty' => $moveQty,
                'abc_a_qty' => $abcAQty,
                'high_risk_qty' => $highRiskQty,
                'coefficient_limited_qty' => $coefficientLimitedQty,
                'expected_ktr_uplift_pp' => $uplift,
                'reason' => $this->routingReason($weakItem, $target, $marketplace),
                'decision_ru' => $this->routingDecisionText($weakItem, $target, $marketplace, $moveQty, $uplift),
            ];
        }

        return $recommendations;
    }

    /**
     * Рекомендации по конкретному SKU. Общее ранжирование отвечает "какое направление лучше",
     * а этот слой отвечает "куда именно лучше вести этот товар".
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private function buildSkuRoutingRecommendations(array $lines, string $marketplace): array
    {
        $groups = [];
        $totalQty = 0;
        $totalFinancialWeight = 0.0;
        foreach ($lines as $line) {
            $sku = trim((string) ($line['sku'] ?? ''));
            $destinationId = (string) ($line['warehouse_id'] ?? $line['cluster_id'] ?? '');
            if ($sku === '' || $destinationId === '') {
                continue;
            }

            $explain = $this->decodeExplain($line);
            $territorial = is_array($explain['territorial'] ?? null) ? $explain['territorial'] : [];
            $qty = max(0, (int) ($line['qty_rounded'] ?? 0));
            $financialWeight = $this->lineFinancialWeight($line, $qty);
            $totalQty += $qty;
            $totalFinancialWeight += $financialWeight;

            $groups[$sku][] = [
                'sku' => $sku,
                'product_name' => $line['product_name'] ?? null,
                'destination_id' => $destinationId,
                'destination_name' => $line['warehouse_name'] ?? $line['cluster_name'] ?? $destinationId,
                'qty' => $qty,
                'financial_weight' => $financialWeight,
                'expected_revenue' => (float) ($line['expected_revenue'] ?? 0),
                'expected_profit' => (float) ($line['expected_profit'] ?? 0),
                'supply_cost' => (float) ($line['supply_cost_estimate'] ?? 0),
                'score' => (float) ($territorial['score'] ?? 0),
                'demand_closure_score' => (float) ($territorial['regional_demand_closure_score'] ?? 0),
                'abc_priority' => strtoupper((string) ($territorial['abc_priority'] ?? $explain['inputs']['abc_priority'] ?? 'C')),
                'risk_level' => (string) ($line['risk_level'] ?? 'low'),
                'rank_reason' => $territorial['rank_reason'] ?? null,
            ];
        }

        $recommendations = [];
        foreach ($groups as $sku => $rows) {
            if (count($rows) < 2) {
                continue;
            }

            usort($rows, static fn (array $a, array $b): int => ((float) ($a['score'] ?? 0)) <=> ((float) ($b['score'] ?? 0)));
            $from = $rows[0];
            $to = $rows[count($rows) - 1];
            $scoreGap = (float) ($to['score'] ?? 0) - (float) ($from['score'] ?? 0);
            if ($scoreGap < 8 || (int) ($from['qty'] ?? 0) <= 0) {
                continue;
            }

            $abc = strtoupper((string) ($from['abc_priority'] ?? $to['abc_priority'] ?? 'C'));
            $risk = (string) ($from['risk_level'] ?? $to['risk_level'] ?? 'low');
            $isHigh = $abc === 'A' || $risk === 'high';
            $moveQty = max(1, min(
                (int) ($from['qty'] ?? 0),
                (int) ceil(((int) ($from['qty'] ?? 0)) * ($isHigh ? 0.7 : 0.4))
            ));
            $uplift = $totalQty > 0 ? round(($scoreGap * $moveQty) / $totalQty, 2) : 0.0;
            $fromFinancialWeight = max(0.0, (float) ($from['financial_weight'] ?? 0));
            $moveFinancialWeight = $fromFinancialWeight > 0 && (int) ($from['qty'] ?? 0) > 0
                ? round($fromFinancialWeight * ($moveQty / max(1, (int) ($from['qty'] ?? 0))), 2)
                : 0.0;
            $financialSharePercent = $totalFinancialWeight > 0
                ? round($moveFinancialWeight / $totalFinancialWeight * 100, 2)
                : 0.0;
            $financialPriority = match (true) {
                $financialSharePercent >= 15 => 'high',
                $financialSharePercent >= 5 => 'medium',
                default => 'normal',
            };
            $entity = $marketplace === 'ozon' ? 'кластер' : 'склад';
            $priority = $isHigh || $financialPriority === 'high'
                ? 'high'
                : ($financialPriority === 'medium' || $scoreGap >= 25 ? 'medium' : 'normal');

            $recommendations[] = [
                'type' => 'sku_reroute_to_better_destination',
                'marketplace' => $marketplace,
                'priority' => $priority,
                'financial_priority' => $financialPriority,
                'sku' => $sku,
                'product_name' => $from['product_name'] ?? $to['product_name'] ?? null,
                'from' => [
                    'id' => $from['destination_id'] ?? null,
                    'name' => $from['destination_name'] ?? null,
                    'score' => round((float) ($from['score'] ?? 0), 2),
                    'qty' => (int) ($from['qty'] ?? 0),
                    'rank_reason' => $from['rank_reason'] ?? null,
                ],
                'to' => [
                    'id' => $to['destination_id'] ?? null,
                    'name' => $to['destination_name'] ?? null,
                    'score' => round((float) ($to['score'] ?? 0), 2),
                    'qty' => (int) ($to['qty'] ?? 0),
                    'rank_reason' => $to['rank_reason'] ?? null,
                ],
                'recommended_qty' => $moveQty,
                'financial_weight' => $moveFinancialWeight,
                'financial_share_percent' => $financialSharePercent,
                'expected_revenue' => round((float) ($from['expected_revenue'] ?? 0), 2),
                'expected_profit' => round((float) ($from['expected_profit'] ?? 0), 2),
                'supply_cost' => round((float) ($from['supply_cost'] ?? 0), 2),
                'abc_priority' => $abc,
                'risk_level' => $risk,
                'score_gap' => round($scoreGap, 2),
                'expected_ktr_uplift_pp' => $uplift,
                'reason' => $isHigh
                    ? "SKU {$sku}: {$entity} назначения быстрее закрывает спрос; для A-товаров и высокого риска отсутствия скорость важнее небольшой разницы в стоимости."
                    : "SKU {$sku}: {$entity} назначения имеет более высокий территориальный балл и лучше подходит для этого товара.",
                'decision_ru' => 'Рекомендация по SKU: перенести ' . $moveQty . ' шт. из «'
                    . ($from['destination_name'] ?? 'слабого направления') . '» в «'
                    . ($to['destination_name'] ?? 'лучшее направление') . '». '
                    . 'Разница балла: +' . round($scoreGap, 2) . '; ожидаемый вклад в КТР: +' . $uplift . ' п.п.'
                    . ($moveFinancialWeight > 0 ? ' Финансовый вес переноса: ' . number_format($moveFinancialWeight, 0, ',', ' ') . '.' : ''),
            ];
        }

        usort($recommendations, static fn (array $a, array $b): int => [
            $b['priority'] === 'high' ? 1 : 0,
            (float) ($b['financial_share_percent'] ?? 0),
            (float) ($b['financial_weight'] ?? 0),
            (float) ($b['score_gap'] ?? 0),
            (int) ($b['recommended_qty'] ?? 0),
        ] <=> [
            $a['priority'] === 'high' ? 1 : 0,
            (float) ($a['financial_share_percent'] ?? 0),
            (float) ($a['financial_weight'] ?? 0),
            (float) ($a['score_gap'] ?? 0),
            (int) ($a['recommended_qty'] ?? 0),
        ]);

        return array_slice($recommendations, 0, 8);
    }

    /**
     * @param array<string, mixed> $from
     * @param array<string, mixed> $to
     */
    private function routingReason(array $from, array $to, string $marketplace): string
    {
        $fromScore = (float) ($from['score'] ?? 0);
        $toScore = (float) ($to['score'] ?? 0);
        $abcAQty = (int) ($from['abc_a_qty'] ?? 0);
        $highRiskQty = (int) ($from['high_risk_qty'] ?? 0);
        $coefficientLimitedQty = (int) ($from['coefficient_limited_qty'] ?? 0);

        if ($abcAQty > 0) {
            return 'A-товар лучше вести в более быстрое направление: скорость закрытия спроса важнее небольшой разницы в стоимости.';
        }
        if ($highRiskQty > 0) {
            return 'Высокий риск отсутствия товара лучше закрывать приоритетным направлением, иначе сохраняется риск упущенной выручки.';
        }
        if ($coefficientLimitedQty > 0) {
            return 'Исходное направление имеет дорогие коэффициенты или ограничения, поэтому часть объёма лучше увести в более доступное направление.';
        }

        return $marketplace === 'ozon'
            ? "Кластер назначения сильнее по локальности и скорости доставки: {$fromScore} → {$toScore}."
            : "Склад назначения сильнее по скорости, стоимости и доступности приёмки: {$fromScore} → {$toScore}.";
    }

    /**
     * @param array<string, mixed> $from
     * @param array<string, mixed> $to
     */
    private function routingDecisionText(array $from, array $to, string $marketplace, int $qty, float $uplift): string
    {
        $entity = $marketplace === 'ozon' ? 'кластер' : 'склад';
        $fromName = $this->destinationName($from);
        $toName = $this->destinationName($to);

        return "Рекомендация: направить {$qty} шт. из «{$fromName}» в «{$toName}». "
            . "Этот {$entity} лучше закрывает региональный спрос; ожидаемый вклад в КТР: +{$uplift} п.п.";
    }

    /**
     * @param array<string, mixed> $item
     */
    private function destinationName(array $item): string
    {
        $name = $item['warehouse_name']
            ?? $item['cluster_name']
            ?? $item['warehouse_id']
            ?? $item['cluster_id']
            ?? null;

        return $name !== null && $name !== '' ? (string) $name : 'направление без названия';
    }

    private function emptySummary(string $marketplace): array
    {
        return [
            'marketplace' => $marketplace,
            'status' => 'нет данных',
            'method' => null,
            'ktr' => [
                'value' => 0.0,
                'label' => 'КТР 0%',
                'explanation' => 'Недостаточно данных для расчёта территориального распределения.',
            ],
            'warehouse_ranking' => [],
            'cluster_ranking' => [],
            'routing_recommendations' => [],
            'sku_routing_recommendations' => [],
            'notes' => [],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $keys
     */
    private function firstNumeric(array $payload, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return (float) $payload[$key];
            }
        }

        return null;
    }

    /**
     * @param list<string> $warehouseIds
     * @return array<string, array<string, mixed>>
     */
    private function wildberriesSlotFacts(array $warehouseIds): array
    {
        if ($warehouseIds === []) {
            return [];
        }

        return WarehouseSlot::query()
            ->where('marketplace', 'wildberries')
            ->whereIn('warehouse_id', $warehouseIds)
            ->available()
            ->upcoming()
            ->orderBy('date')
            ->orderBy('coefficient')
            ->get()
            ->groupBy('warehouse_id')
            ->map(function ($slots) {
                $best = $slots->first();
                $date = $best?->date?->toDateString();
                $leadTimeDays = $this->slotLeadTimeDays($date);

                return [
                    'coefficient' => $best?->coefficient !== null ? (float) $best->coefficient : null,
                    'delivery_coefficient' => $best?->delivery_coefficient !== null ? (float) $best->delivery_coefficient : null,
                    'storage_coefficient' => $best?->storage_coefficient !== null ? (float) $best->storage_coefficient : null,
                    'capacity_remaining' => $best?->capacity_remaining,
                    'date' => $date,
                    'lead_time_days' => $leadTimeDays,
                    'lead_time_score' => $this->slotLeadTimeScore($leadTimeDays),
                ];
            })
            ->all();
    }

    private function slotLeadTimeDays(mixed $date): ?int
    {
        if ($date === null || trim((string) $date) === '') {
            return null;
        }

        try {
            $slotDate = $date instanceof Carbon
                ? $date->copy()->startOfDay()
                : Carbon::parse((string) $date)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        return max(0, (int) now()->startOfDay()->diffInDays($slotDate, false));
    }

    private function slotLeadTimeScore(?int $days): float
    {
        if ($days === null) {
            return 72.0;
        }

        return match (true) {
            $days <= 1 => 100.0,
            $days <= 3 => 90.0,
            $days <= 7 => 72.0,
            $days <= 14 => 52.0,
            default => 35.0,
        };
    }

    private function deliverySpeedScore(mixed $coefficient, ?int $slotLeadTimeDays): float
    {
        $coefficientScore = 100 / max(1.0, is_numeric($coefficient) ? (float) $coefficient : 1.0);
        if ($slotLeadTimeDays === null) {
            return round($coefficientScore, 2);
        }

        return round(max(0.0, min(100.0, $coefficientScore * 0.62 + $this->slotLeadTimeScore($slotLeadTimeDays) * 0.38)), 2);
    }

    /**
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private function decodeExplain(array $line): array
    {
        $explain = $line['explain_json'] ?? [];
        if (is_string($explain)) {
            $decoded = json_decode($explain, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($explain) ? $explain : [];
    }

    /**
     * @param array<string, mixed> $explain
     */
    private function encodeExplain(array $explain): string
    {
        return json_encode($explain, JSON_UNESCAPED_UNICODE);
    }

    private function abcWeight(string $abc): float
    {
        return match ($this->normalizeAbcPriority($abc)) {
            'A' => 1.65,
            'B' => 1.15,
            default => 1.0,
        };
    }

    private function normalizeAbcPriority(mixed $abc): string
    {
        $value = trim((string) $abc);
        $value = strtr($value, [
            'А' => 'A',
            'В' => 'B',
            'С' => 'C',
            'а' => 'A',
            'в' => 'B',
            'с' => 'C',
        ]);
        $value = strtoupper($value);

        return in_array($value, ['A', 'B', 'C'], true) ? $value : 'C';
    }

    private function riskWeight(string $risk): float
    {
        return match ($risk) {
            'high' => 1.35,
            'med', 'medium' => 1.15,
            default => 1.0,
        };
    }

    private function calculateWarehouseScore(
        float $speedScore,
        float $costScore,
        float $capacityScore,
        float $demandClosureScore,
        float $abcPriorityScore,
        float $oosUrgencyScore,
        float $coefficientPenalty,
        float $priorityScore,
        string $abc,
        float $abcWeight,
        float $riskWeight,
        float $abcSpeedCostAdjustment = 0.0,
    ): float {
        $weights = $this->scoreWeightsByAbc($abc);
        $score = (
            $demandClosureScore * $weights['speed']
            + $costScore * $weights['cost']
            + $capacityScore * $weights['capacity']
            + $abcPriorityScore * $weights['abc']
            + $oosUrgencyScore * $weights['oos']
            + $priorityScore * $weights['priority']
        )
            * $abcWeight
            * $riskWeight
            - $coefficientPenalty * 0.45
            + min(12.0, $speedScore / 12.0)
            + $abcSpeedCostAdjustment;

        return round(max(0.0, min(150.0, $score)), 2);
    }

    /**
     * A-товары сильнее завязаны на скорость доставки: если быстрый склад закрывает
     * региональный спрос, он должен выигрывать даже при немного худшей стоимости.
     *
     * @return array{speed:float,cost:float,capacity:float,abc:float,oos:float,priority:float}
     */
    private function scoreWeightsByAbc(string $abc): array
    {
        return match ($this->normalizeAbcPriority($abc)) {
            'A' => ['speed' => 0.46, 'cost' => 0.08, 'capacity' => 0.10, 'abc' => 0.18, 'oos' => 0.12, 'priority' => 0.06],
            'B' => ['speed' => 0.34, 'cost' => 0.18, 'capacity' => 0.13, 'abc' => 0.12, 'oos' => 0.13, 'priority' => 0.10],
            default => ['speed' => 0.22, 'cost' => 0.30, 'capacity' => 0.16, 'abc' => 0.08, 'oos' => 0.12, 'priority' => 0.12],
        };
    }

    private function abcSpeedCostAdjustment(
        string $abc,
        float $speedScore,
        float $demandClosureScore,
        float $coefficientPenalty,
        float $costScore,
    ): float {
        return match ($this->normalizeAbcPriority($abc)) {
            'A' => ($speedScore >= 70 && $demandClosureScore >= 75)
                ? max(4.0, 14.0 - min(8.0, $coefficientPenalty / 8.0))
                : 0.0,
            'B' => ($speedScore >= 75 && $demandClosureScore >= 75 && $coefficientPenalty < 25)
                ? 4.0
                : 0.0,
            default => ($coefficientPenalty >= 30 || $costScore < 55)
                ? -12.0
                : (($costScore >= 85 && $speedScore >= 45) ? 3.0 : 0.0),
        };
    }

    /**
     * @param array<string, mixed> $explain
     */
    private function constraintCoefficient(array $explain): ?float
    {
        $constraints = is_array($explain['constraints'] ?? null) ? $explain['constraints'] : [];
        $values = [];
        foreach (['coefficient', 'acceptance_coefficient', 'delivery_coefficient', 'storage_coefficient', 'logistics_coefficient'] as $key) {
            if (isset($constraints[$key]) && is_numeric($constraints[$key])) {
                $values[] = (float) $constraints[$key];
            }
        }

        return $values !== [] ? max($values) : null;
    }

    /**
     * @param array<string, mixed> $explain
     */
    private function constraintDetailCoefficient(array $explain, string $key): ?float
    {
        $value = $explain['constraints'][$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    private function gradeForScore(float $score): string
    {
        return match (true) {
            $score >= 90 => 'отлично',
            $score >= 75 => 'хорошо',
            $score >= 55 => 'средне',
            default => 'требует улучшения',
        };
    }

    private function calculateClusterScore(float $speedScore, float $localityScore, float $lostProfit): float
    {
        return round(max(0.0, min(150.0, $speedScore * 0.55 + $localityScore * 0.25 + min(50, $lostProfit / 1000) * 0.2)), 2);
    }

    private function calculateOzonClusterScore(
        float $speedScore,
        float $localityScore,
        float $demandClosureScore,
        float $abcPriorityScore,
        float $oosUrgencyScore,
        float $lostProfit,
        float $constraintPenalty,
        float $inTransitRelief,
    ): float {
        $lostProfitScore = min(80.0, max(0.0, $lostProfit / 900.0));
        $score = $speedScore * 0.24
            + $localityScore * 0.18
            + $demandClosureScore * 0.28
            + $abcPriorityScore * 0.10
            + $oosUrgencyScore * 0.12
            + $lostProfitScore * 0.08
            - $constraintPenalty * 0.5
            - $inTransitRelief * 0.35;

        return round(max(0.0, min(150.0, $score)), 2);
    }

    private function ozonLocalityScore(float $expectedSavingsRub, float $lostProfit, int $qty): float
    {
        $savingsPerUnit = $qty > 0 ? $expectedSavingsRub / $qty : $expectedSavingsRub;
        $savingsScore = min(35.0, max(0.0, $savingsPerUnit / 25.0));
        $lostProfitScore = min(25.0, max(0.0, $lostProfit / 2500.0));

        return round(max(35.0, min(100.0, 45.0 + $savingsScore + $lostProfitScore)), 2);
    }

    /**
     * Оценивает, насколько выбранный склад/кластер совпадает с реальным
     * региональным спросом SKU. Если профиля нет, возвращаем нейтральный балл,
     * чтобы старые данные не ломали ранжирование.
     *
     * @param array<string, mixed> $line
     * @param array<string, mixed> $explain
     */
    private function regionalDemandFitScore(array $line, array $explain): float
    {
        $regional = is_array($explain['regional_demand'] ?? null) ? $explain['regional_demand'] : [];
        if ($regional === []) {
            return 55.0;
        }

        $destinationIds = array_values(array_filter(array_map(
            static fn (mixed $value): string => mb_strtolower(trim((string) $value)),
            [
                $line['cluster_id'] ?? null,
                $line['warehouse_id'] ?? null,
                $line['destination_id'] ?? null,
                $regional['target_cluster_id'] ?? null,
            ]
        )));
        $destinationNames = array_values(array_filter(array_map(
            static fn (mixed $value): string => mb_strtolower(trim((string) $value)),
            [
                $line['cluster_name'] ?? null,
                $line['warehouse_name'] ?? null,
                $line['destination'] ?? null,
                $line['region'] ?? null,
            ]
        )));
        $destinationNames = array_values(array_unique(array_merge(
            $destinationNames,
            $this->regionalAliasesForDestination($destinationNames)
        )));

        foreach ([
            ['id' => $regional['dominant_demand_cluster_id'] ?? null, 'share' => $regional['dominant_demand_cluster_share'] ?? null],
            ['id' => $regional['dominant_sales_cluster_id'] ?? null, 'share' => $regional['dominant_sales_cluster_share'] ?? null],
        ] as $dominant) {
            $id = mb_strtolower(trim((string) ($dominant['id'] ?? '')));
            if ($id !== '' && in_array($id, $destinationIds, true)) {
                return $this->clampScore((float) ($dominant['share'] ?? 80.0));
            }
        }

        $profileScore = $this->regionalProfileMatchScore($regional, $destinationIds, $destinationNames);
        if ($profileScore !== null) {
            return $profileScore;
        }

        $expectedLocality = $regional['expected_locality_rate'] ?? null;
        if (is_numeric($expectedLocality)) {
            return $this->clampScore((float) $expectedLocality);
        }

        return 55.0;
    }

    /**
     * @param array<string, mixed> $regional
     * @param list<string> $destinationIds
     * @param list<string> $destinationNames
     */
    private function regionalProfileMatchScore(array $regional, array $destinationIds, array $destinationNames): ?float
    {
        foreach (['sales_profile', 'delivery_fo_profile', 'by_delivery_fo', 'warehouse_sales_profile', 'clusters_summary', 'demand_profile', 'stock_profile'] as $key) {
            $profile = $regional[$key] ?? null;
            if (! is_array($profile) || $profile === []) {
                continue;
            }

            $rows = $this->normalizeRegionalProfileRows($profile);
            $totalOrders = 0.0;
            foreach ($rows as $row) {
                if (is_array($row) && is_numeric($row['orders'] ?? null)) {
                    $totalOrders += (float) $row['orders'];
                }
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $rowIds = array_values(array_filter(array_map(
                    static fn (mixed $value): string => mb_strtolower(trim((string) $value)),
                    [$row['cluster_id'] ?? null, $row['warehouse_id'] ?? null, $row['id'] ?? null]
                )));
                $rowNames = array_values(array_filter(array_map(
                    static fn (mixed $value): string => mb_strtolower(trim((string) $value)),
                    [$row['cluster_name'] ?? null, $row['warehouse_name'] ?? null, $row['name'] ?? null, $row['region'] ?? null, $row['fo'] ?? null, $row['district'] ?? null]
                )));

                $matched = array_intersect($destinationIds, $rowIds) !== []
                    || array_intersect($destinationNames, $rowNames) !== [];

                if (! $matched) {
                    continue;
                }

                if (is_numeric($row['share_percent'] ?? null)) {
                    return $this->clampScore((float) $row['share_percent']);
                }
                if ($totalOrders > 0 && is_numeric($row['orders'] ?? null)) {
                    return $this->clampScore(((float) $row['orders'] / $totalOrders) * 100);
                }
                if (is_numeric($row['locality_rate'] ?? null)) {
                    return $this->clampScore((float) $row['locality_rate']);
                }

                return 72.0;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $profile
     * @return list<array<string, mixed>>
     */
    private function normalizeRegionalProfileRows(array $profile): array
    {
        $rows = [];
        foreach ($profile as $key => $row) {
            if (is_array($row)) {
                $rows[] = $row;
                continue;
            }

            if (is_numeric($row) && (string) $key !== '') {
                $rows[] = [
                    'region' => (string) $key,
                    'name' => (string) $key,
                    'orders' => (float) $row,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param list<string> $destinationNames
     * @return list<string>
     */
    private function regionalAliasesForDestination(array $destinationNames): array
    {
        $aliases = [];
        $rules = [
            [
                'aliases' => ['central', 'центральный федеральный округ', 'центральный фо', 'цфо', 'москва'],
                'needles' => ['коледино', 'подольск', 'электросталь', 'тула', 'белые столбы', 'чехов', 'домодедово', 'внуково', 'рязань', 'брянск', 'москва'],
            ],
            [
                'aliases' => ['northwest', 'северо-западный федеральный округ', 'северо-западный фо', 'сзфо', 'санкт-петербург'],
                'needles' => ['санкт-петербург', 'спб', 'шушары'],
            ],
            [
                'aliases' => ['south_cluster', 'южный федеральный округ', 'северо-кавказский федеральный округ', 'юфо', 'скфо'],
                'needles' => ['краснодар', 'ростов-на-дону', 'невинномысск'],
            ],
            [
                'aliases' => ['volga', 'приволжский федеральный округ', 'пфо'],
                'needles' => ['казань', 'нижний новгород', 'самара'],
            ],
            [
                'aliases' => ['ural', 'уральский федеральный округ', 'уфо'],
                'needles' => ['екатеринбург'],
            ],
            [
                'aliases' => ['siberia_cluster', 'сибирский федеральный округ', 'дальневосточный федеральный округ', 'сфо', 'дфо'],
                'needles' => ['новосибирск', 'красноярск', 'хабаровск'],
            ],
        ];

        foreach ($destinationNames as $name) {
            foreach ($rules as $rule) {
                foreach ($rule['needles'] as $needle) {
                    if (str_contains($name, $needle)) {
                        foreach ($rule['aliases'] as $alias) {
                            $aliases[] = mb_strtolower($alias);
                        }
                    }
                }
            }
        }

        return array_values(array_unique($aliases));
    }

    private function clampScore(float $score): float
    {
        return round(max(0.0, min(100.0, $score)), 2);
    }

    private function ozonDemandClosureScore(
        float $speedScore,
        float $localityScore,
        float $regionalDemandFitScore,
        string $abc,
        string $risk,
        float $lostProfit,
        float $constraintPenalty,
        float $inTransitRelief,
    ): float {
        $abcBoost = match ($this->normalizeAbcPriority($abc)) {
            'A' => 1.18,
            'B' => 1.06,
            default => 0.94,
        };
        $riskBoost = match ($risk) {
            'high' => 1.14,
            'med', 'medium' => 1.06,
            default => 1.0,
        };
        $lostProfitBoost = 1.0 + min(0.18, max(0.0, $lostProfit) / 300000.0);
        $constraintMultiplier = $constraintPenalty >= 55 ? 0.78 : ($constraintPenalty >= 30 ? 0.9 : 1.0);
        $transitMultiplier = $inTransitRelief >= 50 ? 0.82 : ($inTransitRelief >= 25 ? 0.92 : 1.0);

        $baseScore = $speedScore * 0.46 + $localityScore * 0.28 + $regionalDemandFitScore * 0.26;

        return round(max(0.0, min(150.0, $baseScore * $abcBoost * $riskBoost * $lostProfitBoost * $constraintMultiplier * $transitMultiplier)), 2);
    }

    /**
     * @param array<string, mixed> $explain
     */
    private function ozonConstraintPenalty(array $explain): float
    {
        $coefficient = $this->constraintCoefficient($explain);
        $penalty = $coefficient !== null ? min(80.0, max(0.0, ($coefficient - 1.0) * 18.0)) : 0.0;

        if (($explain['constraints']['capped_to_qty'] ?? null) !== null) {
            $penalty += 18.0;
        }

        return round(min(100.0, $penalty), 2);
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, mixed> $explain
     */
    private function ozonInTransitRelief(array $line, array $explain): float
    {
        $inTransitQty = (int) ($line['in_transit'] ?? 0);
        if ($inTransitQty <= 0) {
            return 0.0;
        }

        $dailyDemand = (float) ($explain['inputs']['daily_demand'] ?? $line['demand_daily'] ?? 0);
        if ($dailyDemand <= 0) {
            return min(25.0, $inTransitQty * 0.5);
        }

        $coverageDays = $inTransitQty / $dailyDemand;

        return round(min(75.0, $coverageDays * 3.0), 2);
    }

    /**
     * @param array<string, mixed> $cluster
     */
    private function ozonClusterRankReason(array $cluster): string
    {
        if (! empty($cluster['is_fast_for_a_items'])) {
            return 'Кластер поднят в ранжировании: быстро закрывает спрос по A-товарам.';
        }

        if ((float) ($cluster['lost_profit'] ?? 0) > 0 && (float) ($cluster['demand_closure_score'] ?? 0) >= 70) {
            return 'Кластер приоритетен: есть потеря маржи и хороший балл закрытия спроса.';
        }

        if ((float) ($cluster['coefficient_penalty'] ?? 0) >= 35) {
            return 'Кластер снижен в ранжировании из-за ограничений или коэффициентов из файла.';
        }

        if ((float) ($cluster['demand_closure_score'] ?? 0) >= 80) {
            return 'Кластер хорошо закрывает региональный спрос по текущим данным Ozon.';
        }

        return 'Кластер подходит для планового пополнения, но не выглядит критичным направлением.';
    }

    /**
     * @param array<string, mixed> $cluster
     */
    private function ozonDestinationPolicyStatus(array $cluster): string
    {
        if ((int) ($cluster['abc_a_qty'] ?? 0) > 0 && ! empty($cluster['is_fast_for_a_items'])) {
            return 'a_speed_priority';
        }

        if ((int) ($cluster['high_risk_qty'] ?? 0) > 0 && (float) ($cluster['demand_closure_score'] ?? 0) >= 70) {
            return 'oos_speed_priority';
        }

        if ((float) ($cluster['coefficient_penalty'] ?? 0) >= 30) {
            return 'coefficient_limited';
        }

        return 'balanced';
    }

    /**
     * @param array<string, mixed> $cluster
     * @return list<string>
     */
    private function ozonDestinationPriorityReasons(array $cluster): array
    {
        $reasons = [];

        if ((int) ($cluster['abc_a_qty'] ?? 0) > 0) {
            $reasons[] = ! empty($cluster['is_fast_for_a_items'])
                ? 'A-товары: быстрый кластер получает повышенный приоритет.'
                : 'A-товары есть, но кластер не выглядит достаточно быстрым для большей части объёма.';
        }

        if ((int) ($cluster['high_risk_qty'] ?? 0) > 0) {
            $reasons[] = 'Есть высокий риск отсутствия товара: скорость закрытия спроса повышает приоритет.';
        }

        if ((float) ($cluster['demand_closure_score'] ?? 0) >= 80) {
            $reasons[] = 'Кластер хорошо закрывает региональный спрос.';
        }

        if ((float) ($cluster['lost_profit'] ?? 0) > 0) {
            $reasons[] = 'Есть потенциальная потеря маржи: поставка может снизить упущенную прибыль.';
        }

        if ((float) ($cluster['coefficient_penalty'] ?? 0) >= 30) {
            $reasons[] = 'Ограничения или коэффициенты снижают приоритет кластера.';
        }

        return $reasons !== [] ? $reasons : ['Кластер выбран по балансу скорости доставки, локальности, ABC и риска отсутствия.'];
    }

    /**
     * @param array<string, mixed> $cluster
     */
    private function ozonDestinationDecisionText(array $cluster): string
    {
        $name = (string) ($cluster['cluster_name'] ?? $cluster['cluster_id'] ?? 'кластер');
        $score = (float) ($cluster['score'] ?? 0);
        $grade = (string) ($cluster['grade'] ?? 'без оценки');
        $qty = (int) ($cluster['qty'] ?? 0);
        $policy = (string) ($cluster['abc_policy_status'] ?? 'balanced');

        $policyText = match ($policy) {
            'a_speed_priority' => 'для A-товаров кластер получает приоритет за скорость закрытия спроса',
            'oos_speed_priority' => 'кластер помогает быстрее закрыть риск отсутствия товара',
            'coefficient_limited' => 'приоритет ограничен правилами или коэффициентами из файла',
            default => 'решение сбалансировано по скорости доставки, локальности, экономике и риску',
        };

        return "Кластер «{$name}»: {$grade}, балл {$score}, объём {$qty} шт.; {$policyText}.";
    }

    /**
     * @param array<string, mixed> $cluster
     */
    private function ozonClusterSpeedTier(array $cluster): string
    {
        $score = (float) ($cluster['demand_closure_score'] ?? 0);

        return match (true) {
            $score >= 90 => 'очень быстрый',
            $score >= 75 => 'быстрый',
            $score >= 55 => 'средний',
            default => 'медленный',
        };
    }

    /**
     * @param array<string, mixed> $cluster
     */
    private function ozonClusterConstraintStatus(array $cluster): string
    {
        $limitedQty = (int) ($cluster['coefficient_limited_qty'] ?? 0);
        $penalty = (float) ($cluster['coefficient_penalty'] ?? 0);

        if ($limitedQty > 0 || $penalty >= 35) {
            return 'ограничения влияют на объём';
        }

        if ($penalty >= 12) {
            return 'есть мягкие ограничения';
        }

        return 'ограничений нет';
    }

    /**
     * @param array<string, mixed> $cluster
     */
    private function ozonClusterInTransitStatus(array $cluster): string
    {
        $inTransitQty = (int) ($cluster['in_transit_qty'] ?? 0);
        $relief = (float) ($cluster['in_transit_relief'] ?? 0);

        if ($inTransitQty <= 0) {
            return 'товаров в пути нет';
        }

        if ($relief >= 45) {
            return 'потребность заметно закрыта товарами в пути';
        }

        return 'товары в пути частично снижают срочность';
    }

    /**
     * @param array<string, mixed> $cluster
     * @return array{status:string,title:string,recommendation:string,evidence:list<string>}
     */
    private function ozonClusterActionPlan(array $cluster): array
    {
        $name = (string) ($cluster['cluster_name'] ?? $cluster['cluster_id'] ?? 'кластер');
        $score = (float) ($cluster['score'] ?? 0);
        $demandScore = (float) ($cluster['demand_closure_score'] ?? 0);
        $lostProfit = (float) ($cluster['lost_profit'] ?? 0);
        $limitedQty = (int) ($cluster['coefficient_limited_qty'] ?? 0);
        $inTransitQty = (int) ($cluster['in_transit_qty'] ?? 0);
        $inTransitRelief = (float) ($cluster['in_transit_relief'] ?? 0);
        $abcAQty = (int) ($cluster['abc_a_qty'] ?? 0);
        $highRiskQty = (int) ($cluster['high_risk_qty'] ?? 0);

        $evidence = [
            "балл кластера: {$score}",
            "закрытие регионального спроса: {$demandScore}",
        ];

        if ($abcAQty > 0) {
            $evidence[] = "A-товары: {$abcAQty} шт.";
        }

        if ($highRiskQty > 0) {
            $evidence[] = "высокий риск OOS: {$highRiskQty} шт.";
        }

        if ($lostProfit > 0) {
            $evidence[] = 'упущенная маржа: ' . round($lostProfit, 2) . ' ₽';
        }

        if ($limitedQty > 0) {
            $evidence[] = "ограничения влияют на {$limitedQty} шт.";
        }

        if ($inTransitQty > 0) {
            $evidence[] = "в пути уже {$inTransitQty} шт.";
        }

        if ($limitedQty > 0) {
            return [
                'status' => 'check_constraints',
                'title' => "Проверить ограничения Ozon для кластера «{$name}»",
                'recommendation' => 'Перед черновиком поставки проверьте файл ограничений: часть объёма может быть недоступна или дорогая для приёмки.',
                'evidence' => $evidence,
            ];
        }

        if ($inTransitRelief >= 45 && $highRiskQty === 0) {
            return [
                'status' => 'lower_urgency_in_transit',
                'title' => "Снизить срочность по кластеру «{$name}»",
                'recommendation' => 'Часть потребности уже закрыта товарами в пути, поэтому не стоит автоматически добивать кластер до полного горизонта.',
                'evidence' => $evidence,
            ];
        }

        if (($abcAQty > 0 || $highRiskQty > 0) && $demandScore >= 75) {
            return [
                'status' => 'prioritize_fast_cluster',
                'title' => "Держать приоритет для кластера «{$name}»",
                'recommendation' => 'Кластер быстро закрывает спрос по важным товарам; его стоит оставлять выше при распределении бюджета и объёма.',
                'evidence' => $evidence,
            ];
        }

        if ($lostProfit > 0 && $score >= 70) {
            return [
                'status' => 'recover_lost_profit',
                'title' => "Закрывать упущенную маржу в кластере «{$name}»",
                'recommendation' => 'Поставка в этот кластер может снизить потери от нелокальных заказов и отсутствия товара.',
                'evidence' => $evidence,
            ];
        }

        return [
            'status' => 'balanced_replenishment',
            'title' => "Плановое пополнение кластера «{$name}»",
            'recommendation' => 'Кластер можно оставлять в плане, но без повышенного приоритета: решение сбалансировано по спросу, скорости, остаткам и экономике.',
            'evidence' => $evidence,
        ];
    }

    private function ozonLineRankReason(string $abc, float $speedScore, float $localityScore, float $lostProfit, float $constraintPenalty, float $inTransitRelief, string $risk): string
    {
        if ($this->normalizeAbcPriority($abc) === 'A' && $speedScore >= 70) {
            return 'A-товар ведём в быстрый кластер: скорость доставки важнее небольшой разницы в экономике.';
        }

        if ($risk === 'high' && $lostProfit > 0) {
            return 'Высокий риск отсутствия товара и потеря маржи: кластер поднят для быстрого закрытия спроса.';
        }

        if ($constraintPenalty >= 35) {
            return 'Кластер получает штраф: файл ограничений или коэффициенты мешают поставке.';
        }

        if ($inTransitRelief >= 35) {
            return 'Срочность снижена: часть потребности уже закрывают товары в пути.';
        }

        if ($localityScore >= 80) {
            return 'Кластер улучшает локальность и снижает переплату за доставку.';
        }

        return 'Кластер оценён по балансу скорости, локальности, риска отсутствия и экономики.';
    }

    private function ozonLinePolicyStatus(
        string $abc,
        float $speedScore,
        float $demandClosureScore,
        float $constraintPenalty,
        float $inTransitRelief,
        string $risk,
    ): string {
        $abc = $this->normalizeAbcPriority($abc);

        if ($abc === 'A' && ($speedScore >= 70 || $demandClosureScore >= 80)) {
            return 'a_speed_priority';
        }

        if ($risk === 'high' && $demandClosureScore >= 70) {
            return 'oos_speed_priority';
        }

        if ($constraintPenalty >= 30) {
            return 'coefficient_limited';
        }

        if ($inTransitRelief >= 35) {
            return 'balanced';
        }

        return 'balanced';
    }

    /**
     * @return list<string>
     */
    private function ozonLinePriorityReasons(
        string $abc,
        float $speedScore,
        float $demandClosureScore,
        float $localityScore,
        float $lostProfit,
        float $constraintPenalty,
        float $inTransitRelief,
        string $risk,
    ): array {
        $abc = $this->normalizeAbcPriority($abc);
        $reasons = [];

        if ($abc === 'A') {
            $reasons[] = $speedScore >= 70 || $demandClosureScore >= 80
                ? 'A-товар: быстрый кластер повышает приоритет поставки.'
                : 'A-товар: кластер не выглядит быстрым, нужна проверка направления.';
        } elseif ($abc === 'C') {
            $reasons[] = 'C-товар: система осторожнее относится к лишнему запасу и проверяет ограничения.';
        } else {
            $reasons[] = 'B-товар: скорость, локальность и экономика учитываются сбалансированно.';
        }

        if ($risk === 'high') {
            $reasons[] = 'Высокий риск отсутствия товара: скорость закрытия спроса повышает приоритет.';
        }

        if ($demandClosureScore >= 80) {
            $reasons[] = 'Кластер хорошо закрывает региональный спрос.';
        }

        if ($localityScore >= 80) {
            $reasons[] = 'Кластер улучшает локальность и снижает переплату за доставку.';
        }

        if ($lostProfit > 0) {
            $reasons[] = 'Есть упущенная маржа: поставка может снизить потери.';
        }

        if ($constraintPenalty >= 30) {
            $reasons[] = 'Ограничения или коэффициенты снижают приоритет.';
        }

        if ($inTransitRelief >= 35) {
            $reasons[] = 'Товары в пути уже закрывают часть потребности, срочность снижена.';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param list<string> $priorityReasons
     */
    private function ozonLineDecisionText(
        string $clusterName,
        string $abc,
        float $score,
        int $qty,
        string $policyStatus,
        array $priorityReasons,
    ): string {
        $policyText = match ($policyStatus) {
            'a_speed_priority' => 'выбран как быстрый кластер для A-товара',
            'oos_speed_priority' => 'выбран для снижения риска отсутствия товара',
            'coefficient_limited' => 'оставлен с ограничением из-за правил или коэффициентов',
            default => 'оценён по балансу скорости, локальности, экономики и риска',
        };
        $mainReason = $priorityReasons[0] ?? 'расчёт учитывает скорость доставки, локальность, ABC и риск отсутствия';

        return "Кластер «{$clusterName}» {$policyText}: {$qty} шт., балл {$score}. {$mainReason}";
    }

    private function ozonLineRecommendedAction(float $score, float $constraintPenalty, float $inTransitRelief, string $risk): string
    {
        if ($constraintPenalty >= 35) {
            return 'проверить ограничения перед поставкой';
        }

        if ($inTransitRelief >= 45 && $risk !== 'high') {
            return 'снизить срочность: часть потребности уже в пути';
        }

        if ($score >= 85 || ($risk === 'high' && $score >= 70)) {
            return 'можно включать в рекомендацию';
        }

        return 'оставить как плановое направление';
    }

    private function demandClosureScore(
        float $speedScore,
        float $regionalDemandFitScore,
        string $abc,
        string $risk,
        float $priorityScore,
        float $coefficientPenalty,
        float $capacityScore,
    ): float
    {
        $abcBoost = match ($this->normalizeAbcPriority($abc)) {
            'A' => 1.24,
            'B' => 1.05,
            default => 0.92,
        };
        $riskBoost = match ($risk) {
            'high' => 1.12,
            'med', 'medium' => 1.04,
            default => 1.0,
        };
        $priorityBoost = 1.0 + min(0.18, max(0.0, $priorityScore) / 600.0);
        $capacityMultiplier = $capacityScore < 60 ? 0.82 : 1.0;
        $constraintMultiplier = $coefficientPenalty >= 55 ? 0.78 : ($coefficientPenalty >= 30 ? 0.9 : 1.0);

        $baseScore = $speedScore * 0.62 + $regionalDemandFitScore * 0.38;

        return round(max(0.0, min(150.0, $baseScore * $abcBoost * $riskBoost * $priorityBoost * $capacityMultiplier * $constraintMultiplier)), 2);
    }

    private function coefficientPenalty(mixed $acceptanceCoefficient, mixed $storageCoefficient): float
    {
        $acceptance = is_numeric($acceptanceCoefficient) ? max(1.0, (float) $acceptanceCoefficient) : 1.0;
        $storage = is_numeric($storageCoefficient) ? max(1.0, (float) $storageCoefficient) : 1.0;
        $worst = max($acceptance, $storage);

        return round(min(100.0, ($worst - 1.0) * 18.0), 2);
    }

    private function capacityScore(mixed $capacityRemaining, int $qty): float
    {
        if ($capacityRemaining === null || ! is_numeric($capacityRemaining)) {
            return 72.0;
        }

        $capacity = (int) $capacityRemaining;
        if ($capacity <= 0) {
            return 0.0;
        }

        if ($capacity < $qty) {
            return max(20.0, round($capacity / max(1, $qty) * 70.0, 2));
        }

        return 100.0;
    }

    private function abcPriorityScore(string $abc): float
    {
        return match ($this->normalizeAbcPriority($abc)) {
            'A' => 100.0,
            'B' => 68.0,
            default => 38.0,
        };
    }

    private function oosUrgencyScore(string $risk, float $priorityScore): float
    {
        $riskScore = match ($risk) {
            'high' => 100.0,
            'med', 'medium' => 64.0,
            default => 28.0,
        };

        return round(min(120.0, $riskScore + max(0.0, $priorityScore) * 0.18), 2);
    }

    /**
     * @param array<string, mixed> $warehouse
     */
    private function warehouseAbcPolicyStatus(array $warehouse): string
    {
        if ((int) ($warehouse['abc_a_qty'] ?? 0) > 0 && ! empty($warehouse['is_fast_for_a_items'])) {
            return 'a_speed_priority';
        }

        if ((float) ($warehouse['coefficient_penalty'] ?? 0) >= 30) {
            return 'coefficient_limited';
        }

        if ((int) ($warehouse['abc_a_qty'] ?? 0) === 0 && (float) ($warehouse['score_factors']['стоимость_и_коэффициенты'] ?? 0) >= 80) {
            return 'c_cost_priority';
        }

        return 'balanced';
    }

    /**
     * @param array<string, mixed> $warehouse
     * @return list<string>
     */
    private function warehousePriorityReasons(array $warehouse): array
    {
        $reasons = [];

        if ((int) ($warehouse['abc_a_qty'] ?? 0) > 0) {
            $reasons[] = ! empty($warehouse['is_fast_for_a_items'])
                ? 'A-товары: скорость закрытия спроса имеет повышенный вес.'
                : 'A-товары есть, но склад не выглядит быстрым для большей части объёма.';
        }

        if ((int) ($warehouse['high_risk_qty'] ?? 0) > 0) {
            $reasons[] = 'Есть высокий риск отсутствия товара: склад влияет на защиту от потери продаж.';
        }

        if ((float) ($warehouse['demand_closure_score'] ?? 0) >= 80) {
            $reasons[] = 'Склад хорошо закрывает региональный спрос.';
        }

        $slotLeadTimeDays = $warehouse['score_factors']['ближайший_слот_дней'] ?? null;
        if (is_numeric($slotLeadTimeDays) && (int) $slotLeadTimeDays <= 3) {
            $reasons[] = 'Ближайший слот доступен быстро.';
        } elseif (is_numeric($slotLeadTimeDays) && (int) $slotLeadTimeDays >= 10) {
            $reasons[] = 'Ближайший слот далеко, скорость направления ниже.';
        }

        if ((float) ($warehouse['score_factors']['стоимость_и_коэффициенты'] ?? 0) >= 80) {
            $reasons[] = 'Коэффициенты и хранение выглядят комфортно.';
        }

        if ((float) ($warehouse['coefficient_penalty'] ?? 0) >= 30) {
            $reasons[] = 'Дорогие коэффициенты или ограничения снижают приоритет.';
        }

        if (($warehouse['score_factors']['доступная_ёмкость'] ?? null) !== null && (float) ($warehouse['score_factors']['доступность_емкости'] ?? 100) < 70) {
            $reasons[] = 'Доступная ёмкость ограничивает объём поставки.';
        }

        return $reasons !== [] ? $reasons : ['Склад выбран по балансу скорости, стоимости, ABC и риска отсутствия товара.'];
    }

    /**
     * @param array<string, mixed> $warehouse
     */
    private function warehouseDecisionText(array $warehouse): string
    {
        $name = (string) ($warehouse['warehouse_name'] ?? $warehouse['warehouse_id'] ?? 'склад');
        $score = (float) ($warehouse['score'] ?? 0);
        $grade = (string) ($warehouse['grade'] ?? 'без оценки');
        $qty = (int) ($warehouse['qty'] ?? 0);
        $policy = (string) ($warehouse['abc_policy_status'] ?? 'balanced');

        $policyText = match ($policy) {
            'a_speed_priority' => 'для A-товаров склад получает приоритет за скорость закрытия спроса',
            'c_cost_priority' => 'для планового/C-спроса склад выгоден по коэффициентам и стоимости',
            'coefficient_limited' => 'приоритет ограничен дорогими коэффициентами или правилами приёмки',
            default => 'решение сбалансировано по скорости, стоимости и доступности',
        };

        return "Склад «{$name}»: {$grade}, балл {$score}, объём {$qty} шт.; {$policyText}.";
    }

    /**
     * @param array<string, mixed> $warehouse
     */
    private function warehouseSpeedTier(array $warehouse): string
    {
        $speedScore = (float) ($warehouse['score_factors']['скорость_закрытия_спроса'] ?? 0);
        $demandClosureScore = (float) ($warehouse['demand_closure_score'] ?? 0);

        return match (true) {
            $speedScore >= 85 || $demandClosureScore >= 90 => 'очень быстрый',
            $speedScore >= 70 || $demandClosureScore >= 75 => 'быстрый',
            $speedScore >= 45 || $demandClosureScore >= 55 => 'средний',
            default => 'медленный',
        };
    }

    /**
     * @param array<string, mixed> $warehouse
     */
    private function warehouseCostTier(array $warehouse): string
    {
        $costScore = (float) ($warehouse['score_factors']['стоимость_и_коэффициенты'] ?? 0);
        $penalty = (float) ($warehouse['coefficient_penalty'] ?? 0);

        return match (true) {
            $penalty >= 45 || $costScore < 45 => 'дорого/ограничено',
            $penalty >= 25 || $costScore < 65 => 'нужна проверка стоимости',
            $costScore >= 85 => 'выгодно',
            default => 'нормально',
        };
    }

    /**
     * @param array<string, mixed> $warehouse
     */
    private function warehouseCapacityStatus(array $warehouse): string
    {
        $capacityScore = (float) ($warehouse['score_factors']['доступность_емкости'] ?? 72);
        $capacity = $warehouse['score_factors']['доступная_ёмкость'] ?? null;

        if ($capacity !== null && is_numeric($capacity) && (float) $capacity <= 0) {
            return 'нет доступной ёмкости';
        }

        return match (true) {
            $capacityScore >= 95 => 'ёмкости достаточно',
            $capacityScore >= 70 => 'ёмкость нормальная',
            $capacityScore >= 40 => 'ёмкость ограничена',
            default => 'ёмкости может не хватить',
        };
    }

    /**
     * @param array<string, mixed> $warehouse
     * @return array<string, mixed>
     */
    private function warehouseActionPlan(array $warehouse): array
    {
        $score = (float) ($warehouse['score'] ?? 0);
        $qty = (int) ($warehouse['qty'] ?? 0);
        $abcAQty = (int) ($warehouse['abc_a_qty'] ?? 0);
        $highRiskQty = (int) ($warehouse['high_risk_qty'] ?? 0);
        $coefficientPenalty = (float) ($warehouse['coefficient_penalty'] ?? 0);
        $capacityStatus = $this->warehouseCapacityStatus($warehouse);
        $speedTier = $this->warehouseSpeedTier($warehouse);
        $costTier = $this->warehouseCostTier($warehouse);
        $slotLeadTimeDays = $warehouse['score_factors']['ближайший_слот_дней'] ?? null;

        $status = 'plan';
        $title = 'Оставить как плановое направление';
        $recommendation = 'Склад можно использовать для обычного пополнения после проверки экономики и лимитов.';
        $priority = 'normal';

        if (str_contains($capacityStatus, 'не хватить') || str_contains($capacityStatus, 'нет доступной')) {
            $status = 'capacity_limited';
            $title = 'Ограничить объём из-за ёмкости';
            $recommendation = 'Не увеличивайте поставку на этот склад без проверки доступной ёмкости или альтернативных складов.';
            $priority = 'high';
        } elseif ($coefficientPenalty >= 35) {
            $status = 'review_cost';
            $title = 'Проверить коэффициенты перед поставкой';
            $recommendation = 'Склад может быть дорогим или ограниченным: сравните с быстрым альтернативным складом перед отгрузкой.';
            $priority = 'medium';
        } elseif ($abcAQty > 0 && ! empty($warehouse['is_fast_for_a_items'])) {
            $status = 'prioritize_a_items';
            $title = 'Вести сюда A-товары';
            $recommendation = 'Для A-товаров этот склад стоит держать в приоритете: скорость закрытия спроса важнее небольшой разницы в стоимости.';
            $priority = 'high';
        } elseif ($highRiskQty > 0 && $score >= 70) {
            $status = 'protect_oos';
            $title = 'Использовать для защиты от отсутствия';
            $recommendation = 'Склад подходит для товаров с высоким риском отсутствия: он быстрее закрывает спрос по текущему профилю.';
            $priority = 'high';
        } elseif ($score >= 75) {
            $status = 'increase';
            $title = 'Можно увеличивать поставку';
            $recommendation = 'Склад выглядит сильным по балансу скорости, стоимости, ёмкости и регионального спроса.';
            $priority = 'medium';
        } elseif ($score < 55) {
            $status = 'avoid';
            $title = 'Не приоритет для новой поставки';
            $recommendation = 'Склад слабее альтернатив: используйте его только если быстрые или дешёвые направления недоступны.';
            $priority = 'medium';
        }

        return [
            'status' => $status,
            'title' => $title,
            'priority' => $priority,
            'recommended_qty_hint' => $qty,
            'decision_ru' => $recommendation,
            'speed_tier' => $speedTier,
            'cost_tier' => $costTier,
            'capacity_status' => $capacityStatus,
            'evidence' => array_values(array_filter([
                "скорость: {$speedTier}",
                is_numeric($slotLeadTimeDays) ? 'ближайший слот: ' . (int) $slotLeadTimeDays . ' дн.' : null,
                "стоимость: {$costTier}",
                "ёмкость: {$capacityStatus}",
                $abcAQty > 0 ? "A-товары: {$abcAQty} шт." : null,
                $highRiskQty > 0 ? "высокий риск отсутствия: {$highRiskQty} шт." : null,
            ])),
        ];
    }

    private function lineAbcPolicyStatus(
        string $abc,
        float $speedScore,
        float $costScore,
        float $coefficientPenalty,
        string $risk,
    ): string {
        $abc = $this->normalizeAbcPriority($abc);

        if ($abc === 'A' && $speedScore >= 70) {
            return 'a_speed_priority';
        }

        if ($risk === 'high' && $speedScore >= 60) {
            return 'oos_speed_priority';
        }

        if ($coefficientPenalty >= 30) {
            return 'coefficient_limited';
        }

        if ($abc === 'C' && $costScore >= 80) {
            return 'c_cost_priority';
        }

        return 'balanced';
    }

    /**
     * @return list<string>
     */
    private function linePriorityReasons(
        string $abc,
        float $speedScore,
        float $demandClosureScore,
        float $costScore,
        float $coefficientPenalty,
        float $capacityScore,
        string $risk,
        float $regionalDemandFitScore,
        ?int $slotLeadTimeDays = null,
    ): array {
        $abc = $this->normalizeAbcPriority($abc);
        $reasons = [];

        if ($abc === 'A') {
            $reasons[] = $speedScore >= 70
                ? 'A-товар: скорость склада повышает приоритет поставки.'
                : 'A-товар: скорость склада не выглядит сильной, нужна проверка направления.';
        } elseif ($abc === 'C') {
            $reasons[] = $costScore >= 80
                ? 'C-товар: стоимость и коэффициенты важнее лишней скорости.'
                : 'C-товар: дорогие коэффициенты могут привести к лишнему запасу.';
        } else {
            $reasons[] = 'B-товар: скорость и стоимость учитываются сбалансированно.';
        }

        if ($risk === 'high') {
            $reasons[] = 'Высокий риск отсутствия товара: скорость закрытия спроса повышает приоритет.';
        }

        if ($demandClosureScore >= 80) {
            $reasons[] = 'Склад хорошо закрывает региональный спрос.';
        } elseif ($regionalDemandFitScore < 45) {
            $reasons[] = 'Склад слабо совпадает с региональным спросом.';
        }

        if ($slotLeadTimeDays !== null && $slotLeadTimeDays <= 3) {
            $reasons[] = 'Ближайший слот доступен быстро.';
        } elseif ($slotLeadTimeDays !== null && $slotLeadTimeDays >= 10) {
            $reasons[] = 'Ближайший слот далеко: нужна проверка альтернатив.';
        }

        if ($coefficientPenalty >= 30) {
            $reasons[] = 'Коэффициенты или ограничения снижают приоритет.';
        } elseif ($costScore >= 80) {
            $reasons[] = 'Коэффициенты и хранение выглядят комфортно.';
        }

        if ($capacityScore < 60) {
            $reasons[] = 'Ёмкость склада может ограничить рекомендуемое количество.';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param list<string> $priorityReasons
     */
    private function lineDecisionText(
        string $warehouseName,
        string $abc,
        float $score,
        int $qty,
        string $policyStatus,
        array $priorityReasons,
    ): string {
        $policyText = match ($policyStatus) {
            'a_speed_priority' => 'выбран как быстрый склад для A-товара',
            'oos_speed_priority' => 'выбран для снижения риска отсутствия',
            'c_cost_priority' => 'выбран как экономичный склад для планового пополнения',
            'coefficient_limited' => 'оставлен с ограничением из-за коэффициентов',
            default => 'оценён по балансу скорости, стоимости и риска',
        };
        $mainReason = $priorityReasons[0] ?? 'расчёт учитывает скорость, стоимость, ABC и риск отсутствия';

        return "Склад «{$warehouseName}» {$policyText}: {$qty} шт., балл {$score}. {$mainReason}";
    }

    private function lineRecommendedAction(float $score, float $coefficientPenalty, float $capacityScore, string $risk): string
    {
        if ($coefficientPenalty >= 35 || $capacityScore < 50) {
            return 'проверить перед поставкой';
        }

        if ($score >= 85 || ($risk === 'high' && $score >= 70)) {
            return 'можно включать в рекомендацию';
        }

        return 'оставить как плановое направление';
    }

    private function lineActionStatus(float $score, float $coefficientPenalty, float $capacityScore, string $risk, string $abc): string
    {
        if ($capacityScore < 50) {
            return 'capacity_limited';
        }

        if ($coefficientPenalty >= 35) {
            return 'review_cost';
        }

        if ($this->normalizeAbcPriority($abc) === 'A' && $score >= 70) {
            return 'prioritize_a_items';
        }

        if ($risk === 'high' && $score >= 70) {
            return 'protect_oos';
        }

        if ($score >= 80) {
            return 'increase';
        }

        if ($score < 55) {
            return 'avoid';
        }

        return 'plan';
    }

    /**
     * @param array<string, mixed> $warehouse
     */
    private function warehouseRankReason(array $warehouse): string
    {
        if (! empty($warehouse['is_fast_for_a_items'])) {
            return 'Склад поднят в ранжировании: скорость закрытия спроса для A-товаров важнее небольшой разницы в стоимости.';
        }

        if ((float) ($warehouse['coefficient_penalty'] ?? 0) >= 35) {
            return 'Склад снижен в ранжировании из-за дорогих коэффициентов или ограничений приёмки.';
        }

        if ((float) ($warehouse['demand_closure_score'] ?? 0) >= 80) {
            return 'Склад хорошо закрывает региональный спрос и подходит для приоритетного пополнения.';
        }

        return 'Склад оставлен как плановое направление: решение зависит от дефицита, стоимости и доступной ёмкости.';
    }

    private function lineRankReason(string $abc, float $speedScore, float $costScore, float $coefficientPenalty, float $capacityScore, string $risk): string
    {
        if ($this->normalizeAbcPriority($abc) === 'A' && $speedScore >= 75) {
            return 'A-товар ведём в быстрый склад: скорость важнее небольшой разницы в стоимости.';
        }

        if ($risk === 'high' && $speedScore >= 65) {
            return 'Высокий риск отсутствия товара: склад выбран выше за скорость закрытия спроса.';
        }

        if ($coefficientPenalty >= 35) {
            return 'Склад получает штраф: коэффициенты или ограничения делают поставку дорогой.';
        }

        if ($capacityScore < 60) {
            return 'Склад получает штраф: доступной ёмкости может не хватить на рекомендацию.';
        }

        if ($costScore >= 80) {
            return 'Склад экономически комфортный: коэффициенты и хранение не выглядят дорогими.';
        }

        return 'Склад оценён по балансу скорости, стоимости, ABC и риска отсутствия товара.';
    }

    /**
     * Финансовый вес нужен для второй версии прочтения КТР: не только
     * "сколько штук ушло в хорошие направления", но и насколько ценный объём
     * оказался в быстрых/приоритетных складах или кластерах.
     *
     * @param array<string, mixed> $line
     */
    private function lineFinancialWeight(array $line, int $qty): float
    {
        $expectedRevenue = (float) ($line['expected_revenue'] ?? 0);
        if ($expectedRevenue > 0) {
            return $expectedRevenue;
        }

        $supplyCost = (float) ($line['supply_cost_estimate'] ?? 0);
        if ($supplyCost > 0) {
            return $supplyCost;
        }

        $expectedProfit = (float) ($line['expected_profit'] ?? 0);
        if ($expectedProfit > 0) {
            return $expectedProfit;
        }

        return (float) max(0, $qty);
    }

    private function financialKtrPolicyStatus(?float $financialValue, float $qtyValue, ?float $financialPriorityShare): string
    {
        if ($financialValue === null) {
            return 'финансовый вес не рассчитан: нет ожидаемой выручки или стоимости строк';
        }

        if ($financialValue + 8 < $qtyValue) {
            return 'ценный товар распределён хуже, чем общий объём: проверьте дорогие SKU в слабых направлениях';
        }

        if (($financialPriorityShare ?? 0) >= 80) {
            return 'ценный объём в основном попадает в приоритетные направления';
        }

        return 'финансовое распределение требует проверки: часть ценного объёма не в приоритетных направлениях';
    }

    private function formatNumberRu(float $value): string
    {
        return number_format($value, 1, ',', ' ');
    }
}
