<?php

namespace App\Domains\Locality\Jobs;

use App\Domains\Locality\Ingestion\FinanceTransactionSyncer;
use App\Models\Integration;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncFinanceTransactionsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 1800;

    public function __construct(
        private readonly ?int $integrationId = null,
        private readonly int $days = 2,
    ) {
    }

    public function handle(FinanceTransactionSyncer $syncer): void
    {
        $to = now();
        $from = $to->copy()->subDays(max(1, $this->days));

        $query = Integration::query()->where('is_active', true)->where('marketplace', 'ozon');
        if ($this->integrationId !== null) {
            $query->where('id', $this->integrationId);
        }

        foreach ($query->get() as $integration) {
            try {
                $result = $syncer->syncForIntegration($integration, $from, $to);
                Log::channel('locality')->info('SyncFinanceTransactionsJob result', [
                    'integration_id' => $integration->id,
                    'from' => $from->toDateTimeString(),
                    'to' => $to->toDateTimeString(),
                    'result' => $result->toArray(),
                ]);
            } catch (\Throwable $e) {
                Log::channel('locality')->error('SyncFinanceTransactionsJob failed', [
                    'integration_id' => $integration->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public static function backfill(int $integrationId, Carbon $from, Carbon $to): self
    {
        $job = new self($integrationId, (int) $from->diffInDays($to) + 1);
        return $job;
    }
}
