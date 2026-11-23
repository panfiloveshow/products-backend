<?php

namespace App\Jobs;

use App\Domains\Wildberries\WildberriesMarketplace;
use App\Models\Integration;
use App\Models\InventoryWarehouse;
use App\Services\Wildberries\WildberriesAcquiringSnapshotStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Отдельный джоб для синхронизации фактических начислений за хранение WB
 *
 * Выделен из SyncInventoryJob для:
 * - Изоляции памяти (отчёт реализации WB может быть 80+ MB)
 * - Возможности запуска отдельно от основной синхронизации
 * - Chunk-обработки для экономии памяти
 *
 * Источник данных: WB Finance API POST /api/finance/v1/sales-reports/detailed
 * (v5 reportDetailByPeriod отключён WB 15.07.2026)
 * Поля: storage_fee_total, storage_fee_last_week, storage_fee_report_from, storage_fee_report_to
 */
class SyncStorageFeesJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $uniqueFor = 86400;

    public int $timeout = 600; // 10 минут — отчёт может быть большим

    public array $backoff = [30, 60, 120];
    public ?string $unitEconomicsRunId = null;

    public function uniqueId(): string
    {
        return $this->integrationId.':'.$this->weeks.':'.($this->unitEconomicsRunId ?? 'legacy');
    }

    public function __construct(
        private int $integrationId,
        private array $credentials,
        private int $weeks = 4,
        ?string $unitEconomicsRunId = null
    ) {
        $this->unitEconomicsRunId = $unitEconomicsRunId;
        if ($unitEconomicsRunId) {
            $this->tries = 0;
            $this->onQueue(config('unit_economics_sync.enrichment_queue'));
        }
    }

    public function retryUntil(): ?\DateTimeInterface
    {
        return $this->unitEconomicsRunId ? \App\Models\UnitEconomicsSyncRun::find($this->unitEconomicsRunId)?->deadline_at : null;
    }

    private function stage(string $status, array $details = []): void
    {
        if ($this->unitEconomicsRunId) {
            app(\App\Services\UnitEconomics\UnitEconomicsSyncCoordinator::class)->updateStage($this->unitEconomicsRunId, 'finance', $status, $details);
        }
    }

    public function handle(WildberriesAcquiringSnapshotStore $acquiringStore): void
    {
        $this->stage('running', ['next_retry_at' => null]);
        $startTime = microtime(true);

        Log::info('SyncStorageFeesJob: starting', [
            'integration_id' => $this->integrationId,
            'weeks' => $this->weeks,
            'memory_start' => $this->formatMemory(memory_get_usage(true)),
        ]);

        try {
            // Создаём клиент WB
            $marketplace = new WildberriesMarketplace($this->credentials, Integration::find($this->integrationId));

            // Один Finance-отчёт сразу для хранения и эквайринга. Два
            // последовательных запроса выбивали второй этап в 429.
            $financeSnapshot = $marketplace->getStorageAndAcquiringBySku($this->weeks);
            if (($financeSnapshot['status'] ?? null) === 'rate_limited') {
                // Базовый токен без сервисного секрета допускает Finance-запрос
                // раз в 12 часов. Не сокращаем X-Ratelimit-Retry лимитом в 6 часов.
                $delay = max(60, (int) ($financeSnapshot['retry_after'] ?? 60) + 15);
                Log::warning('SyncStorageFeesJob: WB Finance rate limited; deferring job', [
                    'integration_id' => $this->integrationId,
                    'delay_seconds' => $delay,
                ]);
                $this->release($delay);
                $this->stage('waiting_external', ['error_code' => 'rate_limited', 'next_retry_at' => now()->addSeconds($delay)->utc()->toIso8601String()]);
                $this->dispatchUnitEconomicsIfPending();

                return;
            }

            $storageFeesBySku = is_array($financeSnapshot['storage_by_sku'] ?? null)
                ? $financeSnapshot['storage_by_sku']
                : [];
            $acquiringSnapshot = is_array($financeSnapshot['acquiring'] ?? null)
                ? $financeSnapshot['acquiring']
                : ['by_sku' => [], 'avg' => 0.0, 'observed_at' => null];

            if (($financeSnapshot['status'] ?? null) !== 'success') {
                $this->stage('failed', ['error_code' => 'finance_unavailable']);
                Log::warning('SyncStorageFeesJob: WB Finance snapshot unavailable; preserving previous values', [
                    'integration_id' => $this->integrationId,
                ]);
                $this->dispatchUnitEconomicsIfPending();

                return;
            }

            Log::info('SyncStorageFeesJob: storage fees loaded', [
                'count' => count($storageFeesBySku),
                'memory_after_load' => $this->formatMemory(memory_get_usage(true)),
            ]);

            // Обновляем InventoryWarehouse chunk-ами по 500 записей
            $updated = 0;
            $notFound = 0;
            $chunkSize = 500;
            $chunks = array_chunk($storageFeesBySku, $chunkSize, true);

            foreach ($chunks as $chunkIndex => $chunk) {
                DB::beginTransaction();

                try {
                    foreach ($chunk as $key => $data) {
                        // Ищем по barcode (sku), nm_id или sa_name
                        $query = InventoryWarehouse::where('integration_id', $this->integrationId)
                            ->where('marketplace', 'wildberries');

                        // Пробуем найти по разным ключам
                        $warehouse = $query->clone()->where('sku', $key)->first();

                        if (! $warehouse && isset($data['barcode'])) {
                            $warehouse = $query->clone()->where('sku', $data['barcode'])->first();
                        }

                        if (! $warehouse && isset($data['sa_name'])) {
                            // sa_name = артикул продавца (vendorCode)
                            $warehouse = $query->clone()->where('sku', $data['sa_name'])->first();
                        }

                        if ($warehouse) {
                            $warehouse->update([
                                'storage_fee_total' => $data['storage_fee_total'] ?? 0,
                                'storage_fee_last_week' => $data['storage_fee_last_week'] ?? 0,
                                'storage_fee_report_from' => $data['report_date_from'] ?? null,
                                'storage_fee_report_to' => $data['report_date_to'] ?? null,
                            ]);
                            $updated++;
                        } else {
                            $notFound++;
                        }
                    }

                    DB::commit();

                    // Логируем прогресс каждые 5 чанков
                    if (($chunkIndex + 1) % 5 === 0) {
                        Log::debug('SyncStorageFeesJob: progress', [
                            'chunks_processed' => $chunkIndex + 1,
                            'total_chunks' => count($chunks),
                            'updated' => $updated,
                            'memory' => $this->formatMemory(memory_get_usage(true)),
                        ]);
                    }

                } catch (\Exception $e) {
                    DB::rollBack();
                    Log::error('SyncStorageFeesJob: chunk failed', [
                        'chunk_index' => $chunkIndex,
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }
            }

            // Эквайринг сохраняется до запуска unit-economics. Команда подхватит
            // этот свежий снимок и не сделает второй запрос к Finance API.
            $acquiringUpdated = $acquiringStore->persist($this->integrationId, $acquiringSnapshot);

            $duration = round(microtime(true) - $startTime, 2);

            Log::info('SyncStorageFeesJob: completed', [
                'integration_id' => $this->integrationId,
                'updated' => $updated,
                'not_found' => $notFound,
                'acquiring_updated_rows' => $acquiringUpdated,
                'acquiring_source_keys' => count($acquiringSnapshot['by_sku'] ?? []),
                'duration_sec' => $duration,
                'memory_peak' => $this->formatMemory(memory_get_peak_usage(true)),
            ]);

            // Обновляем Integration если есть
            $integration = Integration::find($this->integrationId);
            if ($integration) {
                $integration->update([
                    'last_sync_at' => now(),
                    'last_sync_status' => 'completed',
                ]);
            }

            $targetRunId = null;
            if ($this->unitEconomicsRunId) {
                $requeued = app(\App\Services\UnitEconomics\UnitEconomicsSyncCoordinator::class)
                    ->completeFinanceAndRequeueEnrichment($this->unitEconomicsRunId, [
                        'total' => $updated,
                        'succeeded' => $updated,
                        'observed_at' => $acquiringSnapshot['observed_at'] ?? null,
                    ]);
                $targetRunId = $requeued ? $this->unitEconomicsRunId : null;
            }
            $this->dispatchUnitEconomicsSync($targetRunId);

        } catch (\Exception $e) {
            Log::error('SyncStorageFeesJob: failed', [
                'integration_id' => $this->integrationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->stage('failed', ['error_code' => 'finance_worker_failed']);
        Log::error('SyncStorageFeesJob: job failed permanently', [
            'integration_id' => $this->integrationId,
            'error' => $exception->getMessage(),
        ]);

        $targetRunId = null;
        if ($this->unitEconomicsRunId) {
            $run = \App\Models\UnitEconomicsSyncRun::find($this->unitEconomicsRunId);
            $enrichmentStatus = $run?->stages['enrichment']['status'] ?? null;
            if (in_array($enrichmentStatus, ['completed', 'partial', 'running', 'queued'], true)) {
                return;
            }
            $coordinator = app(\App\Services\UnitEconomics\UnitEconomicsSyncCoordinator::class);
            if ($run && ! $run->deadline_at->isPast() && $coordinator->queueEnrichmentIfPending($run->id)) {
                $targetRunId = $run->id;
            }
        }

        $this->dispatchUnitEconomicsSync($targetRunId);
    }

    private function formatMemory(int $bytes): string
    {
        return round($bytes / 1024 / 1024, 2).' MB';
    }

    private function dispatchUnitEconomicsIfPending(): void
    {
        if ($this->unitEconomicsRunId === null) {
            $this->dispatchUnitEconomicsSync(null);

            return;
        }
        $coordinator = app(\App\Services\UnitEconomics\UnitEconomicsSyncCoordinator::class);
        if ($coordinator->queueEnrichmentIfPending($this->unitEconomicsRunId)) {
            $this->dispatchUnitEconomicsSync($this->unitEconomicsRunId);
        }
    }

    private function dispatchUnitEconomicsSync(?string $runId = null): void
    {
        SyncUnitEconomicsJob::dispatch($this->integrationId, unitEconomicsRunId: $runId);

        Log::info('UnitEconomics sync dispatched after WB storage fees sync', [
            'integration_id' => $this->integrationId,
            'unit_economics_run_id' => $runId,
        ]);
    }
}
