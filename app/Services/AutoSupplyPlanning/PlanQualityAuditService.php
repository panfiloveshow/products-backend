<?php

namespace App\Services\AutoSupplyPlanning;

use App\Models\AutoSupplyPlan;

class PlanQualityAuditService
{
    /**
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function audit(array $lines, AutoSupplyPlan $plan, array $context = []): array
    {
        $totalLines = count($lines);
        $totalQty = array_sum(array_map(static fn (array $line): int => max(0, (int) ($line['qty_rounded'] ?? 0)), $lines));
        $riskCounters = [
            'low_confidence_lines' => 0,
            'low_confidence_qty' => 0,
            'promo_spike_lines' => 0,
            'promo_spike_qty' => 0,
            'overplanned_lines' => 0,
            'overplanned_qty' => 0,
            'negative_profit_lines' => 0,
            'negative_profit_qty' => 0,
            'marketplace_need_raised_lines' => 0,
            'marketplace_need_raised_qty' => 0,
            'remaining_marketplace_need_lines' => 0,
            'remaining_marketplace_need_qty' => 0,
            'unmatched_marketplace_need_count' => 0,
            'unmatched_marketplace_need_qty' => 0,
            'quantity_guard_lines' => 0,
            'quantity_guard_final_qty' => 0,
            'quantity_guard_reduced_qty' => 0,
        ];
        $examples = [];

        foreach ($lines as $line) {
            $qty = max(0, (int) ($line['qty_rounded'] ?? 0));
            $explain = $this->decodeExplain($line);
            $confidence = (string) ($explain['confidence']['confidence_level'] ?? 'good');
            $confidenceReasons = array_values(array_filter((array) ($explain['confidence']['confidence_reasons'] ?? [])));
            $dailyDemand = (float) ($explain['inputs']['daily_demand'] ?? $line['demand_daily'] ?? 0);
            $targetCoverDays = (float) ($explain['inputs']['target_cover_days'] ?? $plan->target_cover_days ?? 0);
            $expectedProfit = (float) ($line['expected_profit'] ?? $explain['facts']['expected_profit'] ?? 0);
            $marketplaceNeed = is_array($explain['marketplace_needs'] ?? null) ? $explain['marketplace_needs'] : [];
            $quantityGuard = $this->quantityGuard($explain);

            if (! empty($quantityGuard['applied'])) {
                $riskCounters['quantity_guard_lines']++;
                $riskCounters['quantity_guard_final_qty'] += $qty;
                $riskCounters['quantity_guard_reduced_qty'] += max(0, (int) ($quantityGuard['reduced_by_qty'] ?? 0));
                $this->addExample($examples, $line, 'Количество снижено защитой данных', $qty);
            }

            if (in_array($confidence, ['low', 'bad'], true)) {
                $riskCounters['low_confidence_lines']++;
                $riskCounters['low_confidence_qty'] += $qty;
                $this->addExample($examples, $line, 'Низкая достоверность спроса', $qty);
            }

            if ($this->hasPromoSpikeReason($confidenceReasons)) {
                $riskCounters['promo_spike_lines']++;
                $riskCounters['promo_spike_qty'] += $qty;
                $this->addExample($examples, $line, 'Похоже на всплеск или период после акции', $qty);
            }

            if ($this->isOverplanned($qty, $dailyDemand, $targetCoverDays, $marketplaceNeed)) {
                $riskCounters['overplanned_lines']++;
                $riskCounters['overplanned_qty'] += $qty;
                $this->addExample($examples, $line, 'Количество выше безопасного покрытия', $qty);
            }

            if ($expectedProfit < 0) {
                $riskCounters['negative_profit_lines']++;
                $riskCounters['negative_profit_qty'] += $qty;
                $this->addExample($examples, $line, 'Отрицательная ожидаемая прибыль', $qty);
            }

            $raisedByQty = max(0, (int) ($marketplaceNeed['raised_by_qty'] ?? 0));
            if ($raisedByQty > 0) {
                $riskCounters['marketplace_need_raised_lines']++;
                $riskCounters['marketplace_need_raised_qty'] += $raisedByQty;
            }

            $remainingGap = max(0, (int) ($marketplaceNeed['remaining_gap_qty'] ?? 0));
            if ($remainingGap > 0) {
                $riskCounters['remaining_marketplace_need_lines']++;
                $riskCounters['remaining_marketplace_need_qty'] += $remainingGap;
                $this->addExample($examples, $line, 'Потребность маркетплейса закрыта не полностью', $qty);
            }
        }

        $constraintsSummary = is_array($context['constraints_summary'] ?? null) ? $context['constraints_summary'] : [];
        $constraintsPlanningSource = is_array($constraintsSummary['planning_source'] ?? null) ? $constraintsSummary['planning_source'] : [];
        $riskCounters['unmatched_marketplace_need_count'] = max(0, (int) ($constraintsSummary['unmatched_marketplace_need_count'] ?? 0));
        $riskCounters['unmatched_marketplace_need_qty'] = max(0, (int) ($constraintsSummary['unmatched_marketplace_need_qty'] ?? 0));
        $constraintsRequireReview = ! empty($constraintsPlanningSource['requires_review'])
            || $riskCounters['unmatched_marketplace_need_qty'] > 0
            || (int) ($constraintsSummary['unmatched_constraints_count'] ?? 0) > 0
            || (int) ($constraintsSummary['marketplace_need_remaining_delta_qty'] ?? 0) > 0
            || (int) ($constraintsSummary['blocked_lines'] ?? 0) > 0;
        foreach (array_slice(array_values((array) ($constraintsSummary['unmatched_marketplace_needs'] ?? [])), 0, 4) as $need) {
            if (! is_array($need)) {
                continue;
            }

            $this->addMarketplaceNeedExample(
                $examples,
                $need,
                'Потребность маркетплейса не попала в строки плана'
            );
        }

        $territorialSummary = is_array($context['territorial_summary'] ?? null) ? $context['territorial_summary'] : [];
        $ktr = is_array($territorialSummary['ktr'] ?? null) ? $territorialSummary['ktr'] : [];
        $ktrValue = isset($ktr['value']) ? (float) $ktr['value'] : null;
        $financialKtrValue = isset($ktr['financial_value']) ? (float) $ktr['financial_value'] : null;
        $financialPriorityShare = isset($ktr['financial_priority_share_percent']) ? (float) $ktr['financial_priority_share_percent'] : null;
        $abcFastShare = isset($ktr['abc_a_fast_share_percent']) ? (float) $ktr['abc_a_fast_share_percent'] : null;
        $highRiskFastShare = isset($ktr['high_risk_fast_share_percent']) ? (float) $ktr['high_risk_fast_share_percent'] : null;

        $status = $this->status($riskCounters, $totalLines, $totalQty, $ktrValue, $financialKtrValue, $financialPriorityShare, $abcFastShare, $highRiskFastShare);
        $actions = $this->actions($riskCounters, $totalQty, $ktrValue, $financialKtrValue, $financialPriorityShare, $abcFastShare, $highRiskFastShare, $plan);

        return [
            'version' => 'plan-quality-audit-1',
            'status' => $status,
            'status_label' => match ($status) {
                'bad' => 'Нужна ручная проверка',
                'warning' => 'План ограничен защитой данных',
                default => 'План выглядит устойчиво',
            },
            'summary_ru' => $this->summaryText($status, $riskCounters, $totalLines, $totalQty),
            'risk_counters' => $riskCounters,
            'risk_share_percent' => [
                'low_confidence_qty' => $this->share($riskCounters['low_confidence_qty'], $totalQty),
                'promo_spike_qty' => $this->share($riskCounters['promo_spike_qty'], $totalQty),
                'overplanned_qty' => $this->share($riskCounters['overplanned_qty'], $totalQty),
                'negative_profit_qty' => $this->share($riskCounters['negative_profit_qty'], $totalQty),
                'remaining_marketplace_need_qty' => $this->share($riskCounters['remaining_marketplace_need_qty'], max(1, (int) ($constraintsSummary['total_marketplace_need_qty'] ?? $riskCounters['remaining_marketplace_need_qty']))),
                'unmatched_marketplace_need_qty' => $this->share($riskCounters['unmatched_marketplace_need_qty'], max(1, (int) ($constraintsSummary['total_file_marketplace_need_qty'] ?? $riskCounters['unmatched_marketplace_need_qty']))),
                'quantity_guard_final_qty' => $this->share($riskCounters['quantity_guard_final_qty'], $totalQty),
                'quantity_guard_reduced_qty' => $this->share($riskCounters['quantity_guard_reduced_qty'], $totalQty + $riskCounters['quantity_guard_reduced_qty']),
            ],
            'territorial_checks' => [
                'ktr_value' => $ktrValue,
                'ktr_target' => (float) ($ktr['target_value'] ?? 80),
                'financial_ktr_value' => $financialKtrValue,
                'financial_priority_share_percent' => $financialPriorityShare,
                'financial_status_ru' => $this->financialTerritorialStatusText($ktrValue, $financialKtrValue, $financialPriorityShare),
                'abc_a_fast_share_percent' => $abcFastShare,
                'high_risk_fast_share_percent' => $highRiskFastShare,
                'status_ru' => $this->territorialStatusText($ktrValue, $financialKtrValue, $financialPriorityShare, $abcFastShare, $highRiskFastShare),
            ],
            'guards_applied' => [
                'promo_spike_guard' => $riskCounters['promo_spike_lines'] > 0,
                'low_confidence_trial' => $riskCounters['low_confidence_lines'] > 0,
                'negative_profit_guard' => (int) ($context['selection_summary']['negative_profit_skipped_lines'] ?? 0) > 0,
                'budget_guard' => (int) ($context['selection_summary']['budget_skipped_lines'] ?? 0) > 0,
                'quantity_guard' => $riskCounters['quantity_guard_lines'] > 0
                    || (int) ($context['selection_summary']['quantity_guard_capped_lines'] ?? 0) > 0,
                'marketplace_need_backlog' => $riskCounters['unmatched_marketplace_need_qty'] > 0,
                'marketplace_constraints_guard' => (int) ($context['constraints_summary']['capped_lines'] ?? 0) > 0
                    || (int) ($context['constraints_summary']['blocked_lines'] ?? 0) > 0,
                'constraints_require_review' => $constraintsRequireReview,
            ],
            'actions' => $actions,
            'examples' => array_values($examples),
            'acceptance_gates' => [
                'can_export' => $status !== 'bad',
                'can_create_ozon_draft' => $status !== 'bad'
                    && ! $constraintsRequireReview
                    && (string) $plan->marketplace === 'ozon',
                'requires_manual_review' => $status !== 'good' || $constraintsRequireReview,
                'constraints_require_review' => $constraintsRequireReview,
                'manual_review_reason_ru' => $status === 'good' && ! $constraintsRequireReview
                    ? null
                    : ($constraintsRequireReview
                        ? 'Перед созданием черновика разберите файл ограничений/потребностей: есть незакрытые правила, заблокированные строки или потребность, не попавшая в план.'
                        : 'Перед поставкой проверьте строки из examples: там сосредоточены риски завышения, всплесков или незакрытых ограничений.'),
            ],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $examples
     */
    private function addExample(array &$examples, array $line, string $reason, int $qty): void
    {
        if (count($examples) >= 8) {
            return;
        }

        $key = (string) ($line['sku'] ?? '') . '|' . ($line['cluster_id'] ?? $line['warehouse_id'] ?? '') . '|' . $reason;
        if (isset($examples[$key])) {
            return;
        }

        $examples[$key] = [
            'sku' => (string) ($line['sku'] ?? ''),
            'product_name' => $line['product_name'] ?? null,
            'destination' => $line['cluster_name'] ?? $line['warehouse_name'] ?? null,
            'qty' => $qty,
            'reason_ru' => $reason,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $examples
     * @param array<string, mixed> $need
     */
    private function addMarketplaceNeedExample(array &$examples, array $need, string $reason): void
    {
        if (count($examples) >= 8) {
            return;
        }

        $key = 'marketplace_need|' . ($need['canonical_key'] ?? $need['sku'] ?? '') . '|' . ($need['destination_id'] ?? $need['destination_name'] ?? '');
        if (isset($examples[$key])) {
            return;
        }

        $examples[$key] = [
            'sku' => (string) ($need['sku'] ?? ''),
            'product_name' => null,
            'destination' => $need['destination_name'] ?? $need['destination_id'] ?? null,
            'qty' => max(0, (int) ($need['need_qty'] ?? 0)),
            'reason_ru' => $reason,
        ];
    }

    /**
     * @param list<string> $reasons
     */
    private function hasPromoSpikeReason(array $reasons): bool
    {
        foreach ($reasons as $reason) {
            if (in_array($reason, [
                'promo_spike_suspected',
                'promo_spike_peak_share',
                'promo_spike_peak_vs_median',
                'recent_spike_vs_period',
                'post_promo_cooldown',
                'no_recent_sales_after_30d_spike',
                'external_sources_capped_by_spike_guard',
                'optimizer_protective_trial_quantity_cap',
            ], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $marketplaceNeed
     */
    private function isOverplanned(int $qty, float $dailyDemand, float $targetCoverDays, array $marketplaceNeed): bool
    {
        if ($dailyDemand <= 0 || $targetCoverDays <= 0 || $qty <= 0) {
            return false;
        }

        $safeQty = (int) ceil($dailyDemand * $targetCoverDays * 1.35);
        $needQty = (int) ($marketplaceNeed['need_qty'] ?? 0);
        if ($needQty > 0) {
            $safeQty = max($safeQty, $needQty);
        }

        return $qty > max(3, $safeQty);
    }

    /**
     * @param array<string, int> $riskCounters
     */
    private function status(array $riskCounters, int $totalLines, int $totalQty, ?float $ktrValue, ?float $financialKtrValue, ?float $financialPriorityShare, ?float $abcFastShare, ?float $highRiskFastShare): string
    {
        $lowConfidenceShare = $this->share($riskCounters['low_confidence_qty'], $totalQty);
        $overplannedShare = $this->share($riskCounters['overplanned_qty'], $totalQty);
        $negativeProfitShare = $this->share($riskCounters['negative_profit_qty'], $totalQty);
        $unprotectedLowConfidenceLines = max(0, $riskCounters['low_confidence_lines'] - $riskCounters['quantity_guard_lines']);
        $financialLag = $ktrValue !== null && $financialKtrValue !== null
            ? $ktrValue - $financialKtrValue
            : 0.0;

        if (
            $totalLines > 0
            && (
                ($lowConfidenceShare >= 45 && $unprotectedLowConfidenceLines > 0)
                || $overplannedShare >= 35
                || $negativeProfitShare >= 25
                || $riskCounters['unmatched_marketplace_need_qty'] >= max(1, (int) ceil($totalQty * 1.5))
                || ($ktrValue !== null && $ktrValue < 45)
                || ($financialKtrValue !== null && $financialKtrValue < 45)
                || ($abcFastShare !== null && $abcFastShare < 50)
            )
        ) {
            return 'bad';
        }

        if (
            $riskCounters['promo_spike_lines'] > 0
            || $riskCounters['remaining_marketplace_need_lines'] > 0
            || $riskCounters['unmatched_marketplace_need_qty'] > 0
            || $lowConfidenceShare > 0
            || $overplannedShare > 0
            || ($ktrValue !== null && $ktrValue < 80)
            || ($financialKtrValue !== null && ($financialLag >= 8 || ($financialPriorityShare !== null && $financialPriorityShare < 70)))
            || ($highRiskFastShare !== null && $highRiskFastShare < 75)
        ) {
            return 'warning';
        }

        return 'good';
    }

    /**
     * @param array<string, int> $riskCounters
     * @return list<array<string, mixed>>
     */
    private function actions(array $riskCounters, int $totalQty, ?float $ktrValue, ?float $financialKtrValue, ?float $financialPriorityShare, ?float $abcFastShare, ?float $highRiskFastShare, AutoSupplyPlan $plan): array
    {
        $actions = [];
        $financialLag = $ktrValue !== null && $financialKtrValue !== null
            ? $ktrValue - $financialKtrValue
            : 0.0;

        if ($riskCounters['quantity_guard_lines'] > 0) {
            $actions[] = [
                'type' => 'review_protected_trial_quantities',
                'priority' => 'medium',
                'title' => 'Проверить trial-количество после защиты',
                'description' => 'Система уже снизила завышенные количества после всплеска или слабых данных. Проверьте эти строки как пробную поставку, а не как уверенную рекомендацию.',
                'affected_lines' => $riskCounters['quantity_guard_lines'],
                'affected_qty' => $riskCounters['quantity_guard_final_qty'],
                'reduced_qty' => $riskCounters['quantity_guard_reduced_qty'],
            ];
        }

        if ($riskCounters['unmatched_marketplace_need_qty'] > 0) {
            $actions[] = [
                'type' => 'create_candidates_for_unmatched_marketplace_needs',
                'priority' => 'high',
                'title' => 'Разобрать незакрытые потребности из файла',
                'description' => 'Файл маркетплейса показывает потребность, но часть правил не нашла строк в плане. Проверьте SKU, кластеры/склады и создайте кандидаты или исправьте сопоставление товаров.',
                'affected_rules' => $riskCounters['unmatched_marketplace_need_count'],
                'affected_qty' => $riskCounters['unmatched_marketplace_need_qty'],
            ];
        }

        if ($riskCounters['promo_spike_lines'] > 0 || $riskCounters['low_confidence_lines'] > 0) {
            $actions[] = [
                'type' => 'review_demand',
                'priority' => $riskCounters['quantity_guard_lines'] > 0 ? 'medium' : 'high',
                'title' => 'Проверить спрос после акции',
                'description' => $riskCounters['quantity_guard_lines'] > 0
                    ? 'В плане есть строки после всплеска, но система уже ограничила их trial-количеством. Проверьте последние 7-14 дней перед поставкой.'
                    : 'В плане есть строки, где спрос похож на всплеск или недостаточно подтверждён. Для них лучше оставить пробное количество или проверить последние 7-14 дней.',
                'affected_lines' => $riskCounters['promo_spike_lines'] + $riskCounters['low_confidence_lines'],
            ];
        }

        if ($riskCounters['overplanned_lines'] > 0) {
            $actions[] = [
                'type' => 'reduce_overplanned',
                'priority' => 'high',
                'title' => 'Снизить завышенные количества',
                'description' => 'Часть строк выше безопасного покрытия. Проверьте, не попала ли туда акция, разовая продажа или потребность без экономического подтверждения.',
                'affected_qty' => $riskCounters['overplanned_qty'],
            ];
        }

        if ($riskCounters['remaining_marketplace_need_lines'] > 0) {
            $actions[] = [
                'type' => 'review_uncovered_marketplace_need',
                'priority' => 'medium',
                'title' => 'Проверить незакрытую потребность маркетплейса',
                'description' => 'Файл потребностей показал больший объём, но часть не прошла лимиты. Проверьте ограничения, бюджет и альтернативные склады/кластеры.',
                'affected_qty' => $riskCounters['remaining_marketplace_need_qty'],
            ];
        }

        if ($ktrValue !== null && $ktrValue < 80) {
            $actions[] = [
                'type' => 'improve_territorial_distribution',
                'priority' => 'medium',
                'title' => 'Улучшить территориальное распределение',
                'description' => 'КТР ниже целевого: стоит перенести часть количества в быстрые склады/кластеры, особенно для A-товаров и товаров с высоким риском отсутствия.',
                'current_ktr' => $ktrValue,
            ];
        }

        if ($financialKtrValue !== null && ($financialLag >= 8 || ($financialPriorityShare !== null && $financialPriorityShare < 70))) {
            $actions[] = [
                'type' => 'review_financial_distribution',
                'priority' => $financialLag >= 18 || $financialKtrValue < 45 ? 'high' : 'medium',
                'title' => 'Проверить распределение ценного товара',
                'description' => 'Финансовый КТР хуже обычного: ценный товар попал в более слабые склады/кластеры. Проверьте дорогие SKU и перенесите их в быстрые направления, если экономика и ограничения позволяют.',
                'current_financial_ktr' => $financialKtrValue,
                'current_ktr' => $ktrValue,
                'financial_lag_pp' => $financialLag > 0 ? round($financialLag, 2) : 0.0,
                'financial_priority_share_percent' => $financialPriorityShare,
            ];
        }

        if ($abcFastShare !== null && $abcFastShare < 80) {
            $actions[] = [
                'type' => 'protect_a_items',
                'priority' => 'high',
                'title' => 'A-товары вести в быстрые направления',
                'description' => 'Для A-товаров скорость закрытия спроса важнее небольшой экономии на коэффициентах.',
                'current_share_percent' => $abcFastShare,
            ];
        }

        if ($highRiskFastShare !== null && $highRiskFastShare < 75) {
            $actions[] = [
                'type' => 'protect_oos',
                'priority' => 'high',
                'title' => 'Высокий риск отсутствия закрывать быстрыми направлениями',
                'description' => 'Строки с высоким риском отсутствия товара должны попадать в быстрые склады/кластеры, иначе план оставляет риск упущенной выручки.',
                'current_share_percent' => $highRiskFastShare,
            ];
        }

        if ($actions === []) {
            $actions[] = [
                'type' => 'ready_for_manual_check',
                'priority' => 'low',
                'title' => 'Проверить и экспортировать',
                'description' => (string) $plan->marketplace === 'ozon'
                    ? 'Критичных рисков не найдено. Можно переходить к предпросмотру черновиков Ozon и ручному подтверждению.'
                    : 'Критичных рисков не найдено. Можно переходить к экспорту рекомендаций.',
                'affected_qty' => $totalQty,
            ];
        }

        return array_slice($actions, 0, 6);
    }

    private function summaryText(string $status, array $riskCounters, int $totalLines, int $totalQty): string
    {
        if ($totalLines === 0) {
            return 'В плане нет строк для аудита.';
        }

        if ($status === 'bad') {
            return "План требует ручной проверки: найдено {$riskCounters['low_confidence_lines']} строк с низкой достоверностью, {$riskCounters['overplanned_lines']} возможных завышений, {$riskCounters['negative_profit_lines']} убыточных строк и {$riskCounters['unmatched_marketplace_need_qty']} шт. незакрытой потребности из файлов.";
        }

        if ($status === 'warning') {
            if ($riskCounters['unmatched_marketplace_need_qty'] > 0) {
                return "План ограничен данными маркетплейса: {$riskCounters['unmatched_marketplace_need_qty']} шт. потребности из файла не попали в строки плана; проверьте сопоставление SKU и направлений.";
            }

            if ($riskCounters['quantity_guard_lines'] > 0) {
                return "План рассчитан с защитой данных: {$riskCounters['quantity_guard_lines']} строк снижены до trial-количества, всего убрано {$riskCounters['quantity_guard_reduced_qty']} шт.; финальный объём {$totalQty} шт. нужно проверить по действиям ниже.";
            }

            return "План рассчитан с защитой данных: система нашла риски в части строк, но финальный объём {$totalQty} шт. можно проверять по действиям ниже.";
        }

        return "План выглядит устойчиво: {$totalLines} строк, {$totalQty} шт., критичных сигналов завышения не найдено.";
    }

    private function territorialStatusText(?float $ktrValue, ?float $financialKtrValue, ?float $financialPriorityShare, ?float $abcFastShare, ?float $highRiskFastShare): string
    {
        if ($ktrValue === null) {
            return 'Территориальный аудит недоступен: нет КТР.';
        }

        $financialOk = $financialKtrValue === null
            || (($ktrValue - $financialKtrValue) < 8 && ($financialPriorityShare === null || $financialPriorityShare >= 70));

        if ($ktrValue >= 80 && $financialOk && ($abcFastShare === null || $abcFastShare >= 80) && ($highRiskFastShare === null || $highRiskFastShare >= 75)) {
            return 'Территориальное распределение близко к целевому.';
        }

        return 'Территориальное распределение требует улучшения: проверьте быстрые направления для A-товаров, товаров с высоким риском отсутствия и ценного объёма.';
    }

    private function financialTerritorialStatusText(?float $ktrValue, ?float $financialKtrValue, ?float $financialPriorityShare): ?string
    {
        if ($financialKtrValue === null) {
            return null;
        }

        $lag = $ktrValue !== null ? round($ktrValue - $financialKtrValue, 2) : 0.0;
        if ($lag >= 8) {
            return "Финансовый КТР ниже обычного на {$lag} п.п.: дорогие товары распределены хуже общего объёма.";
        }

        if ($financialPriorityShare !== null && $financialPriorityShare < 70) {
            return "В приоритетных направлениях только {$financialPriorityShare}% ценного объёма.";
        }

        return 'Финансовое распределение выглядит устойчиво.';
    }

    private function share(int $value, int $total): float
    {
        return $total > 0 ? round($value / $total * 100, 2) : 0.0;
    }

    /**
     * @param array<string, mixed> $explain
     * @return array<string, mixed>
     */
    private function quantityGuard(array $explain): array
    {
        $guard = $explain['optimizer_quantity_guard'] ?? null;
        if (is_array($guard)) {
            return $guard;
        }

        $guard = $explain['inputs']['protective_quantity_guard'] ?? null;
        if (is_array($guard)) {
            return $guard;
        }

        $guard = $explain['protective_quantity_guard'] ?? null;
        if (is_array($guard)) {
            return $guard;
        }

        return [];
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
}
