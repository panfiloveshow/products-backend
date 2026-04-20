<?php

namespace App\Domains\Locality\Explainability;

use App\Models\InventoryHistory;
use App\Models\InventoryWarehouse;
use App\Models\OzonWarehouseCluster;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Возвращает снапшот стока SKU по кластерам на произвольную дату.
 * Источник приоритета: inventory_history → current inventory_warehouses (fallback).
 */
class OzonInventoryHistoryReader
{
    /**
     * @return array{
     *     by_cluster: array<string,int>,
     *     data_source: 'inventory_history'|'current_snapshot',
     * }
     */
    public function stockByClusterOnDate(int $integrationId, string $sku, Carbon $at): array
    {
        $rows = InventoryHistory::query()
            ->select(['warehouse_id', 'quantity'])
            ->where('sku', $sku)
            ->when(
                $this->historyHasIntegrationColumn(),
                fn ($q) => $q->where('integration_id', $integrationId)
            )
            ->whereDate('date', $at->toDateString())
            ->get();

        if ($rows->isNotEmpty()) {
            $byCluster = [];
            foreach ($rows as $row) {
                $clusterName = OzonWarehouseCluster::getClusterNameByWarehouse((string) $row->warehouse_id);
                if ($clusterName === null) {
                    continue;
                }
                $byCluster[$clusterName] = ($byCluster[$clusterName] ?? 0) + (int) ($row->quantity ?? 0);
            }

            return [
                'by_cluster' => $byCluster,
                'data_source' => 'inventory_history',
            ];
        }

        return [
            'by_cluster' => $this->currentStockByCluster($integrationId, $sku),
            'data_source' => 'current_snapshot',
        ];
    }

    /** @return array<string,int> */
    public function currentStockByCluster(int $integrationId, string $sku): array
    {
        $rows = DB::select(
            <<<'SQL'
                SELECT owc.cluster_name, SUM(iw.quantity) AS qty
                FROM inventory_warehouses iw
                JOIN ozon_warehouse_clusters owc ON UPPER(owc.warehouse_name) = UPPER(iw.warehouse_name)
                WHERE iw.integration_id = ? AND iw.sku = ? AND iw.quantity > 0
                GROUP BY owc.cluster_name
            SQL,
            [$integrationId, $sku]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row->cluster_name] = (int) $row->qty;
        }

        return $result;
    }

    private function historyHasIntegrationColumn(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        try {
            $cached = \Illuminate\Support\Facades\Schema::hasColumn('inventory_history', 'integration_id');
        } catch (\Throwable) {
            $cached = false;
        }

        return $cached;
    }
}
