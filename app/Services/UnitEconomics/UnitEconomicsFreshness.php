<?php

namespace App\Services\UnitEconomics;

use App\Models\Integration;
use App\Models\Product;
use App\Models\UnitEconomicsCache;
use App\Models\UnitEconomicsSyncState;
use Carbon\CarbonImmutable;

class UnitEconomicsFreshness
{
    public function forIntegration(Integration $integration): array
    {
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        $run = $coordinator->latest((int) $integration->id);
        $state = UnitEconomicsSyncState::find($integration->id);
        $cache = UnitEconomicsCache::where('integration_id', $integration->id);
        $cacheTime = (clone $cache)->max('updated_at');
        $rows = (clone $cache)->count();
        $field = $integration->marketplace === 'wildberries' ? 'wb_data' : 'ozon_data';
        $products = Product::where('integration_id', $integration->id);
        $source = (clone $products)->selectRaw("count(*) as total, count({$field}->>'price_observed_at') as observed, min({$field}->>'price_observed_at') as oldest")->first();
        $oldest = $source?->oldest;
        $known = $source && (int) $source->total > 0 && (int) $source->observed === (int) $source->total && $oldest;
        $sourceFresh = false;
        if ($known) {
            try {
                $sourceFresh = CarbonImmutable::parse($oldest)->greaterThanOrEqualTo(now()->subMinutes(config('unit_economics_sync.price_max_age_minutes', 30)));
            } catch (\Throwable) {
                $known = false;
            }
        }
        $unpublished = $state ? (clone $cache)->where('source_version', '<', $state->requested_version)->exists() : true;
        $pricePublished = $run && ($run->stages['prices']['status'] ?? '') === 'completed'
            && ($run->stages['projection']['status'] ?? '') === 'completed'
            && $state->published_version >= $state->requested_version && ! $unpublished;
        $fresh = $coordinator->enabled((int) $integration->id) && $sourceFresh && $pricePublished && $run?->status === 'completed';
        // Render expired work as failed even when its worker never started.
        // Keep this read model side-effect free: recovery owns persisted transitions.
        $expired = $run && in_array($run->status, ['queued', 'running', 'waiting_external'], true)
            && $run->deadline_at->isPast();
        $priceStage = $run?->stages['prices'] ?? [];
        $credentialsRejected = ($priceStage['status'] ?? '') === 'failed'
            && ($priceStage['error_code'] ?? '') === 'credentials_rejected';
        // A request-level 429 did not inspect individual SKUs. Older workers
        // counted every retained price as missing; do not expose that as SKU errors.
        $globalPriceWait = ($priceStage['status'] ?? '') === 'waiting_external'
            && (int) ($priceStage['http_status'] ?? 0) === 429
            && (int) ($priceStage['succeeded'] ?? 0) === 0;
        $previousPublication = null;
        if ($state && $state->published_version > 0 && $state->requested_version > $state->published_version
            && $state->observed_version <= $state->published_version && $known && ! $expired && ! $credentialsRejected
            && in_array($priceStage['status'] ?? '', ['queued', 'running', 'waiting_external'], true)
            && in_array($run?->stages['projection']['status'] ?? '', ['pending', 'queued', 'waiting_external'], true)
            && (clone $cache)->distinct()->count('sku') === (int) $source->total
            && ! (clone $cache)->where(fn ($q) => $q->where('source_version', '!=', $state->published_version)
                ->orWhereNotNull('marketplace_data->calculation_error_code'))->exists()) {
            $previousPublication = ['version' => $state->published_version, 'total' => (int) $source->total,
                'label' => 'доступен предыдущий расчёт'];
        }
        $activeStage = $run && collect($run->stages)->contains(
            fn ($data) => in_array($data['status'] ?? '', ['running', 'waiting_external'], true)
        );
        // A 401 cannot recover without a new key. Do not promise queue progress
        // for unstarted work, while preserving independent work already running.
        $blockedByKey = $credentialsRejected && (int) ($priceStage['http_status'] ?? 0) === 401 && ! $activeStage;
        $status = $expired || $blockedByKey ? 'failed' : ($run?->status ?? 'unknown');
        $updating = in_array($status, ['queued', 'running', 'waiting_external'], true);
        $hasStageErrors = $run && collect($run->required_stages)->contains(
            fn ($name) => in_array($run->stages[$name]['status'] ?? '', ['failed', 'partial'], true)
        );
        $marketplaceLabel = $integration->marketplace === 'wildberries' ? 'WB' : 'Ozon';
        $label = match (true) {
            $credentialsRejected => (int) ($priceStage['http_status'] ?? 0) === 403
                ? "{$marketplaceLabel} запретил получение цен. Проверьте права API-ключа"
                : "{$marketplaceLabel} отклонил API-ключ. Обновите его в интеграции",
            $status === 'waiting_external' => $pricePublished
                ? "Цены обновлены; остальные данные ожидают API {$marketplaceLabel}"
                : ($previousPublication ? "Расчёты доступны; обновление цен ожидает API {$marketplaceLabel}" : 'Ожидаем доступ к API маркетплейса'),
            $status === 'queued' && $hasStageErrors => $pricePublished
                ? 'Цены обновлены; есть ошибки остальных этапов' : 'Синхронизация ожидает обработки; есть ошибки этапов',
            $status === 'queued' => $pricePublished
                ? 'Цены обновлены; остальные этапы в очереди' : 'Синхронизация в очереди; ожидаем обработчик',
            $updating && $hasStageErrors => 'Синхронизация продолжается; есть ошибки этапов',
            $updating => $pricePublished ? 'Цены обновлены; остальные данные загружаются' : 'Синхронизация выполняется',
            $status === 'failed' => 'Обновление завершилось с ошибкой; показаны предыдущие данные',
            $status === 'partial' => 'Обновлено частично; есть ошибки отдельных этапов',
            $fresh => $run->purpose === 'prices' ? 'Цены подтверждены и опубликованы' : 'Обязательные данные обновлены',
            ! $known => 'Свежесть цен не подтверждена',
            default => 'Цены устарели; требуется синхронизация',
        };
        $stage = function (string $name) use ($run, $expired, $blockedByKey, $priceStage, $globalPriceWait): array {
            $data = $run?->stages[$name] ?? ['status' => 'unknown'];
            if ($name === 'prices' && $globalPriceWait) {
                $data['failed'] = 0;
            }
            if (($expired || $blockedByKey) && in_array($data['status'], ['pending', 'queued', 'running', 'waiting_external', ...($blockedByKey ? ['skipped'] : [])], true)) {
                $data = array_merge($data, ['status' => 'failed', 'error_code' => $blockedByKey ? 'credentials_rejected' : 'deadline_exceeded',
                    'next_retry_at' => null, 'finished_at' => $blockedByKey ? ($priceStage['finished_at'] ?? null) : $run->deadline_at->utc()->toIso8601String()]);
            }

            $projecting = $name === 'projection' && in_array($data['status'], ['queued', 'running'], true);
            $progressTotal = match (true) {
                // The projection counter only includes batches that have already finished.
                $projecting => $priceStage['succeeded'] ?? 0,
                // Enrichment counts describe source collection until its cache is published.
                $name === 'enrichment' && $data['status'] === 'running' => 0,
                default => $data['total'] ?? 0,
            };

            $projecting = $name === 'projection' && in_array($data['status'], ['queued', 'running'], true);
            $progressTotal = match (true) {
                // The projection counter only includes batches that have already finished.
                $projecting => $run?->stages['prices']['succeeded'] ?? 0,
                // Enrichment counts describe source collection until its cache is published.
                $name === 'enrichment' && $data['status'] === 'running' => 0,
                default => $data['total'] ?? 0,
            };

            return array_merge($data, ['items_synced' => $data['succeeded'] ?? 0,
                'completed_at' => $data['finished_at'] ?? null,
                'progress_percent' => $progressTotal > 0
                    ? min($projecting ? 99 : 100, round(100 * (($data['succeeded'] ?? 0) + ($data['failed'] ?? 0)) / $progressTotal)) : null]);
        };
        $errorSkus = [];
        if ((! $globalPriceWait && ($priceStage['failed'] ?? 0) > 0) || ($run?->stages['projection']['failed'] ?? 0) > 0) {
            $failedCacheSkus = UnitEconomicsCache::where('integration_id', $integration->id)
                ->whereNotNull('marketplace_data->calculation_error_code')->distinct()->limit(10)->pluck('sku')->all();
            $errorSkus = Product::where('integration_id', $integration->id)
                ->where(fn ($q) => $q->whereNotNull($field.'->price_sync_error_code')->orWhereIn('sku', $failedCacheSkus))
                ->limit(10)->get(['sku', 'vendor_code'])->map(fn ($p) => $p->vendor_code ?: $p->sku)->values()->all();
        }

        return ['integration_id' => $integration->id, 'run_id' => $run?->id, 'purpose' => $run?->purpose,
            'status' => $status, 'requested_version' => $state?->requested_version ?? 0,
            'observed_version' => $state?->observed_version ?? 0, 'published_version' => $state?->published_version ?? 0,
            'publication_revision' => $state?->publication_revision ?? 0, 'next_retry_at' => $expired || $blockedByKey ? null : $run?->next_retry_at?->utc()->toIso8601String(),
            'source' => ['total' => (int) ($source?->total ?? 0), 'observed' => (int) ($source?->observed ?? 0),
                'oldest_observed_at' => $oldest, 'price_is_fresh' => (bool) ($sourceFresh && $pricePublished), 'error_skus' => $errorSkus],
            'overall' => ['color' => $fresh ? 'green' : ($status === 'failed' || $credentialsRejected ? 'red' : ($rows > 0 || $updating ? 'yellow' : 'gray')),
                'label' => $label, 'is_fresh' => (bool) $fresh, 'is_updating' => (bool) $updating, 'last_updated_at' => $cacheTime],
            'stages' => ['products' => $stage('catalog'), 'inventory' => $stage('inventory'), 'prices' => $stage('prices'),
                'unit_economics' => array_merge($stage('projection'), ['rows' => $rows, 'last_updated_at' => $cacheTime,
                    'published' => $previousPublication]),
                'finance' => $stage('finance'), 'enrichment' => $stage('enrichment'), 'locality' => ['status' => 'unknown']]];
    }
}
