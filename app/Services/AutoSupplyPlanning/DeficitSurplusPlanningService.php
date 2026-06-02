<?php

namespace App\Services\AutoSupplyPlanning;

use App\Models\AutoSupplyPlan;
use Carbon\CarbonImmutable;

class DeficitSurplusPlanningService
{
    /**
     * @param list<array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    public function analyze(array $lines, AutoSupplyPlan $plan, array $context = []): array
    {
        $marketplace = (string) $plan->marketplace;
        $minCoverDays = (float) ($context['min_cover_days'] ?? $plan->min_cover_days ?? 0);
        $targetCoverDays = (float) ($context['target_cover_days'] ?? $plan->target_cover_days ?? 0);
        $allowsInternalTransfer = ! in_array($marketplace, ['ozon', 'wildberries'], true);
        $asOfDate = $this->asOfDate($context);

        $deficit = [
            'lines' => 0,
            'qty' => 0,
            'high_risk_lines' => 0,
            'lost_revenue_daily' => 0.0,
            'lost_revenue_until_min_cover' => 0.0,
            'earliest_stockout_date' => null,
            'earliest_stockout_after_days' => null,
            'top' => [],
        ];
        $surplus = [
            'lines' => 0,
            'qty' => 0,
            'overstock_risk_lines' => 0,
            'overstock_days_total' => 0.0,
            'top' => [],
        ];
        $inTransit = [
            'lines' => 0,
            'qty' => 0,
            'deficit_covered_qty' => 0,
            'coverage_days_total' => 0.0,
        ];

        $deficitBySku = [];
        $surplusBySku = [];

        foreach ($lines as $line) {
            $explain = $this->decodeExplain($line);
            $dailyDemand = $this->dailyDemand($line, $explain);
            $currentStock = (int) ($line['current_stock'] ?? 0);
            $inTransitQty = (int) ($line['in_transit'] ?? 0);
            $availableNow = $currentStock + $inTransitQty;
            $qty = (int) ($line['qty_rounded'] ?? 0);
            $risk = (string) ($line['risk_level'] ?? 'low');
            $sku = (string) ($line['sku'] ?? '');

            if ($inTransitQty > 0) {
                $inTransit['lines']++;
                $inTransit['qty'] += $inTransitQty;
                if ($dailyDemand > 0) {
                    $inTransit['coverage_days_total'] += $inTransitQty / $dailyDemand;
                }
            }

            if ($dailyDemand <= 0) {
                continue;
            }

            $lineMinCoverDays = (float) ($explain['inputs']['min_cover_days'] ?? $minCoverDays);
            $lineTargetCoverDays = (float) ($explain['inputs']['target_cover_days'] ?? $targetCoverDays);
            $deficitQty = $lineMinCoverDays > 0
                ? max(0, (int) ceil($lineMinCoverDays * $dailyDemand - $availableNow))
                : 0;
            $surplusQty = $lineTargetCoverDays > 0
                ? max(0, (int) floor($availableNow - $lineTargetCoverDays * $dailyDemand))
                : 0;
            $daysOfCover = $this->daysOfCover($availableNow, $dailyDemand);
            $stockoutAfterDays = $this->stockoutAfterDays($availableNow, $dailyDemand);
            $stockoutDate = $this->stockoutDate($asOfDate, $stockoutAfterDays);
            $lostRevenueUntilMinCover = $this->lostRevenueUntilCover(
                lostRevenueDaily: (float) ($line['lost_revenue_daily'] ?? 0),
                coverDays: $daysOfCover,
                targetDays: $lineMinCoverDays,
            );

            if ($deficitQty > 0) {
                $deficit['lines']++;
                $deficit['qty'] += $deficitQty;
                $deficit['lost_revenue_daily'] += (float) ($line['lost_revenue_daily'] ?? 0);
                $deficit['lost_revenue_until_min_cover'] += $lostRevenueUntilMinCover;
                $deficit['earliest_stockout_after_days'] = $deficit['earliest_stockout_after_days'] === null
                    ? $stockoutAfterDays
                    : min((float) $deficit['earliest_stockout_after_days'], $stockoutAfterDays);
                $deficit['earliest_stockout_date'] = $deficit['earliest_stockout_date'] === null || $stockoutDate->lessThan($deficit['earliest_stockout_date'])
                    ? $stockoutDate
                    : $deficit['earliest_stockout_date'];
                if ($risk === 'high') {
                    $deficit['high_risk_lines']++;
                }
                if ($inTransitQty > 0) {
                    $inTransit['deficit_covered_qty'] += min($deficitQty, $inTransitQty);
                }

                $deficitBySku[$sku][] = $this->balancePoint($line, $deficitQty, $dailyDemand, $availableNow, $asOfDate);
                $deficit['top'][] = $this->topRow($line, $deficitQty, $dailyDemand, $availableNow, 'дефицит', $asOfDate, $lineMinCoverDays, $lineTargetCoverDays);
            }

            if ($surplusQty > 0) {
                $surplus['lines']++;
                $surplus['qty'] += $surplusQty;
                $surplus['overstock_days_total'] += max(0.0, $daysOfCover - $lineTargetCoverDays);
                if ($qty === 0 || ($line['cover_days_before'] ?? 0) > $lineTargetCoverDays * 2) {
                    $surplus['overstock_risk_lines']++;
                }

                $surplusBySku[$sku][] = $this->balancePoint($line, $surplusQty, $dailyDemand, $availableNow, $asOfDate);
                $surplus['top'][] = $this->topRow($line, $surplusQty, $dailyDemand, $availableNow, 'профицит', $asOfDate, $lineMinCoverDays, $lineTargetCoverDays);
            }
        }

        $deficit['lost_revenue_daily'] = round($deficit['lost_revenue_daily'], 2);
        $deficit['lost_revenue_until_min_cover'] = round($deficit['lost_revenue_until_min_cover'], 2);
        $deficit['earliest_stockout_after_days'] = $deficit['earliest_stockout_after_days'] !== null
            ? round((float) $deficit['earliest_stockout_after_days'], 2)
            : null;
        $deficit['earliest_stockout_date'] = $deficit['earliest_stockout_date'] instanceof CarbonImmutable
            ? $deficit['earliest_stockout_date']->toDateString()
            : null;
        $deficit['top'] = $this->topByQty($deficit['top']);
        $surplus['overstock_days_total'] = round($surplus['overstock_days_total'], 2);
        $surplus['top'] = $this->topByQty($surplus['top']);
        $inTransit['coverage_days_total'] = round($inTransit['coverage_days_total'], 2);

        $redistribution = $this->buildRedistribution(
            deficitBySku: $deficitBySku,
            surplusBySku: $surplusBySku,
            allowed: $allowsInternalTransfer,
            marketplace: $marketplace,
        );

        return [
            'version' => 'deficit-surplus-1',
            'status' => 'включено',
            'method' => 'Отдельный анализ дефицита, профицита, товаров в пути и допустимого перераспределения',
            'deficit_summary' => $deficit,
            'surplus_summary' => $surplus,
            'in_transit_summary' => $inTransit,
            'redistribution' => $redistribution,
            'recommendations' => $this->recommendations($deficit, $surplus, $inTransit, $redistribution),
        ];
    }

    /**
     * @param array<string, list<array<string, mixed>>> $deficitBySku
     * @param array<string, list<array<string, mixed>>> $surplusBySku
     * @return array<string, mixed>
     */
    private function buildRedistribution(array $deficitBySku, array $surplusBySku, bool $allowed, string $marketplace): array
    {
        $suggestions = [];
        if ($allowed) {
            foreach ($deficitBySku as $sku => $deficitPoints) {
                $surplusPoints = $surplusBySku[$sku] ?? [];
                foreach ($deficitPoints as $deficitPoint) {
                    $need = (int) $deficitPoint['qty'];
                    foreach ($surplusPoints as &$surplusPoint) {
                        if ($need <= 0) {
                            break;
                        }
                        if ((int) $surplusPoint['qty'] <= 0 || $surplusPoint['destination_key'] === $deficitPoint['destination_key']) {
                            continue;
                        }

                        $transferQty = min($need, (int) $surplusPoint['qty']);
                        $suggestions[] = [
                            'sku' => $sku,
                            'product_name' => $deficitPoint['product_name'],
                            'from_destination' => $surplusPoint['destination_name'],
                            'to_destination' => $deficitPoint['destination_name'],
                            'transfer_qty' => $transferQty,
                            'from_days_of_cover' => $surplusPoint['days_of_cover'] ?? null,
                            'to_days_of_cover' => $deficitPoint['days_of_cover'] ?? null,
                            'to_stockout_date' => $deficitPoint['stockout_date'] ?? null,
                            'to_stockout_after_days' => $deficitPoint['stockout_after_days'] ?? null,
                            'reason' => 'Закрыть дефицит за счёт профицита без новой закупки',
                        ];
                        $need -= $transferQty;
                        $surplusPoint['qty'] -= $transferQty;
                    }
                    unset($surplusPoint);
                }
            }
        }

        return [
            'allowed' => $allowed,
            'policy' => $allowed
                ? 'Разрешено для собственных складов или 3PL-сценариев'
                : 'Для FBO Ozon/WB физическое перераспределение между складами маркетплейса недоступно продавцу',
            'marketplace' => $marketplace,
            'suggestions_count' => count($suggestions),
            'suggestions' => array_slice($suggestions, 0, 50),
        ];
    }

