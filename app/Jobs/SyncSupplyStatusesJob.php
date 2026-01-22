<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Models\Supply;
use App\Services\Supply\SupplyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job для синхронизации статусов поставок из Ozon
 * 
 * Запускается по расписанию (каждые 15-30 минут)
 */
class SyncSupplyStatusesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(
        public ?int $integrationId = null
    ) {}

    public function handle(SupplyService $service): void
    {
        $startTime = microtime(true);

        try {
            // Получаем активные поставки
            $query = Supply::active()
                ->whereNotNull('ozon_draft_id');

            if ($this->integrationId) {
                $query->where('integration_id', $this->integrationId);
            }

            $supplies = $query->get();

            Log::info('Starting supply status sync', [
                'integration_id' => $this->integrationId,
                'supplies_count' => $supplies->count(),
            ]);

            $synced = 0;
            $errors = 0;

            foreach ($supplies as $supply) {
                try {
                    $service->syncStatus($supply);
                    $synced++;
                } catch (\Exception $e) {
                    $errors++;
                    Log::warning('Failed to sync supply status', [
                        'supply_id' => $supply->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                // Небольшая задержка между запросами
                usleep(100000); // 100ms
            }

            $duration = round(microtime(true) - $startTime, 2);

            Log::info('Supply status sync completed', [
                'integration_id' => $this->integrationId,
                'synced' => $synced,
                'errors' => $errors,
                'duration_sec' => $duration,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to sync supply statuses', [
                'integration_id' => $this->integrationId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
