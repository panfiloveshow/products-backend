<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\SyncLog;
use App\Models\UnitEconomics;
use App\Models\UnitEconomicsCache;
use App\Models\InventoryWarehouse;
use App\Services\InventoryService;
use App\Services\LimitsSyncService;
use App\Services\Marketplace\MarketplaceFactory;
use App\Domains\Marketplace\MarketplaceFactory as DomainMarketplaceFactory;
use App\Support\ActivityLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncProductsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const SUSPICIOUS_EMPTY_API_THRESHOLD = 30;

    private const DB_CHUNK_SIZE = 100;

    private const PROGRESS_FLUSH_EVERY = 75;

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    public function __construct(
        public SyncLog $syncLog
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function uniqueId(): string
    {
        return implode(':', [
            'products',
            $this->syncLog->marketplace,
            (string) ($this->syncLog->integration_id ?? '0'),
        ]);
    }

    public function handle(InventoryService $inventoryService, ?LimitsSyncService $limitsSync = null): void
    {
        $limitsSync ??= app(LimitsSyncService::class);
        $this->syncLog->start();

        // User-токен прокидывается в credentials при старте sync (IntegrationController::sync).
        // Activities отправляются только если есть user-токен — service token не используется.
        $userToken = $this->syncLog->credentials['_sellico_token'] ?? null;

        // Обновляем статус на интеграции
        if ($this->syncLog->integration_id) {
            \App\Models\Integration::where('id', $this->syncLog->integration_id)
                ->update(['last_sync_status' => 'running', 'last_sync_at' => now()]);

            ActivityLogger::forIntegration(
                (int) $this->syncLog->integration_id,
                'products_sync_started',
                'Запущена синхронизация товаров',
                "Маркетплейс: {$this->syncLog->marketplace}",
                [
                    'entity_type' => 'sync_log',
                    'entity_id' => $this->syncLog->id,
                    'marketplace' => $this->syncLog->marketplace,
                    'sync_type' => 'products',
                ],
                $userToken,
            );
        }

        try {
            // Получаем credentials из SyncLog (зашифрованы в БД)
            $credentials = $this->syncLog->credentials ?? [];
            $integration = $this->syncLog->integration_id
                ? \App\Models\Integration::find($this->syncLog->integration_id)
                : null;

            // Создаём сервис маркетплейса с credentials
            $marketplace = in_array($this->syncLog->marketplace, ['wildberries', 'ozon', 'yandex', 'yandex_market', 'uzum'], true)
                ? DomainMarketplaceFactory::create($this->syncLog->marketplace, $credentials, $integration)
                : MarketplaceFactory::create($this->syncLog->marketplace, $credentials);
            $products = $marketplace->getProducts();

            // Для Yandex: добавляем integration_id в каждый товар если он есть
            if ($this->syncLog->integration_id && in_array($this->syncLog->marketplace, ['yandex', 'yandex_market'], true)) {
                foreach ($products as &$product) {
                    $product['integration_id'] = $this->syncLog->integration_id;
                }
                unset($product);
                Log::info('Yandex products enriched with integration_id', [
                    'integration_id' => $this->syncLog->integration_id,
                    'count' => count($products),
                ]);
            }

            $beforeFilter = count($products);
            $products = array_values(array_filter(
                $products,
                static fn (array $p): bool => trim((string) ($p['sku'] ?? '')) !== ''
            ));
            if ($beforeFilter !== count($products)) {
                Log::warning('SyncProductsJob: skipped rows without sku', [
                    'marketplace' => $this->syncLog->marketplace,
                    'dropped' => $beforeFilter - count($products),
                ]);
            }

            $totalFromApi = count($products);
            $catalogComplete = method_exists($marketplace, 'isCatalogComplete') && $marketplace->isCatalogComplete()
                && $beforeFilter === $totalFromApi;
            $this->syncLog->update([
                'metadata' => array_merge($this->syncLog->metadata ?? [], [
                    'total_from_api' => $totalFromApi,
                    'catalog_complete' => $catalogComplete,
                    'phase' => 'products',
                ]),
            ]);

            if (empty($products)) {
                if (in_array($this->syncLog->marketplace, ['wildberries', 'ozon'], true) && ! $catalogComplete) {
                    throw new \RuntimeException('Marketplace catalog completeness was not confirmed');
                }
                $previousCatalogSize = $this->lastCompletedProductsApiTotal();
                if ($previousCatalogSize >= self::SUSPICIOUS_EMPTY_API_THRESHOLD) {
                    throw new \RuntimeException(
                        "Marketplace returned zero products while previous successful syncs reported {$previousCatalogSize} items; refusing to treat as success."
                    );
                }

                Log::warning('No products returned from marketplace API', [
                    'marketplace' => $this->syncLog->marketplace,
                    'integration_id' => $this->syncLog->integration_id,
                    'previous_catalog_total_from_api' => $previousCatalogSize,
                ]);
                $this->syncLog->complete(0, 0);
                $this->syncProductsLimit($limitsSync);

                return;
            }

            // Все товары аккаунта маркетплейса принадлежат данной интеграции.
            // Sellico API не предоставляет список SKU продуктов — integration_id
            // привязывается к каждому товару напрямую через syncProduct().
            $integrationId = $this->syncLog->integration_id;

            $synced = 0;
            $failed = 0;
            $updated = 0;
            $created = 0;

            // Чанки по DB_CHUNK_SIZE: одна внешняя транзакция, внутри SAVEPOINT на товар —
            // меньше накладных расходов, чем отдельная транзакция на каждый SKU, при этом
            // ошибка по одному товару не откатывает остальные в чанке.
            foreach (array_chunk($products, self::DB_CHUNK_SIZE) as $chunk) {
                DB::beginTransaction();
                try {
                    foreach ($chunk as $i => $productData) {
                        $savepoint = 'sync_sp_'.$i;
                        try {
                            DB::statement('SAVEPOINT '.$savepoint);
                            $result = $this->syncProduct($productData);
                            DB::statement('RELEASE SAVEPOINT '.$savepoint);
                            $synced++;

                            if ($result === 'created') {
                                $created++;
                            } elseif ($result === 'updated') {
                                $updated++;
                            }

                            if ($synced % self::PROGRESS_FLUSH_EVERY === 0) {
                                $this->syncLog->update(['items_synced' => $synced]);
                            }
                        } catch (\Exception $e) {
                            DB::statement('ROLLBACK TO SAVEPOINT '.$savepoint);
                            $failed++;
                            Log::error('Failed to sync product', [
                                'marketplace' => $this->syncLog->marketplace,
                                'sku' => $productData['sku'] ?? 'unknown',
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                    DB::commit();
                } catch (\Throwable $e) {
                    DB::rollBack();
                    throw $e;
                }
            }

            $pruned = $catalogComplete && $failed === 0 ? $this->pruneMissingMarketplaceProducts($products) : 0;

            // Сохраняем метаданные о синхронизации
            $this->syncLog->update([
                'metadata' => array_merge($this->syncLog->metadata ?? [], [
                    'total_from_api' => count($products),
                    'created' => $created,
                    'updated' => $updated,
                    'unchanged' => $synced - $created - $updated,
                    'pruned' => $pruned,
                    'phase' => 'products_done',
                ]),
            ]);

            $this->syncLog->complete($synced, $failed);

            // Обновляем статус на интеграции
            if ($this->syncLog->integration_id) {
                \App\Models\Integration::where('id', $this->syncLog->integration_id)
                    ->update(['last_sync_status' => 'completed', 'last_sync_at' => now(), 'last_sync_error' => null]);

                ActivityLogger::forIntegration(
                    (int) $this->syncLog->integration_id,
                    'products_sync_completed',
                    'Синхронизация товаров завершена',
                    "Обработано {$synced} товаров (создано {$created}, обновлено {$updated})",
                    [
                        'entity_type' => 'sync_log',
                        'entity_id' => $this->syncLog->id,
                        'marketplace' => $this->syncLog->marketplace,
                        'sync_type' => 'products',
                        'synced' => $synced,
                        'created' => $created,
                        'updated' => $updated,
                        'pruned' => $pruned,
                        'failed' => $failed,
                    ],
                    $userToken,
                );
            }

            Log::info('Products sync completed', [
                'marketplace' => $this->syncLog->marketplace,
                'synced' => $synced,
                'created' => $created,
                'updated' => $updated,
                'pruned' => $pruned,
                'failed' => $failed,
            ]);

            $this->syncProductsLimit($limitsSync);

            // WB has an extra inventory -> storage-fees hop before the full
            // unit-economics sync. A delayed/failed storage job must not leave
            // the page on the previous price after products were refreshed.
            // Rebuild the projection from fresh Product data immediately; the
            // full sync after storage fees will enrich and rebuild it again.
            $this->dispatchWildberriesPriceCacheRefresh();

            // Автоматически запускаем синхронизацию остатков после товаров (через сервис: lock + дедуп)
            $credentials = $this->syncLog->credentials ?? [];
            // WB can resolve its key from the linked integration even when the
            // queued credentials array is empty. Continue the full workflow;
            // otherwise catalog succeeds and all following stages stay pending.
            if (! empty($credentials) || ($this->syncLog->marketplace === 'wildberries' && $integration !== null)) {
                $inventorySyncLog = $inventoryService->startSync(
                    $this->syncLog->marketplace,
                    $credentials,
                    $this->syncLog->integration_id,
                    5,
                    $this->syncLog->metadata['unit_economics_run_id'] ?? null
                );

                Log::info('Inventory sync dispatched after products sync', [
                    'marketplace' => $this->syncLog->marketplace,
                    'inventory_sync_id' => $inventorySyncLog->id,
                ]);
            }

            // Unit economics запускается после inventory/storage sync, чтобы расчёт
            // видел свежие остатки, КС и storage fees.
            if ($this->syncLog->integration_id) {
                // Локальность продаж напрямую влияет на взвешенные тарифы
                // в юнит-экономике, поэтому пересчитываем её после sync'а
                // (раньше зависело только от cron'а в 05:00 — данные могли
                // протухать на сутки). Задержка 60с чтобы inventory sync
                // успел завершиться и положить свежие stock-данные.
                if ($this->syncLog->marketplace === 'ozon') {
                    \App\Domains\Locality\Jobs\AggregateLocalityDailyJob::dispatch(
                        (int) $this->syncLog->integration_id
                    )
                        ->onQueue('locality')
                        ->delay(now()->addSeconds(60));

                    Log::info('Locality aggregate dispatched after products sync', [
                        'integration_id' => $this->syncLog->integration_id,
                    ]);
                }
            }
        } catch (\Exception $e) {
            if ($this->job && $this->attempts() < $this->tries) {
                // The unique job still exists during backoff. Keep its log active
                // so another click cannot create a pending log with no queue job.
                $backoff = $this->backoff();
                $delay = $backoff[min($this->attempts() - 1, count($backoff) - 1)];
                $this->syncLog->update([
                    'status' => SyncLog::STATUS_PENDING,
                    'error_message' => $e->getMessage(),
                    'metadata' => array_merge($this->syncLog->metadata ?? [], [
                        'queue_available_at' => now()->addSeconds($delay)->utc()->toIso8601String(),
                    ]),
                ]);
                app(\App\Services\UnitEconomics\UnitEconomicsSyncCoordinator::class)->logStage($this->syncLog);
                Log::warning('Products sync attempt will be retried', [
                    'sync_log_id' => $this->syncLog->id,
                    'integration_id' => $this->syncLog->integration_id,
                    'attempt' => $this->attempts(),
                    'delay_s' => $delay,
                    'error_class' => $e::class,
                ]);

                throw $e;
            }
            $this->syncLog->fail($e->getMessage());

            // Обновляем статус на интеграции
            if ($this->syncLog->integration_id) {
                \App\Models\Integration::where('id', $this->syncLog->integration_id)
                    ->update(['last_sync_status' => 'failed', 'last_sync_error' => substr($e->getMessage(), 0, 500)]);

                ActivityLogger::forIntegration(
                    (int) $this->syncLog->integration_id,
                    'products_sync_failed',
                    'Ошибка синхронизации товаров',
                    substr($e->getMessage(), 0, 300),
                    [
                        'entity_type' => 'sync_log',
                        'entity_id' => $this->syncLog->id,
                        'marketplace' => $this->syncLog->marketplace,
                        'sync_type' => 'products',
                        'error' => substr($e->getMessage(), 0, 500),
                    ],
                    $userToken,
                );
            }

            Log::error('Products sync failed', [
                'marketplace' => $this->syncLog->marketplace,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Если API ответил подозрительно маленьким списком по сравнению
     * с тем, что лежит в БД — НЕ удаляем «стейл». Это страховка от
     * частичных ответов маркетплейса (rate-limit на середине пагинации),
     * которые раньше могли снести 90% каталога магазина.
     */
    private const PRUNE_MIN_PRODUCT_RATIO = 0.7;
    private const PRUNE_MIN_PRODUCTS_TO_SKIP_GUARD = 100;

    private function pruneMissingMarketplaceProducts(array $products): int
    {
        if (($this->syncLog->metadata['catalog_complete'] ?? false) !== true) {
            return 0;
        }
        if (! in_array($this->syncLog->marketplace ?? '', ['ozon', 'wildberries'], true)) {
            return 0;
        }

        $integrationId = $this->syncLog->integration_id;
        if (! $integrationId) {
            return 0;
        }

        $currentSkus = collect($products)
            ->pluck('sku')
            ->filter(fn ($sku) => trim((string) $sku) !== '')
            ->map(fn ($sku) => trim((string) $sku))
            ->unique()
            ->values();

        if ($currentSkus->isEmpty()) {
            return 0;
        }

        // Threshold-guard: если текущий ответ API содержит существенно меньше
        // товаров, чем уже есть в БД — возможно пагинация упала на середине.
        // В таком случае отказываемся удалять «недостающие» и логируем warning.
        $existingCount = Product::query()
            ->where('integration_id', $integrationId)
            ->where('marketplace', $this->syncLog->marketplace)
            ->count();

        $currentCount = $currentSkus->count();

        if (
            $existingCount >= self::PRUNE_MIN_PRODUCTS_TO_SKIP_GUARD
            && $currentCount < $existingCount * self::PRUNE_MIN_PRODUCT_RATIO
        ) {
            Log::warning('Pruning skipped: suspicious drop in API response size', [
                'marketplace' => $this->syncLog->marketplace,
                'integration_id' => $integrationId,
                'current_count' => $currentCount,
                'existing_count' => $existingCount,
                'ratio' => $existingCount > 0 ? round($currentCount / $existingCount, 3) : null,
                'threshold' => self::PRUNE_MIN_PRODUCT_RATIO,
            ]);
            return 0;
        }

        $staleQuery = Product::query()
            ->where('integration_id', $integrationId)
            ->where('marketplace', $this->syncLog->marketplace)
            ->whereNotIn('sku', $currentSkus->all());
        $currentNmIds = collect();
        if ($this->syncLog->marketplace === 'wildberries') {
            // A legacy barcode can disappear while its card is still active.
            // Only a known card missing from the complete catalog is removable.
            $currentNmIds = collect($products)->pluck('wb_data.nmID')->filter()
                ->map(fn ($id) => (string) $id)->unique()->values();
            if ($currentNmIds->isEmpty()) {
                return 0;
            }
            $staleQuery->whereNotNull('wb_data->nmID')->whereNotIn('wb_data->nmID', $currentNmIds->all());
        }

        return DB::transaction(function () use ($staleQuery, $integrationId, $currentCount, $existingCount, $currentNmIds): int {
            // Keep the same state -> product lock order as price publication.
            $state = \Illuminate\Support\Facades\Schema::hasTable('unit_economics_sync_states')
                ? \App\Models\UnitEconomicsSyncState::whereKey($integrationId)->lockForUpdate()->first() : null;
            if ($this->syncLog->marketplace === 'wildberries') {
                $run = $state?->latest_run_id ? \App\Models\UnitEconomicsSyncRun::whereKey($state->latest_run_id)->lockForUpdate()->first() : null;
                $priceStage = $run?->stages['prices'] ?? [];
                $observed = strtotime($priceStage['response_received_at'] ?? '');
                // Content can stop returning a card while Prices still serves it.
                // Retire only a matching, recent, complete price snapshot's gap.
                if (! $run || $run->requested_version !== $state->requested_version
                    || ! in_array($priceStage['status'] ?? '', ['completed', 'partial'], true)
                    || ($priceStage['complete'] ?? false) !== true || (int) ($priceStage['http_status'] ?? 0) !== 200
                    || ! $observed || $observed < now()->subMinutes(config('unit_economics_sync.price_max_age_minutes', 30))->getTimestamp()) {
                    return 0;
                }
                $staleQuery->where('wb_data->price_sync_error_code', 'price_not_returned')
                    ->whereIn('wb_data->price_sync_requested_version', [(int) $state->requested_version, (string) $state->requested_version]);
            }
            $staleSkus = $staleQuery->lockForUpdate()->get(['id', 'sku', 'wb_data'])
                ->filter(function (Product $product) use ($currentNmIds, $state): bool {
                    if ($this->syncLog->marketplace !== 'wildberries') {
                        return true;
                    }
                    // JSON numbers and canonical strings represent the same
                    // version. Booleans/floats must not become integer 1 through
                    // a database's JSON comparison rules during deletion.
                    $version = $product->wb_data['price_sync_requested_version'] ?? null;
                    if ($version !== (int) $state->requested_version && $version !== (string) $state->requested_version) return false;
                    $nm = trim((string) ($product->wb_data['nmID'] ?? ''));

                    return ctype_digit($nm) && (int) $nm > 0 && ! $currentNmIds->contains((string) (int) $nm);
                })->pluck('sku')
                ->filter(fn ($sku) => trim((string) $sku) !== '')->unique()->values();
            if ($staleSkus->isEmpty()) {
                return 0;
            }
            $staleSkuValues = $staleSkus->all();

            UnitEconomicsCache::query()->where('integration_id', $integrationId)
                ->where('marketplace', $this->syncLog->marketplace)->whereIn('sku', $staleSkuValues)->delete();
            UnitEconomics::query()->where('integration_id', $integrationId)
                ->where('marketplace', $this->syncLog->marketplace)->whereIn('sku', $staleSkuValues)->delete();
            InventoryWarehouse::query()->where('integration_id', $integrationId)
                ->where('marketplace', $this->syncLog->marketplace)->whereIn('sku', $staleSkuValues)->delete();
            $deletedProducts = Product::query()->where('integration_id', $integrationId)
                ->where('marketplace', $this->syncLog->marketplace)->whereIn('sku', $staleSkuValues)->delete();
            $state?->increment('publication_revision');

            Log::info('Pruned stale marketplace products after full sync', [
                'marketplace' => $this->syncLog->marketplace,
                'integration_id' => $integrationId,
                'stale_skus_count' => count($staleSkuValues),
                'deleted_products' => $deletedProducts,
                'api_response_count' => $currentCount,
                'db_existing_count' => $existingCount,
            ]);

            return $deletedProducts;
        });
    }

    /**
     * Синхронизация одного товара с оптимизацией:
     * - Создаёт новый товар если не существует
     * - Обновляет только если есть изменения
     *
     * @return string 'created'|'updated'|'unchanged'
     */
    private function syncProduct(array $productData): string
    {
        return \Illuminate\Support\Facades\DB::transaction(fn () => $this->syncProductLocked($productData));
    }

    private function syncProductLocked(array $productData): string
    {
        $marketplace = $this->syncLog->marketplace;
        $integrationId = $this->syncLog->integration_id ?? null;

        $existingProduct = $this->findExistingProduct($marketplace, $integrationId, $productData);

        if (! $existingProduct) {
            // Создаём новый товар
            Product::create(array_merge($productData, [
                'marketplace' => $marketplace,
                'integration_id' => $integrationId,
            ]));

            return 'created';
        }

        $existingProduct = Product::whereKey($existingProduct->id)->lockForUpdate()->firstOrFail();
        $productData = app(\App\Services\UnitEconomics\MarketplacePriceObservation::class)
            ->preserveConfirmedPrice($existingProduct, $productData);
        $integrationChanged = $integrationId && $existingProduct->integration_id !== $integrationId;

        // Проверяем есть ли изменения
        $hasChanges = $integrationChanged || $this->hasChanges($existingProduct, $productData);

        if ($hasChanges) {
            // Не перезаписываем цену нулём/null если уже есть реальная цена
            $updateData = $productData;
            if (empty($updateData['price']) && ! empty($existingProduct->price)) {
                unset($updateData['price']);
            }
            if (empty($updateData['old_price']) && ! empty($existingProduct->old_price)) {
                unset($updateData['old_price']);
            }

            if ($integrationChanged) {
                Log::warning('Rebinding product to current integration during sync', [
                    'marketplace' => $marketplace,
                    'sku' => $productData['sku'] ?? null,
                    'marketplace_id' => $productData['marketplace_id'] ?? null,
                    'from_integration_id' => $existingProduct->integration_id,
                    'to_integration_id' => $integrationId,
                ]);
            }

            if ($integrationId !== null) {
                $updateData['integration_id'] = $integrationId;
            }
            if ($marketplace === 'wildberries') {
                $updateData = $this->preserveWbFboWarehouses($existingProduct, $updateData);
                $updateData = $this->preserveWbRedemption($existingProduct, $updateData);
            }
            if ($marketplace === 'uzum') {
                $updateData = $this->preserveUzumDimensions($existingProduct, $updateData);
            }
            $existingProduct->update($updateData);

            return 'updated';
        }

        return 'unchanged';
    }

    /**
     * WB: не даём складской разбивке деградировать. Отчёт остатков по складам WB
     * (Analytics API stocks-report/wb-warehouses) рейт-лимитен и иногда пустеет во время
     * синка — тогда в stock_warehouses остаются только FBS-склады продавца
     * («Мой склад»), и КС в юнит-экономике теряет разбивку по реальным складам.
     * Если в новых данных нет ни одного FBO-склада, а в старых были — сохраняем
     * старые FBO-строки (FBS-строки берём свежие).
     */
    /**
     * Габариты Uzum (skuDimension) собирает расширение и кладёт в uzum_data['dimensions'].
     * Openapi-синк их не отдаёт → при обычном update перетёр бы. Переносим из старых данных.
     */
    private function preserveUzumDimensions(Product $existing, array $updateData): array
    {
        if (! isset($updateData['uzum_data']) || ! is_array($updateData['uzum_data'])) {
            return $updateData;
        }
        $oldDims = $existing->uzum_data['dimensions'] ?? null;
        if (is_array($oldDims) && empty($updateData['uzum_data']['dimensions'])) {
            $updateData['uzum_data']['dimensions'] = $oldDims;
        }
        return $updateData;
    }

    /**
     * WB: не даём % выкупа из воронки деградировать до дефолта. Воронка
     * (Analytics sales-funnel) жёстко рейт-лимитена (~единицы запросов в час);
     * при 429 синк товаров пересобирает wb_data без redemption_* — и следующий
     * ЮЭ-синк откатывал весь магазин на дефолт 80%. Если свежих данных нет,
     * а старые были — переносим старые (redemption_observed_at покажет возраст).
     */
    private function preserveWbRedemption(Product $existing, array $updateData): array
    {
        if (! isset($updateData['wb_data']) || ! is_array($updateData['wb_data'])) {
            return $updateData;
        }
        if (($updateData['wb_data']['redemption_rate'] ?? null) !== null) {
            return $updateData;
        }
        if (($existing->wb_data['redemption_rate'] ?? null) === null) {
            return $updateData;
        }

        foreach (['redemption_rate', 'redemption_orders_count', 'redemption_buyouts_count', 'redemption_source', 'redemption_observed_at'] as $key) {
            if (isset($existing->wb_data[$key])) {
                $updateData['wb_data'][$key] = $existing->wb_data[$key];
            }
        }

        return $updateData;
    }

    private function preserveWbFboWarehouses(Product $existing, array $updateData): array
    {
        if (! isset($updateData['wb_data']) || ! is_array($updateData['wb_data'])) {
            return $updateData;
        }

        $newWarehouses = $updateData['wb_data']['stock_warehouses'] ?? [];
        $newWarehouses = is_array($newWarehouses) ? $newWarehouses : [];
        $isFbo = static fn ($w) => is_array($w)
            && strtoupper((string) ($w['fulfillment_type'] ?? 'FBO')) === 'FBO';

        if (array_filter($newWarehouses, $isFbo) !== []) {
            return $updateData;
        }

        $oldWarehouses = is_array($existing->wb_data['stock_warehouses'] ?? null)
            ? $existing->wb_data['stock_warehouses']
            : [];
        $oldFbo = array_values(array_filter($oldWarehouses, $isFbo));
        if ($oldFbo === []) {
            return $updateData;
        }

        $newFbs = array_values(array_filter($newWarehouses, static fn ($w) => ! $isFbo($w)));
        $updateData['wb_data']['stock_warehouses'] = array_merge($oldFbo, $newFbs);
        // Пометка: FBO-строки унаследованы с прошлого синка (отчёт остатков не пришёл).
        $updateData['wb_data']['stock_warehouses_fbo_stale'] = true;

        return $updateData;
    }

    private function findExistingProduct(string $marketplace, ?int $integrationId, array $productData): ?Product
    {
        $sku = $productData['sku'] ?? null;
        if (! $sku) {
            return null;
        }

        if ($integrationId) {
            $byIntegration = Product::where('marketplace', $marketplace)
                ->where('sku', $sku)
                ->where('integration_id', $integrationId)
                ->first();

            if ($byIntegration) {
                return $byIntegration;
            }

            // Не выходим за пределы текущей интеграции: один и тот же SKU/marketplace_id
            // может существовать в другом магазине этого же маркетплейса.
            return Product::where('marketplace', $marketplace)
                ->where('sku', $sku)
                ->whereNull('integration_id')
                ->first();
        }

        $marketplaceId = $productData['marketplace_id'] ?? null;
        if ($marketplaceId !== null && $marketplaceId !== '') {
            $byMarketplaceId = Product::where('marketplace', $marketplace)
                ->where('marketplace_id', (string) $marketplaceId)
                ->first();

            if ($byMarketplaceId) {
                return $byMarketplaceId;
            }
        }

        return Product::where('marketplace', $marketplace)
            ->where('sku', $sku)
            ->first();
    }

    /**
     * Проверяет есть ли изменения между существующим товаром и новыми данными
     */
    private function hasChanges(Product $existing, array $newData): bool
    {
        // Поля которые проверяем на изменения
        $fieldsToCompare = [
            'name',
            'vendor_code',
            'price',
            'old_price',
            'stock',
            'barcode',
            'description',
            'images',
            'category',
            'brand',
            'rating',
            'reviews_count',
            'depth',
            'width',
            'height',
            'weight',
            'volume_weight',
            'fulfillment_type',
        ];

        foreach ($fieldsToCompare as $field) {
            if (! isset($newData[$field])) {
                continue;
            }

            $existingValue = $existing->{$field};
            $newValue = $newData[$field];

            // Для массивов (images) сравниваем как JSON
            if (is_array($newValue)) {
                if (json_encode($existingValue) !== json_encode($newValue)) {
                    return true;
                }

                continue;
            }

            // Для decimal полей сравниваем с округлением
            if (in_array($field, ['price', 'old_price', 'rating', 'depth', 'width', 'height', 'weight', 'volume_weight'])) {
                if (round((float) $existingValue, 2) !== round((float) $newValue, 2)) {
                    return true;
                }

                continue;
            }

            // Обычное сравнение
            if ($existingValue != $newValue) {
                return true;
            }
        }

        $marketplaceDataField = match ($this->syncLog->marketplace) {
            'wildberries' => 'wb_data',
            'ozon' => 'ozon_data',
            'yandex', 'yandex_market' => 'yandex_data',
            default => null,
        };

        if ($marketplaceDataField && isset($newData[$marketplaceDataField])) {
            $existingMpData = $existing->{$marketplaceDataField} ?? [];
            $newMpData = $newData[$marketplaceDataField] ?? [];

            if (json_encode($existingMpData) !== json_encode($newMpData)) {
                return true;
            }
        }

        return false;
    }

    private function lastCompletedProductsApiTotal(): int
    {
        $query = SyncLog::query()
            ->where('sync_type', 'products')
            ->where('status', SyncLog::STATUS_COMPLETED)
            ->where('id', '!=', $this->syncLog->id);

        $mp = $this->syncLog->marketplace;
        if (in_array($mp, ['yandex', 'yandex_market'], true)) {
            $query->whereIn('marketplace', ['yandex', 'yandex_market']);
        } else {
            $query->where('marketplace', $mp);
        }

        if ($this->syncLog->integration_id !== null) {
            $query->where('integration_id', $this->syncLog->integration_id);
        }

        $last = $query->orderByDesc('completed_at')->orderByDesc('created_at')->first();

        return (int) ($last?->metadata['total_from_api'] ?? 0);
    }

    private function syncProductsLimit(LimitsSyncService $limitsSync): void
    {
        if (! $this->syncLog->integration_id) {
            return;
        }

        $workspaceId = (int) (\App\Models\Integration::where('id', $this->syncLog->integration_id)
            ->value('work_space_id') ?? 0);

        if ($workspaceId <= 0) {
            Log::info('Products limit sync skipped: integration has no workspace_id', [
                'integration_id' => $this->syncLog->integration_id,
            ]);
            return;
        }

        try {
            $limitsSync->syncWorkspaceProductsLimit($workspaceId);
        } catch (\Throwable $e) {
            Log::warning('Products limit sync failed after products sync', [
                'workspace_id' => $workspaceId,
                'integration_id' => $this->syncLog->integration_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function dispatchWildberriesPriceCacheRefresh(): void
    {
        if ($this->syncLog->marketplace !== 'wildberries' || ! $this->syncLog->integration_id) {
            return;
        }

        RecalculateUnitEconomicsCacheJob::dispatch((int) $this->syncLog->integration_id)
            ->onQueue('unit-economics');

        if (app(\App\Services\UnitEconomics\UnitEconomicsSyncCoordinator::class)->enabled((int) $this->syncLog->integration_id)) {
            return;
        }

        // WB can keep returning the previous discount for several minutes after
        // the seller changes it. Re-read the authoritative Prices API after the
        // propagation window and rebuild UE only when the price actually changed.
        RefreshWildberriesPricesJob::dispatch((int) $this->syncLog->integration_id)
            ->delay(now()->addMinutes(5))
            ->onQueue('default');

        Log::info('Immediate UE and delayed price refresh dispatched after WB products sync', [
            'integration_id' => $this->syncLog->integration_id,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $this->syncLog->fail($exception->getMessage());

        Log::error('SyncProductsJob failed', [
            'marketplace' => $this->syncLog->marketplace,
            'error' => $exception->getMessage(),
        ]);
    }
}
