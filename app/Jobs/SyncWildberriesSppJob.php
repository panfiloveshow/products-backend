<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Models\WildberriesSppSyncState;
use App\Services\Wildberries\WildberriesSppSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncWildberriesSppJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 300;

    public int $uniqueFor = 21600;

    public function __construct(public int $integrationId) {}

    public function uniqueId(): string
    {
        return 'wildberries-spp:'.$this->integrationId;
    }

    public function handle(WildberriesSppSyncService $service): void
    {
        $state = WildberriesSppSyncState::query()->firstOrCreate(
            ['integration_id' => $this->integrationId],
            ['status' => 'queued', 'requested_at' => now()],
        );
        $attempt = max(1, $this->attempts());

        $state->update([
            'status' => 'running',
            'attempt' => $attempt,
            'started_at' => now(),
            'finished_at' => null,
            'retry_at' => null,
            'message' => 'Получаем СПП и цены Wildberries',
            'last_error' => null,
        ]);

        $integration = Integration::query()->find($this->integrationId);
        if (! $integration || $integration->marketplace !== 'wildberries') {
            $state->update([
                'status' => 'failed',
                'finished_at' => now(),
                'message' => 'Интеграция Wildberries не найдена',
                'last_error' => 'integration_not_found',
            ]);

            return;
        }

        try {
            $result = $service->sync($integration, $attempt);

            $common = [
                'updated_count' => $result['updated'],
                'total_count' => $result['total'],
                'preserved_count' => $result['preserved'],
                'source' => $result['source'],
                'source_counts' => $result['source_counts'],
                'last_success_at' => $result['updated'] > 0 ? now() : $state->last_success_at,
            ];

            if (! $result['report_available'] && $attempt < $this->tries) {
                $delay = $this->retryDelay($attempt);
                $state->update($common + [
                    'status' => 'retrying',
                    'retry_at' => now()->addSeconds($delay),
                    'message' => "Отчёты WB временно недоступны. Повтор через {$delay} сек.",
                    'last_error' => 'sales_and_orders_unavailable',
                ]);

                $this->release($delay);

                return;
            }

            $complete = $result['report_available'] && $result['preserved'] === 0;
            $status = $complete ? 'completed' : 'partial';
            $message = $complete
                ? 'СПП и цены покупателя обновлены'
                : 'Обновлены доступные значения, остальные сохранены без обнуления';

            $state->update($common + [
                'status' => $status,
                'finished_at' => now(),
                'retry_at' => null,
                'message' => $message,
                'last_error' => $result['report_available'] ? null : 'sales_and_orders_unavailable',
            ]);
        } catch (\Throwable $exception) {
            Log::error('SyncWildberriesSppJob failed', [
                'integration_id' => $this->integrationId,
                'attempt' => $attempt,
                'error' => $exception->getMessage(),
            ]);

            if ($attempt < $this->tries) {
                $delay = $this->retryDelay($attempt);
                $state->update([
                    'status' => 'retrying',
                    'retry_at' => now()->addSeconds($delay),
                    'message' => "Ошибка синхронизации. Повтор через {$delay} сек.",
                    'last_error' => 'sync_failed',
                ]);
                $this->release($delay);

                return;
            }

            throw $exception;
        }
    }

    public function failed(\Throwable $exception): void
    {
        WildberriesSppSyncState::query()
            ->where('integration_id', $this->integrationId)
            ->update([
                'status' => 'failed',
                'finished_at' => now(),
                'retry_at' => null,
                'message' => 'Не удалось обновить СПП после повторных попыток',
                'last_error' => 'sync_failed',
                'updated_at' => now(),
            ]);
    }

    public function retryDelay(int $attempt): int
    {
        // Base WB tokens may limit orders to one request per three hours.
        return [1 => 300, 2 => 900, 3 => 3600, 4 => 10800][$attempt] ?? 10800;
    }
}
