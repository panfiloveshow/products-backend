<?php

namespace App\Services\AutoSupplyPlanning;

use App\Models\AutoSupplyPlan;
use App\Models\AutoSupplyPlanLine;
use App\Models\PlanLineEvaluation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Сверка прогноза плана с фактом (этап 2, план-факт). MVP: метрика A —
 * прогноз спроса (demand_daily·N) против реальных продаж из postings/posting_items
 * за окно [plan.created_at; +N дней].
 *
 * Правило: нет факта (0 продаж / нет данных) → status=insufficient_data и метрики null.
 * Никогда не выдаём accuracy=100 на пустоте.
 */
class PlanFactReconciler
{
    /**
     * Оценить одну строку плана. Возвращает атрибуты для PlanLineEvaluation (без сохранения).
     *
     * @return array<string, mixed>
     */
    public function evaluateLine(AutoSupplyPlanLine $line, AutoSupplyPlan $plan): array
    {
        $horizon = $this->horizonDays($plan);
        $windowStart = $plan->created_at?->copy() ?? now();
        $windowEnd = $windowStart->copy()->addDays($horizon);

        $forecast = $this->forecastDemandQty($line, $horizon);
        $actual = $this->actualSalesQty($plan, $line, $windowStart, $windowEnd);
        $exec = $this->supplyExecution($plan, $line, $windowStart, $windowEnd);
        $inventoryOutcome = $this->inventoryOutcome($plan, $line, $windowStart, $windowEnd, $actual, $horizon);

        $base = [
            'auto_supply_plan_id' => $plan->id,
            'auto_supply_plan_line_id' => $line->id,
            'integration_id' => $plan->integration_id,
            'marketplace' => $plan->marketplace,
            'sku' => $line->sku,
            'cluster_id' => $line->cluster_id !== null ? (string) $line->cluster_id : null,
            'warehouse_id' => $line->warehouse_id,
            'plan_created_at' => $windowStart,
            'horizon_days' => $horizon,
            'evaluated_at' => now(),
            'forecast_demand_qty' => $forecast,
            'planned_qty' => (int) $line->qty_rounded,
            'demand_fact_source' => 'postings_fbo',
            // B: исполнение поставки (план vs принято). null, если поставки не найдено.
            'accepted_qty' => $exec['accepted'] ?? null,
            'rejected_qty' => $exec['rejected'] ?? null,
            'acceptance_rate' => $exec['acceptance_rate'] ?? null,
        ];

        // Факт = 0 / нет данных → APE неопределён, не считаем фейковую точность.
        if ($actual <= 0) {
            return $base + [
                'actual_sales_qty' => (float) $actual,
                'abs_pct_error' => null,
                'bias_pct' => null,
                'status' => PlanLineEvaluation::STATUS_INSUFFICIENT,
                'details_json' => [
                    'reason' => 'Нет фактических продаж за горизонт — точность не определена',
                    'inventory_outcome' => $inventoryOutcome,
                    'manual_override_outcome' => $this->manualOverrideOutcome($line, $inventoryOutcome, $exec),
                ],
            ];
        }

        $ape = round(abs($forecast - $actual) / $actual * 100, 2);
        $bias = round(($forecast - $actual) / $actual * 100, 2);

        $discrepancyCauses = $this->discrepancyCauses($forecast, $actual, $line, $exec);
        if (($inventoryOutcome['oos_days'] ?? 0) > 0) {
            $discrepancyCauses[] = 'oos_during_horizon';
        }
        if (($inventoryOutcome['excess_cover_days'] ?? 0) > 0) {
            $discrepancyCauses[] = 'excess_cover_after_horizon';
        }

        return $base + [
            'actual_sales_qty' => (float) $actual,
            'abs_pct_error' => $ape,
            'bias_pct' => $bias,
            'status' => PlanLineEvaluation::STATUS_OK,
            'details_json' => [
                'forecast_error_qty' => round($forecast - $actual, 2),
                'forecast_direction' => $forecast > $actual
                    ? 'over_forecast'
                    : ($forecast < $actual ? 'under_forecast' : 'on_target'),
                'acceptance_discrepancy_qty' => $exec === null
                    ? null
                    : max(0, (int) $line->qty_rounded - (int) ($exec['accepted'] ?? 0)),
                'discrepancy_causes' => array_values(array_unique($discrepancyCauses)),
                'inventory_outcome' => $inventoryOutcome,
                'manual_override_outcome' => $this->manualOverrideOutcome($line, $inventoryOutcome, $exec),
            ],
        ];
    }

