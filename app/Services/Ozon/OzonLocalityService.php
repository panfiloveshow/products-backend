<?php

namespace App\Services\Ozon;

use App\Models\InventoryWarehouse;
use App\Models\OzonWarehouseCluster;
use App\Models\Posting;
use Illuminate\Support\Facades\DB;

/**
 * Рассчитывает per-SKU locality для Ozon на основе реальных данных:
 * - Остатки по складам → кластеры хранения (stockProfile)
 * - Заказы за 30 дней → кластеры спроса (clustersSummary)
 * - Маршруты отгрузок → % локальных продаж
 */
class OzonLocalityService
{
    private ?array $clusterIdByName = null;

    /**
     * Кластеры спроса для конкретного SKU из реальных заказов.
     * Формат совместим с OzonUnitEconomicsCalculator->resolveWeightedProfileMetrics().
     *
     * @return array{clusters_summary: array, sales_profile: array, stock_profile: array, locality_rate: float, total_orders: int}
     */
    public function resolveSkuLocality(int $integrationId, string $sku, int $periodDays = 30): array
    {
        $demandClusters = $this->buildDemandClusters($integrationId, $sku, $periodDays);
        $stockClusters = $this->buildStockClusters($integrationId, $sku);
        $localityRate = $this->calculateLocalityRate($integrationId, $sku, $periodDays);
        $totalOrders = array_sum(array_column($demandClusters, 'orders_count'));

        return [
            'clusters_summary' => $demandClusters,
            'sales_profile' => $this->buildSalesProfile($demandClusters),
            'stock_profile' => $stockClusters,
            'locality_rate' => $localityRate,
            'total_orders' => $totalOrders,
        ];
    }

    /**
     * Batch: кластеры спроса для всех SKU интеграции.
     *
     * @return array<string, array> keyed by SKU
     */
    public function resolveIntegrationLocality(int $integrationId, int $periodDays = 30): array
    {
        $demand = $this->buildDemandClustersForIntegration($integrationId, $periodDays);
        $stock = $this->buildStockClustersForIntegration($integrationId);
        $locality = $this->calculateLocalityRateForIntegration($integrationId, $periodDays);
        $routes = $this->buildShippingRoutesForIntegration($integrationId, $periodDays);

        $allSkus = array_unique(array_merge(array_keys($demand), array_keys($stock)));
        $result = [];

        foreach ($allSkus as $sku) {
            $demandClusters = $demand[$sku] ?? [];
            $result[$sku] = [
                'clusters_summary' => $demandClusters,
                'sales_profile' => $this->buildSalesProfile($demandClusters),
                'stock_profile' => $stock[$sku] ?? [],
                'locality_rate' => $locality[$sku] ?? null,
                'total_orders' => array_sum(array_column($demandClusters, 'orders_count')),
                'shipping_routes' => $routes[$sku] ?? [],
            ];
        }

        return $result;
    }

    /**
     * Количество FBO заказов за 7 дней для всей интеграции (seller-level).
     * Правило Ozon: наценка не применяется если < 50 заказов FBO за 7 дней.
     */
    public function countSellerFboOrders7Days(int $integrationId): int
    {
        $postingCount = Posting::query()
            ->where('integration_id', (string) $integrationId)
            ->where('marketplace', 'ozon')
            ->whereRaw('LOWER(delivery_type) = ?', ['fbo'])
            ->where('created_at', '>', now()->subDays(7))
            ->count();

        if ($postingCount > 0) {
            return $postingCount;
        }

        // Fallback на inventory, если postings ещё не синхронизированы.
        return (int) InventoryWarehouse::query()
            ->where('integration_id', $integrationId)
            ->where('marketplace', 'ozon')
            ->where(function ($query): void {
                $query->where('fulfillment_type', 'FBO')
                    ->orWhere('fulfillment_type', 'fbo');
            })
            ->sum('sales_7_days');
    }

