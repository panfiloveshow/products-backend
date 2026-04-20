<?php

namespace App\Domains\Locality\Jobs;

use App\Domains\Locality\Reconciliation\FinanceReconciler;
use App\Models\Integration;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReconcileFinanceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 1800;

    public function __construct(
        private readonly ?int $integrationId = null,
        private readonly int $days = 28,
    ) {
    }

    public function handle(FinanceReconciler $reconciler): void
    {
        $to = now();
        $from = $to->copy()->subDays(max(1, $this->days));

        $query = Integration::query()->where('is_active', true)->where('marketplace', 'ozon');
        if ($this->integrationId !== null) {
            $query->where('id', $this->integrationId);
        }

        foreach ($query->get() as $integration) {
            try {
                $log = $reconciler->run($integration, $from, $to);
                Log::channel('locality')->info('ReconcileFinanceJob result', [
                    'integration_id' => $integration->id,
                    'verdict' => $log->verdict,
                    'base_diff_percent' => $log->base_logistics_diff_percent,
                    'markup_diff_percent' => $log->markup_diff_percent,
                ]);
            } catch (\Throwable $e) {
                Log::channel('locality')->error('ReconcileFinanceJob failed', [
                    'integration_id' => $integration->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
