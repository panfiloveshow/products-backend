<?php

namespace App\Domains\Locality\Console;

use App\Domains\Locality\Calculation\LocalityAggregator;
use App\Domains\Locality\Reconciliation\FinanceReconciler;
use App\Domains\Locality\Recommendation\RecommendationRanker;
use App\Models\Integration;
use Carbon\Carbon;
use Illuminate\Console\Command;

class LocalityRecomputeCommand extends Command
{
    protected $signature = 'locality:recompute
                            {--integration= : Integration ID}
                            {--period=28 : Period days}
                            {--scope=aggregation : aggregation|recommendations|reconciliation|all}';

    protected $description = 'Запустить пересчёт Locality Engine (агрегация/рекомендации/reconciliation)';

    public function handle(
        LocalityAggregator $aggregator,
        RecommendationRanker $ranker,
        FinanceReconciler $reconciler,
    ): int {
        $integrationId = $this->option('integration');
        $period = (int) $this->option('period');
        $scope = (string) $this->option('scope');

        $query = Integration::query()->where('is_active', true)->where('marketplace', 'ozon');
        if ($integrationId !== null) {
            $query->where('id', (int) $integrationId);
        }

        $integrations = $query->get();
        if ($integrations->isEmpty()) {
            $this->warn('Нет активных Ozon-интеграций.');
            return self::SUCCESS;
        }

        foreach ($integrations as $integration) {
            if (in_array($scope, ['aggregation', 'all'], true)) {
                $this->info("Aggregation: integration_id={$integration->id}, period={$period}d");
                $result = $aggregator->runDaily((int) $integration->id, now(), $period);
                $this->line("  skus={$result['skus_processed']} clusters={$result['clusters_processed']}");
            }

            if (in_array($scope, ['recommendations', 'all'], true)) {
                $this->info("Recommendations: integration_id={$integration->id}");
                $res = $ranker->generate((int) $integration->id);
                $this->line("  generated={$res['generated']} skipped={$res['skipped']} stale_marked={$res['stale_marked']}");
            }

            if (in_array($scope, ['reconciliation', 'all'], true)) {
                $this->info("Reconciliation: integration_id={$integration->id} (last 28d)");
                $to = now();
                $from = $to->copy()->subDays(28);
                $log = $reconciler->run($integration, $from, $to);
                $this->line(sprintf(
                    '  verdict=%s base_diff=%.2f%% markup_diff=%.2f%%',
                    $log->verdict,
                    (float) ($log->base_logistics_diff_percent ?? 0),
                    (float) ($log->markup_diff_percent ?? 0)
                ));
            }
        }

        return self::SUCCESS;
    }
}
