<?php

namespace App\Console\Commands;

use App\Jobs\EvaluatePlanAccuracyJob;
use App\Models\AutoSupplyPlan;
use App\Services\AutoSupplyPlanning\PlanFactReconciler;
use Illuminate\Console\Command;

/**
 * Ставит в очередь оценку точности готовых планов, у которых прошёл горизонт (этап 2).
 * Запускается по расписанию (routes/console.php) под флагом PLAN_ACCURACY_SCHEDULE.
 *
 * Примеры:
 *   php artisan plan:evaluate-accuracy                  # все созревшие неоценённые планы
 *   php artisan plan:evaluate-accuracy --plan=<uuid>    # переоценить конкретный план
 *   php artisan plan:evaluate-accuracy --integration=76 # только одна интеграция
 */
class EvaluatePlanAccuracyCommand extends Command
{
    protected $signature = 'plan:evaluate-accuracy
        {--plan= : UUID конкретного плана (переоценить, игнорируя фильтр)}
        {--integration= : ID интеграции}';

    protected $description = 'Оценить точность готовых планов через горизонт N дней (план-факт)';

    /** Прединдексный фильтр возраста — джоба всё равно проверит точную зрелость по horizon. */
    private const PREFILTER_MIN_AGE_DAYS = 7;
    private const BATCH_LIMIT = 500;

    public function handle(PlanFactReconciler $reconciler): int
    {
        $query = AutoSupplyPlan::query()->where('status', AutoSupplyPlan::STATUS_READY);

        if ($planId = $this->option('plan')) {
            $query->whereKey($planId);
        } else {
            $query->whereNull('accuracy_json')
                ->where('created_at', '<=', now()->subDays(self::PREFILTER_MIN_AGE_DAYS));
        }

        if ($integrationId = $this->option('integration')) {
            $query->where('integration_id', (int) $integrationId);
        }

        $plans = $query->limit(self::BATCH_LIMIT)->get();

        if ($plans->isEmpty()) {
            $this->info('Нет планов для оценки.');

            return self::SUCCESS;
        }

        $dispatched = 0;
        $premature = 0;

        foreach ($plans as $plan) {
            $horizon = $reconciler->horizonDays($plan);
            if ($plan->created_at === null || $plan->created_at->copy()->addDays($horizon)->isFuture()) {
                $premature++;
                continue;
            }

            EvaluatePlanAccuracyJob::dispatch($plan->id);
            $dispatched++;
        }

        $this->info("Поставлено в очередь: {$dispatched}. Ещё не созрели: {$premature}.");

        return self::SUCCESS;
    }
}
