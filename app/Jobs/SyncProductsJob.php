<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\SyncLog;
use App\Models\Integration;
use App\Jobs\RecalculateUnitEconomicsCacheJob;
use App\Domains\Marketplace\MarketplaceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;

    public function __construct(
        public SyncLog $syncLog
    ) {}

    public function handle(): void
    {
        // Убираем лимит времени выполнения для синхронного режима
        set_time_limit(0);
        
        Log::info("Starting products synchronization", [
            'sync_log_id' => $this->syncLog->id,
            'marketplace' => $this->syncLog->marketplace,
            'integration_id' => $this->syncLog->integration_id,
        ]);
        
        $this->syncLog->start();

        try {
            // Получаем credentials из SyncLog (зашифрованы в БД)
            $credentials = $this->syncLog->credentials ?? [];
            
            Log::debug("Creating marketplace service", [
                'marketplace' => $this->syncLog->marketplace,
                'has_credentials' => !empty($credentials),
            ]);
            
            // Получаем Integration для передачи в маркетплейс (нужно для Ozon схемы работы)
            $integration = Integration::find($this->syncLog->integration_id);
            
            // Создаём сервис маркетплейса с credentials и integration
            $marketplace = MarketplaceFactory::create($this->syncLog->marketplace, $credentials, $integration);
            
            Log::info("Fetching products from marketplace API", [
                'marketplace' => $this->syncLog->marketplace,
            ]);
            
            $products = $marketplace->getProducts();
            
            Log::info("Products fetched from API", [
                'marketplace' => $this->syncLog->marketplace,
                'products_count' => count($products),
            ]);

            if (empty($products)) {
                Log::warning("No products returned from marketplace API", [
                    'marketplace' => $this->syncLog->marketplace,
                ]);
                $this->syncLog->complete(0, 0);
                return;
            }

            $synced = 0;
            $failed = 0;
            $updated = 0;
            $created = 0;

            Log::info("Starting database transaction for products sync", [
                'marketplace' => $this->syncLog->marketplace,
                'products_to_sync' => count($products),
            ]);

            DB::beginTransaction();

            foreach ($products as $productData) {
                Log::debug("Processing product", [
                    'marketplace' => $this->syncLog->marketplace,
                    'sku' => $productData['sku'] ?? 'unknown',
                    'marketplace_id' => $productData['marketplace_id'] ?? 'unknown',
                    'name' => $productData['name'] ?? 'unknown',
                ]);
                try {
                    $result = $this->syncProduct($productData);
                    $synced++;
                    
                    if ($result === 'created') {
                        $created++;
                        Log::info("Product created", [
                            'marketplace' => $this->syncLog->marketplace,
                            'sku' => $productData['sku'] ?? 'unknown',
                            'marketplace_id' => $productData['marketplace_id'] ?? 'unknown',
                            'name' => $productData['name'] ?? 'unknown',
                        ]);
                    } elseif ($result === 'updated') {
                        $updated++;
                        Log::info("Product updated", [
                            'marketplace' => $this->syncLog->marketplace,
                            'sku' => $productData['sku'] ?? 'unknown',
                            'marketplace_id' => $productData['marketplace_id'] ?? 'unknown',
                            'name' => $productData['name'] ?? 'unknown',
                        ]);
                    } else {
                        Log::debug("Product unchanged", [
                            'marketplace' => $this->syncLog->marketplace,
                            'sku' => $productData['sku'] ?? 'unknown',
                            'marketplace_id' => $productData['marketplace_id'] ?? 'unknown',
                        ]);
                    }
                } catch (\Exception $e) {
                    $failed++;
                    Log::error("Failed to sync product", [
                        'marketplace' => $this->syncLog->marketplace,
                        'sku' => $productData['sku'] ?? 'unknown',
                        'marketplace_id' => $productData['marketplace_id'] ?? 'unknown',
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            Log::info("Committing database transaction", [
                'marketplace' => $this->syncLog->marketplace,
                'synced' => $synced,
                'created' => $created,
                'updated' => $updated,
                'failed' => $failed,
            ]);
            
            DB::commit();
            
            Log::debug("Updating sync log metadata", [
                'marketplace' => $this->syncLog->marketplace,
            ]);
            
            // Сохраняем метаданные о синхронизации
            $this->syncLog->update([
                'metadata' => [
                    'total_from_api' => count($products),
                    'created' => $created,
                    'updated' => $updated,
                    'unchanged' => $synced - $created - $updated,
                ],
            ]);
            
            $this->syncLog->complete($synced, $failed);

            Log::info("Products sync completed successfully", [
                'sync_log_id' => $this->syncLog->id,
                'marketplace' => $this->syncLog->marketplace,
                'integration_id' => $this->syncLog->integration_id,
                'total_from_api' => count($products),
                'synced' => $synced,
                'created' => $created,
                'updated' => $updated,
                'unchanged' => $synced - $created - $updated,
                'failed' => $failed,
                'duration_seconds' => now()->diffInSeconds($this->syncLog->started_at),
            ]);
            
            // Автоматически запускаем синхронизацию остатков после синхронизации товаров
            Log::info("Triggering inventory sync after products sync", [
                'marketplace' => $this->syncLog->marketplace,
            ]);
            $this->syncInventoryAfterProducts();
            
            // Автоматически создаём/обновляем юнит-экономику для синхронизированных товаров
            Log::info("Triggering unit economics sync after products sync", [
                'marketplace' => $this->syncLog->marketplace,
            ]);
            $this->syncUnitEconomicsAfterProducts();
            
        } catch (\Exception $e) {
            Log::error("Rolling back database transaction due to error", [
                'marketplace' => $this->syncLog->marketplace,
                'error' => $e->getMessage(),
            ]);
            
            DB::rollBack();
            $this->syncLog->fail($e->getMessage());

            Log::error("Products sync failed", [
                'sync_log_id' => $this->syncLog->id,
                'marketplace' => $this->syncLog->marketplace,
                'integration_id' => $this->syncLog->integration_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
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
        
        Log::debug("Searching for existing product", [
            'marketplace' => $marketplace,
            'marketplace_id' => $productData['marketplace_id'],
            'integration_id' => $this->syncLog->integration_id,
        ]);
        
        // Ищем существующий товар по marketplace + marketplace_id + integration_id
        $query = Product::where('marketplace', $marketplace)
            ->where('marketplace_id', $productData['marketplace_id']);
        
        // Если есть integration_id, ищем товар только для этой интеграции
        if ($this->syncLog->integration_id) {
            $query->where('integration_id', $this->syncLog->integration_id);
        }
        
        $existingProduct = $query->first();

        if (!$existingProduct) {
            Log::debug("Product not found, creating new", [
                'marketplace' => $marketplace,
                'marketplace_id' => $productData['marketplace_id'],
                'sku' => $productData['sku'] ?? 'unknown',
            ]);
            
            // Создаём новый товар
            Product::create(array_merge($productData, [
                'marketplace' => $marketplace,
                'integration_id' => $this->syncLog->integration_id,
            ]));
            return 'created';
        }

        Log::debug("Product found, checking for changes", [
            'product_id' => $existingProduct->id,
            'marketplace' => $marketplace,
            'marketplace_id' => $productData['marketplace_id'],
        ]);
        
        // Проверяем есть ли изменения
        $hasChanges = $this->hasChanges($existingProduct, $productData);

        if ($hasChanges) {
            Log::debug("Changes detected, updating product", [
                'product_id' => $existingProduct->id,
                'marketplace' => $marketplace,
                'marketplace_id' => $productData['marketplace_id'],
            ]);
            
            // Обновляем только изменившиеся поля + integration_id если изменился
            $updateData = $productData;
            if ($this->syncLog->integration_id && $existingProduct->integration_id !== $this->syncLog->integration_id) {
                $updateData['integration_id'] = $this->syncLog->integration_id;
            }
            $existingProduct->update($updateData);
            return 'updated';
        }

        Log::debug("No changes detected, skipping update", [
            'product_id' => $existingProduct->id,
            'marketplace' => $marketplace,
            'marketplace_id' => $productData['marketplace_id'],
        ]);
        
        return 'unchanged';
    }

    /**
     * Проверяет есть ли изменения между существующим товаром и новыми данными
     */
    private function hasChanges(Product $existing, array $newData): bool
    {
        Log::debug("Comparing product fields for changes", [
            'product_id' => $existing->id,
            'sku' => $existing->sku,
        ]);
        
        // Поля которые проверяем на изменения
        $fieldsToCompare = [
            'name',
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
            'card_rating',
            'card_rating_details',
            'commission',
            'spp',
            'subject_id',
            'fulfillment_type',
            // Габариты
            'depth',
            'width',
            'height',
            'weight',
            'volume_weight',
            // Данные маркетплейса (комиссии, габариты и т.д.)
            'wb_data',
            'ozon_data',
        ];

        foreach ($fieldsToCompare as $field) {
            if (!isset($newData[$field])) {
                continue;
            }

            $existingValue = $existing->{$field};
            $newValue = $newData[$field];

            // Для массивов (images) сравниваем как JSON
            if (is_array($newValue)) {
                if (json_encode($existingValue) !== json_encode($newValue)) {
                    Log::debug("Field changed (array)", [
                        'product_id' => $existing->id,
                        'field' => $field,
                    ]);
                    return true;
                }
                continue;
            }

            // Для decimal полей сравниваем с округлением
            if (in_array($field, ['price', 'old_price', 'rating'])) {
                if (round((float)$existingValue, 2) !== round((float)$newValue, 2)) {
                    Log::debug("Field changed (decimal)", [
                        'product_id' => $existing->id,
                        'field' => $field,
                        'old_value' => $existingValue,
                        'new_value' => $newValue,
                    ]);
                    return true;
                }
                continue;
            }

            // Обычное сравнение
            if ($existingValue != $newValue) {
                Log::debug("Field changed", [
                    'product_id' => $existing->id,
                    'field' => $field,
                    'old_value' => $existingValue,
                    'new_value' => $newValue,
                ]);
                return true;
            }
        }

        // Проверяем marketplace-specific данные
        $marketplaceDataField = match ($this->syncLog->marketplace) {
            'wildberries' => 'wb_data',
            'ozon' => 'ozon_data',
            'yandex' => 'yandex_data',
            default => null,
        };

        if ($marketplaceDataField && isset($newData[$marketplaceDataField])) {
            $existingMpData = $existing->{$marketplaceDataField} ?? [];
            $newMpData = $newData[$marketplaceDataField] ?? [];
            
            if (json_encode($existingMpData) !== json_encode($newMpData)) {
                Log::debug("Marketplace-specific data changed", [
                    'product_id' => $existing->id,
                    'field' => $marketplaceDataField,
                ]);
                return true;
            }
        }

        Log::debug("No changes detected in any field", [
            'product_id' => $existing->id,
        ]);
        
        return false;
    }

    public function failed(\Throwable $exception): void
    {
        $this->syncLog->fail($exception->getMessage());

        Log::error("SyncProductsJob failed", [
            'marketplace' => $this->syncLog->marketplace,
            'error' => $exception->getMessage(),
        ]);
    }
    
    /**
     * Автоматическая синхронизация остатков после синхронизации товаров
     */
    private function syncInventoryAfterProducts(): void
    {
        try {
            // Создаём новый SyncLog для остатков
            $inventorySyncLog = SyncLog::create([
                'marketplace' => $this->syncLog->marketplace,
                'integration_id' => $this->syncLog->integration_id,
                'sync_type' => 'inventory',
                'status' => SyncLog::STATUS_PENDING,
                'credentials' => $this->syncLog->credentials,
            ]);
            
            // Запускаем синхронизацию остатков
            SyncInventoryJob::dispatch($inventorySyncLog);
            
            Log::info("Inventory sync triggered after products sync", [
                'marketplace' => $this->syncLog->marketplace,
                'integration_id' => $this->syncLog->integration_id,
            ]);
        } catch (\Exception $e) {
            Log::warning("Failed to trigger inventory sync after products", [
                'marketplace' => $this->syncLog->marketplace,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Автоматическое создание/обновление юнит-экономики после синхронизации товаров
     */
    private function syncUnitEconomicsAfterProducts(): void
    {
        if (!$this->syncLog->integration_id) {
            Log::warning("Cannot sync unit economics: no integration_id", [
                'marketplace' => $this->syncLog->marketplace,
            ]);
            return;
        }
        
        try {
            // Запускаем синхронизацию юнит-экономики
            // RecalculateUnitEconomicsCacheJob запускается автоматически после SyncUnitEconomicsJob
            SyncUnitEconomicsJob::dispatch($this->syncLog->integration_id);
            
            Log::info("Unit economics sync triggered after products sync", [
                'marketplace' => $this->syncLog->marketplace,
                'integration_id' => $this->syncLog->integration_id,
            ]);
        } catch (\Exception $e) {
            Log::warning("Failed to trigger unit economics sync after products", [
                'marketplace' => $this->syncLog->marketplace,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
