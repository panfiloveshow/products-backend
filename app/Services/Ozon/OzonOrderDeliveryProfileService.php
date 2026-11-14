<?php

namespace App\Services\Ozon;

/** Delivery/locality profiles from the same real-order source used by the UE cache. */
class OzonOrderDeliveryProfileService
{
    public function forIntegration(int $integrationId, int $periodDays = 28): array
    {
        return array_map($this->fromLocality(...), app(OzonLocalityService::class)->resolveIntegrationLocality($integrationId, $periodDays));
    }

    public function fromLocality(array $locality): array
    {
        $clusters = array_values($locality['clusters_summary'] ?? []);
        $sales = array_values($locality['sales_profile']['clusters'] ?? []);
        $stocks = array_values($locality['stock_profile'] ?? []);
        $routes = array_values($locality['shipping_routes'] ?? []);
        $orders = (int) ($locality['total_orders'] ?? 0);
        $hasOrders = $orders > 0 && $clusters !== [];
        $top = $hasOrders ? $clusters[0] : [];
        $topSales = $hasOrders ? ($sales[0] ?? []) : [];
        $rate = $hasOrders && isset($locality['locality_rate']) ? (float) $locality['locality_rate'] : null;

        return [
            'profile_source' => $hasOrders ? 'real_orders' : 'stock_only',
            'clusters_summary' => $hasOrders ? $clusters : [],
            'sales_profile' => $hasOrders ? $sales : [],
            'stock_profile' => $stocks,
            'route_resolution_status' => $routes !== [] ? 'resolved' : ($stocks !== [] ? 'estimated' : 'unknown'),
            'locality_resolution_status' => $rate !== null ? 'resolved' : 'unknown',
            'calculation_confidence' => $orders >= 10 ? 'high' : ($hasOrders ? 'medium' : 'low'),
            'dominant_cluster_id' => $topSales['cluster_id'] ?? null,
            'dominant_cluster_share' => isset($top['orders_percent']) ? (float) $top['orders_percent'] : null,
            'dominant_sales_cluster_id' => $topSales['cluster_id'] ?? null,
            'dominant_sales_cluster_share' => isset($topSales['sales_share_percent']) ? (float) $topSales['sales_share_percent'] : null,
            'expected_locality_rate' => $rate,
            // The supported order source does not observe forecast delivery time
            // or a current logistics quote. Let the existing tariff calculator resolve them.
            'weighted_logistics_cost' => null,
            'weighted_non_local_markup_percent' => null,
            'route_details' => ['shipping_routes' => $routes, 'demand_clusters' => $clusters,
                'stock_clusters' => $stocks, 'sales_clusters' => $sales],
        ];
    }
}
