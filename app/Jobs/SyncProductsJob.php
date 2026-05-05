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

    public function handle(InventoryService $inventoryService, LimitsSyncService $limitsSync): void
    {
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

            // Создаём сервис маркетплейса с credentials
            $marketplace = MarketplaceFactory::create($this->syncLog->marketplace, $credentials);
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
            $this->syncLog->update([
                'metadata' => array_merge($this->syncLog->metadata ?? [], [
                    'total_from_api' => $totalFromApi,
                    'phase' => 'products',
                ]),
            ]);

            if (empty($products)) {
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

            $pruned = $this->pruneMissingMarketplaceProducts($products);

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

            // Автоматически запускаем синхронизацию остатков после товаров (через сервис: lock + дедуп)
            $credentials = $this->syncLog->credentials ?? [];
            if (! empty($credentials)) {
                $inventorySyncLog = $inventoryService->startSync(
                    $this->syncLog->marketplace,
                    $credentials,
                    $this->syncLog->integration_id,
                    5
                );

                Log::info('Inventory sync dispatched after products sync', [
                    'marketplace' => $this->syncLog->marketplace,
                    'inventory_sync_id' => $inventorySyncLog->id,
                ]);
            }

            // Запускаем пересчёт юнит-экономики + кэша после синхронизации товаров
            if ($this->syncLog->integration_id) {
                SyncUnitEconomicsJob::dispatch($this->syncLog->integration_id)
                    ->onQueue('unit-economics')
                    ->delay(now()->addSeconds(30));

                Log::info('UnitEconomics sync dispatched after products sync', [
                    'integration_id' => $this->syncLog->integration_id,
                ]);

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
        if (($this->syncLog->marketplace ?? '') !== 'ozon') {
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

        $staleSkus = Product::query()
            ->where('integration_id', $integrationId)
            ->where('marketplace', $this->syncLog->marketplace)
            ->whereNotIn('sku', $currentSkus->all())
            ->pluck('sku')
            ->filter(fn ($sku) => trim((string) $sku) !== '')
            ->unique()
            ->values();

        if ($staleSkus->isEmpty()) {
            return 0;
        }

        $staleSkuValues = $staleSkus->all();

        UnitEconomicsCache::query()
            ->where('integration_id', $integrationId)
            ->where('marketplace', $this->syncLog->marketplace)
            ->whereIn('sku', $staleSkuValues)
            ->delete();

        UnitEconomics::query()
            ->where('integration_id', $integrationId)
            ->where('marketplace', $this->syncLog->marketplace)
            ->whereIn('sku', $staleSkuValues)
            ->delete();

        InventoryWarehouse::query()
            ->where('integration_id', $integrationId)
            ->where('marketplace', $this->syncLog->marketplace)
            ->whereIn('sku', $staleSkuValues)
            ->delete();

        $deletedProducts = Product::query()
            ->where('integration_id', $integrationId)
            ->where('marketplace', $this->syncLog->marketplace)
            ->whereIn('sku', $staleSkuValues)
            ->delete();

        Log::info('Pruned stale marketplace products after full sync', [
            'marketplace' => $this->syncLog->marketplace,
            'integration_id' => $integrationId,
            'stale_skus_count' => count($staleSkuValues),
            'deleted_products' => $deletedProducts,
            'api_response_count' => $currentCount,
            'db_existing_count' => $existingCount,
        ]);

        return $deletedProducts;
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
            $existingProduct->update($updateData);

            return 'updated';
        }

        return 'unchanged';
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

    public function failed(\Throwable $exception): void
    {
        $this->syncLog->fail($exception->getMessage());

        Log::error('SyncProductsJob failed', [
            'marketplace' => $this->syncLog->marketplace,
            'error' => $exception->getMessage(),
        ]);
    }
}
