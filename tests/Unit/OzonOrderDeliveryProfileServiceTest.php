<?php

namespace Tests\Unit;

use App\Services\Ozon\OzonOrderDeliveryProfileService;
use PHPUnit\Framework\TestCase;

class OzonOrderDeliveryProfileServiceTest extends TestCase
{
    public function test_real_orders_keep_their_clusters_and_locality_without_inventing_delivery_times_or_costs(): void
    {
        $input = ['clusters_summary' => [
            ['cluster_name' => 'Москва', 'orders_count' => 8, 'orders_percent' => 80],
            ['cluster_name' => 'Казань', 'orders_count' => 2, 'orders_percent' => 20],
        ], 'sales_profile' => ['clusters' => [['cluster_id' => '154', 'cluster_name' => 'Москва', 'sales_share_percent' => 80]]],
            'stock_profile' => [['cluster_name' => 'Казань', 'quantity' => 5]],
            'total_orders' => 10, 'locality_rate' => 20.0,
            'shipping_routes' => [['from' => 'Казань', 'total_orders' => 10]]];
        $profile = (new OzonOrderDeliveryProfileService)->fromLocality($input);
        $this->assertSame('real_orders', $profile['profile_source']);
        $this->assertSame($input['clusters_summary'], $profile['clusters_summary']);
        $this->assertSame(20.0, $profile['expected_locality_rate']);
        $this->assertSame('154', $profile['dominant_cluster_id']);
        $this->assertSame(80.0, $profile['dominant_cluster_share']);
        $this->assertSame('resolved', $profile['locality_resolution_status']);
        $this->assertNull($profile['weighted_logistics_cost']);
        $this->assertNull($profile['weighted_non_local_markup_percent']);
        $this->assertArrayNotHasKey('delivery_time_fbo', $profile);
    }

    public function test_stock_without_orders_is_not_reported_as_observed_demand_or_locality(): void
    {
        $profile = (new OzonOrderDeliveryProfileService)->fromLocality(['stock_profile' => [['cluster_name' => 'Москва', 'quantity' => 5]], 'total_orders' => 0]);
        $this->assertSame('stock_only', $profile['profile_source']);
        $this->assertSame('unknown', $profile['locality_resolution_status']);
        $this->assertNull($profile['expected_locality_rate']);
        $this->assertNull($profile['dominant_cluster_id']);
        $this->assertSame([], $profile['clusters_summary']);
    }
}
