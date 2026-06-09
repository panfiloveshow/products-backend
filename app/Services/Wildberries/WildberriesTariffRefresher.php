<?php

namespace App\Services\Wildberries;

use App\Domains\Marketplace\MarketplaceFactory;
use App\Models\Integration;
use App\Models\InventoryWarehouse;
use App\Models\WildberriesTariffSnapshot;
use Illuminate\Support\Facades\Log;

/**
 * Обновление тарифов складов WB (КС) для одной интеграции.
 *
 * КС не захардкожен — он тянется живьём из /api/v1/tariffs/box (boxDeliveryCoefExpr)
 * и кэшируется в БД в ДВУХ местах, оба нужны для корректного отображения:
 *   1. wildberries_tariff_snapshots.payload  — питает расчёт логистики (tariff breakdown);
 *   2. inventory_warehouses.warehouse_coefficient — питает отображаемый КС
 *      (getAverageWarehouseCoefficient в UnitEconomicsCacheService).
 *
 * Между синхронизациями значения замораживаются. Этот сервис — единый источник
 * правды для их обновления; используется и из SyncUnitEconomicsJob (при ручном
 * синке), и из wb:refresh-tariffs (по расписанию).
 */
class WildberriesTariffRefresher
{
    /**
     * Полное обновление КС интеграции: box-снапшоты + inventory-коэффициенты.
     *
     * @return array{snapshots:int, warehouses:int}
     */
    public function refresh(Integration $integration): array
    {
        $marketplace = $this->resolveMarketplace($integration);
        if (! $marketplace) {
            return ['snapshots' => 0, 'warehouses' => 0];
        }

        return [
            'snapshots' => $this->refreshSnapshots($integration, $marketplace),
            'warehouses' => $this->refreshInventoryCoefficients($integration, $marketplace),
        ];
    }

    /**
     * Обновить box/commission/return/… снапшоты тарифов WB.
     *
     * Канонический upsert тарифных снапшотов: ключ конфликта и обновляемые поля
     * держать в синхроне с любыми другими записями WildberriesTariffSnapshot.
     *
     * @return int Количество записанных строк снапшотов
     */
    public function refreshSnapshots(Integration $integration, ?object $marketplace = null): int
    {
        $marketplace ??= $this->resolveMarketplace($integration);
        if (! $marketplace || ! method_exists($marketplace, 'getTariffSnapshots')) {
            return 0;
        }

        try {
            $snapshots = $marketplace->getTariffSnapshots(now()->format('Y-m-d'));
            $rows = [];
            foreach ($snapshots as $snapshot) {
                $rows[] = [
                    'integration_id' => $integration->id,
                    'marketplace' => 'wildberries',
                    'tariff_type' => $snapshot['tariff_type'] ?? 'unknown',
                    'effective_date' => $snapshot['effective_date'] ?? null,
                    'warehouse_id' => (string) ($snapshot['warehouse_id'] ?? ''),
                    'warehouse_name' => $snapshot['warehouse_name'] ?? null,
                    'subject_id' => (string) ($snapshot['subject_id'] ?? ''),
                    'subject_name' => $snapshot['subject_name'] ?? null,
                    'scheme' => (string) ($snapshot['scheme'] ?? ''),
                    'payload' => json_encode($snapshot['payload'] ?? [], JSON_UNESCAPED_UNICODE),
                    'fetched_at' => $snapshot['fetched_at'] ?? now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                WildberriesTariffSnapshot::upsert(
                    $chunk,
                    ['integration_id', 'tariff_type', 'effective_date', 'warehouse_id', 'subject_id', 'scheme'],
                    ['marketplace', 'warehouse_name', 'subject_name', 'payload', 'fetched_at', 'updated_at']
                );
            }

            Log::info('WildberriesTariffRefresher: snapshots synced', [
                'integration_id' => $integration->id,
                'count' => count($rows),
            ]);

            return count($rows);
        } catch (\Throwable $e) {
            Log::warning('WildberriesTariffRefresher: snapshots failed', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Обновить inventory_warehouses.warehouse_coefficient свежими КС из box-тарифов.
     * Именно эта колонка питает число КС на странице юнит-экономики.
     *
     * @return int Количество обновлённых строк inventory_warehouses
     */
    public function refreshInventoryCoefficients(Integration $integration, ?object $marketplace = null): int
    {
        $marketplace ??= $this->resolveMarketplace($integration);
        if (! $marketplace || ! method_exists($marketplace, 'getInventory')) {
            return 0;
        }

        try {
            $inventory = $marketplace->getInventory();
            $updated = 0;

            foreach ($inventory as $item) {
                $sku = $item['sku'] ?? null;
                $warehouseId = $item['warehouse_id'] ?? null;
                $coefficient = $item['warehouse_coefficient'] ?? null;

                if (! $sku || ! $warehouseId || $coefficient === null) {
                    continue;
                }

                $updated += InventoryWarehouse::where('sku', $sku)
                    ->where('warehouse_id', $warehouseId)
                    ->where('integration_id', $integration->id)
                    ->update(['warehouse_coefficient' => (float) $coefficient]);
            }

            Log::info('WildberriesTariffRefresher: inventory coefficients refreshed', [
                'integration_id' => $integration->id,
                'rows' => $updated,
            ]);

            return $updated;
        } catch (\Throwable $e) {
            Log::warning('WildberriesTariffRefresher: inventory coefficients failed', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Резолв с Sellico-фолбэком: у части WB-интеграций api_key хранится в Sellico,
     * а не локально. Без фолбэка box-тарифы (КС) для них не синкались → КС=100%.
     */
    private function resolveMarketplace(Integration $integration): ?object
    {
        if ($integration->marketplace !== 'wildberries') {
            return null;
        }

        $credentials = $integration->resolveCredentials();
        if (empty($credentials['api_key'] ?? null)) {
            return null;
        }

        return MarketplaceFactory::create('wildberries', $credentials, $integration);
    }
}
