<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Services\AutoSupplyPlanning\MarketplaceConstraintSyncService;
use App\Services\Ozon\OzonCredentialHealthService;
use App\Services\Ozon\OzonSupplySyncService;
use App\Services\PostingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Обновляет оперативные факты, которые не входят в обычный product/inventory sync:
 * postings FBO/FBS, реальные заявки поставок, ограничения направлений и здоровье credentials.
 */
class SyncOzonPlanningOperationalDataJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 1800;

    public int $uniqueFor = 3600;

    public function __construct(public int $integrationId)
    {
        $this->onQueue((string) config('autoplanning.facts.queue', 'ozon-fact-refresh'));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 180, 600, 1200];
    }

    public function uniqueId(): string
    {
        return 'ozon-planning-operational:'.$this->integrationId;
    }

    public function handle(
        OzonSupplySyncService $supplySync,
        PostingService $postings,
        MarketplaceConstraintSyncService $constraints,
        OzonCredentialHealthService $credentials
    ): void {
        $integration = Integration::withoutGlobalScopes()->find($this->integrationId);
        if (! $integration || ! $integration->is_active || $integration->marketplace !== 'ozon') {
            Log::notice('Ozon planning operational sync skipped', [
                'integration_id' => $this->integrationId,
                'reason' => 'integration_missing_inactive_or_not_ozon',
            ]);

            return;
        }

        $this->updateProgress($integration, 'running');

        $credentialHealth = $credentials->check($integration, probe: true);
        if (! ($credentialHealth['usable'] ?? false)) {
            throw new \RuntimeException(
                'Credentials Ozon не готовы: '.($credentialHealth['message'] ?? 'неизвестная ошибка')
            );
        }

        $supplyResult = $supplySync->syncForIntegration($this->integrationId);
        $postingResult = $postings->sync((string) $this->integrationId);
        $constraintSnapshot = $constraints->syncIntegration($integration);

        if ($constraintSnapshot->sync_status === 'error') {
            Log::warning('Ozon planning constraints refresh failed without invalidating other facts', [
                'integration_id' => $this->integrationId,
                'error' => $constraintSnapshot->sync_error,
            ]);
        }

        Log::info('Ozon planning operational facts refreshed', [
            'integration_id' => $this->integrationId,
            'supplies' => $supplyResult,
            'postings' => $postingResult,
            'constraints_status' => $constraintSnapshot->sync_status,
        ]);
        $this->updateProgress($integration->fresh(), 'completed');
    }

    public function failed(\Throwable $exception): void
    {
        $integration = Integration::withoutGlobalScopes()->find($this->integrationId);
        if ($integration) {
            $this->updateProgress($integration, 'failed', $exception->getMessage());
            $integration->updateSyncStatus('failed', substr($exception->getMessage(), 0, 500));
        }
    }

    private function updateProgress(Integration $integration, string $status, ?string $error = null): void
    {
        $settings = is_array($integration->settings) ? $integration->settings : [];
        $progress = is_array($settings['autoplanning_fact_refresh'] ?? null)
            ? $settings['autoplanning_fact_refresh']
            : [];
        $progress['operational_status'] = $status;
        $progress['operational_error'] = $error;
        $progress['operational_updated_at'] = now()->toIso8601String();
        $settings['autoplanning_fact_refresh'] = $progress;
        $integration->settings = $settings;
        $integration->save();
    }
}
