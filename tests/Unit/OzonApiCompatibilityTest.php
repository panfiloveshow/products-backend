<?php

namespace Tests\Unit;

use App\Domains\Ozon\Api\FbsPostingsApi;
use App\Domains\Ozon\Api\OzonClient;
use App\Domains\Ozon\Api\ProductsApi;
use App\Domains\Ozon\Api\StockAnalyticsApi;
use App\Domains\Ozon\OzonMarketplace;
use App\Models\Integration;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OzonApiCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_connection_accepts_an_authenticated_empty_catalog(): void
    {
        Http::fake(['*/v3/product/list' => Http::response(['result' => ['items' => [], 'last_id' => '', 'total_items' => 0]])]);
        $marketplace = new OzonMarketplace(['client_id' => 'client', 'api_key' => 'key']);

        $this->assertTrue($marketplace->testConnection(new Integration));
        Http::assertSent(fn (Request $r) => $r['limit'] === 1);
    }

    public function test_connection_does_not_accept_a_permission_error_as_an_empty_catalog(): void
    {
        Http::fake(['*/v3/product/list' => Http::response(['message' => 'permission denied'], 403)]);
        $this->assertFalse((new OzonMarketplace(['client_id' => 'client', 'api_key' => 'key']))->testConnection(new Integration));
    }

    public function test_empty_queued_credentials_resolve_the_integration_credentials(): void
    {
        config(['services.ozon.client_id' => 'global-client', 'services.ozon.api_key' => 'global-key']);
        Http::fake(['*/v3/product/list' => Http::response(['result' => ['items' => [], 'last_id' => '', 'total_items' => 0]])]);
        $integration = \Mockery::mock(Integration::class)->makePartial();
        $integration->shouldReceive('resolveCredentials')->once()->andReturn(['client_id' => 'seller-client', 'api_key' => 'seller-key']);
        $this->assertTrue((new OzonMarketplace([], $integration))->testConnection($integration));
        Http::assertSent(fn (Request $r) => $r->hasHeader('Client-Id', 'seller-client') && $r->hasHeader('Api-Key', 'seller-key'));
    }

    public function test_product_list_uses_total_items_when_total_is_removed(): void
    {
        Http::fake(['*/v3/product/list' => Http::response(['result' => [
            'items' => [['product_id' => 101, 'offer_id' => 'A']], 'last_id' => 'next', 'total_items' => 150,
        ]])]);
        $result = (new ProductsApi(new OzonClient('client', 'key')))->getProducts();

        $this->assertSame(150, $result['total']);
        $this->assertTrue($result['complete']);
    }

    public function test_act_download_reads_the_post_pdf_body_without_json_decoding(): void
    {
        $pdf = "%PDF-1.7\nexample\n%%EOF";
        Http::fake(['*/v2/posting/fbs/act/get-pdf' => Http::response($pdf, 200, ['Content-Type' => 'application/pdf'])]);
        $result = (new FbsPostingsApi(new OzonClient('client', 'key')))->downloadAct(51);

        $this->assertSame($pdf, $result['content']);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['id'] === 51);
    }

    public function test_stock_analytics_uses_only_the_current_request_fields_and_native_filters(): void
    {
        Http::fake(['*/v1/analytics/stocks' => Http::response(['items' => [
            ['sku' => '123', 'warehouse_id' => 7, 'cluster_id' => 9],
        ]])]);
        $rows = (new StockAnalyticsApi(new OzonClient('client', 'key')))->getStockAnalytics(['123'], [7], [9]);

        $this->assertCount(1, $rows);
        Http::assertSent(fn (Request $r) => $r->data() === ['skus' => ['123'], 'warehouse_ids' => [7], 'cluster_ids' => [9]]);
    }

    public function test_a_thousand_stock_rows_do_not_repeat_the_same_non_paginated_request(): void
    {
        $rows = array_map(fn (int $i) => ['sku' => (string) (1 + (($i - 1) % 100)), 'warehouse_id' => (int) ceil($i / 100)], range(1, 1000));
        Http::fake(['*/v1/analytics/stocks' => Http::sequence()->push(['items' => $rows])->push(['message' => 'no second page'], 400)]);

        $result = (new StockAnalyticsApi(new OzonClient('client', 'key')))->getStockAnalytics(range(1, 100));

        $this->assertCount(1000, $result);
        Http::assertSentCount(1);
    }

    public function test_product_attributes_reads_the_native_result_array(): void
    {
        Http::fake(['*/v4/product/info/attributes' => Http::response(['result' => [['id' => 101, 'offer_id' => 'A']], 'total' => 1, 'last_id' => ''])]);
        $this->assertSame('A', (new ProductsApi(new OzonClient('client', 'key')))->getProductAttributes([101])[0]['offer_id']);
    }

    public function test_attribute_update_returns_the_root_task_id(): void
    {
        Http::fake(['*/v1/product/attributes/update' => Http::response(['task_id' => 101])]);
        $this->assertSame(101, (new ProductsApi(new OzonClient('client', 'key')))->updateAttributes([])['task_id']);
    }

    public function test_product_writes_do_not_report_http_errors_as_success(): void
    {
        Http::fake(['*' => Http::response(['message' => 'forbidden'], 403)]);
        $api = new ProductsApi(new OzonClient('client', 'key'));
        foreach (['importProducts', 'updatePrices', 'updateStocks', 'updateAttributes'] as $method) {
            $this->assertFalse($api->{$method}([])['success'], $method);
        }
    }

    public function test_binary_download_remembers_rate_limit_then_clears_it_on_success(): void
    {
        Http::fake(['*/v2/posting/fbs/act/get-pdf' => Http::sequence()
            ->push(['message' => 'limited'], 429, ['Retry-After' => '90'])
            ->push('%PDF-1.7', 200, ['Content-Type' => 'application/pdf'])]);
        $client = new OzonClient('client', 'key');
        $this->assertNull($client->download('/v2/posting/fbs/act/get-pdf', ['id' => 1]));
        $this->assertSame(429, $client->getLastResponseStatus());
        $this->assertSame(90, $client->getLastRateLimitRetryAfter());
        $this->assertSame('%PDF-1.7', $client->download('/v2/posting/fbs/act/get-pdf', ['id' => 1]));
        $this->assertSame(200, $client->getLastResponseStatus());
        $this->assertNull($client->getLastRateLimitRetryAfter());
    }
}
