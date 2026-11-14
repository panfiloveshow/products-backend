<?php

namespace App\Jobs;

use App\Services\UnitEconomicsCacheService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job для пересчёта кэша юнит-экономики
 *
 * Запускается автоматически после:
 * - Синхронизации товаров
 * - Изменения настроек пользователя
 *
 * ShouldBeUnique защищает от двух параллельных пересчётов одной интеграции —
 * без него при быстрых повторных dispatch'ах (sync завершился + user нажал
 * «синхронизировать» ещё раз) две цепочки в очереди конкурировали за unique-индекс
 * в unit_economics_cache и давали «Integrity constraint violation» / дедлоки.
 */
class RecalculateUnitEconomicsCacheJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;
    public int $maxExceptions = 3;
    public bool $failOnTimeout = true;
    public ?int $requestedAt = null;
    public int $timeout = 900; // 15 минут
    public int $uniqueFor = 1800; // 30 минут блокировки по ключу integrationId
    public ?string $unitEconomicsRunId = null;

    public function __construct(
        public int $integrationId,
        ?string $unitEconomicsRunId = null
    ) {
        $this->unitEconomicsRunId = $unitEconomicsRunId;
        $this->requestedAt = time();
        $this->onQueue('unit-economics');
    }

    public function uniqueId(): string
    {
        return $this->integrationId.':'.($this->unitEconomicsRunId ?? 'default');
    }

    public function middleware(): array
    {
        return [(new \Illuminate\Queue\Middleware\WithoutOverlapping('ue-cache:'.$this->integrationId))->shared()->expireAfter(960)->releaseAfter(30)];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return ($this->unitEconomicsRunId ? \App\Models\UnitEconomicsSyncRun::find($this->unitEconomicsRunId)?->deadline_at : null)
            ?? \Carbon\CarbonImmutable::createFromTimestampUTC($this->requestedAt ?? time())->addHours(6);
    }

    public function handle(UnitEconomicsCacheService $cacheService): array
    {
        Log::info('RecalculateUnitEconomicsCacheJob started', [
            'integration_id' => $this->integrationId,
        ]);

        try {
            $stats = $cacheService->recalculateIntegration($this->integrationId);

            if (($stats['status'] ?? null) === 'failed' || isset($stats['error'])) {
                throw new \RuntimeException('Unit economics projection could not be published');
            }

            Log::log(($stats['errors'] ?? 0) > 0 ? 'warning' : 'info', 'RecalculateUnitEconomicsCacheJob '.($stats['status'] ?? 'completed'), [
                'integration_id' => $this->integrationId,
                'stats' => $stats,
            ]);

            if ($this->unitEconomicsRunId) {
                app(\App\Services\UnitEconomics\UnitEconomicsSyncCoordinator::class)->enrichmentPublished($this->unitEconomicsRunId, $stats);
            }

            return $stats;
        } catch (\Exception $e) {
            Log::error('RecalculateUnitEconomicsCacheJob failed', [
                'integration_id' => $this->integrationId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        if ($this->unitEconomicsRunId) {
            app(\App\Services\UnitEconomics\UnitEconomicsSyncCoordinator::class)->updateStage($this->unitEconomicsRunId, 'enrichment', 'failed', ['error_code' => 'enrichment_projection_failed']);
        }
    }
}