    /**
     * Агрегат точности по набору пер-строчных оценок (для accuracy_json плана).
     *
     * @param array<int,array<string,mixed>> $evaluations
     * @return array<string,mixed>
     */
    public function aggregate(array $evaluations): array
    {
        $scored = array_values(array_filter(
            $evaluations,
            static fn ($e) => ($e['status'] ?? null) === PlanLineEvaluation::STATUS_OK && $e['abs_pct_error'] !== null
        ));

        $linesEvaluated = count($scored);
        $insufficient = count($evaluations) - $linesEvaluated;

        if ($linesEvaluated === 0) {
            $outcomes = array_values(array_filter(array_map(
                static fn (array $evaluation): ?array => is_array($evaluation['details_json']['inventory_outcome'] ?? null)
                    ? $evaluation['details_json']['inventory_outcome']
                    : null,
                $evaluations
            )));
            $manual = array_values(array_filter(array_map(
                static fn (array $evaluation): ?array => is_array($evaluation['details_json']['manual_override_outcome'] ?? null)
                    ? $evaluation['details_json']['manual_override_outcome']
                    : null,
                $evaluations
            )));
            $manualBreakdown = [];
            foreach ($manual as $outcome) {
                $key = (string) ($outcome['outcome'] ?? 'unknown');
                $manualBreakdown[$key] = ($manualBreakdown[$key] ?? 0) + 1;
            }

            return [
                'mape' => null,
                'wape' => null,
                'bias' => null,
                'weighted_bias' => null,
                'lines_evaluated' => 0,
                'lines_insufficient_data' => $insufficient,
                'oos_days_total' => array_sum(array_map(
                    static fn (array $outcome): int => (int) ($outcome['oos_days'] ?? 0),
                    $outcomes
                )),
                'lines_with_oos' => count(array_filter(
                    $outcomes,
                    static fn (array $outcome): bool => (int) ($outcome['oos_days'] ?? 0) > 0
                )),
                'lines_with_excess_cover' => count(array_filter(
                    $outcomes,
                    static fn (array $outcome): bool => (float) ($outcome['excess_cover_days'] ?? 0) > 0
                )),
                'manual_override_lines' => count($manual),
                'manual_override_outcomes' => $manualBreakdown,
                'evaluated_at' => now()->toIso8601String(),
            ];
        }

        $mape = array_sum(array_column($scored, 'abs_pct_error')) / $linesEvaluated;
        $bias = array_sum(array_column($scored, 'bias_pct')) / $linesEvaluated;
        $actualTotal = array_sum(array_map(
            static fn (array $evaluation): float => (float) ($evaluation['actual_sales_qty'] ?? 0),
            $scored
        ));
        $absoluteErrorTotal = array_sum(array_map(
            static fn (array $evaluation): float => abs(
                (float) ($evaluation['forecast_demand_qty'] ?? 0)
                - (float) ($evaluation['actual_sales_qty'] ?? 0)
            ),
            $scored
        ));
        $signedErrorTotal = array_sum(array_map(
            static fn (array $evaluation): float =>
                (float) ($evaluation['forecast_demand_qty'] ?? 0)
                - (float) ($evaluation['actual_sales_qty'] ?? 0),
            $scored
        ));
        $wape = $actualTotal > 0 ? $absoluteErrorTotal / $actualTotal * 100 : null;
        $weightedBias = $actualTotal > 0 ? $signedErrorTotal / $actualTotal * 100 : null;

        $withSupply = array_values(array_filter(
            $evaluations,
            static fn ($e) => ($e['acceptance_rate'] ?? null) !== null
        ));
        $plannedWithSupply = array_sum(array_map(
            static fn (array $evaluation): int => (int) ($evaluation['planned_qty'] ?? 0),
            $withSupply
        ));
        $acceptedWithSupply = array_sum(array_map(
            static fn (array $evaluation): int => (int) ($evaluation['accepted_qty'] ?? 0),
            $withSupply
        ));
        $acceptance = $plannedWithSupply > 0
            ? round(min(100, $acceptedWithSupply / $plannedWithSupply * 100), 2)
            : null;
        $details = array_values(array_filter(array_map(
            static fn (array $evaluation): ?array => is_array($evaluation['details_json'] ?? null)
                ? $evaluation['details_json']
                : null,
            $evaluations
        )));
        $inventoryOutcomes = array_values(array_filter(array_map(
            static fn (array $detail): ?array => is_array($detail['inventory_outcome'] ?? null)
                ? $detail['inventory_outcome']
                : null,
            $details
        )));
        $manualOutcomes = array_values(array_filter(array_map(
            static fn (array $detail): ?array => is_array($detail['manual_override_outcome'] ?? null)
                ? $detail['manual_override_outcome']
                : null,
            $details
        )));
        $manualOutcomeBreakdown = [];
        foreach ($manualOutcomes as $outcome) {
            $key = (string) ($outcome['outcome'] ?? 'unknown');
            $manualOutcomeBreakdown[$key] = ($manualOutcomeBreakdown[$key] ?? 0) + 1;
        }

        return [
            'mape' => round($mape, 2),
            'wape' => $wape !== null ? round($wape, 2) : null,
            'bias' => round($bias, 2),
            'weighted_bias' => $weightedBias !== null ? round($weightedBias, 2) : null,
            'accuracy' => round(max(0, 100 - ($wape ?? $mape)), 2),
            'forecast_qty_total' => round(array_sum(array_column($scored, 'forecast_demand_qty')), 2),
            'actual_qty_total' => round($actualTotal, 2),
            'lines_evaluated' => $linesEvaluated,
            'lines_insufficient_data' => $insufficient,
            'acceptance_rate' => $acceptance,
            'lines_with_supply' => count($withSupply),
            'oos_days_total' => array_sum(array_map(
                static fn (array $outcome): int => (int) ($outcome['oos_days'] ?? 0),
                $inventoryOutcomes
            )),
            'lines_with_oos' => count(array_filter(
                $inventoryOutcomes,
                static fn (array $outcome): bool => (int) ($outcome['oos_days'] ?? 0) > 0
            )),
            'lines_with_excess_cover' => count(array_filter(
                $inventoryOutcomes,
                static fn (array $outcome): bool => (float) ($outcome['excess_cover_days'] ?? 0) > 0
            )),
            'manual_override_lines' => count($manualOutcomes),
            'manual_override_outcomes' => $manualOutcomeBreakdown,
            'evaluated_at' => now()->toIso8601String(),
        ];
    }