    /** @return list<string> */
    private function recommendations(array $deficit, array $surplus, array $inTransit, array $redistribution): array
    {
        $items = [];
        if ((int) $deficit['qty'] > 0) {
            $stockoutText = ($deficit['earliest_stockout_date'] ?? null)
                ? ' Ближайшая дата риска отсутствия: ' . $deficit['earliest_stockout_date'] . '.'
                : '';
            $items[] = 'Сначала закрыть дефицит: есть риск отсутствия товара и потери выручки.' . $stockoutText;
        }
        if ((int) $inTransit['qty'] > 0) {
            $items[] = 'Товары в пути уже учтены, повторно добивать этот объём не нужно.';
        }
        if ((int) $surplus['qty'] > 0) {
            $items[] = 'Профицит отмечен отдельно: такие остатки нельзя считать новой потребностью.';
        }
        if (($redistribution['allowed'] ?? false) && (int) ($redistribution['suggestions_count'] ?? 0) > 0) {
            $items[] = 'Часть дефицита можно закрыть перераспределением без новой закупки.';
        }
        if ($items === []) {
            $items[] = 'Критичного дефицита или профицита по выбранным строкам не найдено.';
        }

        return $items;
    }

    private function dailyDemand(array $line, array $explain): float
    {
        return (float) ($explain['inputs']['daily_demand'] ?? $line['demand_daily'] ?? 0);
    }

