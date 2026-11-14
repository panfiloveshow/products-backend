<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Services\Wildberries\WildberriesTariffRefresher;
use App\Services\UnitEconomicsService;
use App\Domains\Marketplace\MarketplaceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncUnitEconomicsJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;
    public int $maxExceptions = 3;
    public bool $failOnTimeout = true;
    public ?int $requestedAt = null;
    // Крупные магазины (сотни товаров) не укладывались в 600с → таймаут до диспатча кэша,
    // из-за чего товары не появлялись в юнит-экономике. Поднимаем лимит.
    public int $timeout = 1800;

    // Джоб диспатчится из трёх мест (кнопка синка, после синка остатков, после хранения) —
    // без uniqueness дубли стакались и по 20+ минут блокировали очередь unit-economics.
    public int $uniqueFor = 3600;
    public ?string $unitEconomicsRunId = null;

    public function uniqueId(): string
    {
        return $this->integrationId.':'.($this->unitEconomicsRunId ?? 'default');
    }

    public function middleware(): array
    {
        return [(new \Illuminate\Queue\Middleware\WithoutOverlapping('ue-full:'.$this->integrationId))->shared()->expireAfter(1860)->releaseAfter(60)];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return ($this->unitEconomicsRunId ? \App\Models\UnitEconomicsSyncRun::find($this->unitEconomicsRunId)?->deadline_at : null)
            ?? \Carbon\CarbonImmutable::createFromTimestampUTC($this->requestedAt ?? time())->addHours(6);
    }

    public function __construct(
        public int $integrationId,
        public ?string $periodStart = null,
        public ?string $periodEnd = null,
        ?string $unitEconomicsRunId = null
    ) {
        $this->unitEconomicsRunId = $unitEconomicsRunId;
        $this->requestedAt = time();
        $this->onQueue($unitEconomicsRunId ? config('unit_economics_sync.enrichment_queue') : 'unit-economics');
    }

    public function handle(UnitEconomicsService $unitEconomicsService): array
    {
        if ($this->unitEconomicsRunId) {
            app(\App\Services\UnitEconomics\UnitEconomicsSyncCoordinator::class)->updateStage($this->unitEconomicsRunId, 'enrichment', 'running');
        }
        \App\Services\Ozon\OzonActualRatesService::forgetIntegration($this->integrationId);
        $integration = Integration::find($this->integrationId);
        
        if (!$integration) {
            Log::error('SyncUnitEconomicsJob: Integration not found', [
                'integration_id' => $this->integrationId
            ]);
            return ['synced' => 0, 'errors' => 0, 'status' => 'skipped', 'reason' => 'integration_missing'];
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
                            // Индекс локальности (/v1/analytics/local-sale/total); при сбое — прежний.
                            $settings['localization_local_index'] = $localizationIndex['local_sales_index']
                                ?? ($settings['localization_local_index'] ?? null);

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
            
            $syncStartedAt = microtime(true);
            $result = $unitEconomicsService->syncFromRealData(
                $integration,
                $this->periodStart,
                $this->periodEnd,
                $localizationIndex
            );

            $status = $result['status'] ?? ($result['errors'] > 0 ? ($result['synced'] > 0 ? 'partial' : 'failed') : 'completed');
            if ($status === 'failed') {
                throw new \RuntimeException('Unit economics synchronization failed for '.$result['errors'].' SKU(s)');
            }
            Log::log($status === 'partial' ? 'warning' : 'info', 'SyncUnitEconomicsJob '.$status, [
                'integration_id' => $this->integrationId,
                'synced' => $result['synced'],
                'errors' => $result['errors'],
                'duration_s' => round(microtime(true) - $syncStartedAt, 1),
            ]);
            
            // Запускаем пересчёт кэша ПОСЛЕ завершения синхронизации UnitEconomics
            if ($this->unitEconomicsRunId) {
                app(\App\Services\UnitEconomics\UnitEconomicsSyncCoordinator::class)->updateStage($this->unitEconomicsRunId, 'enrichment', 'running', [
                    'source_status' => $status, 'source_errors' => $result['source_errors'] ?? 0,
                    'error_code' => ($result['source_errors'] ?? 0) > 0 ? 'source_data_incomplete' : (($result['errors'] ?? 0) > 0 ? 'sku_sync_failed' : null),
                    'total' => $result['total'] ?? 0,
                    'succeeded' => $result['succeeded'] ?? 0, 'failed' => $result['errors'] ?? 0]);
            }
            RecalculateUnitEconomicsCacheJob::dispatch($this->integrationId, $this->unitEconomicsRunId)
                ->onQueue('unit-economics');

            return $result;
            
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
        if ($this->unitEconomicsRunId) {
            app(\App\Services\UnitEconomics\UnitEconomicsSyncCoordinator::class)->updateStage($this->unitEconomicsRunId, 'enrichment', 'failed', ['error_code' => 'enrichment_failed']);
        }
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
        // Канонический upsert тарифных снапшотов вынесен в WildberriesTariffRefresher,
        // чтобы ручной синк (здесь) и плановый wb:refresh-tariffs не расходились.
        app(WildberriesTariffRefresher::class)->refreshSnapshots($integration);
    }
}
