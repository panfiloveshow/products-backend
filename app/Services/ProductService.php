<?php

namespace App\Services;

use App\Models\Integration;
use App\Models\Product;
use App\Models\SyncLog;
use App\Jobs\SyncProductsJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ProductService
{
    /**
     * Получить статистику товаров с кэшированием
     * Кэш инвалидируется при синхронизации (см. SyncProductsJob, SyncInventoryJob)
     */
    public function getProductsStats(array $filters = []): array
    {
        $cacheKey = 'products_stats_' . md5(json_encode($filters));
        $cacheTtl = 300; // 5 минут
        
        return Cache::remember($cacheKey, $cacheTtl, function () use ($filters) {
            return $this->calculateProductsStats($filters);
        });
    }
    
    /**
     * Инвалидировать кэш статистики товаров
     */
    public static function invalidateStatsCache(?int $integrationId = null, ?string $marketplace = null): void
    {
        // Инвалидируем все возможные комбинации кэша
        $patterns = [
            'products_stats_' . md5(json_encode([])),
        ];
        
        if ($integrationId) {
            $patterns[] = 'products_stats_' . md5(json_encode(['integration_id' => $integrationId]));
        }
        
        if ($marketplace) {
            $patterns[] = 'products_stats_' . md5(json_encode(['marketplace' => $marketplace]));
        }
        
        if ($integrationId && $marketplace) {
            $patterns[] = 'products_stats_' . md5(json_encode(['integration_id' => $integrationId, 'marketplace' => $marketplace]));
            $patterns[] = 'products_stats_' . md5(json_encode(['marketplace' => $marketplace, 'integration_id' => $integrationId]));
        }
        
        foreach ($patterns as $key) {
            Cache::forget($key);
        }
    }
    
    /**
     * Расчёт статистики товаров (без кэша)
     */
    private function calculateProductsStats(array $filters): array
    {
        $query = Product::query();

        if (!empty($filters['marketplace'])) {
            $query->marketplace($filters['marketplace']);
        }

        if (!empty($filters['integration_id'])) {
            $query->where('integration_id', $filters['integration_id']);
        }

        $total = $query->count();
        $inStock = (clone $query)->inStock()->count();
        $outOfStock = (clone $query)->outOfStock()->count();
        $averagePrice = (clone $query)->avg('price') ?? 0;
        $totalValue = (clone $query)->selectRaw('SUM(price * stock) as total')->value('total') ?? 0;
        
        // Платное хранение: берём из InventoryWarehouse.storage_fee_total (фактические начисления)
        // Это данные из отчётов о размещении Ozon и еженедельных отчётов WB
        $storageQuery = \App\Models\InventoryWarehouse::query();
        
        if (!empty($filters['marketplace'])) {
            $storageQuery->where('marketplace', $filters['marketplace']);
        }
        
        if (!empty($filters['integration_id'])) {
            $storageQuery->where('integration_id', $filters['integration_id']);
        }
        
        // Суммируем фактические начисления за хранение (storage_fee_total)
        $totalStorageCost = $storageQuery->sum('storage_fee_total') ?? 0;

        $byMarketplace = Product::select('marketplace')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('AVG(price) as average_price')
            ->groupBy('marketplace')
            ->get()
            ->keyBy('marketplace')
            ->map(fn($item) => [
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
            'total_storage_cost' => round($totalStorageCost, 2),
            'by_marketplace' => $byMarketplace,
        ];
    }

    /**
     * Запускает синхронизацию товаров с маркетплейса
     * 
     * @param string $marketplace Название маркетплейса (wildberries, ozon, yandex)
     * @param array $credentials API-ключи для маркетплейса
     * @param int|null $integrationId ID интеграции из Sellico (опционально)
     * @param string $syncType Тип синхронизации
     * @param string|null $integrationName Название интеграции (опционально)
     */
    public function startSync(
        string $marketplace, 
        array $credentials = [],
        ?int $integrationId = null,
        string $syncType = 'products',
        ?string $integrationName = null
    ): SyncLog {
        // Если передан integration_id, создаём/обновляем локальную интеграцию
        if ($integrationId) {
            $this->ensureIntegrationExists($integrationId, $marketplace, $credentials, $integrationName);
        }
        
        // Проверяем нет ли уже запущенной синхронизации для этой интеграции
        $query = SyncLog::where('marketplace', $marketplace)
            ->where('sync_type', $syncType)
            ->running();
        
        // Если указан integration_id, проверяем только для этой интеграции
        if ($integrationId) {
            $query->where('integration_id', $integrationId);
        }
        
        $existingSync = $query->first();

        if ($existingSync) {
            // Если синхронизация зависла (более 30 минут), завершаем её
            if ($existingSync->created_at < now()->subMinutes(30)) {
                $existingSync->update([
                    'status' => SyncLog::STATUS_FAILED,
                    'error_message' => 'Timeout - stuck for more than 30 minutes',
                    'completed_at' => now(),
                ]);
            } else {
                return $existingSync;
            }
        }

        // Создаём запись о синхронизации с credentials (зашифрованы)
        $syncLog = SyncLog::create([
            'marketplace' => $marketplace,
            'integration_id' => $integrationId,
            'sync_type' => $syncType,
            'status' => SyncLog::STATUS_PENDING,
            'credentials' => $credentials,
        ]);

        // Запускаем Job в очереди
        SyncProductsJob::dispatch($syncLog);

        return $syncLog;
    }
    
    /**
     * Создаёт или обновляет локальную интеграцию при синхронизации из Sellico
     */
    private function ensureIntegrationExists(
        int $integrationId, 
        string $marketplace, 
        array $credentials,
        ?string $name = null
    ): Integration {
        $integration = Integration::find($integrationId);
        
        if ($integration) {
            // Обновляем marketplace если отличается (исправление ошибочных данных)
            if ($integration->marketplace !== $marketplace) {
                Log::info('Updating integration marketplace', [
                    'integration_id' => $integrationId,
                    'old_marketplace' => $integration->marketplace,
                    'new_marketplace' => $marketplace,
                ]);
                
                $integration->update([
                    'marketplace' => $marketplace,
                    'credentials' => $credentials,
                ]);
            } else {
                // Обновляем только credentials
                $integration->update(['credentials' => $credentials]);
            }
            
            return $integration;
        }
        
        // Создаём новую интеграцию
        $integration = Integration::create([
            'id' => $integrationId,
            'name' => $name ?? "Integration #{$integrationId}",
            'marketplace' => $marketplace,
            'credentials' => $credentials,
            'is_active' => true,
            'auto_sync_enabled' => true,
            'sync_interval_hours' => 6,
        ]);
        
        Log::info('Created local integration from Sellico', [
            'integration_id' => $integrationId,
            'marketplace' => $marketplace,
            'name' => $integration->name,
        ]);
        
        return $integration;
    }

    public function getSyncStatuses(): array
    {
        $marketplaces = ['wildberries', 'ozon', 'yandex'];
        $statuses = [];

        foreach ($marketplaces as $marketplace) {
            $lastSync = SyncLog::where('marketplace', $marketplace)
                ->where('sync_type', 'products')
                ->latest()
                ->first();

            $statuses[$marketplace] = [
                'last_sync' => $lastSync?->completed_at,
                'status' => $lastSync?->status ?? 'never',
                'items_synced' => $lastSync?->items_synced ?? 0,
                'items_failed' => $lastSync?->items_failed ?? 0,
                'error' => $lastSync?->error_message,
            ];
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
                        ],
                        $productData
                    );
                    $synced++;
                } catch (\Exception $e) {
                    $failed++;
                    \Log::error("Failed to sync product: " . $e->getMessage(), [
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
