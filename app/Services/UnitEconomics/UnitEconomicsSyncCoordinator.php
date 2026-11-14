<?php

namespace App\Services\UnitEconomics;

use App\Jobs\RefreshMarketplacePricesJob;
use App\Models\Integration;
use App\Models\Product;
use App\Models\SyncLog;
use App\Models\UnitEconomicsSyncRun;
use App\Models\UnitEconomicsSyncState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Persisted workflow state; jobs.payload is never a business-status source. */
class UnitEconomicsSyncCoordinator
{
    private const TERMINAL = ['completed', 'partial', 'failed', 'superseded'];

    public function enabled(int $integrationId): bool
    {
        return (bool) config('unit_economics_sync.enabled')
            || in_array($integrationId, config('unit_economics_sync.integration_ids', []), true);
    }

    public function request(Integration $integration, string $purpose = 'prices', ?array $requiredStages = null): UnitEconomicsSyncRun
    {
        if (! $integration->is_active || ! in_array($purpose, ['prices', 'full'], true)) {
            throw new InvalidArgumentException('An active integration and supported sync purpose are required');
        }

        return DB::transaction(function () use ($integration, $purpose, $requiredStages): UnitEconomicsSyncRun {
            DB::table('unit_economics_sync_states')->insertOrIgnore([
                'integration_id' => $integration->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $state = UnitEconomicsSyncState::whereKey($integration->id)->lockForUpdate()->firstOrFail();
            $run = $state->latest_run_id ? UnitEconomicsSyncRun::whereKey($state->latest_run_id)->lockForUpdate()->first() : null;
            $state->requested_version++;
            $required = $requiredStages ?? ($purpose === 'full'
                ? ['catalog', 'inventory', 'prices', 'projection', 'enrichment', ...($integration->marketplace === 'wildberries' ? ['finance'] : [])]
                : ['prices', 'projection']);
            $requestedStages = $required;

            if (! $run || in_array($run->status, self::TERMINAL, true) || $run->deadline_at->isPast()) {
                if ($run && ! in_array($run->status, self::TERMINAL, true)) {
                    $run->update(['status' => 'failed', 'finished_at' => now()]);
                }
                $run = UnitEconomicsSyncRun::create([
                    'integration_id' => $integration->id, 'version' => $state->requested_version,
                    'requested_version' => $state->requested_version, 'purpose' => $purpose,
                    'status' => 'queued', 'required_stages' => $required, 'stages' => [],
                    'deadline_at' => now()->addHours(max(1, (int) config('unit_economics_sync.deadline_hours', 6))),
                ]);
            } else {
                $required = array_values(array_unique([...$run->required_stages, ...$required]));
                $run->fill(['requested_version' => $state->requested_version,
                    'purpose' => $run->purpose === 'full' || $purpose === 'full' ? 'full' : 'prices',
                    'required_stages' => $required, 'status' => 'queued', 'finished_at' => null]);
            }
            $stages = $run->stages;
            foreach ($required as $name) {
                $stages[$name] ??= $this->stage('pending', $run->requested_version);
                if ($purpose === 'full' && in_array($name, $requestedStages, true)
                    && in_array($stages[$name]['status'], [...self::TERMINAL, 'skipped'], true)) {
                    $stages[$name] = $this->stage('pending', $run->requested_version);
                }
            }
            // A coalesced request still requires the newest price observation and
            // projection. Other in-flight stages keep their original work/lease.
            $stages['prices'] = $this->stage('queued', $run->requested_version);
            $stages['projection'] = $this->stage('pending', $run->requested_version);
            $run->stages = $stages;
            $run->save();
            $state->latest_run_id = $run->id;
            $state->save();

            return $run;
        });
    }

    public function latest(int $integrationId): ?UnitEconomicsSyncRun
    {
        $id = UnitEconomicsSyncState::whereKey($integrationId)->value('latest_run_id');

        return $id ? UnitEconomicsSyncRun::whereKey($id)->where('integration_id', $integrationId)->first() : null;
    }

    public function observe(string $runId, int $version, callable $writer, bool $complete, array $details = []): array
    {
        return $this->withCurrentVersion($runId, $version, function ($state, $run) use ($writer, $version, $complete, $details): array {
            $stats = $writer($run->integration);
            $matched = (int) ($stats['matched'] ?? 0);
            if (($stats['missing'] ?? 0) > 0 && $complete) {
                $details['error_code'] = 'price_missing_for_sku';
            }
            $status = isset($details['next_retry_at']) ? 'waiting_external'
                : ($complete && ($stats['missing'] ?? 0) === 0 && $matched > 0 ? 'completed' : ($matched > 0 ? 'partial' : 'failed'));
            if ($matched > 0) {
                $state->observed_version = max($state->observed_version, $version);
                $state->save();
            }
            if (($stats['missing'] ?? 0) > 0) {
                $state->publication_revision++;
                $state->save();
            }
            $stages = $run->stages;
            $observationId = (string) Str::uuid();
            $stages['prices'] = array_merge($stages['prices'], $details, ['status' => $status,
                'observation_id' => $observationId,
                'version' => $version, 'heartbeat_at' => now()->utc()->toIso8601String(),
                'total' => $stats['total'], 'succeeded' => $matched,
                'failed' => $stats['missing'] + $stats['stale'], 'complete' => $complete,
                'finished_at' => in_array($status, self::TERMINAL, true) ? now()->utc()->toIso8601String() : null]);
            $stages['projection'] = $this->stage($matched > 0 ? 'queued' : ($status === 'waiting_external' ? 'pending' : 'skipped'), $version);
            $stages['projection']['observation_id'] = $observationId;
            $stats['observation_id'] = $observationId;
            $run->stages = $stages;
            $run->heartbeat_at = now();
            $this->deriveStatus($run);
            $run->save();

            return $stats;
        });
    }

    public function finishProjection(string $runId, int $version, array $stats): bool
    {
        return (bool) ($this->withCurrentVersion($runId, $version, function ($state, $run) use ($version, $stats): bool {
            $success = (int) ($stats['success'] ?? 0);
            $errors = (int) ($stats['errors'] ?? 0);
            $status = $errors > 0 ? ($success > 0 ? 'partial' : 'failed') : ($success > 0 ? 'completed' : 'failed');
            if ($success > 0) {
                $state->publication_revision++;
                if ($errors === 0 && ($run->stages['prices']['status'] ?? null) === 'completed') {
                    $state->published_version = max($state->published_version, $version);
                    $run->published_version = $version;
                }
                $state->save();
            }
            $stages = $run->stages;
            $stages['projection'] = array_merge($stages['projection'], ['status' => $status,
                'total' => $stats['total'], 'succeeded' => $success, 'failed' => $errors,
                'error_code' => $errors > 0 ? 'calculation_failed' : null,
                'finished_at' => now()->utc()->toIso8601String(), 'heartbeat_at' => now()->utc()->toIso8601String()]);
            $receivedAt = $stages['prices']['response_received_at'] ?? null;
            if ($receivedAt) {
                $stages['projection']['response_to_publication_seconds'] = max(0, now()->getTimestamp() - CarbonImmutable::parse($receivedAt)->getTimestamp());
            }
            $run->stages = $stages;
            $this->deriveStatus($run);
            $run->heartbeat_at = now();
            $run->save();

            return true;
        })['value'] ?? false);
    }

    public function projectionBatch(string $runId, int $version, string $observationId, ?string $afterId, ?string $cursor, array $stats, bool $done): bool
    {
        return (bool) ($this->withCurrentVersion($runId, $version, function ($state, $run) use ($version, $observationId, $afterId, $cursor, $stats, $done): bool {
            $stage = $run->stages['projection'];
            if (($stage['observation_id'] ?? null) !== $observationId || ($stage['cursor'] ?? null) !== $afterId) {
                return false;
            }
            $totals = ['total' => ($stage['total'] ?? 0) + $stats['total'],
                'success' => ($stage['succeeded'] ?? 0) + $stats['success'],
                'errors' => ($stage['failed'] ?? 0) + $stats['errors']];
            $stages = $run->stages;
            $stages['projection'] = array_merge($stage, ['status' => 'queued', 'cursor' => $cursor,
                'total' => $totals['total'], 'succeeded' => $totals['success'], 'failed' => $totals['errors'],
                'heartbeat_at' => now()->utc()->toIso8601String()]);
            $run->stages = $stages;
            $run->heartbeat_at = now();
            $run->save();
            if ($done) {
                return $this->finishProjection($run->id, $version, $totals);
            }
            if ($stats['success'] > 0) {
                $state->publication_revision++;
                $state->save();
            }

            return true;
        })['value'] ?? false);
    }

    public function start(Integration $integration, string $purpose = 'prices', ?array $requiredStages = null): UnitEconomicsSyncRun
    {
        $run = $this->request($integration, $purpose, $requiredStages);
        RefreshMarketplacePricesJob::dispatch($run->id, (int) $integration->id)->afterCommit();

        return $run;
    }

    public function attachLog(SyncLog $log, string $runId): void
    {
        $metadata = $log->metadata ?? [];
        $log->update(['metadata' => array_merge($metadata, ['unit_economics_run_id' => $runId])]);
        $this->logStage($log);
    }

    public function enrichmentPublished(string $runId, array $stats): void
    {
        $this->mutate($runId, function ($state, $run) use ($stats): void {
            $state->publication_revision++;
            $state->save();
            $sourceStatus = $run->stages['enrichment']['source_status'] ?? 'completed';
            $status = ($stats['errors'] ?? 0) > 0 || $sourceStatus === 'partial' ? 'partial' : 'completed';
            $this->updateStage($run->id, 'enrichment', $status, ['total' => $stats['total'],
                'succeeded' => $stats['success'], 'failed' => $stats['errors'],
                'error_code' => ($stats['errors'] ?? 0) > 0 ? 'enrichment_projection_partial' : ($sourceStatus === 'partial' ? 'source_data_incomplete' : null)]);
        });
    }

    public function logStage(SyncLog $log): void
    {
        $runId = $log->metadata['unit_economics_run_id'] ?? null;
        if (! $runId) {
            return;
        }
        $stage = $log->sync_type === 'inventory' ? 'inventory' : 'catalog';
        $status = match ($log->status) {
            'running' => 'running', 'completed' => $log->items_failed > 0 || ($log->metadata['catalog_complete'] ?? true) === false ? 'partial' : 'completed',
            'failed' => 'failed', default => 'queued',
        };
        $this->updateStage($runId, $stage, $status, ['sync_log_id' => $log->id,
            'available_at' => $log->metadata['queue_available_at'] ?? null,
            'total' => $log->items_synced + $log->items_failed,
            'succeeded' => $log->items_synced, 'failed' => $log->items_failed,
            'complete' => $stage === 'catalog' ? ($log->metadata['catalog_complete'] ?? null) : null,
            'error_code' => $status === 'failed' ? 'marketplace_sync_failed' : (($log->metadata['catalog_complete'] ?? true) === false ? 'catalog_incomplete' : null)]);
        if ($stage === 'catalog' && in_array($status, ['completed', 'partial'], true)) {
            $run = $this->latest((int) $log->integration_id);
            $field = $log->marketplace === 'wildberries' ? 'wb_data' : 'ozon_data';
            $missing = Product::where('integration_id', $log->integration_id)
                ->whereNull($field.'->price_observed_at')->exists();
            if ($run?->id === $runId && $missing && (in_array($run->stages['prices']['status'] ?? null, ['completed', 'partial', 'failed'], true)
                || ($run->stages['prices']['error_code'] ?? null) === 'catalog_required')) {
                $this->start($run->integration);
            }
        }
    }

    public function expire(string $runId, string $reason = 'deadline_exceeded'): void
    {
        $this->mutate($runId, function ($state, $run) use ($reason): void {
            if (in_array($run->status, self::TERMINAL, true)) {
                return;
            }
            $stages = $run->stages;
            foreach ($run->required_stages as $name) {
                if (! in_array($stages[$name]['status'] ?? null, [...self::TERMINAL, 'skipped'], true)) {
                    $stages[$name] = array_merge($stages[$name], ['status' => 'failed',
                        'error_code' => $reason, 'finished_at' => now()->utc()->toIso8601String(), 'next_retry_at' => null]);
                }
            }
            if ($run->published_version < $run->requested_version && ($stages['projection']['status'] ?? null) === 'completed') {
                $stages['projection']['status'] = 'failed';
                $stages['projection']['error_code'] = 'publication_not_confirmed';
            }
            $run->stages = $stages;
            $this->deriveStatus($run);
            $run->save();
        });
    }

    /** Execute data publication and its workflow transition under the same version guard. */
    public function withCurrentVersion(string $runId, int $version, callable $callback): array
    {
        return $this->mutate($runId, function ($state, $run) use ($version, $callback): array {
            if ($run->requested_version !== $version || $run->deadline_at->isPast() || in_array($run->status, self::TERMINAL, true)) {
                return ['accepted' => false, 'value' => null];
            }

            return ['accepted' => true, 'value' => $callback($state, $run)];
        }) ?? ['accepted' => false, 'value' => null];
    }

    public function updateStage(string $runId, string $name, string $status, array $details = [], ?int $version = null): bool
    {
        if (! in_array($status, ['pending', 'queued', 'running', 'waiting_external', 'completed', 'partial', 'failed', 'skipped'], true)) {
            throw new InvalidArgumentException('Unsupported stage status');
        }

        return (bool) $this->mutate($runId, function ($state, $run) use ($name, $status, $details, $version): bool {
            $stages = $run->stages;
            $current = $stages[$name] ?? $this->stage('pending', $version ?? $run->requested_version);
            if ($version !== null && $version < ($current['version'] ?? 0)) {
                return false;
            }
            if ($version !== null && in_array($name, ['prices', 'projection'], true) && $version !== $run->requested_version) {
                return false;
            }
            if (in_array($current['status'], [...self::TERMINAL, 'skipped'], true) && $current['status'] !== $status) {
                return false;
            }
            $next = array_merge($current, $details, ['status' => $status, 'heartbeat_at' => now()->utc()->toIso8601String()]);
            if ($status === 'queued' && ! array_key_exists('available_at', $details) && ! isset($current['available_at'])) {
                $next['available_at'] = now()->utc()->toIso8601String();
            }
            if ($status === 'running') {
                if (! isset($next['started_at']) && isset($current['available_at'])) {
                    $next['queue_wait_seconds'] = max(0, now()->getTimestamp() - CarbonImmutable::parse($current['available_at'])->getTimestamp());
                }
                $next['started_at'] ??= now()->utc()->toIso8601String();
                $run->started_at ??= now();
            }
            if (in_array($status, self::TERMINAL, true) || $status === 'skipped') {
                $next['finished_at'] = now()->utc()->toIso8601String();
                $next['next_retry_at'] = null;
            }
            $stages[$name] = $next;
            $run->stages = $stages;
            $run->heartbeat_at = now();
            $this->deriveStatus($run);
            $run->save();

            return true;
        });
    }

    private function mutate(string $runId, callable $callback): mixed
    {
        $integrationId = UnitEconomicsSyncRun::whereKey($runId)->value('integration_id');
        if (! $integrationId) {
            return null;
        }

        return DB::transaction(function () use ($integrationId, $runId, $callback): mixed {
            // All writers acquire locks in state -> run order.
            $state = UnitEconomicsSyncState::whereKey($integrationId)->lockForUpdate()->first();
            $run = UnitEconomicsSyncRun::whereKey($runId)->where('integration_id', $integrationId)->lockForUpdate()->first();
            if (! $state || ! $run) {
                return null;
            }
            if ($state->latest_run_id !== $runId) {
                if (! in_array($run->status, self::TERMINAL, true)) {
                    $run->update(['status' => 'superseded', 'finished_at' => now()]);
                }

                return null;
            }

            return $callback($state, $run);
        });
    }

    private function stage(string $status, int $version): array
    {
        return ['status' => $status, 'version' => $version, 'queued_at' => now()->utc()->toIso8601String(),
            'available_at' => $status === 'queued' ? now()->utc()->toIso8601String() : null, 'started_at' => null, 'heartbeat_at' => null, 'finished_at' => null,
            'next_retry_at' => null, 'total' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0];
    }

    private function deriveStatus(UnitEconomicsSyncRun $run): void
    {
        $statuses = array_map(fn ($name) => $run->stages[$name]['status'] ?? 'pending', $run->required_stages);
        $run->next_retry_at = collect($run->stages)->pluck('next_retry_at')->filter()->sort()->first();
        if (count(array_filter($statuses, fn ($s) => in_array($s, ['completed', 'partial', 'failed', 'skipped'], true))) === count($statuses)) {
            $hasFailure = count(array_intersect($statuses, ['partial', 'failed', 'skipped'])) > 0;
            $run->status = $hasFailure ? (in_array('completed', $statuses, true) || in_array('partial', $statuses, true) ? 'partial' : 'failed')
                : ($run->published_version >= $run->requested_version ? 'completed' : 'running');
        } elseif (in_array('running', $statuses, true)) {
            $run->status = 'running';
        } elseif (in_array('waiting_external', $statuses, true)) {
            $run->status = 'waiting_external';
        } else {
            $run->status = 'queued';
        }
        $run->finished_at = in_array($run->status, self::TERMINAL, true) ? now() : null;
    }
}