    public function horizonDays(AutoSupplyPlan $plan): int
    {
        $horizon = (int) ($plan->horizon_days ?? 0);
        if ($horizon <= 0) {
            $horizon = (int) ($plan->target_cover_days ?? 0);
        }

        return $horizon > 0 ? $horizon : 28;
    }

    private function forecastDemandQty(AutoSupplyPlanLine $line, int $horizon): float
    {
        $daily = $this->forecastDailyDemand($line);
        if ($daily > 0) {
            return round($daily * $horizon, 2);
        }

        // Фолбэк: сумма прогноза по дням из simulation_json.
        $sim = is_array($line->simulation_json) ? $line->simulation_json : [];
        $sum = 0.0;
        foreach (array_slice($sim, 0, $horizon) as $day) {
            $sum += (float) ($day['sales_forecast'] ?? 0);
        }

        return round($sum, 2);
    }

    /**
     * Дневной прогноз для измерения точности. Если применялась калибровка (этап 4),
     * берём спрос ДО неё (explain.inputs.daily_demand_pre_calibration): иначе план-факт
     * меряет собственную поправку, bias всегда ≈0 и корректор перестаёт сходиться.
     */
    private function forecastDailyDemand(AutoSupplyPlanLine $line): float
    {
        $explain = is_array($line->explain_json) ? $line->explain_json : [];
        $calibrationApplied = (bool) ($explain['inputs']['calibration']['applied'] ?? false);

        if ($calibrationApplied) {
            $raw = $explain['inputs']['daily_demand_pre_calibration'] ?? null;
            if (is_numeric($raw) && (float) $raw > 0) {
                return (float) $raw;
            }
        }

        $daily = $line->demand_daily;

        return is_numeric($daily) ? (float) $daily : 0.0;
    }

