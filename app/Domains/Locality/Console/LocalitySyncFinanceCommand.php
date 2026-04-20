<?php

namespace App\Domains\Locality\Console;

use App\Domains\Locality\Ingestion\FinanceTransactionSyncer;
use App\Models\Integration;
use Carbon\Carbon;
use Illuminate\Console\Command;

class LocalitySyncFinanceCommand extends Command
{
    protected $signature = 'locality:sync-finance
                            {--integration= : Integration ID}
                            {--from= : Дата начала (Y-m-d)}
                            {--to= : Дата конца (Y-m-d), по умолчанию сегодня}';

    protected $description = 'Выкачать /v3/finance/transaction/list в ozon_finance_transactions';

    public function handle(FinanceTransactionSyncer $syncer): int
    {
        $integrationId = $this->option('integration');
        $fromOpt = $this->option('from');
        $toOpt = $this->option('to');

        $to = $toOpt !== null ? Carbon::parse($toOpt)->endOfDay() : now();
        $from = $fromOpt !== null ? Carbon::parse($fromOpt)->startOfDay() : $to->copy()->subDays(28);

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
            $this->info(sprintf(
                'Sync finance for integration_id=%d, %s → %s',
                $integration->id,
                $from->toDateString(),
                $to->toDateString()
            ));
            $result = $syncer->syncForIntegration($integration, $from, $to);
            $this->line(sprintf(
                '  inserted=%d updated=%d skipped=%d total=%d',
                $result->inserted,
                $result->updated,
                $result->skipped,
                $result->total()
            ));
        }

        return self::SUCCESS;
    }
}
