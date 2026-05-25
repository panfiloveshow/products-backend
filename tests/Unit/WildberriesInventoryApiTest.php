<?php

namespace Tests\Unit;

use App\Domains\Wildberries\Api\InventoryApi;
use App\Domains\Wildberries\Api\WildberriesClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WildberriesInventoryApiTest extends TestCase
{
    public function test_fbs_stocks_map_chrt_id_to_barcode_sku(): void
    {
        Http::fake([
            'marketplace-api.wildberries.ru/api/v3/warehouses' => Http::response([
                ['id' => 55, 'name' => 'Seller WH', 'cargoType' => 1],
            ]),
            'content-api.wildberries.ru/content/v2/get/cards/list' => Http::response([
                'cards' => [[
                    'nmID' => 111,
                    'vendorCode' => 'ART-1',
                    'sizes' => [
                        ['chrtID' => 1001, 'skus' => ['BAR-S']],
                    ],
                ]],
                'cursor' => [],
            ]),
            'marketplace-api.wildberries.ru/api/v3/stocks/55' => Http::response([
                'stocks' => [
                    ['chrtId' => 1001, 'amount' => 7],
                ],
            ]),
        ]);

        $api = new InventoryApi(new WildberriesClient('test-token'));
        $stocks = $api->getStocksFromFbsWarehousesDirect();

        $this->assertCount(1, $stocks);
        $this->assertSame('BAR-S', $stocks[0]['sku']);
        $this->assertSame(7, $stocks[0]['total']);
        $this->assertSame('FBS', $stocks[0]['warehouses'][0]['fulfillment_type']);
    }
}