    /**
     * Реальные продажи (единицы) по SKU за окно из postings/posting_items.
     * Эталон источника — DemandForecaster (postings + posting_items), но SUM(quantity),
     * а не COUNT(*). Отменённые отправления исключаем. Для Ozon — фильтр по кластеру.
     */
    private function actualSalesQty(AutoSupplyPlan $plan, AutoSupplyPlanLine $line, $start, $end): int
    {
        $offer = $line->offer_id ?: $line->sku;

        $query = DB::table('posting_items as pi')
            ->join('postings as p', 'pi.posting_id', '=', 'p.id')
            ->where('p.integration_id', (string) $plan->integration_id)
            ->where('p.marketplace', $plan->marketplace)
            ->whereNull('p.cancelled_at')
            ->whereBetween('p.created_at', [$start, $end])
            ->where(function ($w) use ($offer, $line) {
                $w->where('pi.offer_id', $offer)->orWhere('pi.sku', $line->sku);
                if (! empty($line->barcode)) {
                    $w->orWhere('pi.barcode', $line->barcode);
                }
            });

        // Ozon: кластерную строку факт обязан быть сужен до её кластера. Без имени
        // кластера надёжно сопоставить нельзя — НЕ подменяем суммой всех кластеров
        // (это дало бы ложный under-forecast и отравило калибровку). Лучше «нет данных».
        if ($plan->marketplace === 'ozon' && $line->cluster_id !== null) {
            if (empty($line->cluster_name)) {
                return 0;
            }
            $query->where('p.financial_data->cluster_to', $line->cluster_name);
        }

        return (int) $query->sum('pi.quantity');
    }

