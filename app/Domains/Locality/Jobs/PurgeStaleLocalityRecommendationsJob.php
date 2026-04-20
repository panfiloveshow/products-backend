<?php

namespace App\Domains\Locality\Jobs;

use App\Models\LocalityRecommendation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PurgeStaleLocalityRecommendationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;
    public int $timeout = 600;

    public function handle(): void
    {
        $staleDays = (int) config('locality.recommendation.stale_after_days', 14);
        $purgeDays = (int) config('locality.recommendation.purge_after_days', 30);

        $staleCutoff = now()->subDays($staleDays)->toDateString();
        $purgeCutoff = now()->subDays($purgeDays);

        $markedStale = LocalityRecommendation::query()
            ->where('state', LocalityRecommendation::STATE_NEW)
            ->where('basis_snapshot_date', '<', $staleCutoff)
            ->update(['state' => LocalityRecommendation::STATE_EXPIRED]);

        $softDeleted = LocalityRecommendation::query()
            ->whereIn('state', [LocalityRecommendation::STATE_STALE, LocalityRecommendation::STATE_EXPIRED])
            ->where('updated_at', '<', $purgeCutoff)
            ->delete();

        Log::channel('locality')->info('PurgeStaleLocalityRecommendationsJob done', [
            'marked_stale' => $markedStale,
            'soft_deleted' => $softDeleted,
        ]);
    }
}