    /**
     * Маршруты отгрузок per-SKU: из какого кластера куда отправляется.
     * Формат: [{ from: "Казань", destinations: [{ to: "Москва", count: 15 }, ...] }]
     *
     * @return array<string, array>  keyed by SKU
     */
    public function buildShippingRoutesForIntegration(int $integrationId, int $periodDays = 30): array
    {
        $rows = DB::select("
            SELECT
                pi.offer_id as sku,
                p.financial_data->>'cluster_from' as cluster_from,
                p.financial_data->>'cluster_to' as cluster_to,
                count(*) as cnt
            FROM postings p
            JOIN posting_items pi ON pi.posting_id = p.id
            WHERE p.integration_id = ?::text
                AND p.created_at > now() - make_interval(days => ?)
                AND p.financial_data->>'cluster_from' IS NOT NULL
                AND p.financial_data->>'cluster_to' IS NOT NULL
            GROUP BY pi.offer_id, cluster_from, cluster_to
            ORDER BY pi.offer_id, cluster_from, cnt DESC
        ", [$integrationId, $periodDays]);

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->sku][$row->cluster_from][] = [
                'to' => $row->cluster_to,
                'count' => (int) $row->cnt,
            ];
        }

        $result = [];
        foreach ($grouped as $sku => $fromClusters) {
            $routes = [];
            foreach ($fromClusters as $from => $destinations) {
                $totalFromCluster = array_sum(array_column($destinations, 'count'));
                $routes[] = [
                    'from' => $from,
                    'total_orders' => $totalFromCluster,
                    'destinations' => $destinations,
                ];
            }
            usort($routes, fn ($a, $b) => $b['total_orders'] <=> $a['total_orders']);
            $result[$sku] = $routes;
        }

