<?php

namespace App\Jobs;

use App\Models\Integration;
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
    public int $timeout = 600;

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
            RecalculateUnitEconomicsCacheJob::dispatch($this->integrationId);
            
        } catch (\Exception $e) {
            Log::error('SyncUnitEconomicsJob failed', [
                'integration_id' => $this->integrationId,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }
}
