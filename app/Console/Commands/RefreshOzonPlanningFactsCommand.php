<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Services\AutoSupplyPlanning\OzonPlanningFactRefreshService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshOzonPlanningFactsCommand extends Command
{
    protected $signature = 'autoplanning:refresh-ozon-facts
        {--integration= : ID одной интеграции Ozon}
        {--delay=90 : Задержка operational sync после постановки каталога в очередь, секунд}';

    protected $description = 'Поставить в очередь полное обновление фактов Ozon для автопланирования';

    public function handle(OzonPlanningFactRefreshService $refresh): int
    {
        $query = Integration::withoutGlobalScopes()
            ->where('marketplace', 'ozon')
            ->syncable();

        if ($this->option('integration')) {
            $query->whereKey((int) $this->option('integration'));
        }

        $integrations = $query->orderBy('id')->get();
        if ($integrations->isEmpty()) {
            $this->info('Активные интеграции Ozon не найдены.');

            return self::SUCCESS;
        }

        $queued = 0;
        $failed = 0;
        $delay = max(0, (int) $this->option('delay'));

        foreach ($integrations as $index => $integration) {
            try {
                $result = $refresh->queue($integration, $delay + ($index * 20));
                $this->line(sprintf(
                    'integration=%d product_sync=%s status=%s operational=queued',
                    $integration->id,
                    $result['product_sync_id'],
                    $result['product_sync_status']
                ));
                $queued++;
            } catch (\Throwable $e) {
                $failed++;
                $this->error("integration={$integration->id}: {$e->getMessage()}");
                Log::error('autoplanning:refresh-ozon-facts failed to queue integration', [
                    'integration_id' => $integration->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Поставлено в очередь: {$queued}; ошибок: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
