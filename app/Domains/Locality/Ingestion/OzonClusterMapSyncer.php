<?php

namespace App\Domains\Locality\Ingestion;

use App\Domains\Ozon\Api\OzonClient;
use App\Domains\Ozon\Api\SuppliesApi;
use App\Models\Integration;
use App\Models\OzonWarehouseCluster;
use Illuminate\Support\Facades\Log;

/**
 * Обновляет справочник ozon_warehouse_clusters на основе /v1/cluster/list и /v1/warehouse/fbo/list.
 *
 * Не удаляет существующих записей (чтобы не потерять ручной сидинг), только upsert по normalized name.
 * Также подсасывает logistic_clusters (вложенный макроуровень) и помечает источник.
 */
class OzonClusterMapSyncer
{
    public function syncForIntegration(Integration $integration): SyncResult
    {
        $api = new SuppliesApi(OzonClient::fromIntegration($integration));
        $clusters = $api->getClusters();

        Log::channel('locality')->info('OzonClusterMapSyncer fetched', [
            'integration_id' => $integration->id,
            'clusters_count' => count($clusters),
        ]);

        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($clusters as $cluster) {
            $clusterId = (int) ($cluster['id'] ?? 0);
            $clusterName = (string) ($cluster['name'] ?? '');
            if ($clusterId === 0 || $clusterName === '') {
                $skipped++;
                continue;
            }

            $logisticClusters = $cluster['logistic_clusters'] ?? [];

            $warehouses = $this->extractWarehouses($cluster);
            foreach ($warehouses as $wh) {
                $warehouseName = (string) ($wh['name'] ?? '');
                if ($warehouseName === '') {
                    $skipped++;
                    continue;
                }

                $normalized = OzonWarehouseCluster::normalizeWarehouseName($warehouseName);
                $existing = OzonWarehouseCluster::where('warehouse_name_normalized', $normalized)->first();

                $payload = [
                    'warehouse_name' => $warehouseName,
                    'warehouse_name_normalized' => $normalized,
                    'cluster_id' => $clusterId,
                    'cluster_name' => $clusterName,
                    'region' => $wh['region'] ?? null,
                    'is_negabarit' => (bool) ($wh['is_negabarit'] ?? false),
                    'is_jewelry' => (bool) ($wh['is_jewelry'] ?? false),
                    'ozon_source' => 'cluster_list_api',
                    'last_refreshed_at' => now(),
                    'logistic_clusters' => $logisticClusters ?: null,
                ];

                if ($existing === null) {
                    OzonWarehouseCluster::query()->create($payload);
                    $inserted++;
                } else {
                    $existing->fill($payload);
                    if ($existing->isDirty()) {
                        $existing->save();
                        $updated++;
                    } else {
                        $skipped++;
                    }
                }
            }
        }

        OzonWarehouseCluster::clearCache();

        Log::channel('locality')->info('OzonClusterMapSyncer completed', [
            'integration_id' => $integration->id,
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
        ]);

        return new SyncResult($inserted, $updated, $skipped);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function extractWarehouses(array $cluster): array
    {
        $result = [];

        foreach ($cluster['logistic_clusters'] ?? [] as $logisticCluster) {
            foreach ($logisticCluster['warehouses'] ?? [] as $wh) {
                $result[] = $this->normalizeWarehouseRow($wh);
            }
        }

        foreach ($cluster['warehouses'] ?? [] as $wh) {
            $result[] = $this->normalizeWarehouseRow($wh);
        }

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeWarehouseRow(array $wh): array
    {
        return [
            'name' => $wh['name'] ?? $wh['warehouse_name'] ?? '',
            'region' => $wh['region'] ?? ($wh['address']['region'] ?? null),
            'is_negabarit' => $wh['is_negabarit'] ?? str_contains(mb_strtoupper((string) ($wh['name'] ?? '')), 'НЕГАБАРИТ'),
            'is_jewelry' => $wh['is_jewelry'] ?? str_contains(mb_strtoupper((string) ($wh['name'] ?? '')), 'ЮВЕЛИРН'),
        ];
    }
}
