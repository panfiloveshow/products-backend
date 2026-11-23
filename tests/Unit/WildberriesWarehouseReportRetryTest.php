<?php

namespace Tests\Unit;

use App\Domains\Wildberries\Api\InventoryApi;
use App\Domains\Wildberries\Api\ProductsApi;
use App\Domains\Wildberries\Api\WildberriesClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WildberriesWarehouseReportRetryTest extends TestCase
{
    public function test_a_report_cooldown_does_not_refetch_cards_or_attempt_a_partial_fbs_sync(): void
    {
        $client = \Mockery::mock(WildberriesClient::class, ['cooldown-key'])->makePartial();
        $client->shouldReceive('analyticsPost')->andReturn(null);
        $client->shouldReceive('analyticsGet')->once()->andReturn(null);
        $client->shouldReceive('getLastResponseStatus')->andReturn(429);
        $client->shouldReceive('getLastRateLimitRetryAfter')->andReturn(900);
        $client->shouldNotReceive('get');
        $products = \Mockery::mock(ProductsApi::class);
        $products->shouldNotReceive('getProducts');
        $api = new InventoryApi($client, $products);

        $this->assertSame([], $api->getStocks());
        $this->assertSame(900, $api->getDeferredRetryAfter());
    }

    public function test_a_rate_limited_status_check_resumes_the_same_report_on_the_next_attempt(): void
    {
        Http::preventStrayRequests();
        $created = $statusReads = 0;
        $readyAt = now()->addSeconds(900)->timestamp;
        $request = function (string $endpoint, object $state) use (&$created, &$statusReads, $readyAt): ?array {
            if ($endpoint === '/api/v1/warehouse_remains') {
                $created++;
                return ['data' => ['taskId' => 'existing-report']];
            }
            if (str_ends_with($endpoint, '/status')) {
                $statusReads++;
                if (now()->timestamp < $readyAt) {
                    if ($statusReads > 1) {
                        throw new \RuntimeException('Polling before Retry-After is forbidden');
                    }
                    $state->status = 429;
                    $state->retry = 900;
                    return null;
                }
                return ['data' => ['status' => 'done']];
            }
            return [['barcode' => 'BAR-1', 'warehouses' => [['warehouseName' => 'Test WB', 'quantity' => 7]]]];
        };
        $first = $this->api('seller-key', $request);
        $this->assertSame([], $first->getWbWarehousesStocksReport());
        $this->assertSame(15, $first->getDeferredRetryAfter());
        $this->travel(16)->seconds();
        $poll = $this->api('seller-key', $request);
        $this->assertSame([], $poll->getWbWarehousesStocksReport());
        $this->assertSame(900, $poll->getDeferredRetryAfter());

        $this->travel(901)->seconds();
        $rows = $this->api('seller-key', $request)->getWbWarehousesStocksReport();
        $this->assertCount(1, $rows);
        $this->assertSame('BAR-1', $rows[0]['barcode']);
        $this->assertSame(7, $rows[0]['quantity']);
        $this->assertSame(1, $created, 'A queued retry must not create another report.');
    }

    public function test_a_rate_limited_download_retries_the_download_without_recreating_or_repolling(): void
    {
        $created = $statusReads = $downloads = 0;
        $request = function (string $endpoint, object $state) use (&$created, &$statusReads, &$downloads): ?array {
            if ($endpoint === '/api/v1/warehouse_remains') {
                $created++;
                return ['data' => ['taskId' => 'ready-report']];
            }
            if (str_ends_with($endpoint, '/status')) {
                $statusReads++;
                return ['data' => ['status' => 'done']];
            }
            $downloads++;
            if ($downloads === 1) {
                $state->status = 429;
                $state->retry = 120;
                return null;
            }
            return [['barcode' => 'BAR-2', 'warehouses' => [['warehouseName' => 'Test WB', 'quantity' => 3]]]];
        };
        $first = $this->api('download-key', $request);
        $this->assertSame([], $first->getWbWarehousesStocksReport());
        $this->assertSame(15, $first->getDeferredRetryAfter());
        $this->travel(16)->seconds();
        $poll = $this->api('download-key', $request);
        $this->assertSame([], $poll->getWbWarehousesStocksReport());
        $this->assertSame(120, $poll->getDeferredRetryAfter());
        $this->travel(121)->seconds();
        $this->assertCount(1, $this->api('download-key', $request)->getWbWarehousesStocksReport());
        $this->assertSame(1, $created);
        $this->assertSame(1, $statusReads);
        $this->assertSame(2, $downloads);
    }

    public function test_report_creation_cooldown_is_shared_by_retries_but_not_other_keys(): void
    {
        $created = 0;
        $request = function (string $endpoint, object $state) use (&$created): ?array {
            $created++;
            $state->status = 429;
            $state->retry = 900;
            return null;
        };
        $first = $this->api('key-one', $request);
        $this->assertSame([], $first->getWbWarehousesStocksReport());
        $this->assertSame(900, $first->getDeferredRetryAfter());
        $this->api('key-one', $request)->getWbWarehousesStocksReport();
        $this->assertSame(1, $created);
        $this->api('key-two', $request)->getWbWarehousesStocksReport();
        $this->assertSame(2, $created);
    }

    private function api(string $key, callable $request): InventoryApi
    {
        $state = (object) ['status' => 200, 'retry' => null];
        $client = \Mockery::mock(WildberriesClient::class, [$key])->makePartial();
        $client->shouldReceive('analyticsPost')->andReturnUsing(function () use ($state): ?array {
            $state->status = 403;
            return null;
        });
        $client->shouldReceive('analyticsGet')->andReturnUsing(function (string $endpoint) use ($state, $request): ?array {
            $state->status = 200;
            $state->retry = null;
            return $request($endpoint, $state);
        });
        $client->shouldReceive('getLastResponseStatus')->andReturnUsing(fn () => $state->status);
        $client->shouldReceive('getLastRateLimitRetryAfter')->andReturnUsing(fn () => $state->retry);
        $products = \Mockery::mock(ProductsApi::class);
        $products->shouldReceive('getProducts')->andReturn(['cards' => [], 'cursor' => null]);

        return new InventoryApi($client, $products);
    }
}