    /**
     * B: исполнение поставки — сколько по SKU реально принято на МП за окно.
     * Best-effort матч по (integration_id, sku, окно создания поставки); статусы приёмки
     * accepted_partial/accepted_full/closed. Возвращает null, если поставок не найдено.
     *
     * @return array{accepted:int, rejected:int, acceptance_rate:float|null}|null
     */
    private function supplyExecution(AutoSupplyPlan $plan, AutoSupplyPlanLine $line, $start, $end): ?array
    {
        $offer = $line->offer_id ?: $line->sku;

        $base = DB::table('supply_items as si')
            ->join('supplies as s', 'si.supply_id', '=', 's.id')
            ->where('s.integration_id', $plan->integration_id)
            ->whereIn('s.status', ['accepted_partial', 'accepted_full', 'closed'])
            ->whereBetween('s.created_at', [$start, $end]);

        $row = null;
        if (Schema::hasColumn('supply_items', 'auto_supply_plan_line_id')) {
            $row = (clone $base)
                ->where('si.auto_supply_plan_line_id', $line->id)
                ->selectRaw('COALESCE(SUM(si.accepted_qty), 0) acc, COALESCE(SUM(si.rejected_qty), 0) rej, COUNT(*) cnt')
                ->first();
        }

        // Совместимость со старыми поставками, созданными до появления прямой
        // связи plan line → supply item.
        if ($row === null || (int) $row->cnt === 0) {
            $row = (clone $base)
                ->when(
                    Schema::hasColumn('supply_items', 'auto_supply_plan_line_id'),
                    fn ($query) => $query->whereNull('si.auto_supply_plan_line_id')
                )
                ->where(function ($w) use ($offer, $line) {
                    $w->where('si.sku', $line->sku);
                    if ($offer !== $line->sku) {
                        $w->orWhere('si.sku', $offer);
                    }
                })
                ->selectRaw('COALESCE(SUM(si.accepted_qty), 0) acc, COALESCE(SUM(si.rejected_qty), 0) rej, COUNT(*) cnt')
                ->first();
        }

        if ($row === null || (int) $row->cnt === 0) {
            return null;
        }

        $accepted = (int) $row->acc;
        $rejected = (int) $row->rej;
        $planned = (int) $line->qty_rounded;
        $rate = $planned > 0 ? round(min(100, $accepted / $planned * 100), 2) : null;

        return ['accepted' => $accepted, 'rejected' => $rejected, 'acceptance_rate' => $rate];
    }

    /**
     * @param array{accepted:int,rejected:int,acceptance_rate:float|null}|null $execution
     * @return list<string>
     */
    private function discrepancyCauses(
        float $forecast,
        float $actual,
        AutoSupplyPlanLine $line,
        ?array $execution
    ): array {
        $causes = [];
        if ($actual > 0 && $forecast > $actual * 1.25) {
            $causes[] = 'demand_below_forecast';
        }
        if ($actual > $forecast * 1.25) {
            $causes[] = 'demand_above_forecast';
        }
        if (($execution['rejected'] ?? 0) > 0) {
            $causes[] = 'marketplace_rejected_units';
        }
        if (
            $execution !== null
            && ($execution['accepted'] ?? 0) < (int) $line->qty_rounded
        ) {
            $causes[] = 'supply_underaccepted';
        }
        if ($line->manual_updated_at !== null) {
            $causes[] = 'manual_override';
        }

        return $causes;
    }

