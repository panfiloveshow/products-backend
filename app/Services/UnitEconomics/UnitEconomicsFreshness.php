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
        $updating = $run && in_array($run->status, ['queued', 'running', 'waiting_external'], true);
        $status = $run?->status ?? 'unknown';
        $label = match (true) {
            $status === 'waiting_external' => 'Ожидаем доступ к API маркетплейса',
            $updating => $pricePublished ? 'Цены обновлены; остальные данные загружаются' : 'Синхронизация выполняется',
            $status === 'failed' => 'Обновление завершилось с ошибкой; показаны предыдущие данные',
            $status === 'partial' => 'Обновлено частично; есть ошибки отдельных этапов',
            $fresh => $run->purpose === 'prices' ? 'Цены подтверждены и опубликованы' : 'Обязательные данные обновлены',
            ! $known => 'Свежесть цен не подтверждена',
            default => 'Цены устарели; требуется синхронизация',
        };
        $stage = function (string $name) use ($run): array {
            $data = $run?->stages[$name] ?? ['status' => 'unknown'];

            return array_merge($data, ['items_synced' => $data['succeeded'] ?? 0,
                'completed_at' => $data['finished_at'] ?? null,
                'progress_percent' => ($data['total'] ?? 0) > 0
                    ? min(100, round(100 * (($data['succeeded'] ?? 0) + ($data['failed'] ?? 0)) / $data['total'])) : null]);
        };
        $errorSkus = [];
        if (($run?->stages['prices']['failed'] ?? 0) > 0 || ($run?->stages['projection']['failed'] ?? 0) > 0) {
            $failedCacheSkus = UnitEconomicsCache::where('integration_id', $integration->id)
                ->whereNotNull('marketplace_data->calculation_error_code')->distinct()->limit(10)->pluck('sku')->all();
            $errorSkus = Product::where('integration_id', $integration->id)
                ->where(fn ($q) => $q->whereNotNull($field.'->price_sync_error_code')->orWhereIn('sku', $failedCacheSkus))
                ->limit(10)->get(['sku', 'vendor_code'])->map(fn ($p) => $p->vendor_code ?: $p->sku)->values()->all();
        }

        return ['integration_id' => $integration->id, 'run_id' => $run?->id, 'purpose' => $run?->purpose,
            'status' => $status, 'requested_version' => $state?->requested_version ?? 0,
            'observed_version' => $state?->observed_version ?? 0, 'published_version' => $state?->published_version ?? 0,
            'publication_revision' => $state?->publication_revision ?? 0, 'next_retry_at' => $run?->next_retry_at?->utc()->toIso8601String(),
            'source' => ['total' => (int) ($source?->total ?? 0), 'observed' => (int) ($source?->observed ?? 0),
                'oldest_observed_at' => $oldest, 'price_is_fresh' => (bool) ($sourceFresh && $pricePublished), 'error_skus' => $errorSkus],
            'overall' => ['color' => $fresh ? 'green' : ($status === 'failed' ? 'red' : ($rows > 0 || $updating ? 'yellow' : 'gray')),
                'label' => $label, 'is_fresh' => (bool) $fresh, 'is_updating' => (bool) $updating, 'last_updated_at' => $cacheTime],
            'stages' => ['products' => $stage('catalog'), 'inventory' => $stage('inventory'), 'prices' => $stage('prices'),
                'unit_economics' => array_merge($stage('projection'), ['rows' => $rows, 'last_updated_at' => $cacheTime]),
                'finance' => $stage('finance'), 'enrichment' => $stage('enrichment'), 'locality' => ['status' => 'unknown']]];
    }
}
