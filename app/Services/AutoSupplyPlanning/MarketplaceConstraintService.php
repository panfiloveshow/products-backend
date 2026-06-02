<?php

namespace App\Services\AutoSupplyPlanning;

use App\Models\AutoSupplyPlan;

class MarketplaceConstraintService
{
    /**
     * @param list<array<string, mixed>> $lines
     * @return array{lines:list<array<string, mixed>>, summary:array<string, mixed>}
     */
    public function apply(array $lines, AutoSupplyPlan $plan, string $marketplace): array
    {
        $params = is_array($plan->params) ? $plan->params : [];
        $constraints = $this->normalizeConstraints($params, $marketplace);
        $metadata = is_array($params['constraint_metadata'] ?? null)
            ? $params['constraint_metadata']
            : [];

        if ($constraints === []) {
            return [
                'lines' => $lines,
                'summary' => $this->emptySummary($marketplace, $metadata),
            ];
        }

        $remainingByKey = [];
        foreach ($constraints as $key => $constraint) {
            if (isset($constraint['max_qty'])) {
                $canonicalKey = (string) ($constraint['canonical_key'] ?? $key);
                $remainingByKey[$canonicalKey] = max(0, (int) $constraint['max_qty']);
            }
        }

        $result = [];
        $cappedLines = 0;
        $blockedLines = 0;
        $matchedLines = 0;
        $coefficientLines = 0;
        $matchedNeedLines = 0;
        $totalMarketplaceNeedQty = 0;
        $totalNeedDeltaQty = 0;
        $totalNeedRemainingDeltaQty = 0;
        $totalNeedIncreasedQty = 0;
        $needRaisedLines = 0;
        $totalReducedQty = 0;
        $appliedExamples = [];
        $matchedScopes = [];
        $matchedCanonicalKeys = [];
        $sourceTypeCounts = $this->uniqueSourceTypeCounts($constraints);
        $totalInputQty = array_sum(array_map(
            static fn (array $line): int => (int) ($line['qty_rounded'] ?? 0),
            $lines
        ));

        foreach ($lines as $line) {
            $keys = $this->lineConstraintKeys($line, $marketplace);
            $matchedConstraints = $this->matchedConstraintsForLine($keys, $constraints);

            if ($matchedConstraints === []) {
                $result[] = $line;
                continue;
            }

            $matchedLines++;
            $constraint = $this->mergeMatchedConstraints($matchedConstraints);
            foreach ($matchedConstraints as $matchedConstraint) {
                $scope = (string) ($matchedConstraint['scope'] ?? 'не определено');
                $matchedScopes[$scope] = ($matchedScopes[$scope] ?? 0) + 1;
                $matchedCanonicalKey = (string) ($matchedConstraint['canonical_key'] ?? '');
                if ($matchedCanonicalKey !== '') {
                    $matchedCanonicalKeys[$matchedCanonicalKey] = true;
                }
            }
            if ($this->hasAnyCoefficient($constraint)) {
                $coefficientLines++;
            }
            if (($constraint['need_qty'] ?? null) !== null) {
                $matchedNeedLines++;
                $needQty = max(0, (int) $constraint['need_qty']);
                $plannedQtyBeforeNeed = (int) ($line['qty_rounded'] ?? 0);
                $totalMarketplaceNeedQty += $needQty;
                $totalNeedDeltaQty += $needQty - $plannedQtyBeforeNeed;
            }

            $qty = (int) ($line['qty_rounded'] ?? 0);
            $available = (bool) ($constraint['is_available'] ?? true);

            if (! $available) {
                $blockedLines++;
                $totalReducedQty += $qty;
                $this->recordAppliedExample($appliedExamples, $line, $constraint, 'blocked', $qty, 0);
                continue;
            }

            $plannedQtyBeforeNeed = null;
            $needQtyForExample = null;
            if (($constraint['need_qty'] ?? null) !== null) {
                $plannedQtyBeforeNeed = (int) ($line['qty_rounded'] ?? 0);
                $needQtyForExample = max(0, (int) $constraint['need_qty']);
                $line = $this->applyMarketplaceNeedQty($line, $constraint);
            }

            $qty = (int) ($line['qty_rounded'] ?? 0);
            $lineWasCappedOrBlocked = false;
            foreach ($this->capConstraintsForLine($matchedConstraints) as $capConstraint) {
                $canonicalKey = (string) ($capConstraint['canonical_key'] ?? '');
                if ($canonicalKey === '' || ! array_key_exists($canonicalKey, $remainingByKey)) {
                    continue;
                }

                $qty = (int) ($line['qty_rounded'] ?? 0);
                $allowedQty = min($qty, $remainingByKey[$canonicalKey]);
                $remainingByKey[$canonicalKey] -= $allowedQty;

                if ($allowedQty <= 0) {
                    $blockedLines++;
                    $totalReducedQty += $qty;
                    $lineWasCappedOrBlocked = true;
                    $this->recordAppliedExample($appliedExamples, $line, $capConstraint, 'blocked', $qty, 0, $needQtyForExample);
                    continue 2;
                }

                if ($allowedQty < $qty) {
                    $line = $this->capLine($line, $allowedQty, $capConstraint);
                    $cappedLines++;
                    $totalReducedQty += $qty - $allowedQty;
                    $lineWasCappedOrBlocked = true;
                    $this->recordAppliedExample($appliedExamples, $line, $capConstraint, 'capped', $qty, $allowedQty, $needQtyForExample);
                }
            }

            if (($constraint['need_qty'] ?? null) !== null) {
                if ($plannedQtyBeforeNeed !== null && (int) ($line['qty_rounded'] ?? 0) > $plannedQtyBeforeNeed) {
                    $needRaisedLines++;
                    $totalNeedIncreasedQty += (int) ($line['qty_rounded'] ?? 0) - $plannedQtyBeforeNeed;
                }
                $totalNeedRemainingDeltaQty += max(0, max(0, (int) $constraint['need_qty']) - (int) ($line['qty_rounded'] ?? 0));
                $this->recordAppliedExample(
                    $appliedExamples,
                    $line,
                    $constraint,
                    'marketplace_need',
                    $plannedQtyBeforeNeed,
                    (int) ($line['qty_rounded'] ?? 0),
                    max(0, (int) $constraint['need_qty'])
                );
            } elseif (! $lineWasCappedOrBlocked && $this->hasAnyCoefficient($constraint)) {
                $this->recordAppliedExample($appliedExamples, $line, $constraint, 'coefficient', $qty, $qty);
            }

            $result[] = $this->attachConstraintFact($line, $constraint);
        }

        $constraintsCount = $this->uniqueConstraintsCount($constraints);
        $matchedConstraintsCount = count($matchedCanonicalKeys);
        $unmatchedConstraintsCount = max(0, $constraintsCount - $matchedConstraintsCount);
        $unmatchedMarketplaceNeeds = $this->unmatchedMarketplaceNeedFacts($constraints, array_keys($matchedCanonicalKeys));
        $unmatchedMarketplaceNeedQty = array_sum(array_map(
            static fn (array $need): int => (int) ($need['need_qty'] ?? 0),
            $unmatchedMarketplaceNeeds
        ));
        $allMarketplaceNeeds = $this->uniqueMarketplaceNeedFacts($constraints);
        $totalFileMarketplaceNeedQty = array_sum(array_map(
            static fn (array $need): int => (int) ($need['need_qty'] ?? 0),
            $allMarketplaceNeeds
        ));
        $matchPercent = count($lines) > 0 ? round($matchedLines / count($lines) * 100, 2) : 0.0;
        $needMatchPercent = count($lines) > 0 ? round($matchedNeedLines / count($lines) * 100, 2) : 0.0;
        $reducedQtyPercent = $totalInputQty > 0 ? round($totalReducedQty / $totalInputQty * 100, 2) : 0.0;

        return [
            'lines' => array_values($result),
            'summary' => [
                'marketplace' => $marketplace,
                'source' => 'Файл или параметры ограничений',
                'source_kind' => $metadata !== [] ? 'constraint_file' : 'request_params',
                'source_status' => $this->sourceStatus($matchedLines, $matchedNeedLines, $coefficientLines, $cappedLines, $blockedLines, $metadata, count($unmatchedMarketplaceNeeds)),
                'human_status' => $this->humanStatus($matchedLines, $matchedNeedLines, $cappedLines, $blockedLines, $metadata, count($unmatchedMarketplaceNeeds)),
                'decision_ru' => $this->decisionText($matchedLines, $matchedNeedLines, $totalNeedRemainingDeltaQty, $cappedLines, $blockedLines, $unmatchedConstraintsCount, count($unmatchedMarketplaceNeeds), $unmatchedMarketplaceNeedQty),
                'coverage_summary_ru' => $this->coverageText($matchPercent, $needMatchPercent, $unmatchedConstraintsCount, count($unmatchedMarketplaceNeeds), $unmatchedMarketplaceNeedQty),
                'constraints_count' => $constraintsCount,
                'matched_constraints_count' => $matchedConstraintsCount,
                'unmatched_constraints_count' => $unmatchedConstraintsCount,
                'source_type_counts' => $sourceTypeCounts,
                'matched_lines' => $matchedLines,
                'capped_lines' => $cappedLines,
                'blocked_lines' => $blockedLines,
                'coefficient_lines' => $coefficientLines,
                'marketplace_needs_count' => $this->uniqueMarketplaceNeedsCount($constraints),
                'file_marketplace_needs_count' => count($allMarketplaceNeeds),
                'total_file_marketplace_need_qty' => $totalFileMarketplaceNeedQty,
                'matched_marketplace_need_lines' => $matchedNeedLines,
                'total_marketplace_need_qty' => $totalMarketplaceNeedQty,
                'marketplace_need_delta_qty' => $totalNeedDeltaQty,
                'marketplace_need_remaining_delta_qty' => $totalNeedRemainingDeltaQty,
                'marketplace_need_increased_qty' => $totalNeedIncreasedQty,
                'marketplace_need_raised_lines' => $needRaisedLines,
                'unmatched_marketplace_need_count' => count($unmatchedMarketplaceNeeds),
                'unmatched_marketplace_need_qty' => $unmatchedMarketplaceNeedQty,
                'unmatched_marketplace_needs' => array_slice($unmatchedMarketplaceNeeds, 0, 8),
                'reduced_qty' => $totalReducedQty,
                'matched_scopes' => $matchedScopes,
                'applied_examples' => $appliedExamples,
                'applied' => $matchedLines > 0,
                'metadata' => $metadata,
                'source_file' => $metadata['file']['name'] ?? $metadata['file_name'] ?? null,
                'source_hash' => $metadata['file']['sha256'] ?? null,
                'parser_version' => $metadata['summary']['parser_version'] ?? null,
                'warnings_count' => $metadata['summary']['warnings_count'] ?? count($metadata['warnings'] ?? []),
                'coverage' => [
                    'match_percent' => $matchPercent,
                    'marketplace_need_match_percent' => $needMatchPercent,
                    'reduced_qty_percent' => $reducedQtyPercent,
                ],
                'planning_source' => [
                    'used_as_constraints' => $matchedLines > 0,
                    'used_as_marketplace_needs' => $matchedNeedLines > 0,
                    'used_as_coefficients' => $coefficientLines > 0,
                    'used_for_quantity_caps' => $cappedLines > 0 || $blockedLines > 0,
                    'has_unmatched_marketplace_needs' => count($unmatchedMarketplaceNeeds) > 0,
                    'requires_review' => $unmatchedConstraintsCount > 0 || $totalNeedRemainingDeltaQty > 0 || $blockedLines > 0 || count($unmatchedMarketplaceNeeds) > 0,
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function marketplaceNeedFacts(AutoSupplyPlan $plan, string $marketplace): array
    {
        $params = is_array($plan->params) ? $plan->params : [];

        return $this->uniqueMarketplaceNeedFacts($this->normalizeConstraints($params, $marketplace));
    }

    /**
     * Добавляет candidate-строки из файла потребностей маркетплейса, если
     * внутренний расчёт не создал строку по этому SKU × направлению.
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    public function appendMarketplaceNeedCandidates(
        array $lines,
        AutoSupplyPlan $plan,
        string $marketplace,
        mixed $products = null,
        mixed $unitEconomics = null,
    ): array {
        $params = is_array($plan->params) ? $plan->params : [];
        $constraints = $this->normalizeConstraints($params, $marketplace);
        $needs = $this->uniqueMarketplaceNeedFacts($constraints);

        if ($needs === []) {
            return $lines;
        }

        $selectedOzonClusterIds = $this->selectedOzonClusterIds($plan, $marketplace);
        $matchedNeedKeys = [];
        foreach ($lines as $line) {
            foreach ($this->matchedConstraintsForLine($this->lineConstraintKeys($line, $marketplace), $constraints) as $constraint) {
                if (($constraint['need_qty'] ?? null) === null) {
                    continue;
                }
                $canonicalKey = (string) ($constraint['canonical_key'] ?? '');
                if ($canonicalKey !== '') {
                    $matchedNeedKeys[$canonicalKey] = true;
                }
            }
        }

        foreach ($needs as $need) {
            $canonicalKey = (string) ($need['canonical_key'] ?? '');
            $sku = trim((string) ($need['sku'] ?? ''));
            $needQty = max(0, (int) ($need['need_qty'] ?? 0));

            if ($canonicalKey === '' || isset($matchedNeedKeys[$canonicalKey]) || $sku === '' || $needQty <= 0) {
                continue;
            }
            if (! $this->marketplaceNeedMatchesSelectedOzonClusters($need, $selectedOzonClusterIds)) {
                continue;
            }

            $skuAliases = $this->skuAliases($sku);
            $product = $this->lookupByAliases($products, $skuAliases);
            $ue = $this->lookupByAliases($unitEconomics, $skuAliases);

            $lines[] = $this->buildMarketplaceNeedCandidateLine($plan, $marketplace, $need, $product, $ue);
            $matchedNeedKeys[$canonicalKey] = true;
        }

        return array_values($lines);
    }

    /**
     * @return list<int>
     */
    private function selectedOzonClusterIds(AutoSupplyPlan $plan, string $marketplace): array
    {
        if ($marketplace !== 'ozon') {
            return [];
        }

        $params = is_array($plan->params) ? $plan->params : [];

        return array_values(array_filter(
            array_map('intval', (array) ($params['cluster_ids'] ?? [])),
            static fn (int $clusterId): bool => $clusterId > 0
        ));
    }

    /**
     * Ozon cluster selection is a hard invariant: external marketplace needs
     * from files may add candidate rows only inside the selected clusters.
     *
     * @param list<int> $selectedClusterIds
     * @param array<string, mixed> $need
     */
    private function marketplaceNeedMatchesSelectedOzonClusters(array $need, array $selectedClusterIds): bool
    {
        if ($selectedClusterIds === []) {
            return true;
        }

        $destinationId = $need['destination_id'] ?? null;
        if ($destinationId === null || $destinationId === '') {
            return false;
        }

        $destination = trim((string) $destinationId);
        if (str_starts_with($destination, 'cluster:')) {
            $destination = substr($destination, strlen('cluster:'));
        }

        return is_numeric($destination) && in_array((int) $destination, $selectedClusterIds, true);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function normalizeConstraints(array $params, string $marketplace): array
    {
        $raw = $marketplace === 'ozon'
            ? ($params['cluster_constraints'] ?? [])
            : ($params['warehouse_constraints'] ?? []);

        if (! is_array($raw)) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $key => $item) {
            if (! is_array($item)) {
                continue;
            }

            $sku = isset($item['sku']) && trim((string) $item['sku']) !== ''
                ? trim((string) $item['sku'])
                : null;
            $id = $marketplace === 'ozon'
                ? ($item['cluster_id'] ?? (is_string($key) && $key !== '' ? $key : null))
                : ($item['warehouse_id'] ?? (is_string($key) && $key !== '' ? $key : null));
            $name = $item['name'] ?? $item['warehouse_name'] ?? $item['cluster_name'] ?? null;

            if (($id === null || $id === '') && ($name === null || $name === '') && $sku === null) {
                continue;
            }

            $destinationAliases = $this->destinationAliases(
                $id !== null && $id !== '' ? (string) $id : null,
                $name !== null && $name !== '' ? (string) $name : null
            );
            $skuAliases = $this->skuAliases($sku);
            $canonicalDestination = $destinationAliases[0] ?? null;
            $canonicalSku = $skuAliases[0] ?? null;
            $canonicalKey = $this->constraintKey($canonicalDestination, $canonicalSku, $marketplace);

            if ($canonicalKey === null) {
                continue;
            }

            $constraint = [
                'id' => $id !== null && $id !== '' ? (string) $id : null,
                'sku' => $sku,
                'name' => $name,
                'max_qty' => isset($item['max_qty']) ? (int) $item['max_qty'] : null,
                'need_qty' => isset($item['need_qty']) ? (int) $item['need_qty'] : null,
                'is_available' => array_key_exists('is_available', $item) ? (bool) $item['is_available'] : true,
                'coefficient' => $this->normalizedCoefficient($item),
                'acceptance_coefficient' => isset($item['acceptance_coefficient']) ? (float) $item['acceptance_coefficient'] : null,
                'delivery_coefficient' => isset($item['delivery_coefficient']) ? (float) $item['delivery_coefficient'] : null,
                'storage_coefficient' => isset($item['storage_coefficient']) ? (float) $item['storage_coefficient'] : null,
                'logistics_coefficient' => isset($item['logistics_coefficient']) ? (float) $item['logistics_coefficient'] : null,
                'reason' => $item['reason'] ?? null,
                'scope' => $item['scope'] ?? $this->constraintScope($canonicalDestination, $sku, $marketplace),
                'source_type' => $item['source_type'] ?? (isset($item['need_qty']) && ! isset($item['max_qty']) ? 'marketplace_need' : 'marketplace_constraint'),
                'canonical_key' => $canonicalKey,
                'aliases' => [
                    'destinations' => $destinationAliases,
                    'sku' => $skuAliases,
                ],
            ];

            foreach ($this->constraintAliases($destinationAliases, $skuAliases, $marketplace, includeFallbacks: false) as $aliasKey) {
                $normalized[$aliasKey] = $constraint;
            }
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function lineConstraintKeys(array $line, string $marketplace): array
    {
        $destinationAliases = [];
        if ($marketplace === 'ozon') {
            $destinationAliases = $this->destinationAliases(
                isset($line['cluster_id']) && $line['cluster_id'] !== null ? (string) $line['cluster_id'] : null,
                isset($line['cluster_name']) && $line['cluster_name'] !== null ? (string) $line['cluster_name'] : null
            );
        } else {
            $destinationAliases = $this->destinationAliases(
                isset($line['warehouse_id']) && $line['warehouse_id'] !== null ? (string) $line['warehouse_id'] : null,
                isset($line['warehouse_name']) && $line['warehouse_name'] !== null ? (string) $line['warehouse_name'] : null
            );
        }

        return $this->constraintAliases($destinationAliases, $this->lineSkuAliases($line), $marketplace, includeFallbacks: true);
    }

    /**
     * Возвращает все правила, совпавшие со строкой: точное направление+SKU,
     * правило направления целиком и глобальное правило SKU. Это важно для
     * файлов маркетплейса, где потребность и ограничение часто лежат разными
     * строками.
     *
     * @param list<string> $keys
     * @param array<string, array<string, mixed>> $constraints
     * @return list<array<string, mixed>>
     */
    private function matchedConstraintsForLine(array $keys, array $constraints): array
    {
        $matched = [];
        $seen = [];

        foreach ($keys as $key) {
            if (! isset($constraints[$key])) {
                continue;
            }

            $constraint = $constraints[$key];
            $canonicalKey = (string) ($constraint['canonical_key'] ?? $key);
            if (isset($seen[$canonicalKey])) {
                continue;
            }

            $seen[$canonicalKey] = true;
            $matched[] = $constraint;
        }

        return $matched;
    }

    /**
     * @param list<array<string, mixed>> $constraints
     * @return array<string, mixed>
     */
    private function mergeMatchedConstraints(array $constraints): array
    {
        $merged = $constraints[0] ?? [];
        $merged['is_available'] = true;
        $merged['matched_constraint_keys'] = [];
        $merged['matched_scopes'] = [];

        foreach ($constraints as $constraint) {
            if (($constraint['is_available'] ?? true) === false) {
                $merged['is_available'] = false;
            }

            foreach (['coefficient', 'acceptance_coefficient', 'delivery_coefficient', 'storage_coefficient', 'logistics_coefficient'] as $key) {
                if (($constraint[$key] ?? null) === null) {
                    continue;
                }
                $merged[$key] = isset($merged[$key]) && $merged[$key] !== null
                    ? max((float) $merged[$key], (float) $constraint[$key])
                    : (float) $constraint[$key];
            }

            if (($constraint['need_qty'] ?? null) !== null) {
                $merged['need_qty'] = max((int) ($merged['need_qty'] ?? 0), max(0, (int) $constraint['need_qty']));
            }

            if (($constraint['max_qty'] ?? null) !== null) {
                $merged['max_qty'] = isset($merged['max_qty'])
                    ? min((int) $merged['max_qty'], (int) $constraint['max_qty'])
                    : (int) $constraint['max_qty'];
            }

            $canonicalKey = (string) ($constraint['canonical_key'] ?? '');
            if ($canonicalKey !== '') {
                $merged['matched_constraint_keys'][] = $canonicalKey;
            }
            if (($constraint['scope'] ?? null) !== null) {
                $merged['matched_scopes'][] = (string) $constraint['scope'];
            }
        }

        $merged['matched_constraint_keys'] = array_values(array_unique($merged['matched_constraint_keys']));
        $merged['matched_scopes'] = array_values(array_unique($merged['matched_scopes']));
        $merged['source_type'] = $this->mergedSourceType($constraints);
        $merged['scope'] = implode(' + ', $merged['matched_scopes']);
        $merged['reason'] = $this->mergedReason($constraints);

        return $merged;
    }

    /**
     * @param list<array<string, mixed>> $constraints
     * @return list<array<string, mixed>>
     */
    private function capConstraintsForLine(array $constraints): array
    {
        return array_values(array_filter(
            $constraints,
            static fn (array $constraint): bool => ($constraint['max_qty'] ?? null) !== null
        ));
    }

    /**
     * @param list<array<string, mixed>> $constraints
     */
    private function mergedSourceType(array $constraints): string
    {
        $hasNeed = false;
        $hasConstraint = false;
        $hasCoefficient = false;

        foreach ($constraints as $constraint) {
            $hasNeed = $hasNeed || ($constraint['need_qty'] ?? null) !== null;
            $hasConstraint = $hasConstraint || ($constraint['max_qty'] ?? null) !== null || ($constraint['is_available'] ?? true) === false;
            $hasCoefficient = $hasCoefficient || $this->hasAnyCoefficient($constraint);
        }

        return match (true) {
            $hasNeed && $hasConstraint => 'constraint_and_need',
            $hasNeed => 'marketplace_need',
            $hasConstraint => 'marketplace_constraint',
            $hasCoefficient => 'coefficient',
            default => 'marketplace_constraint',
        };
    }

    /**
     * @param list<array<string, mixed>> $constraints
     */
    private function mergedReason(array $constraints): ?string
    {
        $reasons = array_values(array_unique(array_filter(array_map(
            static fn (array $constraint): ?string => isset($constraint['reason']) && trim((string) $constraint['reason']) !== ''
                ? trim((string) $constraint['reason'])
                : null,
            $constraints
        ))));

        return $reasons !== [] ? implode('; ', array_slice($reasons, 0, 3)) : null;
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, mixed> $constraint
     * @return array<string, mixed>
     */
    private function applyMarketplaceNeedQty(array $line, array $constraint): array
    {
        $needQty = max(0, (int) ($constraint['need_qty'] ?? 0));
        $oldQty = (int) ($line['qty_rounded'] ?? 0);

        if ($needQty <= $oldQty) {
            return $line;
        }

        $line['qty_rounded'] = $needQty;
        $line['qty_recommended'] = max((float) ($line['qty_recommended'] ?? 0), (float) $needQty);

        foreach (['supply_cost_estimate', 'expected_revenue', 'expected_profit'] as $moneyKey) {
            if (isset($line[$moneyKey]) && $oldQty > 0) {
                $line[$moneyKey] = round(((float) $line[$moneyKey]) * ($needQty / $oldQty), 2);
            }
        }

        $explain = $this->decodeExplain($line);
        $explain['marketplace_needs'] = array_merge($explain['marketplace_needs'] ?? [], [
            'planned_before_need_qty' => $oldQty,
            'raised_by_qty' => $needQty - $oldQty,
            'quantity_adjustment_ru' => "Количество поднято с {$oldQty} до {$needQty} шт., потому что файл маркетплейса показывает более высокую потребность.",
        ]);
        $line['explain_json'] = json_encode($explain, JSON_UNESCAPED_UNICODE);

        return $line;
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, mixed> $constraint
     * @return array<string, mixed>
     */
    private function capLine(array $line, int $allowedQty, array $constraint): array
    {
        $oldQty = (int) ($line['qty_rounded'] ?? 0);
        $line['qty_rounded'] = $allowedQty;
        $line['qty_recommended'] = min((float) ($line['qty_recommended'] ?? $allowedQty), $allowedQty);

        foreach (['supply_cost_estimate', 'expected_revenue', 'expected_profit'] as $moneyKey) {
            if (isset($line[$moneyKey]) && $oldQty > 0) {
                $line[$moneyKey] = round(((float) $line[$moneyKey]) * ($allowedQty / $oldQty), 2);
            }
        }

        return $this->attachConstraintFact($line, array_merge($constraint, [
            'capped_from_qty' => $oldQty,
            'capped_to_qty' => $allowedQty,
        ]));
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, mixed> $constraint
     * @return array<string, mixed>
     */
    private function attachConstraintFact(array $line, array $constraint): array
    {
        $explain = $this->decodeExplain($line);
        $previousConstraints = is_array($explain['constraints'] ?? null) ? $explain['constraints'] : [];
        $explain['constraints'] = array_merge($previousConstraints, [
            'applied' => true,
            'source' => 'Файл или параметры ограничений',
            'source_type' => $constraint['source_type'] ?? null,
            'limit_name' => $constraint['name'] ?? null,
            'sku' => $constraint['sku'] ?? null,
            'max_qty' => $constraint['max_qty'] ?? null,
            'coefficient' => $constraint['coefficient'] ?? null,
            'acceptance_coefficient' => $constraint['acceptance_coefficient'] ?? null,
            'delivery_coefficient' => $constraint['delivery_coefficient'] ?? null,
            'storage_coefficient' => $constraint['storage_coefficient'] ?? null,
            'logistics_coefficient' => $constraint['logistics_coefficient'] ?? null,
            'reason' => $constraint['reason'] ?? null,
            'scope' => $constraint['scope'] ?? null,
            'matched_constraint_keys' => $constraint['matched_constraint_keys'] ?? [],
            'matched_scopes' => $constraint['matched_scopes'] ?? [],
            'capped_from_qty' => $constraint['capped_from_qty'] ?? $previousConstraints['capped_from_qty'] ?? null,
            'capped_to_qty' => $constraint['capped_to_qty'] ?? $previousConstraints['capped_to_qty'] ?? null,
            'decision_ru' => $this->lineConstraintDecisionText($line, $constraint, $previousConstraints),
        ]);
        if (($constraint['need_qty'] ?? null) !== null) {
            $plannedQty = (int) ($line['qty_rounded'] ?? 0);
            $needQty = max(0, (int) $constraint['need_qty']);
            $explain['marketplace_needs'] = array_merge($explain['marketplace_needs'] ?? [], [
                'applied' => true,
                'source' => 'Файл потребностей или ограничений маркетплейса',
                'source_type' => $constraint['source_type'] ?? 'marketplace_need',
                'need_qty' => $needQty,
                'planned_qty' => $plannedQty,
                'delta_qty' => $needQty - $plannedQty,
                'remaining_gap_qty' => max(0, $needQty - $plannedQty),
                'scope' => $constraint['scope'] ?? null,
                'destination_name' => $constraint['name'] ?? null,
                'sku' => $constraint['sku'] ?? null,
                'reason' => $constraint['reason'] ?? null,
                'interpretation_ru' => $needQty > $plannedQty
                    ? 'Маркетплейс/файл показывает потребность выше текущего плана.'
                    : ($needQty < $plannedQty
                        ? 'Текущий план выше указанной потребности: стоит проверить ограничение или спрос.'
                        : 'Текущий план совпадает с указанной потребностью.'),
                'decision_ru' => $this->marketplaceNeedDecisionText($plannedQty, $needQty, $explain['marketplace_needs'] ?? []),
            ]);
        }
        $line['explain_json'] = json_encode($explain, JSON_UNESCAPED_UNICODE);

        return $line;
    }

    /**
     * @param list<array<string, mixed>> $examples
     * @param array<string, mixed> $line
     * @param array<string, mixed> $constraint
     */
    private function recordAppliedExample(
        array &$examples,
        array $line,
        array $constraint,
        string $action,
        ?int $qtyFrom = null,
        ?int $qtyTo = null,
        ?int $needQty = null,
    ): void {
        if (count($examples) >= 8) {
            return;
        }

        $destinationName = $constraint['name']
            ?? $line['cluster_name']
            ?? $line['warehouse_name']
            ?? $constraint['id']
            ?? $line['cluster_id']
            ?? $line['warehouse_id']
            ?? null;

        $examples[] = [
            'action' => $action,
            'decision_ru' => $this->constraintExampleDecisionText($action, $qtyFrom, $qtyTo, $needQty),
            'sku' => $line['sku'] ?? $constraint['sku'] ?? null,
            'destination_name' => $destinationName,
            'scope' => $constraint['scope'] ?? null,
            'qty_from' => $qtyFrom,
            'qty_to' => $qtyTo,
            'need_qty' => $needQty,
            'max_qty' => $constraint['max_qty'] ?? null,
            'coefficient' => $constraint['coefficient'] ?? null,
            'reason' => $constraint['reason'] ?? null,
            'source_type' => $constraint['source_type'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, mixed> $constraint
     * @param array<string, mixed> $previousConstraints
     */
    private function lineConstraintDecisionText(array $line, array $constraint, array $previousConstraints): string
    {
        $cappedFrom = $constraint['capped_from_qty'] ?? $previousConstraints['capped_from_qty'] ?? null;
        $cappedTo = $constraint['capped_to_qty'] ?? $previousConstraints['capped_to_qty'] ?? null;

        if ($cappedFrom !== null && $cappedTo !== null && (int) $cappedTo < (int) $cappedFrom) {
            return 'Файл ограничений снизил количество с ' . (int) $cappedFrom . ' до ' . (int) $cappedTo . ' шт., чтобы не превысить доступный лимит направления.';
        }

        if (($constraint['need_qty'] ?? null) !== null) {
            $needQty = max(0, (int) $constraint['need_qty']);
            $plannedQty = (int) ($line['qty_rounded'] ?? 0);

            if ($plannedQty < $needQty) {
                return "Потребность маркетплейса {$needQty} шт. учтена не полностью: после лимитов осталось {$plannedQty} шт.";
            }

            if ($plannedQty === $needQty) {
                return "План доведён до потребности маркетплейса: {$needQty} шт.";
            }

            return "План выше потребности маркетплейса {$needQty} шт.; проверьте спрос, экономику и страховой запас.";
        }

        if ($this->hasAnyCoefficient($constraint)) {
            return 'Файл не ограничил количество, но коэффициенты направления учтены в ранжировании складов/кластеров.';
        }

        return 'Файл ограничений совпал со строкой и сохранён как источник планирования.';
    }

    /**
     * @param array<string, mixed> $previousNeeds
     */
    private function marketplaceNeedDecisionText(int $plannedQty, int $needQty, array $previousNeeds): string
    {
        $beforeQty = isset($previousNeeds['planned_before_need_qty'])
            ? (int) $previousNeeds['planned_before_need_qty']
            : null;

        if ($plannedQty < $needQty) {
            return "План закрывает {$plannedQty} из {$needQty} шт.; остаток потребности заблокирован лимитами.";
        }

        if ($beforeQty !== null && $plannedQty > $beforeQty) {
            return "План поднят с {$beforeQty} до {$plannedQty} шт. по потребности маркетплейса.";
        }

        if ($plannedQty === $needQty) {
            return 'План полностью закрывает потребность маркетплейса.';
        }

        return 'Потребность маркетплейса учтена, но итоговый план выше неё из-за спроса или страхового запаса.';
    }

    private function constraintExampleDecisionText(string $action, ?int $qtyFrom, ?int $qtyTo, ?int $needQty): string
    {
        return match ($action) {
            'blocked' => 'Строка убрана: направление недоступно или остаток лимита равен нулю.',
            'capped' => 'Количество снижено с ' . (int) $qtyFrom . ' до ' . (int) $qtyTo . ' шт. по лимиту файла.',
            'marketplace_need' => $needQty !== null
                ? 'Потребность маркетплейса: ' . $needQty . ' шт.; итог плана: ' . (int) $qtyTo . ' шт.'
                : 'Потребность маркетплейса учтена при расчёте количества.',
            'coefficient' => 'Коэффициенты направления учтены в ранжировании, количество не менялось.',
            default => 'Правило файла применено к строке плана.',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(string $marketplace, array $metadata = []): array
    {
        return [
            'marketplace' => $marketplace,
            'source' => $metadata !== [] ? 'Файл ограничений загружен, но совпадений со строками плана нет' : null,
            'source_kind' => $metadata !== [] ? 'constraint_file' : 'none',
            'source_status' => $metadata !== [] ? 'file_loaded_no_matches' : 'not_provided',
            'human_status' => $metadata !== [] ? 'Файл загружен, но в строках плана совпадений не найдено' : 'Файл ограничений не использовался',
            'decision_ru' => $metadata !== []
                ? 'Проверьте, что названия кластеров/складов и артикулы в файле совпадают с данными плана.'
                : 'Расчёт выполнен без внешнего файла ограничений или потребностей.',
            'coverage_summary_ru' => $metadata !== [] ? 'Покрытие строк: 0%. Файл не повлиял на рекомендации.' : 'Внешний источник ограничений не подключён.',
            'constraints_count' => 0,
            'matched_constraints_count' => 0,
            'unmatched_constraints_count' => 0,
            'source_type_counts' => [],
            'matched_lines' => 0,
            'capped_lines' => 0,
            'blocked_lines' => 0,
            'coefficient_lines' => 0,
            'marketplace_needs_count' => 0,
            'file_marketplace_needs_count' => 0,
            'total_file_marketplace_need_qty' => 0,
            'matched_marketplace_need_lines' => 0,
            'total_marketplace_need_qty' => 0,
            'marketplace_need_delta_qty' => 0,
            'marketplace_need_remaining_delta_qty' => 0,
            'marketplace_need_increased_qty' => 0,
            'marketplace_need_raised_lines' => 0,
            'unmatched_marketplace_need_count' => 0,
            'unmatched_marketplace_need_qty' => 0,
            'unmatched_marketplace_needs' => [],
            'reduced_qty' => 0,
            'matched_scopes' => [],
            'applied_examples' => [],
            'applied' => false,
            'metadata' => $metadata,
            'source_file' => $metadata['file']['name'] ?? $metadata['file_name'] ?? null,
            'source_hash' => $metadata['file']['sha256'] ?? null,
            'parser_version' => $metadata['summary']['parser_version'] ?? null,
            'warnings_count' => $metadata['summary']['warnings_count'] ?? count($metadata['warnings'] ?? []),
            'coverage' => [
                'match_percent' => 0.0,
                'marketplace_need_match_percent' => 0.0,
                'reduced_qty_percent' => 0.0,
            ],
            'planning_source' => [
                'used_as_constraints' => false,
                'used_as_marketplace_needs' => false,
                'used_as_coefficients' => false,
                'used_for_quantity_caps' => false,
                'has_unmatched_marketplace_needs' => false,
                'requires_review' => $metadata !== [],
            ],
        ];
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
     * @param array<string, mixed> $item
     */
    private function normalizedCoefficient(array $item): ?float
    {
        $values = [];
        if (isset($item['coefficient']) && is_numeric($item['coefficient'])) {
            $values[] = (float) $item['coefficient'];
        }

        foreach (['acceptance_coefficient', 'delivery_coefficient', 'storage_coefficient', 'logistics_coefficient'] as $key) {
            if (isset($item[$key]) && is_numeric($item[$key])) {
                $values[] = (float) $item[$key];
            }
        }

        return $values !== [] ? max($values) : null;
    }

    /**
     * @param array<string, mixed> $constraint
     */
    private function hasAnyCoefficient(array $constraint): bool
    {
        foreach (['coefficient', 'acceptance_coefficient', 'delivery_coefficient', 'storage_coefficient', 'logistics_coefficient'] as $key) {
            if (($constraint[$key] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    private function constraintKey(?string $destinationId, ?string $sku, string $marketplace): ?string
    {
        unset($marketplace);

        if ($destinationId !== null && $sku !== null) {
            return 'destination:' . $this->normalizeKeyPart($destinationId) . '|sku:' . $this->normalizeKeyPart($sku);
        }

        if ($destinationId !== null) {
            return 'destination:' . $this->normalizeKeyPart($destinationId);
        }

        if ($sku !== null) {
            return 'sku:' . $this->normalizeKeyPart($sku);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function destinationAliases(?string $id, ?string $name): array
    {
        return array_values(array_unique(array_filter([
            $id !== null && trim($id) !== '' ? trim($id) : null,
            $name !== null && trim($name) !== '' ? trim($name) : null,
        ], static fn (?string $value): bool => $value !== null && $value !== '')));
    }

    /**
     * @return list<string>
     */
    private function skuAliases(?string $sku): array
    {
        return $sku !== null && trim($sku) !== ''
            ? [trim($sku)]
            : [];
    }

    /**
     * @param array<string, mixed> $line
     * @return list<string>
     */
    private function lineSkuAliases(array $line): array
    {
        $aliases = [];
        foreach ([
            'sku',
            'offer_id',
            'barcode',
            'barcodes',
            'product_id',
            'product_sku',
            'marketplace_sku',
            'ozon_sku',
            'fbo_sku',
            'nm_id',
            'vendor_code',
            'article',
        ] as $key) {
            $value = $line[$key] ?? null;
            if (is_array($value)) {
                foreach ($value as $nestedValue) {
                    $this->addSkuAlias($aliases, $nestedValue);
                }
                continue;
            }

            $this->addSkuAlias($aliases, $value);
        }

        $explain = $this->decodeExplain($line);
        $inputs = is_array($explain['inputs'] ?? null) ? $explain['inputs'] : [];
        foreach ([
            'sku',
            'offer_id',
            'barcode',
            'barcodes',
            'product_id',
            'product_sku',
            'marketplace_sku',
            'ozon_sku',
            'fbo_sku',
            'nm_id',
            'vendor_code',
            'article',
        ] as $key) {
            $value = $inputs[$key] ?? null;
            if (is_array($value)) {
                foreach ($value as $nestedValue) {
                    $this->addSkuAlias($aliases, $nestedValue);
                }
                continue;
            }

            $this->addSkuAlias($aliases, $value);
        }

        return array_values(array_unique($aliases));
    }

    /**
     * @param list<string> $aliases
     */
    private function addSkuAlias(array &$aliases, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return;
        }

        $aliases[] = $value;
    }

    /**
     * @param list<string> $destinationAliases
     * @param list<string> $skuAliases
     * @return list<string>
     */
    private function constraintAliases(array $destinationAliases, array $skuAliases, string $marketplace, bool $includeFallbacks): array
    {
        $keys = [];

        if ($destinationAliases !== [] && $skuAliases !== []) {
            foreach ($destinationAliases as $destinationAlias) {
                foreach ($skuAliases as $skuAlias) {
                    $key = $this->constraintKey($destinationAlias, $skuAlias, $marketplace);
                    if ($key !== null) {
                        $keys[] = $key;
                    }
                }
            }

            if (! $includeFallbacks) {
                return array_values(array_unique($keys));
            }
        }

        foreach ($destinationAliases as $destinationAlias) {
            $key = $this->constraintKey($destinationAlias, null, $marketplace);
            if ($key !== null) {
                $keys[] = $key;
            }
        }

        foreach ($skuAliases as $skuAlias) {
            $key = $this->constraintKey(null, $skuAlias, $marketplace);
            if ($key !== null) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    private function normalizeKeyPart(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = str_replace('ё', 'е', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return $normalized;
    }

    /**
     * @param list<string> $aliases
     */
    private function lookupByAliases(mixed $items, array $aliases): mixed
    {
        if ($items === null || $aliases === []) {
            return null;
        }

        foreach ($aliases as $alias) {
            if (is_object($items) && method_exists($items, 'get')) {
                $found = $items->get($alias);
                if ($found !== null) {
                    return $found;
                }
            }

            if (is_array($items) && array_key_exists($alias, $items)) {
                return $items[$alias];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $need
     * @return array<string, mixed>
     */
    private function buildMarketplaceNeedCandidateLine(
        AutoSupplyPlan $plan,
        string $marketplace,
        array $need,
        mixed $product,
        mixed $ue,
    ): array {
        $sku = trim((string) ($need['sku'] ?? ''));
        $needQty = max(0, (int) ($need['need_qty'] ?? 0));
        $destinationId = $need['destination_id'] !== null && $need['destination_id'] !== ''
            ? (string) $need['destination_id']
            : null;
        $destinationName = $need['destination_name'] !== null && $need['destination_name'] !== ''
            ? (string) $need['destination_name']
            : $destinationId;

        $price = $this->numericValue($product, 'price') ?? $this->numericValue($ue, 'price') ?? 0.0;
        $costPrice = $this->numericValue($product, 'cost_price') ?? $this->numericValue($ue, 'cost_price') ?? 0.0;
        $commissionPercent = $this->numericValue($ue, 'commission_percent') ?? 0.0;
        $logisticsCost = $this->numericValue($ue, 'logistics_cost') ?? $this->numericValue($ue, 'delivery_cost') ?? 0.0;
        $storageCostDaily = (($this->numericValue($ue, 'storage_cost') ?? 0.0) / 30.0);
        $supplyCostEstimate = $costPrice > 0 ? $costPrice * $needQty : 0.0;
        $expectedRevenue = $price > 0 ? $price * $needQty : 0.0;
        $expectedProfit = $expectedRevenue
            - $supplyCostEstimate
            - ($expectedRevenue * ($commissionPercent / 100))
            - ($logisticsCost * $needQty)
            - ($storageCostDaily * 30);
        $roiPercent = $supplyCostEstimate > 0 ? round(($expectedProfit / $supplyCostEstimate) * 100, 2) : 0.0;

        $isOzon = $marketplace === 'ozon';
        $clusterId = $isOzon && $destinationId !== null && is_numeric($destinationId) ? (int) $destinationId : null;
        $warehouseId = ! $isOzon ? $destinationId : null;
        $productName = $this->stringValue($product, 'name')
            ?? $this->stringValue($product, 'product_name')
            ?? $this->stringValue($ue, 'product_name');
        $barcode = $this->stringValue($product, 'barcode');
        $now = now();

        $explain = [
            'version' => 3,
            'candidate_source' => [
                'type' => 'marketplace_need_file',
                'source_type' => $need['source_type'] ?? 'marketplace_need',
                'reason' => $need['reason'] ?? null,
                'decision_ru' => 'Строка создана из файла потребностей маркетплейса: внутренний расчёт не создал её сам, поэтому дальше она проходит ограничения, экономику, бюджет и проверку качества.',
            ],
            'inputs' => [
                'demand_source' => 'marketplace_need_file',
                'stock_scope' => $isOzon ? 'cluster' : 'warehouse',
                'daily_demand' => 0,
                'abc_priority' => 'B',
                'target_cover_days' => 30,
                'min_cover_days' => 7,
                'marketplace_need_qty' => $needQty,
            ],
            'math' => [
                'qty_rounded' => $needQty,
                'qty_anchor' => 'marketplace_need',
                'needed_after_caps' => $needQty,
            ],
            'confidence' => [
                'needs_manual_review' => true,
                'confidence_level' => 'warning',
                'confidence_reasons' => ['marketplace_need_without_internal_candidate'],
                'sources' => [
                    'demand' => 'marketplace_need_file',
                    'stock' => null,
                    'economics' => $ue !== null ? 'unit_economics' : ($product !== null ? 'product' : null),
                ],
            ],
            'unit_economics' => [
                'commission_percent' => $commissionPercent,
                'logistics_cost' => $logisticsCost,
                'roi_percent' => $roiPercent,
            ],
        ];

        return [
            'auto_supply_plan_id' => $plan->id,
            'tenant_id' => $plan->tenant_id,
            'sku' => $sku,
            'offer_id' => $sku,
            'product_name' => $productName,
            'barcode' => $barcode,
            'price' => $price > 0 ? round($price, 2) : null,
            'cost_price' => $costPrice > 0 ? round($costPrice, 2) : null,
            'warehouse_id' => $warehouseId,
            'warehouse_name' => ! $isOzon ? $destinationName : null,
            'cluster_id' => $clusterId,
            'cluster_name' => $isOzon ? $destinationName : null,
            'region' => $destinationName,
            'own_stock' => 0,
            'own_stock_reserved' => 0,
            'deficit' => $needQty,
            'destination' => $destinationName,
            'destination_id' => $destinationId,
            'destination_type' => $isOzon ? 'cluster' : 'warehouse',
            'qty_recommended' => $needQty,
            'qty_rounded' => $needQty,
            'current_stock' => 0,
            'in_transit' => 0,
            'sales_7_days' => 0,
            'sales_14_days' => 0,
            'sales_30_days' => 0,
            'avg_daily_sales' => 0,
            'ewma_daily_sales' => 0,
            'demand_daily' => 0,
            'sales_trend' => 'unknown',
            'sales_trend_percent' => 0,
            'cover_days_before' => null,
            'cover_days_after' => null,
            'oos_date' => null,
            'surplus_days' => null,
            'storage_cost_daily' => round($storageCostDaily, 2),
            'storage_cost_monthly' => $this->numericValue($ue, 'storage_cost'),
            'lost_revenue_daily' => null,
            'supply_cost_estimate' => $supplyCostEstimate > 0 ? round($supplyCostEstimate, 2) : null,
            'expected_revenue' => $expectedRevenue > 0 ? round($expectedRevenue, 2) : null,
            'expected_profit' => round($expectedProfit, 2),
            'roi_percent' => $roiPercent,
            'priority_score' => 72,
            'priority' => 'high',
            'turnover_days' => null,
            'explain_json' => json_encode($explain),
            'risk_level' => 'medium',
            'simulation_json' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function numericValue(mixed $item, string $key): ?float
    {
        if ($item === null) {
            return null;
        }

        $value = is_array($item) ? ($item[$key] ?? null) : ($item->{$key} ?? null);

        return is_numeric($value) ? (float) $value : null;
    }

    private function stringValue(mixed $item, string $key): ?string
    {
        if ($item === null) {
            return null;
        }

        $value = is_array($item) ? ($item[$key] ?? null) : ($item->{$key} ?? null);
        $value = $value !== null ? trim((string) $value) : '';

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<string, array<string, mixed>> $constraints
     */
    private function uniqueConstraintsCount(array $constraints): int
    {
        return count(array_unique(array_map(
            static fn (array $constraint): string => (string) ($constraint['canonical_key'] ?? ''),
            $constraints
        )));
    }

    /**
     * @param array<string, array<string, mixed>> $constraints
     */
    private function uniqueMarketplaceNeedsCount(array $constraints): int
    {
        return count($this->uniqueMarketplaceNeedFacts($constraints));
    }

    /**
     * @param array<string, array<string, mixed>> $constraints
     * @return list<array<string, mixed>>
     */
    private function uniqueMarketplaceNeedFacts(array $constraints): array
    {
        $facts = [];
        foreach ($constraints as $constraint) {
            if (($constraint['need_qty'] ?? null) === null) {
                continue;
            }

            $canonicalKey = (string) ($constraint['canonical_key'] ?? '');
            if ($canonicalKey === '') {
                continue;
            }

            if (isset($facts[$canonicalKey])) {
                $facts[$canonicalKey]['need_qty'] = max((int) $facts[$canonicalKey]['need_qty'], max(0, (int) $constraint['need_qty']));
                continue;
            }

            $facts[$canonicalKey] = [
                'canonical_key' => $canonicalKey,
                'sku' => $constraint['sku'] ?? null,
                'destination_id' => $constraint['id'] ?? null,
                'destination_name' => $constraint['name'] ?? $constraint['id'] ?? null,
                'need_qty' => max(0, (int) $constraint['need_qty']),
                'max_qty' => $constraint['max_qty'] ?? null,
                'scope' => $constraint['scope'] ?? null,
                'reason' => $constraint['reason'] ?? null,
                'source_type' => $constraint['source_type'] ?? 'marketplace_need',
            ];
        }

        return array_values($facts);
    }

    /**
     * @param array<string, array<string, mixed>> $constraints
     * @param list<string> $matchedCanonicalKeys
     * @return list<array<string, mixed>>
     */
    private function unmatchedMarketplaceNeedFacts(array $constraints, array $matchedCanonicalKeys): array
    {
        $matched = array_fill_keys($matchedCanonicalKeys, true);

        return array_values(array_filter(
            $this->uniqueMarketplaceNeedFacts($constraints),
            static fn (array $fact): bool => ! isset($matched[(string) ($fact['canonical_key'] ?? '')])
        ));
    }

    private function constraintScope(?string $destinationId, ?string $sku, string $marketplace): string
    {
        $destinationLabel = $marketplace === 'ozon' ? 'кластер' : 'склад';

        return match (true) {
            $destinationId !== null && $sku !== null => "{$destinationLabel} + SKU",
            $destinationId !== null => "{$destinationLabel} целиком",
            $sku !== null => 'SKU во всех направлениях',
            default => 'не определено',
        };
    }

    /**
     * @param array<string, array<string, mixed>> $constraints
     * @return array<string, int>
     */
    private function uniqueSourceTypeCounts(array $constraints): array
    {
        $unique = [];
        foreach ($constraints as $constraint) {
            $canonicalKey = (string) ($constraint['canonical_key'] ?? '');
            if ($canonicalKey === '') {
                continue;
            }
            $unique[$canonicalKey] = (string) ($constraint['source_type'] ?? 'marketplace_constraint');
        }

        $counts = [];
        foreach ($unique as $sourceType) {
            $counts[$sourceType] = ($counts[$sourceType] ?? 0) + 1;
        }

        return $counts;
    }

    private function sourceStatus(
        int $matchedLines,
        int $matchedNeedLines,
        int $coefficientLines,
        int $cappedLines,
        int $blockedLines,
        array $metadata,
        int $unmatchedMarketplaceNeedCount = 0,
    ): string {
        if ($matchedLines <= 0) {
            if ($unmatchedMarketplaceNeedCount > 0) {
                return 'marketplace_needs_unmatched';
            }

            return $metadata !== [] ? 'file_loaded_no_matches' : 'not_applied';
        }

        if ($blockedLines > 0 || $cappedLines > 0) {
            return 'applied_with_limits';
        }

        if ($matchedNeedLines > 0) {
            return 'applied_as_marketplace_needs';
        }

        if ($coefficientLines > 0) {
            return 'applied_as_coefficients';
        }

        return 'applied';
    }

    private function humanStatus(
        int $matchedLines,
        int $matchedNeedLines,
        int $cappedLines,
        int $blockedLines,
        array $metadata,
        int $unmatchedMarketplaceNeedCount = 0,
    ): string {
        if ($matchedLines <= 0) {
            if ($unmatchedMarketplaceNeedCount > 0) {
                return 'Потребности маркетплейса загружены, но не совпали со строками плана';
            }

            return $metadata !== []
                ? 'Файл загружен, но не совпал со строками плана'
                : 'Ограничения из запроса не совпали со строками плана';
        }

        if ($blockedLines > 0 || $cappedLines > 0) {
            return 'Файл ограничений повлиял на итоговые количества';
        }

        if ($matchedNeedLines > 0) {
            return 'Потребности маркетплейса учтены как отдельный источник';
        }

        return 'Ограничения учтены как источник планирования';
    }

    private function decisionText(
        int $matchedLines,
        int $matchedNeedLines,
        int $remainingNeedQty,
        int $cappedLines,
        int $blockedLines,
        int $unmatchedConstraintsCount,
        int $unmatchedMarketplaceNeedCount = 0,
        int $unmatchedMarketplaceNeedQty = 0,
    ): string {
        if ($unmatchedMarketplaceNeedQty > 0) {
            return "Файл показывает незакрытые потребности: {$unmatchedMarketplaceNeedQty} шт. по {$unmatchedMarketplaceNeedCount} правилам не попали в строки плана. Проверьте SKU, кластеры/склады или создайте отдельные кандидаты.";
        }

        if ($matchedLines <= 0) {
            return 'Файл не изменил план: нет совпадений по складам/кластерам или артикулам.';
        }

        if ($remainingNeedQty > 0) {
            return "Файл показывает потребность, но {$remainingNeedQty} шт. не закрыто из-за лимитов или ограничений.";
        }

        if ($blockedLines > 0 || $cappedLines > 0) {
            return 'Система снизила или убрала часть строк, чтобы не нарушить ограничения маркетплейса.';
        }

        if ($matchedNeedLines > 0) {
            return 'Потребности маркетплейса совпали со строками плана и прошли ограничения.';
        }

        if ($unmatchedConstraintsCount > 0) {
            return 'Часть правил из файла не нашла совпадений: проверьте названия направлений и артикулы.';
        }

        return 'Источник ограничений успешно применён к плану.';
    }

    private function coverageText(float $matchPercent, float $needMatchPercent, int $unmatchedConstraintsCount, int $unmatchedMarketplaceNeedCount = 0, int $unmatchedMarketplaceNeedQty = 0): string
    {
        $parts = ["Покрытие строк: {$matchPercent}%"];

        if ($needMatchPercent > 0) {
            $parts[] = "потребности совпали с {$needMatchPercent}% строк";
        }

        if ($unmatchedMarketplaceNeedQty > 0) {
            $parts[] = "незакрытая потребность: {$unmatchedMarketplaceNeedQty} шт. по {$unmatchedMarketplaceNeedCount} правилам";
        }

        if ($unmatchedConstraintsCount > 0) {
            $parts[] = "не совпало правил: {$unmatchedConstraintsCount}";
        }

        return implode('; ', $parts) . '.';
    }
}
