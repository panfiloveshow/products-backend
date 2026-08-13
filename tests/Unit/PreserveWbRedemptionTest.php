<?php

namespace Tests\Unit;

use App\Jobs\SyncProductsJob;
use App\Models\Product;
use App\Models\SyncLog;
use PHPUnit\Framework\TestCase;

class PreserveWbRedemptionTest extends TestCase
{
    private function invoke(Product $existing, array $updateData): array
    {
        $job = new SyncProductsJob(new SyncLog);
        $method = new \ReflectionMethod($job, 'preserveWbRedemption');

        return $method->invoke($job, $existing, $updateData);
    }

    public function test_keeps_old_funnel_redemption_when_new_sync_has_none(): void
    {
        $existing = new Product;
        $existing->wb_data = [
            'redemption_rate' => 73.5,
            'redemption_orders_count' => 40,
            'redemption_buyouts_count' => 29,
            'redemption_source' => 'wb_sales_funnel',
            'redemption_observed_at' => '2026-08-01T00:00:00+00:00',
        ];

        $result = $this->invoke($existing, ['wb_data' => ['redemption_rate' => null, 'nmID' => 123]]);

        $this->assertSame(73.5, $result['wb_data']['redemption_rate']);
        $this->assertSame('wb_sales_funnel', $result['wb_data']['redemption_source']);
        $this->assertSame('2026-08-01T00:00:00+00:00', $result['wb_data']['redemption_observed_at']);
        $this->assertSame(123, $result['wb_data']['nmID']);
    }

    public function test_fresh_funnel_data_wins_over_old(): void
    {
        $existing = new Product;
        $existing->wb_data = ['redemption_rate' => 73.5, 'redemption_source' => 'wb_sales_funnel'];

        $result = $this->invoke($existing, ['wb_data' => ['redemption_rate' => 91.0, 'redemption_source' => 'wb_sales_funnel']]);

        $this->assertSame(91.0, $result['wb_data']['redemption_rate']);
    }

    public function test_no_old_data_leaves_update_untouched(): void
    {
        $existing = new Product;
        $existing->wb_data = ['nmID' => 123];

        $result = $this->invoke($existing, ['wb_data' => ['redemption_rate' => null]]);

        $this->assertNull($result['wb_data']['redemption_rate']);
        $this->assertArrayNotHasKey('redemption_source', $result['wb_data']);
    }
}
