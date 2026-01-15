<?php

namespace App\Jobs;

use App\Domains\Supplies\Services\SupplyRecommendationService;
use App\Models\Integration;
use App\Models\SupplyRecommendation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job для автоматической генерации рекомендаций по поставкам
 * 
 * Запускается по расписанию (ежедневно) или вручную.
 * Анализирует остатки и продажи, создаёт рекомендации для товаров
 * с низким запасом.
 */
class GenerateSupplyRecommendationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;

    public function __construct(
        private ?int $integrationId = null,
        private ?string $marketplace = null
    ) {}

    public function handle(SupplyRecommendationService $recommendationService): void
    {
        Log::info('Starting supply recommendations generation', [
            'integration_id' => $this->integrationId,
            'marketplace' => $this->marketplace,
        ]);

        $query = Integration::query()
            ->whereIn('marketplace', ['wildberries', 'ozon'])
            ->where('is_active', true);

        if ($this->integrationId) {
            $query->where('id', $this->integrationId);
        }

        if ($this->marketplace) {
            $query->where('marketplace', $this->marketplace);
        }

        $integrations = $query->get();

        $totalRecommendations = 0;
        $errors = [];

        foreach ($integrations as $integration) {
            try {
                Log::info('Generating recommendations for integration', [
                    'integration_id' => $integration->id,
                    'marketplace' => $integration->marketplace,
                    'name' => $integration->name,
                ]);

                // Удаляем старые неиспользованные рекомендации для этой интеграции
                $this->cleanupOldRecommendations($integration->id);

                // Генерируем новые рекомендации
                $recommendations = $recommendationService->generateForIntegration($integration);

                $count = $recommendations->count();
                $totalRecommendations += $count;

                Log::info('Recommendations generated', [
                    'integration_id' => $integration->id,
                    'count' => $count,
                    'urgent' => $recommendations->where('priority', 'urgent')->count(),
                    'high' => $recommendations->where('priority', 'high')->count(),
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to generate recommendations', [
                    'integration_id' => $integration->id,
                    'error' => $e->getMessage(),
                ]);

                $errors[] = [
                    'integration_id' => $integration->id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        Log::info('Supply recommendations generation completed', [
            'integrations_processed' => $integrations->count(),
            'total_recommendations' => $totalRecommendations,
            'errors_count' => count($errors),
        ]);
    }

    /**
     * Удалить старые неиспользованные рекомендации
     */
    private function cleanupOldRecommendations(int $integrationId): void
    {
        // Удаляем рекомендации старше 7 дней, которые не были использованы
        $deleted = SupplyRecommendation::where('integration_id', $integrationId)
            ->where('is_used', false)
            ->where('is_dismissed', false)
            ->where('created_at', '<', now()->subDays(7))
            ->delete();

        if ($deleted > 0) {
            Log::info('Cleaned up old recommendations', [
                'integration_id' => $integrationId,
                'deleted' => $deleted,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateSupplyRecommendationsJob failed', [
            'integration_id' => $this->integrationId,
            'marketplace' => $this->marketplace,
            'error' => $exception->getMessage(),
        ]);
    }
}
