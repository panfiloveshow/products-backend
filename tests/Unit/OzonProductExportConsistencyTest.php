<?php

namespace Tests\Unit;

use App\Domains\Ozon\Api\OzonClient;
use App\Domains\Ozon\Api\ProductsApi;
use PHPUnit\Framework\TestCase;

class OzonProductExportConsistencyTest extends TestCase
{
    private function client(): OzonClient
    {
        return new class extends OzonClient {
            public array $calls = [];
            public string $mode = 'ok';
            public function __construct() {}
            public function post(string $endpoint, array $data = [], bool $forceObject = false, bool $retryRateLimit = true): ?array
            {
                $this->calls[] = [$endpoint, $data];
                if ($endpoint === '/v3/product/import') return ['result' => $this->mode === 'no_task' ? [] : ['task_id' => count($this->calls)]];
                if ($endpoint === '/v1/product/import/prices' && $this->mode === 'lost_price') return ['_error' => true, '_sellico_price_dispatch' => ['status' => 'submit_unknown']];
                $kind = $endpoint === '/v1/product/import/prices' ? 'prices' : 'stocks';
                return ['result' => array_map(function ($row) use ($kind) {
                    $failed = $kind === 'prices' && $this->mode === 'reject_price';
                    $result = ['offer_id' => $row['offer_id'], 'updated' => ! $failed, 'errors' => $failed ? [['code' => 'PRICE_REJECTED']] : []];
                    if ($kind === 'stocks') $result['warehouse_id'] = $row['warehouse_id'];
                    if ($this->mode === 'wrong_scope' && $kind === 'prices') $result['offer_id'] = 'other-offer';
                    return $result;
                }, $data[$kind])];
            }
        };
    }

    private function product(string $offer = 'isolated-offer'): array
    {
        return ['offer_id' => $offer, 'name' => 'Explicit isolated product', 'price' => '123.45', 'currency_code' => 'RUB'];
    }

    public function test_missing_or_invalid_explicit_money_never_starts_an_import(): void
    {
        $client = $this->client(); $api = new ProductsApi($client);
        foreach (['missing_price', 'missing_currency', 'null_price', 'zero_price', 'negative_price', 'fractional_stock', 'null_old_price', 'duplicate_offer'] as $case) {
            $p = $this->product();
            if ($case === 'missing_price') unset($p['price']);
            if ($case === 'missing_currency') unset($p['currency_code']);
            if ($case === 'null_price') $p['price'] = null;
            if ($case === 'zero_price') $p['price'] = '0.00';
            if ($case === 'negative_price') $p['price'] = '-1';
            if ($case === 'fractional_stock') $p['stock'] = 1.5;
            if ($case === 'null_old_price') $p['old_price'] = null;
            $result = $api->exportProducts($case === 'duplicate_offer' ? [$p, $p] : [$p], 301);
            $this->assertFalse($result['success'], $case);
            $this->assertSame('not_dispatched', $result['status'], $case);
        }
        $this->assertSame([], $client->calls);
    }

    public function test_omitted_old_price_and_stock_are_not_replaced_by_zero(): void
    {
        $client = $this->client(); $result = (new ProductsApi($client))->exportProducts([$this->product()], 301);
        $this->assertTrue($result['success']);
        $this->assertSame('accepted', $result['status']);
        $this->assertFalse($result['completion_verified']);
        $this->assertNull($result['imported']);
        $this->assertSame(1, $result['accepted_for_import']);
        $this->assertCount(2, $client->calls);
        $price = $client->calls[1][1]['prices'][0];
        $this->assertSame('123.45', $price['price']);
        $this->assertArrayNotHasKey('old_price', $price);
        $this->assertArrayNotHasKey('stocks', $result['steps']);
    }

    public function test_explicit_zero_old_price_and_stock_remain_explicit(): void
    {
        $client = $this->client(); $p = $this->product(); $p['old_price'] = '0'; $p['stock'] = 0;
        $result = (new ProductsApi($client))->exportProduct($p, 301);
        $this->assertTrue($result['success']);
        $this->assertSame('0', $client->calls[1][1]['prices'][0]['old_price']);
        $this->assertSame(0, $client->calls[2][1]['stocks'][0]['stock']);
        $this->assertSame('accepted', $result['steps']['stocks']['status']);
    }

    public function test_an_explicit_price_rejection_stops_remaining_exports_and_is_partial(): void
    {
        $client = $this->client(); $client->mode = 'reject_price'; $products = [];
        for ($i = 0; $i < 101; $i++) $products[] = $this->product('isolated-'.$i) + ['stock' => 5];
        $result = (new ProductsApi($client))->exportProducts($products, 301);
        $this->assertFalse($result['success']); $this->assertSame('partial', $result['status']);
        $this->assertSame(100, $result['accepted_for_import']);
        $this->assertCount(2, $client->calls);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_unknown_or_wrong_item_receipts_are_not_reported_as_success_or_retried(): void
    {
        foreach (['lost_price', 'wrong_scope', 'no_task'] as $mode) {
            $client = $this->client(); $client->mode = $mode;
            $result = (new ProductsApi($client))->exportProduct($this->product() + ['stock' => 5], 301);
            $this->assertFalse($result['success']); $this->assertSame('unknown', $result['status']);
            $this->assertCount($mode === 'no_task' ? 1 : 2, $client->calls);
            $this->assertArrayNotHasKey('stocks', $result['steps']);
        }
    }

    public function test_an_explicit_stock_without_a_warehouse_does_not_start_a_partial_export(): void
    {
        $client = $this->client();
        $result = (new ProductsApi($client))->exportProduct($this->product() + ['stock' => 5]);
        $this->assertFalse($result['success']); $this->assertSame('not_dispatched', $result['status']);
        $this->assertSame([], $client->calls);
    }
}
