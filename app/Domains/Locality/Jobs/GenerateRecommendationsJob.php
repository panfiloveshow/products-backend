<?php

namespace App\Domains\Locality\Jobs;

use App\Domains\Locality\Recommendation\RecommendationRanker;
use App\Models\Integration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateRecommendationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 1800;

    public function __construct(private readonly ?int $integrationId = null)
    {
    }

    public function handle(RecommendationRanker $ranker): void
    {
        $query = Integration::query()->where('is_active', true)->where('marketplace', 'ozon');
        if ($this->integrationId !== null) {
            $query->where('id', $this->integrationId);
        }

        foreach ($query->get() as $integration) {
            try {
                $result = $ranker->generate((int) $integration->id);
                Log::channel('locality')->info('GenerateRecommendationsJob result', [
                    'integration_id' => $integration->id,
                    'result' => $result,
                ]);
            } catch (\Throwable $e) {
                Log::channel('locality')->error('GenerateRecommendationsJob failed', [
                    'integration_id' => $integration->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
