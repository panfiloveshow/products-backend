<?php

namespace App\Jobs;

use App\Models\AutoSupplyPlan;
use App\Models\PlanLineEvaluation;
use App\Services\AutoSupplyPlanning\PlanFactReconciler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Оценка точности плана через горизонт N дней (этап 2, план-факт).
 * Для каждой строки считает прогноз vs факт (PlanFactReconciler), пишет
 * PlanLineEvaluation и агрегат в plan.accuracy_json. Идемпотентна.
 */
class EvaluatePlanAccuracyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;

    public function __construct(private string $planId) {}

    public function handle(PlanFactReconciler $reconciler): void
    {
        $plan = AutoSupplyPlan::query()->with('lines')->find($this->planId);

        if ($plan === null || $plan->status !== AutoSupplyPlan::STATUS_READY) {
            return;
        }

        // Зрелость: оцениваем только когда горизонт реально прошёл.
        $horizon = $reconciler->horizonDays($plan);
        if ($plan->created_at === null || $plan->created_at->copy()->addDays($horizon)->isFuture()) {
            Log::info('EvaluatePlanAccuracyJob: план ещё не созрел, пропуск', [
                'plan_id' => $plan->id,
                'horizon_days' => $horizon,
            ]);

            return;
        }

        $lines = $plan->lines;
        if ($lines->isEmpty()) {
            return;
        }

        $evaluations = [];
        foreach ($lines as $line) {
            $attrs = $reconciler->evaluateLine($line, $plan);
            PlanLineEvaluation::updateOrCreate(
                ['auto_supply_plan_line_id' => $line->id],
                $attrs
            );
            $evaluations[] = $attrs;
        }

        $updates = ['accuracy_json' => $reconciler->aggregate($evaluations)];
        $hasSupplies = $plan->supplies()->exists();
        $allSuppliesTerminal = $hasSupplies && ! $plan->supplies()
            ->whereNotIn('status', ['accepted_partial', 'accepted_full', 'closed', 'cancelled'])
            ->exists();
        if ($allSuppliesTerminal) {
            $updates['business_status'] = AutoSupplyPlan::BUSINESS_STATUS_RECONCILED;
        }
        $plan->forceFill($updates)->save();

        Log::info('EvaluatePlanAccuracyJob: план оценён', [
            'plan_id' => $plan->id,
            'lines' => count($evaluations),
            'mape' => $plan->accuracy_json['mape'] ?? null,
            'wape' => $plan->accuracy_json['wape'] ?? null,
        ]);
    }
}
