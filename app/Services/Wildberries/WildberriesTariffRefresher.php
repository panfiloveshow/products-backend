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
     * Тянет тарифы по ключу самой интеграции (для одиночного/ручного синка).
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
     * Один раз получить тарифные данные WB (снапшоты + карту КС). Тарифы WB
     * не зависят от продавца (склад/категория — одинаковые для всех), поэтому
     * для массового обновления тянем их ОДИН раз с любого рабочего ключа и
     * применяем ко всем интеграциям — это и быстрее, и не упирается в 429.
     *
     * @return array{snapshots:array, coefMap:array}
     */
    public function fetchSharedTariffData(Integration $anyIntegration): array
    {
        $marketplace = $this->resolveMarketplace($anyIntegration);
        if (! $marketplace) {
            return ['snapshots' => [], 'coefMap' => []];
        }

        return [
            'snapshots' => method_exists($marketplace, 'getTariffSnapshots')
                ? (array) $marketplace->getTariffSnapshots(now()->format('Y-m-d'))
                : [],
            'coefMap' => method_exists($marketplace, 'getWarehouseCoefficients')
                ? (array) $marketplace->getWarehouseCoefficients()
                : [],
        ];
    }

    /**
     * Применить заранее полученные тарифные данные к одной интеграции
     * (без обращения к WB API — для массового обновления).
     *
     * @return array{snapshots:int, warehouses:int}
     */
    public function applyShared(Integration $integration, array $snapshots, array $coefMap): array
    {
        return [
            'snapshots' => $this->applySnapshots($integration, $snapshots),
            'warehouses' => $this->applyInventoryCoefficients($integration, $coefMap),
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

        return $this->applySnapshots($integration, (array) $marketplace->getTariffSnapshots(now()->format('Y-m-d')));
    }

    /**
     * Записать набор тарифных снапшотов для интеграции (upsert).
     *
     * Канонический upsert тарифных снапшотов: ключ конфликта и обновляемые поля
     * держать в синхроне с любыми другими записями WildberriesTariffSnapshot.
     *
     * @return int Количество записанных строк снапшотов
     */
    public function applySnapshots(Integration $integration, array $snapshots): int
    {
        if (empty($snapshots)) {
            return 0;
        }

        try {
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
     * Именно эта колонка питает число КС на странице юнит-экономики
     * (getAverageWarehouseCoefficient в UnitEconomicsCacheService).
     *
     * КС берём из getWarehouseCoefficients() (карта «склад → коэффициент» из
     * /api/v1/tariffs/box) и матчим к строкам остатков по имени склада, т.к.
     * getInventory() поле warehouse_coefficient не отдаёт.
     *
     * @return int Количество обновлённых строк inventory_warehouses
     */
    public function refreshInventoryCoefficients(Integration $integration, ?object $marketplace = null): int
    {
        $marketplace ??= $this->resolveMarketplace($integration);
        if (! $marketplace || ! method_exists($marketplace, 'getWarehouseCoefficients')) {
            return 0;
        }

        return $this->applyInventoryCoefficients($integration, (array) $marketplace->getWarehouseCoefficients());
    }

    /**
     * Записать КС из готовой карты тарифов в inventory_warehouses интеграции.
     *
     * @param  array  $coefMap  Результат getWarehouseCoefficients(): [normName => ['delivery_coef'=>mult, 'warehouse_name'=>..]]
     * @return int Количество обновлённых строк inventory_warehouses
     */
    public function applyInventoryCoefficients(Integration $integration, array $coefMap): int
    {
        if (empty($coefMap)) {
            return 0;
        }

        try {
            // Карта «ключ имени склада → КС (множитель, 1.80 = 180%)». Кладём по
            // нижнерегистровому имени склада из box-тарифов — так совпадаем с
            // warehouse_name в inventory_warehouses (оба приходят из WB).
            $byName = [];
            foreach ($coefMap as $normalized => $row) {
                $coef = (float) ($row['delivery_coef'] ?? 0);
                if ($coef <= 0) {
                    continue;
                }
                $original = (string) ($row['warehouse_name'] ?? '');
                if ($original !== '') {
                    $byName[$this->nameKey($original)] = $coef;
                }
            }

            if (empty($byName)) {
                return 0;
            }

            $updated = 0;
            $names = InventoryWarehouse::where('integration_id', $integration->id)
                ->where('marketplace', 'wildberries')
                ->whereNotNull('warehouse_name')
                ->distinct()
                ->pluck('warehouse_name');

            foreach ($names as $name) {
                $coef = $byName[$this->nameKey((string) $name)] ?? null;
                if ($coef === null) {
                    continue;
                }

                $updated += InventoryWarehouse::where('integration_id', $integration->id)
                    ->where('marketplace', 'wildberries')
                    ->where('warehouse_name', $name)
                    ->update(['warehouse_coefficient' => $coef]);
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

    /** Ключ для сопоставления имён складов (регистронезависимо). */
    private function nameKey(string $name): string
    {
        return mb_strtolower(trim($name));
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
