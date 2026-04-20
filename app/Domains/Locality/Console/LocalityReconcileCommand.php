<?php

namespace App\Domains\Locality\Console;

use App\Domains\Locality\Reconciliation\FinanceReconciler;
use App\Models\Integration;
use Carbon\Carbon;
use Illuminate\Console\Command;

class LocalityReconcileCommand extends Command
{
    protected $signature = 'locality:reconcile
                            {--integration= : Integration ID}
                            {--from= : Дата начала (Y-m-d)}
                            {--to= : Дата конца (Y-m-d), по умолчанию сегодня}';

    protected $description = 'Сверить ozon_order_unit_economics с ozon_finance_transactions';

    public function handle(FinanceReconciler $reconciler): int
    {
        $integrationId = $this->option('integration');
        $fromOpt = $this->option('from');
        $toOpt = $this->option('to');

        $to = $toOpt !== null ? Carbon::parse($toOpt)->endOfDay() : now()->endOfDay();
        $from = $fromOpt !== null ? Carbon::parse($fromOpt)->startOfDay() : $to->copy()->subDays(28)->startOfDay();

        $query = Integration::query()->where('is_active', true)->where('marketplace', 'ozon');
        if ($integrationId !== null) {
            $query->where('id', (int) $integrationId);
        }

        foreach ($query->get() as $integration) {
            $this->info("Reconcile integration_id={$integration->id}, {$from->toDateString()} → {$to->toDateString()}");
            $log = $reconciler->run($integration, $from, $to);
            $this->line(sprintf(
                '  verdict=%s base_diff=%.2f%% markup_diff=%.2f%%',
                $log->verdict,
                (float) ($log->base_logistics_diff_percent ?? 0),
                (float) ($log->markup_diff_percent ?? 0)
            ));
        }

        return self::SUCCESS;
    }
}
