<?php

namespace App\Services\AutoSupplyPlanning;

use App\Jobs\SyncOzonPlanningOperationalDataJob;
use App\Models\Integration;
use App\Services\ProductService;
use Illuminate\Support\Str;

/**
 * Единая точка запуска полного обновления фактов Ozon для автопланирования.
 *
 * Product sync уже канонически запускает inventory sync, а тот — unit economics.
 * Здесь дополнительно ставится независимый operational sync. Оба контура
 * идемпотентны и защищены unique/overlap locks.
 */
class OzonPlanningFactRefreshService
{
    public function __construct(private readonly ProductService $products)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function queue(Integration $integration, int $operationalDelaySeconds = 90): array
    {
        if ($integration->marketplace !== 'ozon' || ! $integration->is_active) {
            throw new \InvalidArgumentException('Нужна активная интеграция Ozon.');
        }

        $credentials = $integration->getDecryptedCredentials();
        $hasOAuth = ! empty($credentials['oauth_access_token'] ?? $credentials['access_token'] ?? null);
        $hasApiKey = ! empty($credentials['client_id'] ?? null)
            && ! empty($credentials['api_key'] ?? null);

        if (! $hasOAuth && ! $hasApiKey) {
            throw new \RuntimeException('В интеграции отсутствуют рабочие credentials Ozon.');
        }

        $productSync = $this->products->startSync(
            'ozon',
            $credentials,
            (int) $integration->id,
            'products'
        );

        $operational = SyncOzonPlanningOperationalDataJob::dispatch((int) $integration->id);
        if ($operationalDelaySeconds > 0) {
            $operational->delay(now()->addSeconds($operationalDelaySeconds));
        }

        $refreshId = (string) Str::uuid();
        $settings = is_array($integration->settings) ? $integration->settings : [];
        $settings['autoplanning_fact_refresh'] = [
            'id' => $refreshId,
            'status' => 'running',
            'requested_at' => now()->toIso8601String(),
            'product_sync_id' => (string) $productSync->id,
            'operational_status' => 'queued',
            'operational_error' => null,
        ];
        $integration->settings = $settings;
        $integration->save();
        $integration->updateSyncStatus('running');

        return [
            'integration_id' => (int) $integration->id,
            'refresh_id' => $refreshId,
            'product_sync_id' => $productSync->id,
            'product_sync_status' => $productSync->status,
            'operational_sync_queued' => true,
            'operational_delay_seconds' => max(0, $operationalDelaySeconds),
            'pipeline' => [
                'products',
                'inventory_and_sales',
                'unit_economics',
                'postings',
                'supplies',
                'constraints',
                'credential_health',
            ],
            'progress_url' => '/api/auto-supply-plans/data-health?integration_id=' . $integration->id,
        ];
    }
}
