<?php

namespace App\Services\AutoSupplyPlanning;

use App\Models\AutoSupplyPlan;

class PlanLineOptimizer
{
    /**
     * @param list<array<string, mixed>> $lines
     * @return array{lines:list<array<string, mixed>>, summary:array<string, mixed>}
     */
    public function optimize(array $lines, AutoSupplyPlan $plan, array $context = []): array
    {
        $mode = (string) ($plan->params['planning_mode'] ?? $plan->mode ?? AutoSupplyPlan::MODE_BALANCED);
        $skipNegativeProfit = (bool) ($plan->params['skip_negative_profit'] ?? false);
        $budgetLimit = (float) ($plan->budget_limit ?? 0);
        $constraintsSummary = is_array($context['constraints_summary'] ?? null) ? $context['constraints_summary'] : [];
        $sourceCandidatesTotal = (int) ($context['source_candidates_total'] ?? count($lines));
        $sourceQtyTotal = (int) ($context['source_qty_total'] ?? array_sum(array_map(
            static fn (array $line): int => (int) ($line['qty_rounded'] ?? 0),
            $lines
        )));

        $candidates = [];
        $skippedByReason = [];
        $candidateAudit = [];
        $quantityGuardCappedLines = 0;
        $quantityGuardReducedQty = 0;

        foreach ($lines as $line) {
            $line = $this->enrichCandidate($line, $mode, $context);
            $explainAfterEnrichment = $this->decodeExplain($line);
            $optimizerQuantityGuard = is_array($explainAfterEnrichment['optimizer_quantity_guard'] ?? null)
                ? $explainAfterEnrichment['optimizer_quantity_guard']
                : [];
            if (! empty($optimizerQuantityGuard['applied'])) {
                $quantityGuardCappedLines++;
                $quantityGuardReducedQty += max(0, (int) ($optimizerQuantityGuard['qty_before'] ?? 0) - (int) ($optimizerQuantityGuard['qty_after'] ?? 0));
            }

            if ($skipNegativeProfit && (float) ($line['expected_profit'] ?? 0) < 0) {
                $skippedByReason['negative_profit'] = ($skippedByReason['negative_profit'] ?? 0) + 1;
                $candidateAudit[] = $this->candidateAuditRow($line, 'отсечено', 'negative_profit');
                continue;
            }

            $candidates[] = $line;
            $candidateAudit[] = $this->candidateAuditRow($line, 'кандидат', 'passed_initial_filters');
        }

        usort($candidates, static fn (array $a, array $b): int => [
            (float) ($b['_planning_score'] ?? 0),
            (float) ($b['priority_score'] ?? 0),
            (float) ($b['roi_percent'] ?? 0),
            (int) ($b['qty_rounded'] ?? 0),
        ] <=> [
            (float) ($a['_planning_score'] ?? 0),
            (float) ($a['priority_score'] ?? 0),
            (float) ($a['roi_percent'] ?? 0),
            (int) ($a['qty_rounded'] ?? 0),
        ]);

        $selected = [];
        $budgetUsed = 0.0;
        $budgetSkippedRows = [];
        $budgetSelectionPolicy = $budgetLimit > 0 ? 'score_knapsack_v1' : 'score_order_no_budget';

        if ($budgetLimit > 0) {
            [$selectedCandidates, $budgetSkippedCandidates, $budgetUsed] = $this->selectWithinBudget($candidates, $budgetLimit);

            foreach ($budgetSkippedCandidates as $line) {
                $skippedByReason['budget_limit'] = ($skippedByReason['budget_limit'] ?? 0) + 1;
                $budgetSkippedRows[] = $this->candidateAuditRow($line, 'отсечено', 'budget_limit');
            }

            foreach ($selectedCandidates as $line) {
                $selected[] = $this->markSelected($line);
            }
        } else {
            foreach ($candidates as $line) {
                $selected[] = $this->markSelected($line);
            }
        }

        $selectedDecision = $budgetLimit > 0 ? 'selected_by_budget_optimization' : 'selected_by_score';
        $selectedAuditRows = array_map(
            fn (array $line): array => $this->candidateAuditRow($line, 'выбрано', $selectedDecision),
            $selected
        );
        $candidateAudit = array_merge($selectedAuditRows, $budgetSkippedRows, array_filter(
            $candidateAudit,
            static fn (array $row): bool => ($row['status'] ?? null) === 'отсечено'
        ));

        $reasonBreakdown = [];
        $selectedDeficitLines = 0;
        $selectedSurplusLines = 0;
        $selectedWithInTransitLines = 0;
        foreach ($selected as $line) {
            $explain = $this->decodeExplain($line);
            $reason = (string) ($explain['reason'] ?? 'replenishment');
            $reasonBreakdown[$reason] = ($reasonBreakdown[$reason] ?? 0) + 1;
            $selectedDeficitLines += (int) (($explain['facts']['deficit_qty'] ?? 0) > 0);
            $selectedSurplusLines += (int) (($explain['facts']['surplus_qty'] ?? 0) > 0);
            $selectedWithInTransitLines += (int) (($explain['facts']['in_transit_qty'] ?? 0) > 0);
        }

        $selected = array_map(static function (array $line): array {
            unset($line['_planning_score'], $line['_planning_reason']);

            return $line;
        }, $selected);

        $funnelStages = $this->buildFunnelStages(
            sourceCandidatesTotal: $sourceCandidatesTotal,
            sourceQtyTotal: $sourceQtyTotal,
            constraintsSummary: $constraintsSummary,
            candidatesAfterConstraints: count($lines),
            candidatesAfterEconomics: count($candidates),
            selectedLines: count($selected),
            skippedByReason: $skippedByReason,
            budgetLimit: $budgetLimit,
            budgetUsed: $budgetUsed,
        );
        $portfolioSummary = $this->portfolioSummary($selected);

        return [
            'lines' => $selected,
            'summary' => [
                'mode' => $mode,
                'candidates_total' => count($lines),
                'selected_lines' => count($selected),
                'skipped_lines' => array_sum($skippedByReason),
                'skipped_by_reason' => $skippedByReason,
                'reason_breakdown' => $reasonBreakdown,
                'budget_limit' => $budgetLimit > 0 ? $budgetLimit : null,
                'budget_used' => $budgetLimit > 0 ? round($budgetUsed, 2) : null,
                'budget_selection_policy' => $budgetSelectionPolicy,
                'budget_skipped_lines' => $skippedByReason['budget_limit'] ?? 0,
                'negative_profit_skipped_lines' => $skippedByReason['negative_profit'] ?? 0,
                'selected_deficit_lines' => $selectedDeficitLines,
                'selected_surplus_lines' => $selectedSurplusLines,
                'selected_with_in_transit_lines' => $selectedWithInTransitLines,
                'quantity_guard_capped_lines' => $quantityGuardCappedLines,
                'quantity_guard_reduced_qty' => $quantityGuardReducedQty,
                'portfolio_summary' => $portfolioSummary,
                'candidate_audit' => [
                    'version' => 'optimizer-audit-2',
                    'pipeline' => [
                        '1. Собрали все строки-кандидаты после фильтра выбранных кластеров/складов.',
                        '2. Применили ограничения маркетплейса: закрытые направления удалены, лимиты количества срезаны.',
                        '3. Проверили экономику: убыточные строки отсекли или пометили согласно настройке.',
                        '4. Каждой строке посчитали балл по дефициту, риску отсутствия товара, ABC, ROI, бюджету, локальности, товарам в пути и уверенности данных.',
                        '5. Если бюджет задан, подобрали лучший набор строк под бюджет; если бюджета нет, оставили все подходящие строки.',
                    ],
                    'budget_selection_policy' => $budgetSelectionPolicy,
                    'funnel_stages' => $funnelStages,
                    'funnel_summary_ru' => $this->funnelSummaryText($funnelStages),
                    'portfolio_summary_ru' => $portfolioSummary['decision_ru'],
                    'evaluated_lines' => count($lines),
                    'eligible_candidates' => count($candidates),
                    'selected_lines' => count($selected),
                    'skipped_lines' => array_sum($skippedByReason),
                    'quantity_guard_capped_lines' => $quantityGuardCappedLines,
                    'quantity_guard_reduced_qty' => $quantityGuardReducedQty,
                    'audit_rows' => array_slice($candidateAudit, 0, 16),
                    'top_selected' => array_slice($selectedAuditRows, 0, 8),
                    'top_rejected' => array_slice(array_values(array_filter(
                        $candidateAudit,
                        static fn (array $row): bool => ($row['status'] ?? null) === 'отсечено'
                    )), 0, 8),
                    'decision_ru' => $this->optimizerDecisionText($mode, $budgetLimit, $skipNegativeProfit),
                ],
                'score_policy' => [
                    'version' => 'optimizer-5',
                    'deficit' => 'Дефицит и риск отсутствия товара повышают приоритет строки.',
                    'surplus' => 'Профицит снижает приоритет: лишний запас не должен маскироваться под новую потребность.',
                    'in_transit' => 'Товары в пути уменьшают срочность, если уже закрывают потребность.',
                    'abc' => 'A-товары получают больший вес, особенно в режимах защиты от отсутствия товара и баланса.',
                    'demand_closure' => 'Направления, которые быстрее закрывают региональный спрос, получают отдельный балл до финального выбора.',
                    'economics' => 'ROI и прибыль учитываются после проверки дефицита, риска и ограничений.',
                    'budget' => 'Если задан бюджет, модуль выбора подбирает набор строк с максимальным суммарным баллом внутри бюджета, а не просто берёт строки сверху вниз.',
                    'strategic_portfolio' => 'Внутри бюджета A-товары в быстрых направлениях, риск отсутствия товара и подтверждённые потребности маркетплейса получают дополнительный стратегический вес.',
                    'candidate_audit' => 'В сводке сохраняется проверка выбора: какие строки были кандидатами, какие выбраны и какие отсечены.',
                    'funnel' => 'Воронка показывает путь от всех кандидатов до финального набора: ограничения, экономика, бюджет и выбор.',
                    'marketplace_needs' => 'Потребности маркетплейса повышают приоритет строки, но не отменяют экономику, бюджет и ограничения.',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private function enrichCandidate(array $line, string $mode, array $context): array
    {
        $explain = $this->decodeExplain($line);
        [$line, $explain] = $this->applyOptimizerQuantityGuard($line, $explain, $mode);
        $reason = $this->detectReason($line, $explain, $context);
        $rawScore = $this->score($line, $explain, $mode, $reason);
        $score = $this->guardedScore($rawScore, $reason);
        $selectionReasons = $this->selectionReasons($line, $explain, $reason);
        $scoreComponents = $this->scoreComponents($line, $explain, $reason);
        $mainScoreFactors = $this->mainScoreFactorsRu($scoreComponents);

        $explain['reason'] = $reason;
        $explain['planning_decision'] = [
            'mode' => $mode,
            'selected' => false,
            'score' => round($score, 2),
            'score_basis' => $selectionReasons,
            'score_basis_labels' => $this->scoreBasisLabels($selectionReasons),
            'score_components' => $scoreComponents,
            'main_score_factors_ru' => $mainScoreFactors,
            'score_component_explanation_ru' => $this->scoreComponentExplanationRu($mainScoreFactors),
            'raw_score_before_guard' => round($rawScore, 2),
            'guardrail_ru' => $this->scoreGuardrailText($rawScore, $score, $reason),
        ];
        $explain['facts'] = array_merge($explain['facts'] ?? [], [
            'deficit_qty' => $this->deficitQty($line, $explain),
            'surplus_qty' => $this->surplusQty($line, $explain),
            'in_transit_qty' => (int) ($line['in_transit'] ?? 0),
            'in_transit_coverage_days' => $this->inTransitCoverageDays($line, $explain),
            'abc_priority' => $this->abcPriority($explain),
            'lost_revenue_daily' => (float) ($line['lost_revenue_daily'] ?? 0),
            'expected_profit' => (float) ($line['expected_profit'] ?? 0),
            'roi_percent' => (float) ($line['roi_percent'] ?? 0),
            'marketplace_need_qty' => $this->marketplaceNeedQty($explain),
            'marketplace_need_gap_qty' => $this->marketplaceNeedGapQty($line, $explain),
        ]);

        $line['explain_json'] = $this->encodeExplain($explain);
        $line['_planning_score'] = $score;
        $line['_planning_reason'] = $reason;

        return $line;
    }

    /**
     * Финальный предохранитель от завышения количества после всплесков.
     * Основной cap уже стоит в расчётном job, но модуль выбора тоже должен быть
     * самодостаточным: старые планы, импортированные строки и marketplace-needs
     * не должны превращать артикулы с низкой достоверностью в уверенную большую поставку.
     *
     * @param array<string, mixed> $line
     * @param array<string, mixed> $explain
     * @return array{0:array<string, mixed>,1:array<string, mixed>}
     */
    private function applyOptimizerQuantityGuard(array $line, array $explain, string $mode): array
    {
        $qty = max(0, (int) ($line['qty_rounded'] ?? 0));
        if ($qty <= 0) {
            return [$line, $explain];
        }

        $confidence = (string) ($explain['confidence']['confidence_level'] ?? 'good');
        $confidenceReasons = array_values(array_filter((array) ($explain['confidence']['confidence_reasons'] ?? [])));
        $promoReasons = [
            'promo_spike_suspected',
            'promo_spike_peak_share',
            'promo_spike_peak_vs_median',
            'recent_spike_vs_period',
            'post_promo_cooldown',
            'no_recent_sales_after_30d_spike',
            'external_sources_capped_by_spike_guard',
            'aggregate_recent_decline',
            'fallback_long',
        ];
        $hasPromoSpike = array_intersect($confidenceReasons, $promoReasons) !== [];
        $isLowConfidence = in_array($confidence, ['low', 'bad'], true);
        $isPostPromoMode = $mode === AutoSupplyPlan::MODE_POST_PROMO_CAREFUL
            || in_array((string) ($explain['inputs']['promo_mode'] ?? ''), ['post_promo', 'cautious'], true);

        if (! $isLowConfidence && ! $hasPromoSpike && ! $isPostPromoMode) {
            return [$line, $explain];
        }

        $marketplaceNeed = is_array($explain['marketplace_needs'] ?? null) ? $explain['marketplace_needs'] : [];
        $marketplaceNeedQty = max(0, (int) ($marketplaceNeed['need_qty'] ?? 0));
        if ($marketplaceNeedQty > 0 && empty($marketplaceNeed['remaining_gap_qty'])) {
            $explain['optimizer_quantity_guard'] = [
                'applied' => false,
                'reason' => 'marketplace_need_confirmed',
                'decision_ru' => 'Защита количества не снизила строку: файл маркетплейса явно подтвердил потребность, дальше решение контролируют экономика, бюджет и ограничения.',
            ];

            return [$line, $explain];
        }

        $dailyDemand = (float) ($line['demand_daily']
            ?? $line['real_avg_daily_sales']
            ?? $line['effective_daily_sales']
            ?? $explain['inputs']['daily_demand']
            ?? $explain['facts']['daily_demand']
            ?? 0);
        if ($dailyDemand <= 0) {
            return [$line, $explain];
        }

        $currentStock = max(0, (int) ($line['current_stock'] ?? $line['stock_total'] ?? 0));
        $inTransit = max(0, (int) ($line['in_transit'] ?? $explain['facts']['in_transit_qty'] ?? 0));
        $packMultiple = max(1, (int) ($line['pack_multiple'] ?? $explain['inputs']['pack_multiple'] ?? 1));
        $trialCoverDays = ($hasPromoSpike || $isPostPromoMode) ? 7 : 14;
        if (in_array('no_recent_sales_after_30d_spike', $confidenceReasons, true)) {
            $trialCoverDays = 3;
        }
        $safetyDays = ($hasPromoSpike || $isPostPromoMode) ? 2 : 3;
        $available = $currentStock + $inTransit;
        $capRaw = max(0.0, $dailyDemand * ($trialCoverDays + $safetyDays) - $available);
        $capQty = max($packMultiple, (int) (ceil($capRaw / $packMultiple) * $packMultiple));

        if ($capQty >= $qty) {
            $explain['optimizer_quantity_guard'] = [
                'applied' => false,
                'reason' => $hasPromoSpike || $isPostPromoMode ? 'post_promo_trial_cap_not_needed' : 'low_confidence_trial_cap_not_needed',
                'cap_qty' => $capQty,
                'trial_cover_days' => $trialCoverDays,
                'decision_ru' => 'Защита количества проверила строку, но текущая рекомендация уже не выше осторожного trial-лимита.',
            ];

            return [$line, $explain];
        }

        foreach (['supply_cost_estimate', 'expected_revenue', 'expected_profit'] as $moneyKey) {
            if (isset($line[$moneyKey]) && $qty > 0) {
                $line[$moneyKey] = round(((float) $line[$moneyKey]) * ($capQty / $qty), 2);
            }
        }

        $line['qty_rounded'] = $capQty;
        $line['qty_recommended'] = min((float) ($line['qty_recommended'] ?? $capQty), (float) $capQty);
        $explain['optimizer_quantity_guard'] = [
            'applied' => true,
            'reason' => $hasPromoSpike || $isPostPromoMode
                ? 'protective_post_promo_trial_quantity'
                : 'protective_low_confidence_trial_quantity',
            'qty_before' => $qty,
            'qty_after' => $capQty,
            'reduced_by_qty' => $qty - $capQty,
            'trial_cover_days' => $trialCoverDays,
            'safety_days' => $safetyDays,
            'daily_demand' => round($dailyDemand, 4),
            'available_qty' => $available,
            'confidence_level' => $confidence,
            'confidence_reasons' => $confidenceReasons,
            'decision_ru' => "Модуль выбора снизил количество с {$qty} до {$capQty} шт.: спрос похож на всплеск или данные низкой достоверности, поэтому ставим пробную поставку на {$trialCoverDays} дн. плюс страховой запас.",
        ];
        $explain['confidence']['confidence_reasons'] = array_values(array_unique(array_merge(
            $confidenceReasons,
            ['optimizer_protective_trial_quantity_cap']
        )));

        return [$line, $explain];
    }

    private function guardedScore(float $score, string $reason): float
    {
        return match ($reason) {
            'not_recommended_low_confidence' => min($score, 45.0),
            'not_recommended_negative_profit' => min($score, 20.0),
            default => $score,
        };
    }

    private function scoreGuardrailText(float $rawScore, float $guardedScore, string $reason): ?string
    {
        if ($guardedScore >= $rawScore) {
            return null;
        }

        return match ($reason) {
            'not_recommended_low_confidence' => 'Защита данных ограничила балл: строка похожа на всплеск или рассчитана по слабым источникам, поэтому не может победить только за счёт ROI/прибыли.',
            'not_recommended_negative_profit' => 'Защита экономики ограничила балл: строка убыточная и не должна выигрывать у прибыльных альтернатив.',
            default => 'Защитное правило ограничило балл строки.',
        };
    }

    /**
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private function markSelected(array $line): array
    {
        $explain = $this->decodeExplain($line);
        $reason = (string) ($explain['reason'] ?? 'replenishment');
        $explain['planning_decision']['selected'] = true;
        $explain['planning_decision']['decision_ru'] = match ($reason) {
            'marketplace_need' => 'Строка выбрана системой: файл маркетплейса показывает дополнительную потребность, а строка прошла бюджет, экономику и ограничения.',
            'oos_risk' => 'Строка выбрана системой: есть риск отсутствия товара или дефицит покрытия.',
            'locality_improvement' => 'Строка выбрана системой: направление улучшает локальность или скорость закрытия спроса.',
            'test_cluster' => 'Строка выбрана системой как осторожная тестовая поставка в новый склад или кластер.',
            'not_recommended_low_confidence' => 'Строка выбрана осторожно: данные низкой достоверности, нужна ручная проверка перед поставкой.',
            'not_recommended_negative_profit' => 'Строка выбрана с предупреждением: экономика отрицательная, проверьте маржу перед поставкой.',
            default => 'Строка выбрана системой: её балл прошёл фильтры бюджета, экономики и приоритета.',
        };
        $line['explain_json'] = $this->encodeExplain($explain);

        return $line;
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @return array{0:list<array<string, mixed>>, 1:list<array<string, mixed>>, 2:float}
     */
    private function selectWithinBudget(array $candidates, float $budgetLimit): array
    {
        if ($budgetLimit <= 0) {
            return [$candidates, [], 0.0];
        }

        $freeIndexes = [];
        $priced = [];
        foreach ($candidates as $index => $line) {
            $cost = max(0.0, (float) ($line['supply_cost_estimate'] ?? 0));
            if ($cost <= 0) {
                $freeIndexes[] = $index;
                continue;
            }

            if ($cost > $budgetLimit) {
                continue;
            }

            $priced[] = [
                'candidate_index' => $index,
                'cost' => $cost,
                'value' => $this->budgetOptimizationValue($line),
            ];
        }

        $selectedIndexes = array_fill_keys($freeIndexes, true);
        if ($priced !== []) {
            $scale = max(1.0, $budgetLimit / 800.0);
            $capacity = max(1, (int) floor($budgetLimit / $scale));
            $bestValues = array_fill(0, $capacity + 1, 0.0);
            $bestIndexes = array_fill(0, $capacity + 1, []);

            foreach ($priced as $item) {
                $weight = max(1, (int) ceil($item['cost'] / $scale));
                if ($weight > $capacity) {
                    continue;
                }

                for ($slot = $capacity; $slot >= $weight; $slot--) {
                    $candidateValue = $bestValues[$slot - $weight] + $item['value'];
                    if ($candidateValue <= $bestValues[$slot]) {
                        continue;
                    }

                    $bestValues[$slot] = $candidateValue;
                    $bestIndexes[$slot] = array_merge($bestIndexes[$slot - $weight], [$item['candidate_index']]);
                }
            }

            $bestSlot = 0;
            for ($slot = 1; $slot <= $capacity; $slot++) {
                if ($bestValues[$slot] > $bestValues[$bestSlot]) {
                    $bestSlot = $slot;
                }
            }

            foreach ($bestIndexes[$bestSlot] as $index) {
                $selectedIndexes[$index] = true;
            }
        }

        $selected = [];
        $skipped = [];
        $budgetUsed = 0.0;
        foreach ($candidates as $index => $line) {
            if (isset($selectedIndexes[$index])) {
                $selected[] = $line;
                $budgetUsed += max(0.0, (float) ($line['supply_cost_estimate'] ?? 0));
                continue;
            }

            $skipped[] = $line;
        }

        return [$selected, $skipped, round($budgetUsed, 2)];
    }

    /**
     * @param array<string, mixed> $line
     */
    private function budgetOptimizationValue(array $line): float
    {
        $score = (float) ($line['_planning_score'] ?? 0);
        $expectedProfit = (float) ($line['expected_profit'] ?? 0);
        $roi = (float) ($line['roi_percent'] ?? 0);
        $reason = (string) ($line['_planning_reason'] ?? '');

        $value = max(0.01, $score) * 100.0
            + max(0.0, $expectedProfit) / 50.0
            + max(0.0, $roi);

        $guardMultiplier = match ($reason) {
            'not_recommended_low_confidence' => 0.25,
            'not_recommended_negative_profit' => 0.10,
            default => 1.0,
        };

        return max(0.01, $value * $guardMultiplier * $this->strategicPortfolioMultiplier($line));
    }

    /**
     * @param array<string, mixed> $line
     */
    private function strategicPortfolioMultiplier(array $line): float
    {
        $explain = $this->decodeExplain($line);
        $abc = $this->abcPriority($explain);
        $reason = (string) ($line['_planning_reason'] ?? $explain['reason'] ?? '');
        $risk = (string) ($line['risk_level'] ?? 'low');
        $multiplier = 1.0;

        if ($abc === 'A' && $this->fastDestinationBoost($line, $explain) > 0) {
            $multiplier += 0.35;
        }

        if ($risk === 'high' && $this->demandClosureBoost($line, $explain) > 0) {
            $multiplier += 0.20;
        }

        if ($this->marketplaceNeedGapQty($line, $explain) > 0 || $reason === 'marketplace_need') {
            $multiplier += 0.15;
        }

        if ($this->surplusQty($line, $explain) > 0) {
            $multiplier -= 0.20;
        }

        if ($reason === 'not_recommended_low_confidence') {
            $multiplier -= 0.30;
        }

        if ($reason === 'not_recommended_negative_profit') {
            $multiplier -= 0.50;
        }

        return max(0.1, round($multiplier, 2));
    }

    /**
     * @param list<array<string, mixed>> $selected
     * @return array<string, mixed>
     */
    private function portfolioSummary(array $selected): array
    {
        $totalLines = count($selected);
        $totalQty = 0;
        $aLines = 0;
        $aQty = 0;
        $aFastQty = 0;
        $highRiskLines = 0;
        $highRiskQty = 0;
        $highRiskFastQty = 0;
        $marketplaceNeedLines = 0;
        $marketplaceNeedQty = 0;
        $lowConfidenceLines = 0;
        $negativeProfitLines = 0;
        $surplusLines = 0;
        $totalExpectedProfit = 0.0;
        $totalSupplyCost = 0.0;

        foreach ($selected as $line) {
            $explain = $this->decodeExplain($line);
            $qty = max(0, (int) ($line['qty_rounded'] ?? 0));
            $abc = $this->abcPriority($explain);
            $risk = (string) ($line['risk_level'] ?? 'low');
            $reason = (string) ($explain['reason'] ?? $line['_planning_reason'] ?? '');
            $isFast = $this->fastDestinationBoost($line, $explain) > 0 || $this->demandClosureBoost($line, $explain) >= 70;

            $totalQty += $qty;
            $totalExpectedProfit += (float) ($line['expected_profit'] ?? 0);
            $totalSupplyCost += max(0.0, (float) ($line['supply_cost_estimate'] ?? 0));

            if ($abc === 'A') {
                $aLines++;
                $aQty += $qty;
                $aFastQty += $isFast ? $qty : 0;
            }

            if ($risk === 'high') {
                $highRiskLines++;
                $highRiskQty += $qty;
                $highRiskFastQty += $isFast ? $qty : 0;
            }

            if ($this->marketplaceNeedQty($explain) > 0 || $reason === 'marketplace_need') {
                $marketplaceNeedLines++;
                $marketplaceNeedQty += $qty;
            }

            if ($reason === 'not_recommended_low_confidence') {
                $lowConfidenceLines++;
            }

            if ($reason === 'not_recommended_negative_profit' || (float) ($line['expected_profit'] ?? 0) < 0) {
                $negativeProfitLines++;
            }

            if ($this->surplusQty($line, $explain) > 0) {
                $surplusLines++;
            }
        }

        $aFastShare = $aQty > 0 ? round($aFastQty / $aQty * 100, 2) : null;
        $highRiskFastShare = $highRiskQty > 0 ? round($highRiskFastQty / $highRiskQty * 100, 2) : null;
        $expectedRoi = $totalSupplyCost > 0 ? round($totalExpectedProfit / $totalSupplyCost * 100, 2) : null;

        return [
            'version' => 'optimizer-portfolio-1',
            'selected_lines' => $totalLines,
            'total_qty' => $totalQty,
            'abc_a_lines' => $aLines,
            'abc_a_qty' => $aQty,
            'abc_a_fast_qty' => $aFastQty,
            'abc_a_fast_share_percent' => $aFastShare,
            'high_risk_lines' => $highRiskLines,
            'high_risk_qty' => $highRiskQty,
            'high_risk_fast_qty' => $highRiskFastQty,
            'high_risk_fast_share_percent' => $highRiskFastShare,
            'marketplace_need_lines' => $marketplaceNeedLines,
            'marketplace_need_qty' => $marketplaceNeedQty,
            'low_confidence_lines' => $lowConfidenceLines,
            'negative_profit_lines' => $negativeProfitLines,
            'surplus_lines' => $surplusLines,
            'expected_profit' => round($totalExpectedProfit, 2),
            'supply_cost' => round($totalSupplyCost, 2),
            'expected_roi_percent' => $expectedRoi,
            'decision_ru' => $this->portfolioDecisionText($totalLines, $aFastShare, $highRiskFastShare, $lowConfidenceLines, $negativeProfitLines, $surplusLines),
        ];
    }

    private function portfolioDecisionText(int $totalLines, ?float $aFastShare, ?float $highRiskFastShare, int $lowConfidenceLines, int $negativeProfitLines, int $surplusLines): string
    {
        if ($totalLines <= 0) {
            return 'Модуль выбора не выбрал строки: проверьте ограничения, бюджет и качество данных.';
        }

        $parts = ['Модуль выбора собрал финальный набор строк после ограничений, экономики, бюджета, ABC, риска отсутствия товара и локальности.'];
        if ($aFastShare !== null) {
            $parts[] = "A-товары в быстрых направлениях: {$this->formatNumberRu($aFastShare)}%.";
        }
        if ($highRiskFastShare !== null) {
            $parts[] = "Высокий риск отсутствия товара в быстрых направлениях: {$this->formatNumberRu($highRiskFastShare)}%.";
        }
        if ($lowConfidenceLines > 0) {
            $parts[] = "Строк низкой достоверности: {$lowConfidenceLines}, нужна проверка.";
        }
        if ($negativeProfitLines > 0) {
            $parts[] = "Убыточных строк: {$negativeProfitLines}, проверьте маржу.";
        }
        if ($surplusLines > 0) {
            $parts[] = "Строк с профицитом: {$surplusLines}, они не должны быть главным объёмом поставки.";
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<string, mixed> $constraintsSummary
     * @param array<string, int> $skippedByReason
     * @return list<array<string, mixed>>
     */
    private function buildFunnelStages(
        int $sourceCandidatesTotal,
        int $sourceQtyTotal,
        array $constraintsSummary,
        int $candidatesAfterConstraints,
        int $candidatesAfterEconomics,
        int $selectedLines,
        array $skippedByReason,
        float $budgetLimit,
        float $budgetUsed,
    ): array {
        $blockedByConstraints = (int) ($constraintsSummary['blocked_lines'] ?? 0);
        $cappedByConstraints = (int) ($constraintsSummary['capped_lines'] ?? 0);
        $reducedQty = (int) ($constraintsSummary['reduced_qty'] ?? 0);
        $unmatchedMarketplaceNeedCount = (int) ($constraintsSummary['unmatched_marketplace_need_count'] ?? 0);
        $unmatchedMarketplaceNeedQty = (int) ($constraintsSummary['unmatched_marketplace_need_qty'] ?? 0);
        $qtyAfterConstraints = max(0, $sourceQtyTotal - $reducedQty);
        $negativeProfitSkipped = (int) ($skippedByReason['negative_profit'] ?? 0);
        $budgetSkipped = (int) ($skippedByReason['budget_limit'] ?? 0);

        return [
            [
                'key' => 'all_candidates',
                'label' => 'Все кандидаты',
                'lines' => $sourceCandidatesTotal,
                'qty' => $sourceQtyTotal,
                'decision_ru' => 'Система собрала все возможные строки после выбранных пользователем складов или кластеров.',
            ],
            [
                'key' => 'marketplace_constraints',
                'label' => 'Ограничения маркетплейса',
                'lines' => $candidatesAfterConstraints,
                'lines_before' => $sourceCandidatesTotal,
                'removed_lines' => $blockedByConstraints,
                'changed_lines' => $blockedByConstraints + $cappedByConstraints,
                'qty_before' => $sourceQtyTotal,
                'qty_after' => $qtyAfterConstraints,
                'blocked_lines' => $blockedByConstraints,
                'capped_lines' => $cappedByConstraints,
                'reduced_qty' => $reducedQty,
                'marketplace_need_qty' => (int) ($constraintsSummary['total_marketplace_need_qty'] ?? 0),
                'remaining_marketplace_need_qty' => (int) ($constraintsSummary['marketplace_need_remaining_delta_qty'] ?? 0),
                'file_marketplace_need_qty' => (int) ($constraintsSummary['total_file_marketplace_need_qty'] ?? 0),
                'unmatched_marketplace_need_count' => $unmatchedMarketplaceNeedCount,
                'unmatched_marketplace_need_qty' => $unmatchedMarketplaceNeedQty,
                'unmatched_marketplace_needs' => array_slice(array_values((array) ($constraintsSummary['unmatched_marketplace_needs'] ?? [])), 0, 5),
                'source_file' => $constraintsSummary['source_file'] ?? null,
                'source_status' => $constraintsSummary['source_status'] ?? null,
                'decision_ru' => match (true) {
                    $unmatchedMarketplaceNeedQty > 0 => "Файл маркетплейса содержит незакрытую потребность: {$unmatchedMarketplaceNeedQty} шт. по {$unmatchedMarketplaceNeedCount} правилам не стали кандидатами. Проверьте SKU/кластеры или создайте отдельные строки-кандидаты.",
                    $blockedByConstraints > 0 || $cappedByConstraints > 0 => "Файл ограничений изменил расчёт: удалено строк {$blockedByConstraints}, срезано строк {$cappedByConstraints}, уменьшено {$reducedQty} шт.",
                    default => 'Ограничения маркетплейса не удалили строки из расчёта.',
                },
            ],
            [
                'key' => 'economics_filter',
                'label' => 'Экономика',
                'lines' => $candidatesAfterEconomics,
                'skipped_lines' => $negativeProfitSkipped,
                'decision_ru' => $negativeProfitSkipped > 0
                    ? 'Убыточные строки отсечены до финального выбора.'
                    : 'Убыточные строки не отсекались или не найдены.',
            ],
            [
                'key' => 'budget_filter',
                'label' => 'Бюджет',
                'lines' => max(0, $candidatesAfterEconomics - $budgetSkipped),
                'skipped_lines' => $budgetSkipped,
                'budget_limit' => $budgetLimit > 0 ? $budgetLimit : null,
                'budget_used' => $budgetLimit > 0 ? round($budgetUsed, 2) : null,
                'decision_ru' => $budgetLimit > 0
                    ? 'Система подобрала набор строк с максимальным суммарным баллом внутри бюджета.'
                    : 'Бюджет не задан, поэтому сумма поставки не ограничивала выбор.',
            ],
            [
                'key' => 'final_selection',
                'label' => 'Финальный выбор',
                'lines' => $selectedLines,
                'decision_ru' => 'Эти строки попали в план поставки после ограничений, экономики, бюджета и итогового выбора.',
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $stages
     */
    private function funnelSummaryText(array $stages): string
    {
        $byKey = [];
        foreach ($stages as $stage) {
            $byKey[(string) ($stage['key'] ?? '')] = $stage;
        }

        $all = $byKey['all_candidates'] ?? [];
        $constraints = $byKey['marketplace_constraints'] ?? [];
        $economics = $byKey['economics_filter'] ?? [];
        $budget = $byKey['budget_filter'] ?? [];
        $final = $byKey['final_selection'] ?? [];

        return 'Воронка выбора: собрано '
            . (int) ($all['lines'] ?? 0) . ' строк / ' . (int) ($all['qty'] ?? 0) . ' шт.; ограничения оставили '
            . (int) ($constraints['lines'] ?? 0) . ' строк / ' . (int) ($constraints['qty_after'] ?? 0) . ' шт.'
            . ' (срезано ' . (int) ($constraints['reduced_qty'] ?? 0) . ' шт.'
            . (((int) ($constraints['unmatched_marketplace_need_qty'] ?? 0)) > 0
                ? ', незакрытая потребность ' . (int) ($constraints['unmatched_marketplace_need_qty'] ?? 0) . ' шт.'
                : '')
            . '); экономика отсекла '
            . (int) ($economics['skipped_lines'] ?? 0) . ' строк; бюджет отсёк '
            . (int) ($budget['skipped_lines'] ?? 0) . ' строк; в план попало '
            . (int) ($final['lines'] ?? 0) . ' строк.';
    }

    /**
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private function candidateAuditRow(array $line, string $status, string $decision): array
    {
        $explain = $this->decodeExplain($line);
        $facts = $explain['facts'] ?? [];

        return [
            'sku' => (string) ($line['sku'] ?? ''),
            'product_name' => $line['product_name'] ?? null,
            'destination' => $line['cluster_name'] ?? $line['warehouse_name'] ?? null,
            'qty' => (int) ($line['qty_rounded'] ?? 0),
            'status' => $status,
            'decision' => $decision,
            'decision_ru' => $this->candidateDecisionText($status, $decision, $line, $explain),
            'score' => round((float) ($line['_planning_score'] ?? ($explain['planning_decision']['score'] ?? 0)), 2),
            'reason' => (string) ($explain['reason'] ?? $line['_planning_reason'] ?? 'replenishment'),
            'score_basis' => $explain['planning_decision']['score_basis'] ?? [],
            'score_basis_labels' => $explain['planning_decision']['score_basis_labels'] ?? $this->scoreBasisLabels($explain['planning_decision']['score_basis'] ?? []),
            'score_components' => $explain['planning_decision']['score_components'] ?? [],
            'main_score_factors_ru' => $explain['planning_decision']['main_score_factors_ru'] ?? [],
            'strategic_multiplier' => $this->strategicPortfolioMultiplier($line),
            'risk_level' => $line['risk_level'] ?? null,
            'abc_priority' => $facts['abc_priority'] ?? $this->abcPriority($explain),
            'deficit_qty' => (int) ($facts['deficit_qty'] ?? $this->deficitQty($line, $explain)),
            'surplus_qty' => (int) ($facts['surplus_qty'] ?? $this->surplusQty($line, $explain)),
            'in_transit_qty' => (int) ($facts['in_transit_qty'] ?? ($line['in_transit'] ?? 0)),
            'marketplace_need_qty' => (int) ($facts['marketplace_need_qty'] ?? $this->marketplaceNeedQty($explain)),
            'marketplace_need_gap_qty' => (int) ($facts['marketplace_need_gap_qty'] ?? $this->marketplaceNeedGapQty($line, $explain)),
            'quantity_guard_applied' => ! empty($explain['optimizer_quantity_guard']['applied']),
            'quantity_guard_reduced_by_qty' => (int) ($explain['optimizer_quantity_guard']['reduced_by_qty'] ?? 0),
            'expected_profit' => round((float) ($line['expected_profit'] ?? 0), 2),
            'roi_percent' => round((float) ($line['roi_percent'] ?? 0), 2),
            'supply_cost_estimate' => round((float) ($line['supply_cost_estimate'] ?? 0), 2),
            'territorial_score' => round((float) ($explain['territorial']['score'] ?? 0), 2),
        ];
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, mixed> $explain
     */
    private function candidateDecisionText(string $status, string $decision, array $line, array $explain): string
    {
        if ($decision === 'negative_profit') {
            return 'Строка отсечена: ожидаемая прибыль отрицательная, а включён фильтр убыточных рекомендаций.';
        }

        if ($decision === 'budget_limit') {
            return 'Строка отсечена: оптимальный набор бюджета дал больше пользы без неё, поэтому она не помещается в заданный бюджет.';
        }

        if ($status === 'выбрано') {
            $reason = (string) ($explain['reason'] ?? 'replenishment');

            return match ($reason) {
                'marketplace_need' => 'Выбрано: файл маркетплейса показывает дополнительную потребность по этому направлению.',
                'oos_risk' => 'Выбрано: есть риск отсутствия товара или дефицит покрытия.',
                'locality_improvement' => 'Выбрано: направление улучшает локальность или скорость закрытия спроса.',
                'test_cluster' => 'Выбрано как тестовая поставка в новый кластер/склад.',
                'not_recommended_low_confidence' => 'Выбрано осторожно: данные низкой достоверности, строка требует ручной проверки.',
                'not_recommended_negative_profit' => 'Выбрано с предупреждением: экономика отрицательная, проверьте маржу перед поставкой.',
                default => 'Выбрано: строка прошла проверку по дефициту, экономике, ABC, риску отсутствия товара и ограничениям.',
            };
        }

        return 'Строка стала кандидатом: её итоговое решение зависит от бюджета, балла и остальных кандидатов.';
    }

    private function optimizerDecisionText(string $mode, float $budgetLimit, bool $skipNegativeProfit): string
    {
        $modeLabel = match ($mode) {
            AutoSupplyPlan::MODE_PROTECT_OOS, AutoSupplyPlan::MODE_ANTI_OOS => 'приоритет защиты от отсутствия товара',
            AutoSupplyPlan::MODE_IMPROVE_LOCALITY => 'приоритет локальности и скорости доставки',
            AutoSupplyPlan::MODE_MAX_PROFIT, AutoSupplyPlan::MODE_CASH_SAFE => 'приоритет прибыли, ROI и бюджета',
            AutoSupplyPlan::MODE_POST_PROMO_CAREFUL => 'осторожный режим после акции',
            default => 'сбалансированный режим',
        };

        $budgetText = $budgetLimit > 0
            ? 'Бюджет включён: модуль выбора подбирает лучший набор строк внутри лимита.'
            : 'Бюджет не задан: модуль выбора не ограничивает итоговую сумму поставки.';
        $profitText = $skipNegativeProfit
            ? 'Убыточные строки исключаются до финального выбора.'
            : 'Убыточные строки остаются в проверке выбора и помечаются предупреждением, если не исключены настройкой.';

        return "{$modeLabel}. {$budgetText} {$profitText}";
    }

    private function detectReason(array $line, array $explain, array $context): string
    {
        $confidence = (string) ($explain['confidence']['confidence_level'] ?? 'good');
        $supplyType = (string) ($explain['inputs']['supply_type'] ?? 'replenishment');
        $risk = (string) ($line['risk_level'] ?? 'low');
        $coverBefore = (float) ($line['cover_days_before'] ?? 0);
        $minCoverDays = (float) ($context['min_cover_days'] ?? $explain['inputs']['min_cover_days'] ?? 0);

        if ((float) ($line['expected_profit'] ?? 0) < 0) {
            return 'not_recommended_negative_profit';
        }
        if ($supplyType === 'new_warehouse') {
            return 'test_cluster';
        }
        if ($this->marketplaceNeedQty($explain) > 0) {
            return 'marketplace_need';
        }
        if (in_array($confidence, ['low', 'bad'], true)) {
            return 'not_recommended_low_confidence';
        }
        if ((float) ($explain['territorial']['score'] ?? 0) >= 85) {
            return 'locality_improvement';
        }
        if (($line['expected_savings_rub'] ?? null) !== null || !empty($line['linked_locality_recommendation_ids'] ?? [])) {
            return 'locality_improvement';
        }
        if ($risk === 'high' || ($minCoverDays > 0 && $coverBefore > 0 && $coverBefore < $minCoverDays)) {
            return 'oos_risk';
        }

        return 'replenishment';
    }

    private function score(array $line, array $explain, string $mode, string $reason): float
    {
        $riskRank = ['high' => 100.0, 'med' => 45.0, 'low' => 10.0];
        $priorityScore = (float) ($line['priority_score'] ?? 0);
        $roi = (float) ($line['roi_percent'] ?? 0);
        $expectedProfit = (float) ($line['expected_profit'] ?? 0);
        $lostRevenueDaily = (float) ($line['lost_revenue_daily'] ?? 0);
        $deficitQty = $this->deficitQty($line, $explain);
        $surplusQty = $this->surplusQty($line, $explain);
        $marketplaceNeedGap = $this->marketplaceNeedGapQty($line, $explain);
        $marketplaceNeedBoost = min(220.0, $marketplaceNeedGap * 3.0);
        $savings = (float) ($line['expected_savings_rub'] ?? 0) + (float) ($line['lost_margin_rub'] ?? 0);
        $territorialScore = (float) ($explain['territorial']['score'] ?? 0);
        $confidencePenalty = $reason === 'not_recommended_low_confidence' ? 80.0 : 0.0;
        $negativeProfitPenalty = $reason === 'not_recommended_negative_profit' ? 200.0 : 0.0;
        $riskScore = $riskRank[(string) ($line['risk_level'] ?? 'low')] ?? 0.0;
        $abcScore = $this->abcScore($this->abcPriority($explain));
        $fastDestinationBoost = $this->fastDestinationBoost($line, $explain);
        $demandClosureBoost = $this->demandClosureBoost($line, $explain);
        $surplusPenalty = min(160.0, $surplusQty * 2.0);
        $inTransitRelief = $deficitQty <= 0
            ? min(80.0, $this->inTransitCoverageDays($line, $explain) * 4.0)
            : 0.0;

        return match ($mode) {
            AutoSupplyPlan::MODE_PROTECT_OOS, AutoSupplyPlan::MODE_ANTI_OOS =>
                $riskScore * 2.2 + $deficitQty * 3.0 + $marketplaceNeedBoost * 0.9 + $lostRevenueDaily / 80.0 + $abcScore + $fastDestinationBoost * 1.1 + $demandClosureBoost * 0.9 + $priorityScore + $territorialScore * 0.15 - $surplusPenalty * 1.5 - $inTransitRelief - $negativeProfitPenalty - $confidencePenalty,
            AutoSupplyPlan::MODE_IMPROVE_LOCALITY =>
                $territorialScore * 1.4 + $savings / 100.0 + $marketplaceNeedBoost * 0.75 + $priorityScore + $riskScore * 0.4 + $deficitQty * 0.8 + $abcScore * 0.5 + $fastDestinationBoost * 0.9 + $demandClosureBoost * 1.2 + $roi * 0.2 - $surplusPenalty - $inTransitRelief * 0.4 - $negativeProfitPenalty - $confidencePenalty,
            AutoSupplyPlan::MODE_MAX_PROFIT, AutoSupplyPlan::MODE_CASH_SAFE =>
                $expectedProfit / 100.0 + $roi * 2.0 + $marketplaceNeedBoost * 0.35 + $priorityScore * 0.5 + $riskScore * 0.2 + $deficitQty * 0.5 + $abcScore * 0.4 + $fastDestinationBoost * 0.25 + $demandClosureBoost * 0.15 + $territorialScore * 0.1 - $surplusPenalty - $inTransitRelief * 0.5 - $negativeProfitPenalty - $confidencePenalty,
            AutoSupplyPlan::MODE_POST_PROMO_CAREFUL =>
                $riskScore + $deficitQty * 1.5 + $marketplaceNeedBoost * 0.45 + $abcScore + $fastDestinationBoost * 0.6 + $demandClosureBoost * 0.35 + $priorityScore * 0.6 + max(0.0, $roi) + $territorialScore * 0.1 - $surplusPenalty - $inTransitRelief - $confidencePenalty * 2.0 - $negativeProfitPenalty,
            default =>
                $priorityScore + $riskScore * 1.1 + $marketplaceNeedBoost * 0.8 + $roi * 0.8 + $expectedProfit / 300.0 + $deficitQty * 2.0 + $abcScore + $fastDestinationBoost * 0.8 + $demandClosureBoost * 0.65 + $territorialScore * 0.25 - $surplusPenalty * 2.0 - $inTransitRelief * 0.8 - $negativeProfitPenalty - $confidencePenalty,
        };
    }

    /**
     * @return list<string>
     */
    private function selectionReasons(array $line, array $explain, string $reason): array
    {
        $reasons = [$reason];
        if (($line['risk_level'] ?? null) === 'high') {
            $reasons[] = 'high_oos_risk';
        }
        if ((float) ($line['roi_percent'] ?? 0) > 0) {
            $reasons[] = 'positive_roi';
        }
        if ($this->deficitQty($line, $explain) > 0) {
            $reasons[] = 'has_deficit';
        }
        if ($this->surplusQty($line, $explain) > 0) {
            $reasons[] = 'surplus_penalty_accounted';
        }
        if ((float) ($line['in_transit'] ?? 0) > 0) {
            $reasons[] = 'in_transit_accounted';
        }
        if ((float) ($line['in_transit'] ?? 0) > 0 && $this->deficitQty($line, $explain) <= 0) {
            $reasons[] = 'in_transit_covers_need';
        }
        if (in_array($this->abcPriority($explain), ['A', 'B'], true)) {
            $reasons[] = 'abc_priority_accounted';
        }
        if ($this->fastDestinationBoost($line, $explain) > 0) {
            $reasons[] = 'fast_destination_for_a_items';
        }
        if ($this->demandClosureBoost($line, $explain) > 0) {
            $reasons[] = 'demand_closure_accounted';
        }
        if ((float) ($explain['territorial']['score'] ?? 0) > 0) {
            $reasons[] = 'territorial_score_accounted';
        }
        if ($this->marketplaceNeedQty($explain) > 0) {
            $reasons[] = 'marketplace_need_accounted';
        }
        if (! empty($explain['optimizer_quantity_guard']['applied'])) {
            $reasons[] = 'protective_quantity_guard_accounted';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param list<string> $reasons
     * @return list<string>
     */
    private function scoreBasisLabels(array $reasons): array
    {
        $labels = [
            'replenishment' => 'плановое пополнение',
            'oos_risk' => 'риск отсутствия товара',
            'locality_improvement' => 'улучшение локальности',
            'marketplace_need' => 'потребность маркетплейса',
            'test_cluster' => 'тестовый кластер',
            'not_recommended_negative_profit' => 'отрицательная прибыль',
            'not_recommended_low_confidence' => 'низкая достоверность',
            'high_oos_risk' => 'высокий риск отсутствия товара',
            'positive_roi' => 'положительный ROI',
            'has_deficit' => 'есть дефицит',
            'surplus_penalty_accounted' => 'учтён профицит',
            'in_transit_accounted' => 'учтены товары в пути',
            'in_transit_covers_need' => 'в пути уже закрывает потребность',
            'abc_priority_accounted' => 'учтён ABC-приоритет',
            'fast_destination_for_a_items' => 'быстрое направление для A-товара',
            'demand_closure_accounted' => 'скорость закрытия спроса',
            'territorial_score_accounted' => 'учтена локальность',
            'marketplace_need_accounted' => 'учтена потребность маркетплейса',
            'protective_quantity_guard_accounted' => 'сработала защита количества',
        ];

        return array_values(array_map(
            static fn (string $reason): string => $labels[$reason] ?? str_replace('_', ' ', $reason),
            $reasons
        ));
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, mixed> $explain
     * @return list<array<string, mixed>>
     */
    private function scoreComponents(array $line, array $explain, string $reason): array
    {
        $riskRank = ['high' => 100.0, 'med' => 45.0, 'low' => 10.0];
        $riskLevel = (string) ($line['risk_level'] ?? 'low');
        $deficitQty = $this->deficitQty($line, $explain);
        $surplusQty = $this->surplusQty($line, $explain);
        $inTransitCoverageDays = $this->inTransitCoverageDays($line, $explain);
        $marketplaceNeedGap = $this->marketplaceNeedGapQty($line, $explain);
        $abc = $this->abcPriority($explain);
        $roi = (float) ($line['roi_percent'] ?? 0);
        $expectedProfit = (float) ($line['expected_profit'] ?? 0);
        $territorialScore = (float) ($explain['territorial']['score'] ?? 0);
        $fastDestinationBoost = $this->fastDestinationBoost($line, $explain);
        $demandClosureBoost = $this->demandClosureBoost($line, $explain);
        $confidenceLevel = (string) ($explain['confidence']['confidence_level'] ?? 'good');
        $quantityGuard = is_array($explain['optimizer_quantity_guard'] ?? null) ? $explain['optimizer_quantity_guard'] : [];

        $components = [
            [
                'key' => 'oos_risk',
                'label' => 'Риск отсутствия',
                'value' => round($riskRank[$riskLevel] ?? 0.0, 2),
                'effect' => in_array($riskLevel, ['high', 'med'], true) ? 'positive' : 'neutral',
                'explanation_ru' => $riskLevel === 'high'
                    ? 'Высокий риск отсутствия товара сильно поднимает приоритет.'
                    : 'Риск отсутствия товара учтён, но не является главным фактором.',
            ],
            [
                'key' => 'deficit_qty',
                'label' => 'Дефицит',
                'value' => $deficitQty,
                'effect' => $deficitQty > 0 ? 'positive' : 'neutral',
                'explanation_ru' => $deficitQty > 0
                    ? "Не хватает {$deficitQty} шт. до минимального покрытия."
                    : 'Критичного дефицита до минимального покрытия нет.',
            ],
            [
                'key' => 'marketplace_need_gap',
                'label' => 'Потребность маркетплейса',
                'value' => $marketplaceNeedGap,
                'effect' => $marketplaceNeedGap > 0 ? 'positive' : 'neutral',
                'explanation_ru' => $marketplaceNeedGap > 0
                    ? "Файл/данные маркетплейса показывают разрыв {$marketplaceNeedGap} шт."
                    : 'Отдельной потребности маркетплейса сверх плана нет.',
            ],
            [
                'key' => 'protective_quantity_guard',
                'label' => 'Защита количества',
                'value' => ! empty($quantityGuard['applied']) ? (int) ($quantityGuard['reduced_by_qty'] ?? 0) : 0,
                'effect' => ! empty($quantityGuard['applied']) ? 'negative' : 'neutral',
                'explanation_ru' => ! empty($quantityGuard['applied'])
                    ? (string) ($quantityGuard['decision_ru'] ?? 'Защита количества снизила рекомендацию до trial-поставки.')
                    : 'Защита количества не снижала рекомендацию.',
            ],
            [
                'key' => 'abc_priority',
                'label' => 'ABC',
                'value' => $this->abcScore($abc),
                'effect' => in_array($abc, ['A', 'B'], true) ? 'positive' : 'neutral',
                'explanation_ru' => "ABC-класс {$abc}: " . (in_array($abc, ['A', 'B'], true)
                    ? 'товар получает дополнительный приоритет.'
                    : 'товар не получает повышающий ABC-вес.'),
            ],
            [
                'key' => 'fast_destination_for_a_items',
                'label' => 'Быстрое направление',
                'value' => round($fastDestinationBoost, 2),
                'effect' => $fastDestinationBoost > 0 ? 'positive' : 'neutral',
                'explanation_ru' => $fastDestinationBoost > 0
                    ? 'A-товар или товар с высоким риском отсутствия получает дополнительный приоритет в быстром направлении.'
                    : 'Быстрое направление не даёт отдельного приоритета для этой строки.',
            ],
            [
                'key' => 'demand_closure',
                'label' => 'Закрытие спроса',
                'value' => round($demandClosureBoost, 2),
                'effect' => $demandClosureBoost > 0 ? 'positive' : 'neutral',
                'explanation_ru' => $demandClosureBoost > 0
                    ? "Направление быстрее закрывает региональный спрос: балл {$this->formatNumberRu($demandClosureBoost)}."
                    : 'Нет подтверждённого преимущества по скорости закрытия регионального спроса.',
            ],
            [
                'key' => 'roi',
                'label' => 'ROI',
                'value' => round($roi, 2),
                'effect' => $roi > 0 ? 'positive' : ($roi < 0 ? 'negative' : 'neutral'),
                'explanation_ru' => $roi > 0
                    ? "ROI {$this->formatNumberRu($roi)}% поддерживает рекомендацию."
                    : ($roi < 0 ? "ROI {$this->formatNumberRu($roi)}% снижает приоритет." : 'ROI не даёт дополнительного приоритета.'),
            ],
            [
                'key' => 'expected_profit',
                'label' => 'Ожидаемая прибыль',
                'value' => round($expectedProfit, 2),
                'effect' => $expectedProfit > 0 ? 'positive' : ($expectedProfit < 0 ? 'negative' : 'neutral'),
                'explanation_ru' => $expectedProfit > 0
                    ? "Ожидаемая прибыль {$this->formatMoneyRu($expectedProfit)}."
                    : ($expectedProfit < 0 ? "Ожидаемый убыток {$this->formatMoneyRu(abs($expectedProfit))}." : 'Ожидаемая прибыль не подтверждена.'),
            ],
            [
                'key' => 'territorial_score',
                'label' => 'Локальность',
                'value' => round($territorialScore, 2),
                'effect' => $territorialScore > 0 ? 'positive' : 'neutral',
                'explanation_ru' => $territorialScore > 0
                    ? "Направление даёт территориальный балл {$this->formatNumberRu($territorialScore)}."
                    : 'Территориальный балл не повышает строку.',
            ],
            [
                'key' => 'surplus_qty',
                'label' => 'Профицит',
                'value' => $surplusQty,
                'effect' => $surplusQty > 0 ? 'negative' : 'neutral',
                'explanation_ru' => $surplusQty > 0
                    ? "Уже есть лишний запас {$surplusQty} шт.; приоритет снижен."
                    : 'Лишний запас не найден.',
            ],
            [
                'key' => 'in_transit_coverage',
                'label' => 'В пути',
                'value' => round($inTransitCoverageDays, 2),
                'effect' => $inTransitCoverageDays > 0 && $deficitQty <= 0 ? 'negative' : ($inTransitCoverageDays > 0 ? 'neutral' : 'neutral'),
                'explanation_ru' => $inTransitCoverageDays > 0
                    ? "Товары в пути покрывают примерно {$this->formatNumberRu($inTransitCoverageDays)} дн. спроса."
                    : 'Товары в пути не закрывают потребность.',
            ],
            [
                'key' => 'data_confidence',
                'label' => 'Достоверность данных',
                'value' => $confidenceLevel,
                'effect' => in_array($confidenceLevel, ['low', 'bad'], true) || $reason === 'not_recommended_low_confidence' ? 'negative' : 'positive',
                'explanation_ru' => in_array($confidenceLevel, ['low', 'bad'], true)
                    ? 'Данные слабые или похожи на всплеск: система снижает уверенность.'
                    : 'Данные выглядят достаточно надёжными для рекомендации.',
            ],
        ];

        if ($reason === 'not_recommended_negative_profit') {
            $components[] = [
                'key' => 'negative_profit_guard',
                'label' => 'Защита от убытка',
                'value' => 1,
                'effect' => 'negative',
                'explanation_ru' => 'Строка помечена как рискованная из-за отрицательной экономики.',
            ];
        }

        return $components;
    }

    /**
     * @param list<array<string, mixed>> $components
     * @return list<string>
     */
    private function mainScoreFactorsRu(array $components): array
    {
        $importantKeys = [
            'deficit_qty',
            'oos_risk',
            'marketplace_need_gap',
            'surplus_qty',
            'in_transit_coverage',
            'data_confidence',
            'expected_profit',
            'roi',
            'abc_priority',
            'fast_destination_for_a_items',
            'demand_closure',
            'territorial_score',
            'negative_profit_guard',
        ];
        $byKey = [];
        foreach ($components as $component) {
            $key = (string) ($component['key'] ?? '');
            if ($key !== '') {
                $byKey[$key] = $component;
            }
        }

        $factors = [];
        foreach ($importantKeys as $key) {
            $component = $byKey[$key] ?? null;
            if (!is_array($component)) {
                continue;
            }

            $effect = (string) ($component['effect'] ?? 'neutral');
            $value = $component['value'] ?? null;
            $isMeaningful = match ($key) {
                'data_confidence' => $effect !== 'positive',
                'oos_risk' => $effect === 'positive',
                default => $effect !== 'neutral' && $value !== 0 && $value !== 0.0 && $value !== null && $value !== '',
            };
            if (!$isMeaningful) {
                continue;
            }

            $factors[] = (string) ($component['explanation_ru'] ?? $component['label'] ?? $key);
            if (count($factors) >= 5) {
                break;
            }
        }

        return $factors;
    }

    /**
     * @param list<string> $factors
     */
    private function scoreComponentExplanationRu(array $factors): string
    {
        if ($factors === []) {
            return 'Система не нашла сильных повышающих или понижающих факторов; строка прошла общий расчёт.';
        }

        return 'Главные факторы решения: ' . implode(' ', $factors);
    }

    private function deficitQty(array $line, array $explain): int
    {
        $minCoverDays = (float) ($explain['inputs']['min_cover_days'] ?? 0);
        $dailyDemand = (float) ($explain['inputs']['daily_demand'] ?? $line['demand_daily'] ?? 0);
        $available = (int) ($line['current_stock'] ?? 0) + (int) ($line['in_transit'] ?? 0);

        return $dailyDemand > 0 && $minCoverDays > 0
            ? max(0, (int) ceil($minCoverDays * $dailyDemand - $available))
            : 0;
    }

    private function surplusQty(array $line, array $explain): int
    {
        $targetCoverDays = (float) ($explain['inputs']['target_cover_days'] ?? 0);
        $dailyDemand = (float) ($explain['inputs']['daily_demand'] ?? $line['demand_daily'] ?? 0);
        $available = (int) ($line['current_stock'] ?? 0) + (int) ($line['in_transit'] ?? 0);

        return $dailyDemand > 0 && $targetCoverDays > 0
            ? max(0, (int) floor($available - $targetCoverDays * $dailyDemand))
            : 0;
    }

    private function dailyDemand(array $line, array $explain): float
    {
        return (float) ($explain['inputs']['daily_demand'] ?? $line['demand_daily'] ?? 0);
    }

    private function inTransitCoverageDays(array $line, array $explain): float
    {
        $dailyDemand = $this->dailyDemand($line, $explain);

        return $dailyDemand > 0
            ? round(((int) ($line['in_transit'] ?? 0)) / $dailyDemand, 2)
            : 0.0;
    }

    private function marketplaceNeedQty(array $explain): int
    {
        return max(0, (int) ($explain['marketplace_needs']['need_qty'] ?? 0));
    }

    private function marketplaceNeedGapQty(array $line, array $explain): int
    {
        $needQty = $this->marketplaceNeedQty($explain);
        if ($needQty <= 0) {
            return 0;
        }

        return max(0, $needQty - (int) ($line['qty_rounded'] ?? 0));
    }

    private function abcPriority(array $explain): string
    {
        $priority = strtoupper((string) ($explain['inputs']['abc_priority'] ?? 'C'));

        return in_array($priority, ['A', 'B', 'C'], true) ? $priority : 'C';
    }

    private function abcScore(string $abc): float
    {
        return match ($abc) {
            'A' => 35.0,
            'B' => 18.0,
            default => 5.0,
        };
    }

    private function fastDestinationBoost(array $line, array $explain): float
    {
        $abc = $this->abcPriority($explain);
        $risk = (string) ($line['risk_level'] ?? 'low');
        if ($abc !== 'A' && $risk !== 'high') {
            return 0.0;
        }

        $territorial = is_array($explain['territorial'] ?? null) ? $explain['territorial'] : [];
        $speedScore = max(
            (float) ($territorial['regional_demand_closure_score'] ?? 0),
            (float) ($territorial['delivery_speed_score'] ?? 0),
            (float) ($territorial['speed_score'] ?? 0),
        );

        $policyStatus = (string) ($territorial['abc_policy_status'] ?? '');
        if (
            $speedScore <= 0
            && (
                ! empty($territorial['is_fast_for_a_items'])
                || in_array($policyStatus, ['a_speed_priority', 'oos_speed_priority'], true)
                || str_contains(mb_strtolower((string) ($territorial['speed_tier'] ?? '')), 'быстр')
            )
        ) {
            $speedScore = (float) ($territorial['score'] ?? 0);
        }

        if ($speedScore < 60) {
            return 0.0;
        }

        $abcMultiplier = $abc === 'A' ? 1.0 : 0.65;
        $riskMultiplier = $risk === 'high' ? 1.0 : 0.75;

        return round(min(100.0, $speedScore) * $abcMultiplier * $riskMultiplier, 2);
    }

    private function demandClosureBoost(array $line, array $explain): float
    {
        $territorial = is_array($explain['territorial'] ?? null) ? $explain['territorial'] : [];
        $closureScore = max(
            (float) ($territorial['regional_demand_closure_score'] ?? 0),
            (float) ($territorial['demand_closure_score'] ?? 0),
        );

        if ($closureScore <= 0) {
            return 0.0;
        }

        $constraintPenalty = max(
            (float) ($territorial['coefficient_penalty'] ?? 0),
            (float) ($territorial['constraint_penalty'] ?? 0),
        );
        $inTransitRelief = (float) ($territorial['in_transit_relief'] ?? 0);
        $abcMultiplier = match ($this->abcPriority($explain)) {
            'A' => 1.15,
            'B' => 1.0,
            default => 0.8,
        };
        $riskMultiplier = ((string) ($line['risk_level'] ?? 'low')) === 'high' ? 1.15 : 1.0;

        $adjusted = min(100.0, $closureScore) * $abcMultiplier * $riskMultiplier;
        $adjusted -= min(45.0, $constraintPenalty * 0.45);
        $adjusted -= min(35.0, $inTransitRelief * 0.5);

        return round(max(0.0, min(120.0, $adjusted)), 2);
    }

    private function formatNumberRu(float $value): string
    {
        return number_format($value, 1, ',', ' ');
    }

    private function formatMoneyRu(float $value): string
    {
        return number_format($value, 0, ',', ' ') . ' ₽';
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

    private function encodeExplain(array $explain): string
    {
        return json_encode($explain, JSON_UNESCAPED_UNICODE);
    }
}
