<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Models\WildberriesTariffSnapshot;
use App\Services\UnitEconomicsService;
use App\Domains\Marketplace\MarketplaceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncUnitEconomicsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    // Крупные магазины (сотни товаров) не укладывались в 600с → таймаут до диспатча кэша,
    // из-за чего товары не появлялись в юнит-экономике. Поднимаем лимит.
    public int $timeout = 1800;

    public function __construct(
        public int $integrationId,
        public ?string $periodStart = null,
        public ?string $periodEnd = null
    ) {}

    public function handle(UnitEconomicsService $unitEconomicsService): void
    {
        $integration = Integration::find($this->integrationId);
        
        if (!$integration) {
            Log::error('SyncUnitEconomicsJob: Integration not found', [
                'integration_id' => $this->integrationId
            ]);
            return;
        }
        
        Log::info('SyncUnitEconomicsJob started', [
            'integration_id' => $this->integrationId,
            'marketplace' => $integration->marketplace,
        ]);
        
        try {
            // Для Ozon получаем индекс локализации (среднее время доставки)
            $localizationIndex = null;
            if ($integration->marketplace === 'ozon') {
                $credentials = $integration->getDecryptedCredentials();
                if (!empty($credentials)) {
                    try {
                        $ozonService = MarketplaceFactory::create('ozon', $credentials);
                        
                        // Проверяем нужно ли обновить данные локализации (TTL 24ч)
                        if ($integration->needsLocalizationCheck()) {
                            $localizationIndex = $ozonService->getLocalizationIndex();
                            
                            Log::info('SyncUnitEconomicsJob: Localization index fetched from API', [
                                'integration_id' => $this->integrationId,
                                'average_delivery_time' => $localizationIndex['average_delivery_time'] ?? 29,
                                'tariff_coefficient' => $localizationIndex['tariff_coefficient'] ?? 1.0,
                            ]);
                            
                            // Сохраняем в settings интеграции
                            $settings = $integration->settings ?? [];
                            $settings['avg_delivery_time_hours'] = $localizationIndex['average_delivery_time'];
                            $settings['localization_coefficient'] = $localizationIndex['tariff_coefficient'];
                            $settings['localization_additional_percent'] = $localizationIndex['additional_fee_percent'];
                            $settings['localization_tariff_status'] = $localizationIndex['tariff_status'] ?? 'UNKNOWN';
                            
                            $integration->update([
                                'settings' => $settings,
                                'localization_checked_at' => now(),
                            ]);
                        } else {
                            // Используем кэшированные данные
                            $settings = $integration->settings ?? [];
                            $localizationIndex = [
                                'average_delivery_time' => $settings['avg_delivery_time_hours'] ?? 29,
                                'tariff_coefficient' => $settings['localization_coefficient'] ?? 1.0,
                                'additional_fee_percent' => $settings['localization_additional_percent'] ?? 0,
                                'tariff_status' => $settings['localization_tariff_status'] ?? 'UNKNOWN',
                            ];
                            
                            Log::info('SyncUnitEconomicsJob: Using cached localization index', [
                                'integration_id' => $this->integrationId,
                                'average_delivery_time' => $localizationIndex['average_delivery_time'],
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::warning('SyncUnitEconomicsJob: Failed to get localization index', [
                            'integration_id' => $this->integrationId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            if ($integration->marketplace === 'wildberries') {
                $this->syncWildberriesTariffSnapshots($integration);
            }
            
            $result = $unitEconomicsService->syncFromRealData(
                $integration,
                $this->periodStart,
                $this->periodEnd,
                $localizationIndex
            );
            
            Log::info('SyncUnitEconomicsJob completed', [
                'integration_id' => $this->integrationId,
                'synced' => $result['synced'],
                'errors' => $result['errors'],
            ]);
            
            // Запускаем пересчёт кэша ПОСЛЕ завершения синхронизации UnitEconomics
            RecalculateUnitEconomicsCacheJob::dispatch($this->integrationId)
                ->onQueue('unit-economics');
            
        } catch (\Exception $e) {
            Log::error('SyncUnitEconomicsJob failed', [
                'integration_id' => $this->integrationId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Вызывается очередью при окончательном падении джоба (таймаут / превышение попыток).
     * Тяжёлый syncFromRealData мог не доехать до диспатча кэша (строка выше), из-за чего
     * unit_economics_cache не строился и товары пропадали со страницы юнитки. Поэтому здесь
     * гарантированно ставим пересчёт кэша из уже синхронизированных товаров.
     */
    public function failed(\Throwable $exception): void
    {
        Log::warning('SyncUnitEconomicsJob failed; dispatching cache rebuild anyway', [
            'integration_id' => $this->integrationId,
            'error' => $exception->getMessage(),
        ]);

        try {
            RecalculateUnitEconomicsCacheJob::dispatch($this->integrationId)
                ->onQueue('unit-economics');
        } catch (\Throwable $e) {
            Log::error('SyncUnitEconomicsJob::failed could not dispatch cache rebuild', [
                'integration_id' => $this->integrationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncWildberriesTariffSnapshots(Integration $integration): void
    {
        $credentials = $integration->getDecryptedCredentials();
        if (empty($credentials)) {
            return;
        }

        try {
            $marketplace = MarketplaceFactory::create('wildberries', $credentials, $integration);
            if (! method_exists($marketplace, 'getTariffSnapshots')) {
                return;
            }

            $snapshots = $marketplace->getTariffSnapshots(now()->format('Y-m-d'));
            $rows = [];
            foreach ($snapshots as $snapshot) {
                $rows[] = [
                    'integration_id' => $integration->id,
                    'marketplace' => 'wildberries',
                    'tariff_type' => $snapshot['tariff_type'] ?? 'unknown',
                    'effective_date' => $snapshot['effective_date'] ?? null,
                    'warehouse_id' => (string) ($snapshot['warehouse_id'] ?? ''),
                    'warehouse_name' => $snapshot['warehouse_name'] ?? null,
                    'subject_id' => (string) ($snapshot['subject_id'] ?? ''),
                    'subject_name' => $snapshot['subject_name'] ?? null,
                    'scheme' => (string) ($snapshot['scheme'] ?? ''),
                    'payload' => json_encode($snapshot['payload'] ?? [], JSON_UNESCAPED_UNICODE),
                    'fetched_at' => $snapshot['fetched_at'] ?? now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                WildberriesTariffSnapshot::upsert(
                    $chunk,
                    ['integration_id', 'tariff_type', 'effective_date', 'warehouse_id', 'subject_id', 'scheme'],
                    ['marketplace', 'warehouse_name', 'subject_name', 'payload', 'fetched_at', 'updated_at']
                );
            }

            Log::info('SyncUnitEconomicsJob: WB tariff snapshots synced', [
                'integration_id' => $integration->id,
                'count' => count($rows),
            ]);
        } catch (\Throwable $e) {
            Log::warning('SyncUnitEconomicsJob: failed to sync WB tariff snapshots', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
