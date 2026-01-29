<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Маппинг складов Ozon к кластерам доставки
 * 
 * @property int $id
 * @property string $warehouse_name
 * @property string $warehouse_name_normalized
 * @property int $cluster_id
 * @property string $cluster_name
 * @property string|null $region
 * @property bool $is_negabarit
 * @property bool $is_jewelry
 */
class OzonWarehouseCluster extends Model
{
    protected $table = 'ozon_warehouse_clusters';

    protected $fillable = [
        'warehouse_name',
        'warehouse_name_normalized',
        'cluster_id',
        'cluster_name',
        'region',
        'latitude',
        'longitude',
        'is_hub',
        'is_negabarit',
        'is_jewelry',
    ];

    protected $casts = [
        'cluster_id' => 'integer',
        'latitude' => 'decimal:6',
        'longitude' => 'decimal:6',
        'is_hub' => 'boolean',
        'is_negabarit' => 'boolean',
        'is_jewelry' => 'boolean',
    ];

    /**
     * Найти кластер по названию склада
     * 
     * @param string $warehouseName Название склада из Ozon API
     * @return static|null
     */
    public static function findByWarehouseName(string $warehouseName): ?self
    {
        $normalized = self::normalizeWarehouseName($warehouseName);
        
        return Cache::remember(
            "ozon_warehouse_cluster:{$normalized}",
            now()->addHours(24),
            fn() => self::where('warehouse_name_normalized', $normalized)->first()
        );
    }

    /**
     * Получить ID кластера по названию склада
     * 
     * @param string $warehouseName Название склада из Ozon API
     * @return int|null
     */
    public static function getClusterIdByWarehouse(string $warehouseName): ?int
    {
        $cluster = self::findByWarehouseName($warehouseName);
        return $cluster?->cluster_id;
    }

    /**
     * Получить название кластера по названию склада
     * 
     * @param string $warehouseName Название склада из Ozon API
     * @return string|null
     */
    public static function getClusterNameByWarehouse(string $warehouseName): ?string
    {
        $cluster = self::findByWarehouseName($warehouseName);
        return $cluster?->cluster_name;
    }

    /**
     * Получить все склады кластера
     * 
     * @param int $clusterId ID кластера
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getWarehousesByCluster(int $clusterId)
    {
        return Cache::remember(
            "ozon_cluster_warehouses:{$clusterId}",
            now()->addHours(24),
            fn() => self::where('cluster_id', $clusterId)->get()
        );
    }

    /**
     * Получить полный маппинг складов к кластерам
     * 
     * @return array<string, array{cluster_id: int, cluster_name: string, region: string|null, lat: float|null, lng: float|null, is_hub: bool|null}>
     */
    public static function getAllMapping(): array
    {
        return Cache::remember(
            'ozon_warehouse_clusters_mapping',
            now()->addHours(24),
            function () {
                $mapping = [];
                foreach (self::all() as $item) {
                    $isHub = $item->is_hub;
                    if ($isHub === null) {
                        $isHub = str_contains($item->warehouse_name_normalized, 'ХАБ');
                    }

                    $mapping[$item->warehouse_name_normalized] = [
                        'cluster_id' => $item->cluster_id,
                        'cluster_name' => $item->cluster_name,
                        'region' => $item->region,
                        'lat' => $item->latitude ? (float) $item->latitude : null,
                        'lng' => $item->longitude ? (float) $item->longitude : null,
                        'is_hub' => $isHub,
                    ];
                }
                return $mapping;
            }
        );
    }

    /**
     * Нормализация названия склада для поиска
     */
    public static function normalizeWarehouseName(string $name): string
    {
        // Приводим к верхнему регистру
        $normalized = mb_strtoupper($name);
        // Заменяем пробелы и дефисы на подчёркивания
        $normalized = str_replace([' ', '-'], '_', $normalized);
        // Убираем лишние подчёркивания
        $normalized = preg_replace('/_+/', '_', $normalized);
        return trim($normalized, '_');
    }

    /**
     * Очистить кэш маппинга
     */
    public static function clearCache(): void
    {
        Cache::forget('ozon_warehouse_clusters_mapping');
        // Очищаем кэш по паттерну (если поддерживается драйвером)
        // Для Redis: Cache::tags(['ozon_clusters'])->flush();
    }
}
