<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\SyncLog;
use App\Services\Marketplace\MarketplaceFactory;
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
        $this->syncLog->start();

        try {
            // Получаем credentials из SyncLog (зашифрованы в БД)
            $credentials = $this->syncLog->credentials ?? [];
            
            // Создаём сервис маркетплейса с credentials
            $marketplace = MarketplaceFactory::create($this->syncLog->marketplace, $credentials);
            $products = $marketplace->getProducts();

            if (empty($products)) {
                Log::warning("No products returned from marketplace API", [
                    'marketplace' => $this->syncLog->marketplace,
                ]);
                $this->syncLog->complete(0, 0);
                return;
            }

            // Фильтруем товары по integration_id через Sellico API
            $integrationId = $this->syncLog->integration_id;
            if ($integrationId) {
                $token = request()->bearerToken();
                if ($token) {
                    $sellicoApi = app(\App\Services\SellicoApiService::class);
                    $sellicoApi->setAccessToken($token);
                    
                    $result = $sellicoApi->getIntegrationProducts($integrationId);
                    if ($result['success'] && !empty($result['skus'])) {
                        $allowedSkus = $result['skus'];
                        $originalCount = count($products);
                        
                        $products = array_filter($products, function ($product) use ($allowedSkus) {
                            return in_array($product['sku'] ?? '', $allowedSkus);
                        });
                        
                        Log::info("Filtered products by integration SKUs", [
                            'integration_id' => $integrationId,
                            'original_count' => $originalCount,
                            'filtered_count' => count($products),
                            'allowed_skus_count' => count($allowedSkus),
                        ]);
                    }
                }
            }

            $synced = 0;
            $failed = 0;
            $updated = 0;
            $created = 0;

            // Каждый товар — в своей транзакции.
            // Одна общая транзакция на весь цикл нельзя использовать в PostgreSQL:
            // первая ошибка переводит транзакцию в сломанное состояние (25P02),
            // и все последующие запросы падают с тем же кодом.
            foreach ($products as $productData) {
                try {
                    $result = DB::transaction(function () use ($productData) {
                        return $this->syncProduct($productData);
                    });
                    $synced++;

                    if ($result === 'created') {
                        $created++;
                    } elseif ($result === 'updated') {
                        $updated++;
                    }
                } catch (\Exception $e) {
                    $failed++;
                    Log::error("Failed to sync product", [
                        'marketplace' => $this->syncLog->marketplace,
                        'sku' => $productData['sku'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                }
            }

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

            Log::info("Products sync completed", [
                'marketplace' => $this->syncLog->marketplace,
                'synced' => $synced,
                'created' => $created,
                'updated' => $updated,
                'failed' => $failed,
            ]);

            // Автоматически запускаем синхронизацию остатков после товаров
            $credentials = $this->syncLog->credentials ?? [];
            if (!empty($credentials)) {
                $inventorySyncLog = \App\Models\SyncLog::create([
                    'marketplace' => $this->syncLog->marketplace,
                    'integration_id' => $this->syncLog->integration_id,
                    'sync_type' => 'inventory',
                    'status' => \App\Models\SyncLog::STATUS_PENDING,
                    'credentials' => $credentials,
                ]);

                \App\Jobs\SyncInventoryJob::dispatch($inventorySyncLog)
                    ->delay(now()->addSeconds(5));

                Log::info("Inventory sync dispatched after products sync", [
                    'marketplace' => $this->syncLog->marketplace,
                    'inventory_sync_id' => $inventorySyncLog->id,
                ]);
            }
        } catch (\Exception $e) {
            $this->syncLog->fail($e->getMessage());

            Log::error("Products sync failed", [
                'marketplace' => $this->syncLog->marketplace,
                'error' => $e->getMessage(),
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
        $integrationId = $this->syncLog->integration_id ?? null;
        
        // Ищем существующий товар по sku + integration_id (если указан)
        // Это предотвращает перезапись товаров из разных аккаунтов одного маркетплейса
        $query = Product::where('marketplace', $marketplace)
            ->where('sku', $productData['sku']);
        
        if ($integrationId) {
            // Сначала ищем в рамках выбранной интеграции
            $query->where('integration_id', $integrationId);
        }
        
        $existingProduct = $query->first();

        // Резервный поиск по уникальному ключу (sku + marketplace).
        // Нужен, чтобы не ловить duplicate key, если запись уже есть
        // с другим/пустым integration_id.
        if (!$existingProduct) {
            $existingProduct = Product::where('marketplace', $marketplace)
                ->where('sku', $productData['sku'])
                ->first();
        }

        if (!$existingProduct) {
            // Создаём новый товар
            Product::create(array_merge($productData, [
                'marketplace'    => $marketplace,
                'integration_id' => $integrationId,
            ]));
            return 'created';
        }

        // Всегда обновляем integration_id если он не проставлен или совпадает
        $forceUpdate = ($integrationId && $existingProduct->integration_id !== $integrationId
            && $existingProduct->integration_id === null);

        // Проверяем есть ли изменения
        $hasChanges = $forceUpdate || $this->hasChanges($existingProduct, $productData);

        if ($hasChanges) {
            // Не перезаписываем цену нулём/null если уже есть реальная цена
            $updateData = $productData;
            if (empty($updateData['price']) && !empty($existingProduct->price)) {
                unset($updateData['price']);
            }
            if (empty($updateData['old_price']) && !empty($existingProduct->old_price)) {
                unset($updateData['old_price']);
            }
            if ($integrationId) {
                $updateData['integration_id'] = $integrationId;
            }
            $existingProduct->update($updateData);
            return 'updated';
        }

        return 'unchanged';
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
                    return true;
                }
                continue;
            }

            // Для decimal полей сравниваем с округлением
            if (in_array($field, ['price', 'old_price', 'rating'])) {
                if (round((float)$existingValue, 2) !== round((float)$newValue, 2)) {
                    return true;
                }
                continue;
            }

            // Обычное сравнение
            if ($existingValue != $newValue) {
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
                return true;
            }
        }

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
}
