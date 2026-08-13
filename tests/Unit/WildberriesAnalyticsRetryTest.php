<?php

namespace Tests\Unit;

use App\Domains\Wildberries\Api\WildberriesClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WildberriesAnalyticsRetryTest extends TestCase
{
    public function test_sales_funnel_retries_429_using_rate_limit_header(): void
    {
        Http::fake([
            'seller-analytics-api.wildberries.ru/*' => Http::sequence()
                ->push(['detail' => 'rate limit exceeded'], 429, ['X-RateLimit-Retry' => '1'])
                ->push(['data' => ['products' => []]], 200),
        ]);

        $client = new WildberriesClient('test-key');
        $response = $client->analyticsPost('/api/analytics/v3/sales-funnel/products', []);

        $this->assertSame(['data' => ['products' => []]], $response);
        Http::assertSentCount(2);
    }

    public function test_429_without_retry_header_gives_up_for_sales_funnel(): void
    {
        Http::fake([
            'seller-analytics-api.wildberries.ru/*' => Http::response(['detail' => 'rate limit exceeded'], 429),
        ]);

        $client = new WildberriesClient('test-key');

        $this->assertNull($client->analyticsPost('/api/analytics/v3/sales-funnel/products', []));
        Http::assertSentCount(1);
    }

    public function test_429_with_huge_retry_header_does_not_block_worker(): void
    {
        Http::fake([
            'seller-analytics-api.wildberries.ru/*' => Http::response(
                ['detail' => 'rate limit exceeded'], 429, ['X-RateLimit-Retry' => '3600']
            ),
        ]);

        $client = new WildberriesClient('test-key');

        $this->assertNull($client->analyticsPost('/api/analytics/v3/sales-funnel/products', []));
        Http::assertSentCount(1);
    }
}
