<?php

namespace App\Services\AutoSupplyPlanning;

use App\Models\AutoSupplyPlan;
use App\Models\AutoSupplyPlanLine;
use App\Models\PlanLineEvaluation;
use Illuminate\Support\Facades\DB;

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
                'details_json' => ['reason' => 'Нет фактических продаж за горизонт — точность не определена'],
            ];
        }

        $ape = round(abs($forecast - $actual) / $actual * 100, 2);
        $bias = round(($forecast - $actual) / $actual * 100, 2);

        return $base + [
            'actual_sales_qty' => (float) $actual,
            'abs_pct_error' => $ape,
            'bias_pct' => $bias,
            'status' => PlanLineEvaluation::STATUS_OK,
            'details_json' => null,
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
            return [
                'mape' => null,
                'bias' => null,
                'lines_evaluated' => 0,
                'lines_insufficient_data' => $insufficient,
                'evaluated_at' => now()->toIso8601String(),
            ];
        }

        $mape = array_sum(array_column($scored, 'abs_pct_error')) / $linesEvaluated;
        $bias = array_sum(array_column($scored, 'bias_pct')) / $linesEvaluated;

        $withSupply = array_values(array_filter(
            $evaluations,
            static fn ($e) => ($e['acceptance_rate'] ?? null) !== null
        ));
        $acceptance = count($withSupply) > 0
            ? round(array_sum(array_column($withSupply, 'acceptance_rate')) / count($withSupply), 2)
            : null;

        return [
            'mape' => round($mape, 2),
            'bias' => round($bias, 2),
            'accuracy' => round(max(0, 100 - $mape), 2),
            'lines_evaluated' => $linesEvaluated,
            'lines_insufficient_data' => $insufficient,
            'acceptance_rate' => $acceptance,
            'lines_with_supply' => count($withSupply),
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
        $daily = $line->demand_daily;
        if (is_numeric($daily) && (float) $daily > 0) {
            return round((float) $daily * $horizon, 2);
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

        // Ozon: сузить до кластера строки (портируемый JSON-оператор Laravel `->`).
        if ($plan->marketplace === 'ozon' && ! empty($line->cluster_name)) {
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

        $row = DB::table('supply_items as si')
            ->join('supplies as s', 'si.supply_id', '=', 's.id')
            ->where('s.integration_id', $plan->integration_id)
            ->whereIn('s.status', ['accepted_partial', 'accepted_full', 'closed'])
            ->whereBetween('s.created_at', [$start, $end])
            ->where(function ($w) use ($offer, $line) {
                $w->where('si.sku', $line->sku);
                if ($offer !== $line->sku) {
                    $w->orWhere('si.sku', $offer);
                }
            })
            ->selectRaw('COALESCE(SUM(si.accepted_qty), 0) acc, COALESCE(SUM(si.rejected_qty), 0) rej, COUNT(*) cnt')
            ->first();

        if ($row === null || (int) $row->cnt === 0) {
            return null;
        }

        $accepted = (int) $row->acc;
        $rejected = (int) $row->rej;
        $planned = (int) $line->qty_rounded;
        $rate = $planned > 0 ? round(min(100, $accepted / $planned * 100), 2) : null;

        return ['accepted' => $accepted, 'rejected' => $rejected, 'acceptance_rate' => $rate];
    }
}
