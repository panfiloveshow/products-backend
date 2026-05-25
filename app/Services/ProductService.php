<?php

namespace App\Services;

use App\Jobs\SyncInventoryJob;
use App\Jobs\SyncProductsJob;
use App\Models\Product;
use App\Models\SyncLog;
use App\Models\UnitEconomicsCache;
use App\Support\SyncStartGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ProductService
{
    public function applyComputedStock(
        Builder $query,
        string $alias = 'computed_stock',
        ?int $integrationId = null,
        ?string $marketplace = null
    ): Builder
    {
        $this->joinInventoryTotals($query, $integrationId, $marketplace);

        return $query
            ->select('products.*')
            ->selectRaw($this->computedStockExpression().' as '.$alias);
    }

    public function joinInventoryTotals(
        Builder $query,
        ?int $integrationId = null,
        ?string $marketplace = null
    ): Builder
    {
        $inventoryTotals = DB::table('inventory_warehouses')
            ->when($integrationId !== null, fn ($q) => $q->where('integration_id', $integrationId))
            ->when($marketplace !== null && $marketplace !== '' && $marketplace !== 'all', function ($q) use ($marketplace) {
                if (in_array($marketplace, ['yandex', 'yandex_market'], true)) {
                    $q->whereIn('marketplace', ['yandex', 'yandex_market']);
                } else {
                    $q->where('marketplace', $marketplace);
                }
            })
            ->selectRaw('COALESCE(integration_id, 0) as stock_integration_id, sku, SUM(quantity) as total_stock')
            ->groupByRaw('COALESCE(integration_id, 0), sku');

        return $query
            ->leftJoinSub($inventoryTotals, 'inventory_totals', function ($join) {
                $join->on('inventory_totals.sku', '=', 'products.sku')
                    ->whereRaw('inventory_totals.stock_integration_id = COALESCE(products.integration_id, 0)');
            });
    }

    /**
     * Остаток для витрины: если есть агрегат по складам (после SyncInventoryJob) — только он.
     * Иначе поле products.stock из синка карточек. Раньше складывали оба — получалось двойное
     * число и «залипание» после частичного обновления.
     */
    public function computedStockExpression(string $inventoryAlias = 'inventory_totals'): string
    {
        return "COALESCE({$inventoryAlias}.total_stock, products.stock, 0)";
    }

    public function getProductsStats(array $filters = []): array
    {
        $statsVersion = Cache::get('products_stats_version', 1);
        $cacheKey = 'products_stats_'.$statsVersion.'_'.md5(json_encode($filters));

        return Cache::remember($cacheKey, 60, function () use ($filters) {
            $query = Product::query();
            $this->joinInventoryTotals(
                $query,
                ! empty($filters['integration_id']) ? (int) $filters['integration_id'] : null,
                $filters['marketplace'] ?? null
            );

            if (! empty($filters['marketplace'])) {
                $mp = $filters['marketplace'];
                if (in_array($mp, ['yandex', 'yandex_market'], true)) {
                    $query->whereIn('marketplace', ['yandex', 'yandex_market']);
                } else {
                    $query->marketplace($mp);
                }
            }

            if (! empty($filters['integration_id'])) {
                $query->where('integration_id', (int) $filters['integration_id']);
            }

            if (! empty($filters['search'])) {
                $query->search($filters['search']);
            }

            if (! empty($filters['category'])) {
                $query->where('category', $filters['category']);
            }

            if (! empty($filters['brand'])) {
                $query->where('brand', $filters['brand']);
            }

            if (isset($filters['price_from'])) {
                $query->where('price', '>=', $filters['price_from']);
            }

            if (isset($filters['price_to'])) {
                $query->where('price', '<=', $filters['price_to']);
            }

            if (! empty($filters['in_stock'])) {
                $query->whereRaw($this->computedStockExpression().' > 0');
            }

            $total = $query->count();
            $inStock = (clone $query)->whereRaw($this->computedStockExpression().' > 0')->count();
            $outOfStock = (clone $query)->whereRaw($this->computedStockExpression().' <= 0')->count();
            $averagePrice = (clone $query)->avg('products.price') ?? 0;
            $totalValue = (clone $query)
                ->selectRaw('SUM(COALESCE(products.price, 0) * '.$this->computedStockExpression().') as total')
                ->value('total') ?? 0;

            $byMarketplace = (clone $query)
                ->select('products.marketplace')
                ->selectRaw('COUNT(*) as count')
                ->selectRaw('AVG(products.price) as average_price')
                ->groupBy('products.marketplace')
                ->get()
                ->keyBy('marketplace')
                ->map(fn ($item) => [
                    'count' => $item->count,
                    'average_price' => round($item->average_price, 2),
                ])
                ->toArray();

            return [
                'total' => $total,
                'in_stock' => $inStock,
                'out_of_stock' => $outOfStock,
                'average_price' => round($averagePrice, 2),
                'total_value' => round($totalValue, 2),
                'by_marketplace' => $byMarketplace,
            ];
        });
    }

    /**
     * Запускает синхронизацию товаров с маркетплейса
     *
     * @param  string  $marketplace  Название маркетплейса (wildberries, ozon, yandex)
     * @param  array  $credentials  API-ключи для маркетплейса
     * @param  int|null  $integrationId  ID интеграции из Sellico (опционально)
     * @param  string  $syncType  Тип синхронизации
     */
    public function startSync(
        string $marketplace,
        array $credentials = [],
        ?int $integrationId = null,
        string $syncType = 'products'
    ): SyncLog {
        $lock = Cache::lock(
            SyncStartGuard::cacheLockKey($syncType, $marketplace, $integrationId),
            120
        );

        return $lock->block(15, function () use ($marketplace, $credentials, $integrationId, $syncType) {
            $existingSync = SyncStartGuard::findActiveDuplicate($syncType, $marketplace, $integrationId);
            if ($existingSync) {
                return $existingSync;
            }

            // НЕ чистим кэш юнит-экономики на старте синхронизации.
            // Раньше удаление шло здесь и UI показывал «Нет данных» 2–6 минут
            // пока не отработает RecalculateUnitEconomicsCacheJob.
            // Сейчас пересчёт работает через shadow-update (fix H4):
            //   1. updateOrCreate по каждому SKU — существующие записи обновляются,
            //   2. в конце удаляются только «устаревшие» (updated_at < startedAt),
            //      то есть SKU, которых больше нет в каталоге.
            // Юзер всё время видит данные; они просто плавно обновляются.
            if ($syncType === 'products' && $integrationId !== null) {
                // Сбрасываем только runtime-кэши (Redis), таблицу не трогаем.
                $this->forgetUnitEconomicsRuntimeCache(
                    $integrationId,
                    $this->marketplaceAliases($marketplace)
                );
            }

            $syncLog = SyncLog::create([
                'marketplace' => SyncStartGuard::storageMarketplace($marketplace),
                'integration_id' => $integrationId,
                'sync_type' => $syncType,
                'status' => SyncLog::STATUS_PENDING,
                'credentials' => $credentials,
            ]);

            if ($syncType === 'inventory') {
                SyncInventoryJob::dispatch($syncLog);
            } else {
                SyncProductsJob::dispatch($syncLog);
            }

            \Log::info('Sync job queued', [
                'sync_log_id' => $syncLog->id,
                'sync_type' => $syncType,
                'marketplace' => $syncLog->marketplace,
                'integration_id' => $integrationId,
                'queue_connection' => config('queue.default'),
            ]);

            return $syncLog;
        });
    }

    private function clearUnitEconomicsCacheForSync(?int $integrationId, string $marketplace): void
    {
        if (! Schema::hasTable('unit_economics_cache')) {
            return;
        }

        $deleted = 0;
        $marketplaces = $this->marketplaceAliases($marketplace);

        if ($integrationId !== null) {
            $deleted = UnitEconomicsCache::where('integration_id', $integrationId)->delete();
            $this->forgetUnitEconomicsRuntimeCache($integrationId, $marketplaces);
        } else {
            $deleted = UnitEconomicsCache::whereIn('marketplace', $marketplaces)->delete();
        }

        Log::info('UnitEconomics cache cleared before products sync', [
            'integration_id' => $integrationId,
            'marketplace' => $marketplace,
            'deleted_count' => $deleted,
        ]);
    }

    private function forgetUnitEconomicsRuntimeCache(int $integrationId, array $marketplaces): void
    {
        Cache::forget("ue_cache_stats_{$integrationId}");
        Cache::lock("ue_recalculate_{$integrationId}", 900)->forceRelease();

        foreach ($marketplaces as $marketplace) {
            Cache::forget("ue_scheme_counts_{$integrationId}_{$marketplace}");
            Cache::forget("ue_actual_scheme_{$integrationId}_{$marketplace}");

            foreach ($this->unitEconomicsSchemes($marketplace) as $scheme) {
                Cache::forget("ue_stats_{$integrationId}_{$marketplace}_{$scheme}");
            }
        }
    }

    private function marketplaceAliases(string $marketplace): array
    {
        return match ($marketplace) {
            'yandex', 'yandex_market' => ['yandex', 'yandex_market'],
            default => [$marketplace],
        };
    }

    private function unitEconomicsSchemes(string $marketplace): array
    {
        return match ($marketplace) {
            'ozon' => ['FBO', 'FBS', 'RFBS', 'EXPRESS'],
            'wildberries' => ['FBO', 'FBS', 'DBS', 'EDBS', 'DBW'],
            'yandex', 'yandex_market' => ['FBY', 'FBS', 'DBS', 'EXPRESS'],
            default => ['FBO'],
        };
    }

    public static function invalidateStatsCache(?int $integrationId = null, ?string $marketplace = null): void
    {
        \Illuminate\Support\Facades\Cache::forget("products_stats_{$integrationId}_{$marketplace}");
        \Illuminate\Support\Facades\Cache::forget('products_stats_all');
        if (! \Illuminate\Support\Facades\Cache::has('products_stats_version')) {
            \Illuminate\Support\Facades\Cache::forever('products_stats_version', 1);
        }
        \Illuminate\Support\Facades\Cache::increment('products_stats_version');
    }

    public function getSyncStatuses(?int $integrationId = null): array
    {
        $marketplaces = ['wildberries', 'ozon', 'yandex_market'];
        $statuses = [];

        foreach ($marketplaces as $marketplace) {
            $syncQuery = SyncLog::where('sync_type', 'products')
                ->latest();

            if ($marketplace === 'yandex_market') {
                $syncQuery->whereIn('marketplace', ['yandex_market', 'yandex']);
            } else {
                $syncQuery->where('marketplace', $marketplace);
            }

            if ($integrationId) {
                $syncQuery->where('integration_id', $integrationId);
            }

            $lastSync = $syncQuery->first();

            $meta = $lastSync?->metadata ?? [];
            $itemsTotal = isset($meta['total_from_api']) ? (int) $meta['total_from_api'] : null;

            $statuses[$marketplace] = [
                'last_sync' => $lastSync?->completed_at,
                'status' => $lastSync?->status ?? 'never',
                'items_synced' => $lastSync?->items_synced ?? 0,
                'items_total' => $itemsTotal && $itemsTotal > 0 ? $itemsTotal : null,
                'items_failed' => $lastSync?->items_failed ?? 0,
                'error' => $lastSync?->error_message,
            ];
        }

        // Фронт и интеграции часто используют marketplace=yandex вместо yandex_market
        if (isset($statuses['yandex_market'])) {
            $statuses['yandex'] = $statuses['yandex_market'];
        }

        return $statuses;
    }

    public function syncFromMarketplace(SyncLog $syncLog, array $products): void
    {
        $syncLog->start();

        $synced = 0;
        $failed = 0;

        DB::beginTransaction();
        try {
            foreach ($products as $productData) {
                try {
                    Product::updateOrCreate(
                        [
                            'marketplace' => $syncLog->marketplace,
                            'marketplace_id' => $productData['marketplace_id'],
                            'integration_id' => $syncLog->integration_id,
                        ],
                        $productData
                    );
                    $synced++;
                } catch (\Exception $e) {
                    $failed++;
                    \Log::error('Failed to sync product: '.$e->getMessage(), [
                        'marketplace' => $syncLog->marketplace,
                        'product' => $productData,
                    ]);
                }
            }

            DB::commit();
            $syncLog->complete($synced, $failed);
        } catch (\Exception $e) {
            DB::rollBack();
            $syncLog->fail($e->getMessage());
            throw $e;
        }
    }
}
