<?php

namespace App\Domains\Locality\Recommendation;

use Illuminate\Support\Facades\DB;

/**
 * on_hand из inventory_warehouses + in_transit из supplies per (sku, cluster).
 */
class ClusterStockAggregator
{
    /**
     * @return array<string, array<string, array{on_hand:int, in_transit:int}>>
     *  keyed by sku → cluster_name
     */
    public function byIntegration(int $integrationId): array
    {
        $onHand = $this->queryOnHand($integrationId);
        $inTransit = $this->queryInTransit($integrationId);

        $result = [];
        foreach ($onHand as $row) {
            $result[(string) $row->sku][(string) $row->cluster_name] = [
                'on_hand' => (int) $row->qty,
                'in_transit' => 0,
            ];
        }
        foreach ($inTransit as $row) {
            $sku = (string) $row->sku;
            $cluster = (string) $row->cluster_name;
            if (! isset($result[$sku][$cluster])) {
                $result[$sku][$cluster] = ['on_hand' => 0, 'in_transit' => 0];
            }
            $result[$sku][$cluster]['in_transit'] = (int) $row->qty;
        }

        return $result;
    }

    private function queryOnHand(int $integrationId): array
    {
        return DB::select(
            <<<'SQL'
                SELECT iw.sku, owc.cluster_name, SUM(iw.quantity) AS qty
                FROM inventory_warehouses iw
                JOIN ozon_warehouse_clusters owc ON UPPER(owc.warehouse_name) = UPPER(iw.warehouse_name)
                WHERE iw.integration_id = ? AND iw.quantity > 0
                GROUP BY iw.sku, owc.cluster_name
            SQL,
            [$integrationId]
        );
    }

    private function queryInTransit(int $integrationId): array
    {
        // NB: supplies + supply_items pending в пути — упрощённая эвристика.
        // Берём все supplies со статусами "в доставке" и их items, маппим на cluster по warehouse_name.
        try {
            return DB::select(
                <<<'SQL'
                    SELECT si.sku, owc.cluster_name, SUM(si.quantity) AS qty
                    FROM supplies s
                    JOIN supply_items si ON si.supply_id = s.id
                    LEFT JOIN ozon_warehouse_clusters owc
                        ON UPPER(owc.warehouse_name) = UPPER(s.warehouse_name)
                    WHERE s.integration_id = ?
                        AND s.status IN ('draft','awaiting_confirmation','confirmed','in_transit','at_warehouse','accepting')
                    GROUP BY si.sku, owc.cluster_name
                SQL,
                [$integrationId]
            );
        } catch (\Throwable) {
            return [];
        }
    }
}