    /** @return array<string, mixed> */
    private function balancePoint(array $line, int $qty, float $dailyDemand, int $availableNow, CarbonImmutable $asOfDate): array
    {
        $stockoutAfterDays = $this->stockoutAfterDays($availableNow, $dailyDemand);

        return [
            'sku' => (string) ($line['sku'] ?? ''),
            'product_name' => $line['product_name'] ?? null,
            'destination_key' => $this->destinationKey($line),
            'destination_name' => $this->destinationName($line),
            'qty' => $qty,
            'daily_demand' => round($dailyDemand, 4),
            'available_now' => $availableNow,
            'days_of_cover' => $this->daysOfCover($availableNow, $dailyDemand),
            'stockout_after_days' => $stockoutAfterDays,
            'stockout_date' => $this->stockoutDate($asOfDate, $stockoutAfterDays)->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function topRow(
        array $line,
        int $qty,
        float $dailyDemand,
        int $availableNow,
        string $type,
        CarbonImmutable $asOfDate,
        float $minCoverDays,
        float $targetCoverDays,
    ): array
    {
        $daysOfCover = $this->daysOfCover($availableNow, $dailyDemand);
        $stockoutAfterDays = $this->stockoutAfterDays($availableNow, $dailyDemand);
        $lostRevenueDaily = (float) ($line['lost_revenue_daily'] ?? 0);

        return [
            'type' => $type,
            'sku' => (string) ($line['sku'] ?? ''),
            'product_name' => $line['product_name'] ?? null,
            'destination' => $this->destinationName($line),
            'qty' => $qty,
            'daily_demand' => round($dailyDemand, 4),
            'available_now' => $availableNow,
            'days_of_cover' => $daysOfCover,
            'stockout_after_days' => $stockoutAfterDays,
            'stockout_date' => $this->stockoutDate($asOfDate, $stockoutAfterDays)->toDateString(),
            'lost_revenue_daily' => round($lostRevenueDaily, 2),
            'lost_revenue_until_min_cover' => $this->lostRevenueUntilCover($lostRevenueDaily, $daysOfCover, $minCoverDays),
            'overstock_days_over_target' => round(max(0.0, $daysOfCover - $targetCoverDays), 2),
            'risk_level' => $line['risk_level'] ?? null,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function topByQty(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => ((int) $b['qty']) <=> ((int) $a['qty']));

        return array_slice($rows, 0, 10);
    }

    private function destinationKey(array $line): string
    {
        return (string) (
            $line['destination_id']
            ?? $line['cluster_id']
            ?? $line['warehouse_id']
            ?? $line['warehouse_name']
            ?? 'unknown'
        );
    }

    private function destinationName(array $line): ?string
    {
        return $line['destination']
            ?? $line['cluster_name']
            ?? $line['warehouse_name']
            ?? null;
    }

    /** @param array<string, mixed> $context */
    private function asOfDate(array $context): CarbonImmutable
    {
        $value = $context['as_of_date'] ?? null;

        return $value ? CarbonImmutable::parse((string) $value)->startOfDay() : CarbonImmutable::now()->startOfDay();
    }

    private function daysOfCover(int $availableNow, float $dailyDemand): float
    {
        return $dailyDemand > 0 ? round($availableNow / $dailyDemand, 2) : 0.0;
    }

    private function stockoutAfterDays(int $availableNow, float $dailyDemand): float
    {
        if ($dailyDemand <= 0) {
            return 0.0;
        }

        return round(max(0.0, $availableNow / $dailyDemand), 2);
    }

    private function stockoutDate(CarbonImmutable $asOfDate, float $stockoutAfterDays): CarbonImmutable
    {
        return $asOfDate->addDays((int) ceil(max(0.0, $stockoutAfterDays)));
    }

    private function lostRevenueUntilCover(float $lostRevenueDaily, float $coverDays, float $targetDays): float
    {
        if ($lostRevenueDaily <= 0 || $targetDays <= 0) {
            return 0.0;
        }

        return round(max(0.0, $targetDays - $coverDays) * $lostRevenueDaily, 2);
    }

    /** @return array<string, mixed> */
    private function decodeExplain(array $line): array
    {
        $value = $line['explain_json'] ?? [];
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
