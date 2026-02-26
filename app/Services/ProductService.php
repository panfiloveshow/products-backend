<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SyncLog;
use App\Jobs\SyncProductsJob;
use Illuminate\Support\Facades\DB;

class ProductService
{
    public function getProductsStats(array $filters = []): array
    {
        $query = Product::query();

        if (!empty($filters['marketplace'])) {
            $query->marketplace($filters['marketplace']);
        }

        $total = $query->count();
        $inStock = (clone $query)->inStock()->count();
        $outOfStock = (clone $query)->outOfStock()->count();
        $averagePrice = (clone $query)->avg('price') ?? 0;
        $totalValue = (clone $query)->selectRaw('SUM(price * stock) as total')->value('total') ?? 0;

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
     */
    public function startSync(
        string $marketplace, 
        array $credentials = [],
        ?int $integrationId = null,
        string $syncType = 'products'
    ): SyncLog {
        // Проверяем нет ли уже запущенной синхронизации для этого маркетплейса
        $existingSync = SyncLog::where('marketplace', $marketplace)
            ->where('sync_type', $syncType)
            ->running()
            ->first();

        if ($existingSync) {
            return $existingSync;
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
