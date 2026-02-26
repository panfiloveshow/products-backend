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

            $synced = 0;
            $failed = 0;
            $updated = 0;
            $created = 0;

            DB::beginTransaction();

            foreach ($products as $productData) {
                try {
                    $result = $this->syncProduct($productData);
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

            DB::commit();
            
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
        } catch (\Exception $e) {
            DB::rollBack();
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
        
        // Ищем существующий товар по marketplace + marketplace_id
        $existingProduct = Product::where('marketplace', $marketplace)
            ->where('marketplace_id', $productData['marketplace_id'])
            ->first();

        if (!$existingProduct) {
            // Создаём новый товар
            Product::create(array_merge($productData, [
                'marketplace' => $marketplace,
            ]));
            return 'created';
        }

        // Проверяем есть ли изменения
        $hasChanges = $this->hasChanges($existingProduct, $productData);

        if ($hasChanges) {
            // Не перезаписываем цену нулём/null если уже есть реальная цена
            $updateData = $productData;
            if (empty($updateData['price']) && !empty($existingProduct->price)) {
                unset($updateData['price']);
            }
            if (empty($updateData['old_price']) && !empty($existingProduct->old_price)) {
                unset($updateData['old_price']);
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
