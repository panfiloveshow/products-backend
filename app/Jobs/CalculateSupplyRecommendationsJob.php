<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Services\Supply\LegacySupplyRecommendationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job для автоматического расчёта рекомендаций на поставку
 * 
 * Запускается по расписанию (ежедневно) или вручную
 */
class CalculateSupplyRecommendationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600; // 10 минут

    public function __construct(
        public int $integrationId,
        public ?string $clusterId = null
    ) {}

    public function handle(LegacySupplyRecommendationService $service): void
    {
        $startTime = microtime(true);

        try {
            $integration = Integration::find($this->integrationId);

            if (!$integration) {
                Log::warning('Integration not found for supply recommendations', [
                    'integration_id' => $this->integrationId,
                ]);
                return;
            }

            if ($integration->marketplace !== 'ozon') {
                Log::info('Skipping non-Ozon integration for supply recommendations', [
                    'integration_id' => $this->integrationId,
                    'marketplace' => $integration->marketplace,
                ]);
                return;
            }

            Log::info('Starting supply recommendations calculation', [
                'integration_id' => $this->integrationId,
                'cluster_id' => $this->clusterId,
            ]);

            // Помечаем устаревшие рекомендации
            $expired = $service->expireOldRecommendations($this->integrationId);

            // Рассчитываем новые рекомендации
            $recommendations = $service->calculateRecommendations($integration, $this->clusterId);

            // Сохраняем
            $saved = $service->saveRecommendations($recommendations);

            $duration = round(microtime(true) - $startTime, 2);

            Log::info('Supply recommendations calculation completed', [
                'integration_id' => $this->integrationId,
                'calculated' => $recommendations->count(),
                'saved' => $saved,
                'expired' => $expired,
                'duration_sec' => $duration,
                'oos_risk_count' => $recommendations->where('oos_risk', true)->count(),
                'priority_a_count' => $recommendations->where('priority', 'A')->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to calculate supply recommendations', [
                'integration_id' => $this->integrationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('CalculateSupplyRecommendationsJob failed permanently', [
            'integration_id' => $this->integrationId,
            'error' => $exception->getMessage(),
        ]);
    }
}
