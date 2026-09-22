<?php

namespace Tests\Unit;

use App\Domains\Wildberries\Api\FbsSuppliesApi;
use App\Domains\Wildberries\Api\InventoryApi;
use App\Domains\Wildberries\Api\StorageApi;
use App\Domains\Wildberries\Api\SuppliesApi;
use App\Domains\Wildberries\Api\WildberriesClient;
use App\Services\Marketplace\WildberriesService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Контракты WB Seller API на 22.09.2026 (спеки dev.wildberries.ru):
 * хосты поставок, отключённые с 15.08.2026 методы приёмки, chrtId в FBS-остатках,
 * отсутствие ретраев на 4XX marketplace-api.
 */
class WildberriesApiContractTest extends TestCase
{
    public function test_fbw_supply_details_go_to_supplies_api_host_by_path(): void
    {
        Http::fake([
            'supplies-api.wildberries.ru/api/v1/supplies/777' => Http::response([
                'statusID' => 5,
                'warehouseID' => 507,
                'warehouseName' => 'Коледино',
                'createDate' => '2026-09-01T10:00:00+03:00',
                'supplyDate' => '2026-09-05T00:00:00+03:00',
            ]),
        ]);

        $details = (new SuppliesApi(new WildberriesClient('test-token')))->getSupplyDetails('777');

        $this->assertSame('777', $details['id']);
        $this->assertSame('accepted', $details['status']);
        $this->assertSame('507', $details['warehouse_id']);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://supplies-api.wildberries.ru/api/v1/supplies/777');
    }

    public function test_fbw_supplies_list_sends_pagination_in_query_and_filters_in_body(): void
    {
        Http::fake([
            'supplies-api.wildberries.ru/api/v1/supplies*' => Http::response([
                ['supplyID' => 26596368, 'preorderID' => 34601223, 'statusID' => 2, 'createDate' => '2026-09-01T10:00:00+03:00'],
                ['supplyID' => null, 'preorderID' => 34597755, 'statusID' => 1],
            ]),
        ]);

        $supplies = (new SuppliesApi(new WildberriesClient('test-token')))->getSupplies([
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-22',
        ]);

        $this->assertCount(2, $supplies);
        $this->assertSame('26596368', $supplies[0]['id']);
        $this->assertSame('planned', $supplies[0]['status']);
        $this->assertSame('34597755', $supplies[1]['id']); // незапланированная — по preorderID
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'https://supplies-api.wildberries.ru/api/v1/supplies?limit=1000&offset=0'
            && $r['statusIDs'] === [1, 2, 3, 4, 5, 6]
            && $r['dates'] === [['from' => '2026-09-01', 'till' => '2026-09-22', 'type' => 'createDate']]);
    }

    public function test_disabled_acceptance_and_warehouse_methods_are_not_called(): void
    {
        Http::fake();

        $supplies = new SuppliesApi(new WildberriesClient('test-token'));

        $this->assertSame([], $supplies->getAvailableWarehouses());
        $this->assertSame([], $supplies->getAcceptanceSlots('507', '2026-09-22', '2026-10-06'));
        $this->assertFalse($supplies->supportsFeature('get_acceptance_slots'));
        Http::assertNothingSent();
    }

    public function test_fbs_supplies_list_uses_marketplace_host_with_required_next(): void
    {
        Http::fake([
            'marketplace-api.wildberries.ru/api/v3/supplies*' => Http::response([
                'next' => 0,
                'supplies' => [['id' => 'WB-GI-1234567', 'name' => 'Тест', 'done' => false]],
            ]),
        ]);

        $supplies = (new FbsSuppliesApi(new WildberriesClient('test-token')))->getSupplies();

        $this->assertSame('WB-GI-1234567', $supplies[0]['id']);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://marketplace-api.wildberries.ru/api/v3/supplies?limit=1000&next=0');
    }

    public function test_tariff_snapshots_do_not_call_disabled_acceptance_coefficients(): void
    {
        Http::fake([
            'common-api.wildberries.ru/*' => Http::response(['response' => ['data' => ['warehouseList' => []]]]),
        ]);

        (new StorageApi(new WildberriesClient('test-token')))->getTariffSnapshots('2026-09-22');

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'acceptance/coefficients'));
    }

    public function test_marketplace_client_does_not_retry_4xx(): void
    {
        // С 17.06.2026 один 4XX marketplace-api стоит 10 запросов — повтор только на 429.
        Http::fake([
            'marketplace-api.wildberries.ru/*' => Http::response(['code' => 'IncorrectRequest'], 400),
        ]);

        $client = new WildberriesClient('test-token');

        $this->assertNull($client->post('/api/v3/stocks/55', ['chrtIds' => [1]]));
        $this->assertSame(400, $client->getLastResponseStatus());
        Http::assertSentCount(1);
    }

    public function test_domain_fbs_stocks_stop_warehouse_after_4xx_chunk(): void
    {
        Http::fake([
            'marketplace-api.wildberries.ru/api/v3/stocks/55' => Http::response(['code' => 'IncorrectRequest'], 400),
        ]);

        $stocks = (new InventoryApi(new WildberriesClient('test-token')))
            ->getStocksByWarehouse('55', null, range(1, 2500)); // 3 пачки по 1000

        $this->assertSame([], $stocks);
        Http::assertSentCount(1);
    }

    public function test_legacy_fbs_stocks_map_chrt_id_to_barcode(): void
    {
        Http::fake([
            'marketplace-api.wildberries.ru/api/v3/warehouses' => Http::response([
                ['id' => 55, 'name' => 'Мой склад'],
            ]),
            'content-api.wildberries.ru/content/v2/get/cards/list' => Http::response([
                'cards' => [[
                    'nmID' => 111,
                    'vendorCode' => 'ART-1',
                    'sizes' => [
                        ['chrtID' => 1001, 'skus' => ['BAR-S']],
                        ['chrtID' => 1002, 'skus' => ['BAR-M']],
                    ],
                ]],
                'cursor' => ['updatedAt' => '2026-09-01T00:00:00Z', 'nmID' => 111, 'total' => 1],
            ]),
            // Ответ по спеке — только chrtId + amount, без sku
            'marketplace-api.wildberries.ru/api/v3/stocks/55' => Http::response([
                'stocks' => [
                    ['chrtId' => 1001, 'amount' => 7],
                    ['chrtId' => 1002, 'amount' => 0],
                ],
            ]),
        ]);

        $stocks = (new WildberriesService('test-token'))->getFbsStocks();

        $this->assertSame(['BAR-S', 'BAR-M'], array_column($stocks, 'sku'));
        $this->assertSame([7, 0], array_column($stocks, 'quantity'));
        $this->assertSame('fbs_55', $stocks[0]['warehouse_id']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/v3/stocks/55')
            && $r['chrtIds'] === [1001, 1002]);
    }

    public function test_legacy_warehouse_coefficients_come_from_box_tariffs(): void
    {
        Http::fake([
            'common-api.wildberries.ru/api/v1/tariffs/box*' => Http::response([
                'response' => ['data' => ['warehouseList' => [
                    ['warehouseName' => 'Коледино', 'boxDeliveryCoefExpr' => '160', 'boxStorageCoefExpr' => '115'],
                ]]],
            ]),
        ]);

        $coefficients = (new WildberriesService('test-token'))->getWarehouseCoefficients();

        $this->assertSame(1.6, $coefficients['коледино']['warehouse_coefficient']);
        $this->assertSame([], (new WildberriesService('test-token'))->getWarehouses());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'acceptance/coefficients')
            || str_contains($r->url(), 'supplies-api.wildberries.ru/api/v1/warehouses'));
    }
}