    /**
     * Факт наличия товара за горизонт по дневным inventory snapshots.
     * Для кластерной строки Ozon сначала восстанавливает реальные warehouse_id
     * через каноническую карту warehouse → macrolocal cluster.
     *
     * @return array<string, mixed>
     */
    private function inventoryOutcome(
        AutoSupplyPlan $plan,
        AutoSupplyPlanLine $line,
        $start,
        $end,
        int $actualSales,
        int $horizon
    ): array {
        if (! Schema::hasTable('inventory_history')) {
            return ['status' => 'unavailable', 'source' => 'inventory_history'];
        }

        $warehouseIds = [];
        if ($line->warehouse_id && ! str_starts_with((string) $line->warehouse_id, 'cluster:')) {
            $warehouseIds[] = (string) $line->warehouse_id;
        } elseif (
            $plan->marketplace === 'ozon'
            && $line->cluster_id !== null
            && Schema::hasTable('inventory_warehouses')
            && Schema::hasTable('ozon_warehouse_clusters')
        ) {
            $mapping = \App\Models\OzonWarehouseCluster::getAllMapping();
            $warehouseIds = DB::table('inventory_warehouses')
                ->where('integration_id', $plan->integration_id)
                ->where('marketplace', 'ozon')
                ->where('sku', $line->sku)
                ->get(['warehouse_id', 'warehouse_name'])
                ->filter(function ($warehouse) use ($mapping, $line): bool {
                    $name = (string) ($warehouse->warehouse_name ?? '');
                    if ($name === '') {
                        return false;
                    }
                    $normalized = \App\Models\OzonWarehouseCluster::normalizeWarehouseName($name);

                    return (string) ($mapping[$normalized]['cluster_id'] ?? '') === (string) $line->cluster_id;
                })
                ->pluck('warehouse_id')
                ->filter()
                ->map(fn ($id): string => (string) $id)
                ->unique()
                ->values()
                ->all();
        }

        if ($warehouseIds === []) {
            return [
                'status' => 'insufficient_mapping',
                'source' => 'inventory_history',
                'oos_days' => null,
                'excess_cover_days' => null,
            ];
        }

        $query = DB::table('inventory_history')
            ->where('sku', $line->sku)
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()]);
        if (Schema::hasColumn('inventory_history', 'integration_id')) {
            $query->where('integration_id', $plan->integration_id);
        }
        $daily = $query
            ->selectRaw('date, COALESCE(SUM(quantity), 0) as quantity')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        if ($daily->isEmpty()) {
            return [
                'status' => 'insufficient_data',
                'source' => 'inventory_history',
                'warehouse_ids' => $warehouseIds,
                'oos_days' => null,
                'excess_cover_days' => null,
            ];
        }

        $oosDays = $daily->filter(fn ($row): bool => (int) $row->quantity <= 0)->count();
        $endingStock = (int) $daily->last()->quantity;
        $actualDaily = $horizon > 0 ? $actualSales / $horizon : 0.0;
        $endingCover = $actualDaily > 0 ? $endingStock / $actualDaily : null;
        $coverLimit = (float) ($plan->max_cover_days ?: $plan->target_cover_days ?: 0);
        $excessCover = $endingCover !== null && $coverLimit > 0
            ? max(0.0, $endingCover - $coverLimit)
            : null;

        return [
            'status' => 'ok',
            'source' => 'inventory_history',
            'warehouse_ids' => $warehouseIds,
            'days_observed' => $daily->count(),
            'oos_days' => $oosDays,
            'ending_stock' => $endingStock,
            'actual_daily_sales' => round($actualDaily, 4),
            'ending_cover_days' => $endingCover !== null ? round($endingCover, 2) : null,
            'cover_limit_days' => $coverLimit > 0 ? $coverLimit : null,
            'excess_cover_days' => $excessCover !== null ? round($excessCover, 2) : null,
        ];
    }

    /**
     * @param array<string, mixed> $inventoryOutcome
     * @param array{accepted:int,rejected:int,acceptance_rate:float|null}|null $execution
     * @return array<string, mixed>|null
     */
    private function manualOverrideOutcome(
        AutoSupplyPlanLine $line,
        array $inventoryOutcome,
        ?array $execution
    ): ?array {
        if ($line->manual_updated_at === null && ! is_array($line->manual_override_json)) {
            return null;
        }

        $original = (int) ($line->original_qty_rounded ?? $line->qty_rounded);
        $final = (int) $line->qty_rounded;
        $oosDays = $inventoryOutcome['oos_days'] ?? null;
        $excessCover = $inventoryOutcome['excess_cover_days'] ?? null;
        $outcome = match (true) {
            $oosDays === null && $excessCover === null => 'insufficient_data',
            (int) $oosDays > 0 => 'oos_occurred',
            (float) ($excessCover ?? 0) > 0 => 'excess_cover',
            $execution !== null && (float) ($execution['acceptance_rate'] ?? 0) < 90 => 'underaccepted',
            default => 'no_oos_no_excess',
        };

        return [
            'original_qty' => $original,
            'final_qty' => $final,
            'delta_qty' => $final - $original,
            'direction' => $final > $original ? 'increased' : ($final < $original ? 'decreased' : 'unchanged'),
            'outcome' => $outcome,
            'comment' => $line->manual_comment,
        ];
    }
}