        return $result;
    }

    /**
     * Куда продаётся SKU: кластеры назначения (cluster_to) из postings.
     */
    private function buildDemandClusters(int $integrationId, string $sku, int $periodDays): array
    {
        $rows = DB::select("
            SELECT
                p.financial_data->>'cluster_to' as cluster_name,
                count(*) as orders_count
            FROM postings p
            WHERE p.integration_id = ?::text
                AND p.created_at > now() - make_interval(days => ?)
                AND p.financial_data->>'cluster_to' IS NOT NULL
                AND EXISTS (
                    SELECT 1 FROM posting_items pi
                    WHERE pi.posting_id = p.id AND pi.offer_id = ?
                )
            GROUP BY cluster_name
            ORDER BY orders_count DESC
        ", [$integrationId, $periodDays, $sku]);

        return $this->formatDemandClusters($rows);
    }

    /**
     * Batch: кластеры спроса для всех SKU интеграции.
     *
     * @return array<string, array>
     */
    private function buildDemandClustersForIntegration(int $integrationId, int $periodDays): array
    {
        $rows = DB::select("
            SELECT
                pi.offer_id as sku,
                p.financial_data->>'cluster_to' as cluster_name,
                count(*) as orders_count
            FROM postings p
            JOIN posting_items pi ON pi.posting_id = p.id
            WHERE p.integration_id = ?::text
                AND p.created_at > now() - make_interval(days => ?)
                AND p.financial_data->>'cluster_to' IS NOT NULL
            GROUP BY pi.offer_id, cluster_name
            ORDER BY pi.offer_id, orders_count DESC
        ", [$integrationId, $periodDays]);

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->sku][] = $row;
        }

        $result = [];
        foreach ($grouped as $sku => $clusterRows) {
            $result[$sku] = $this->formatDemandClusters($clusterRows);
        }

        return $result;
    }

    private function formatDemandClusters(array $rows): array
    {
        $totalOrders = array_sum(array_column($rows, 'orders_count'));
        if ($totalOrders === 0) {
            return [];
        }

        return array_map(fn ($row) => [
            'cluster_name' => $row->cluster_name,
            'orders_count' => (int) $row->orders_count,
            'orders_percent' => round(($row->orders_count / $totalOrders) * 100, 2),
        ], $rows);
    }

    private function buildSalesProfile(array $demandClusters): array
    {
        return [
            'clusters' => array_map(function (array $cluster): array {
                return [
                    'cluster_id' => $this->resolveClusterIdByName($cluster['cluster_name'] ?? null),
                    'cluster_name' => $cluster['cluster_name'] ?? null,
                    'sales_30_days' => (int) ($cluster['orders_count'] ?? 0),
                    'sales_share_percent' => isset($cluster['orders_percent'])
                        ? (float) $cluster['orders_percent']
                        : null,
                ];
            }, $demandClusters),
        ];
    }

    private function resolveClusterIdByName(?string $clusterName): ?string
    {
        $normalized = $this->normalizeClusterName($clusterName);
        if ($normalized === null) {
            return null;
        }

        if ($this->clusterIdByName === null) {
            $this->clusterIdByName = OzonWarehouseCluster::query()
                ->select('cluster_id', 'cluster_name')
                ->get()
                ->mapWithKeys(fn (OzonWarehouseCluster $cluster) => [
                    $this->normalizeClusterName($cluster->cluster_name) => (string) $cluster->cluster_id,
                ])
                ->filter()
                ->all();
        }

        return $this->clusterIdByName[$normalized] ?? null;
    }

    private function normalizeClusterName(?string $clusterName): ?string
    {
        if ($clusterName === null) {
            return null;
        }

        $normalized = mb_strtolower(trim($clusterName));

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Где хранится SKU: остатки по складам → кластеры.
     */
    private function buildStockClusters(int $integrationId, string $sku): array
    {
        $rows = DB::select("
            SELECT
                owc.cluster_name,
                sum(iw.quantity) as qty
            FROM inventory_warehouses iw
            JOIN ozon_warehouse_clusters owc
                ON UPPER(owc.warehouse_name) = UPPER(iw.warehouse_name)
            WHERE iw.integration_id = ?
                AND iw.sku = ?
                AND iw.quantity > 0
            GROUP BY owc.cluster_name
            ORDER BY qty DESC
        ", [$integrationId, $sku]);

        return $this->formatStockClusters($rows);
    }

    /**
     * Batch: кластеры остатков для всех SKU интеграции.
     *
     * @return array<string, array>
     */
    private function buildStockClustersForIntegration(int $integrationId): array
    {
        $rows = DB::select("
            SELECT
                iw.sku,
                owc.cluster_name,
                sum(iw.quantity) as qty
            FROM inventory_warehouses iw
            JOIN ozon_warehouse_clusters owc
                ON UPPER(owc.warehouse_name) = UPPER(iw.warehouse_name)
            WHERE iw.integration_id = ?
                AND iw.quantity > 0
            GROUP BY iw.sku, owc.cluster_name
            ORDER BY iw.sku, qty DESC
        ", [$integrationId]);

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->sku][] = $row;
        }

        $result = [];
        foreach ($grouped as $sku => $clusterRows) {
            $result[$sku] = $this->formatStockClusters($clusterRows);
        }

        return $result;
    }

    private function formatStockClusters(array $rows): array
    {
        $totalQty = array_sum(array_column($rows, 'qty'));
        if ($totalQty === 0) {
            return [];
        }

        return array_map(fn ($row) => [
            'cluster_name' => $row->cluster_name,
            'quantity' => (int) $row->qty,
            'share_percent' => round(($row->qty / $totalQty) * 100, 2),
        ], $rows);
    }

    /**
     * % локальных продаж: cluster_from == cluster_to.
     */
    private function calculateLocalityRate(int $integrationId, string $sku, int $periodDays): ?float
    {
        $row = DB::selectOne("
            SELECT
                count(*) as total,
                count(*) FILTER (
                    WHERE p.financial_data->>'cluster_from' = p.financial_data->>'cluster_to'
                ) as local_count
            FROM postings p
            WHERE p.integration_id = ?::text
                AND p.created_at > now() - make_interval(days => ?)
                AND p.financial_data->>'cluster_from' IS NOT NULL
                AND EXISTS (
                    SELECT 1 FROM posting_items pi
                    WHERE pi.posting_id = p.id AND pi.offer_id = ?
                )
        ", [$integrationId, $periodDays, $sku]);

        if (! $row || $row->total == 0) {
            return null;
        }

        return round(($row->local_count / $row->total) * 100, 2);
    }

    /**
     * Batch: % локальных продаж для всех SKU интеграции.
     *
     * @return array<string, float|null>
     */
    private function calculateLocalityRateForIntegration(int $integrationId, int $periodDays): array
    {
        $rows = DB::select("
            SELECT
                pi.offer_id as sku,
                count(*) as total,
                count(*) FILTER (
                    WHERE p.financial_data->>'cluster_from' = p.financial_data->>'cluster_to'
                ) as local_count
            FROM postings p
            JOIN posting_items pi ON pi.posting_id = p.id
            WHERE p.integration_id = ?::text
                AND p.created_at > now() - make_interval(days => ?)
                AND p.financial_data->>'cluster_from' IS NOT NULL
            GROUP BY pi.offer_id
        ", [$integrationId, $periodDays]);

        $result = [];
        foreach ($rows as $row) {
            $result[$row->sku] = $row->total > 0
                ? round(($row->local_count / $row->total) * 100, 2)
                : null;
        }

        return $result;
    }
}
