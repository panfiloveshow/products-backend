<?php

namespace Tests\Unit;

use App\Services\Marketplace\WildberriesService;
use App\Domains\Wildberries\Api\ProductsApi as WildberriesProductsApi;
use App\Domains\Wildberries\Api\StorageApi as WildberriesStorageApi;
use App\Domains\Wildberries\Api\WildberriesClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WildberriesServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_get_products_reports_expired_token(): void
    {
        Http::fake([
            'content-api.wildberries.ru/content/v2/get/cards/list' => Http::response([
                'title' => 'unauthorized',
                'detail' => 'access token expired; Manage tokens at https://seller.wildberries.ru/supplier-settings/access-to-api',
                'status' => 401,
            ], 401),
        ]);

        $service = new WildberriesService('expired-token');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ключ API Wildberries просрочен');

        $service->getProducts();
    }

    public function test_get_products_reports_missing_token_before_request(): void
    {
        Http::fake();

        $service = new WildberriesService('');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ключ API Wildberries не указан');

        $service->getProducts();

        Http::assertNothingSent();
    }

    public function test_get_products_uses_legacy_all_cards_photo_filter_before_wb_cutover(): void
    {
        Carbon::setTestNow('2026-06-02 12:00:00');
        Http::fake([
            'content-api.wildberries.ru/content/v2/get/cards/list' => Http::response([
                'cards' => [],
                'cursor' => ['total' => 0],
            ]),
            'discounts-prices-api.wildberries.ru/api/v2/list/goods/filter*' => Http::response([
                'data' => ['listGoods' => []],
            ]),
            'common-api.wildberries.ru/api/v1/tariffs/commission*' => Http::response([
                'report' => [],
            ]),
        ]);

        $service = new WildberriesService('test-token');
        $service->getProducts();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'content-api.wildberries.ru/content/v2/get/cards/list')
                && $request->data()['settings']['filter']['withPhoto'] === -1;
        });

        Carbon::setTestNow();
    }

    public function test_get_products_keeps_all_cards_photo_filter_after_wb_cutover(): void
    {
        Carbon::setTestNow('2026-06-03 00:00:00');
        Http::fake([
            'content-api.wildberries.ru/content/v2/get/cards/list' => Http::response([
                'cards' => [],
                'cursor' => ['total' => 0],
            ]),
            'discounts-prices-api.wildberries.ru/api/v2/list/goods/filter*' => Http::response([
                'data' => ['listGoods' => []],
            ]),
            'common-api.wildberries.ru/api/v1/tariffs/commission*' => Http::response([
                'report' => [],
            ]),
        ]);

        $service = new WildberriesService('test-token');
        $service->getProducts();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'content-api.wildberries.ru/content/v2/get/cards/list')
                && $request->data()['settings']['filter']['withPhoto'] === -1;
        });

        Carbon::setTestNow();
    }

    public function test_get_products_sends_required_user_agent_to_wildberries_requests(): void
    {
        Http::fake([
            'content-api.wildberries.ru/content/v2/get/cards/list' => Http::response([
                'cards' => [],
                'cursor' => ['total' => 0],
            ]),
            'discounts-prices-api.wildberries.ru/api/v2/list/goods/filter*' => Http::response([
                'data' => ['listGoods' => []],
            ]),
            'common-api.wildberries.ru/api/v1/tariffs/commission*' => Http::response([
                'report' => [],
            ]),
        ]);

        (new WildberriesService('test-token'))->getProducts();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'content-api.wildberries.ru/content/v2/get/cards/list')
                && $request->hasHeader('User-Agent', 'wbas_sellico.ru9757');
        });
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'discounts-prices-api.wildberries.ru/api/v2/list/goods/filter')
                && $request->hasHeader('User-Agent', 'wbas_sellico.ru9757');
        });
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'common-api.wildberries.ru/api/v1/tariffs/commission')
                && $request->hasHeader('User-Agent', 'wbas_sellico.ru9757');
        });
    }

    public function test_get_products_maps_prices_by_size_not_first_size_for_all_barcodes(): void
    {
        Http::fake([
            'content-api.wildberries.ru/content/v2/get/cards/list' => Http::response([
                'cards' => [[
                    'nmID' => 111,
                    'imtID' => 222,
                    'vendorCode' => 'ART-1',
                    'subjectID' => 333,
                    'subjectName' => 'Тест',
                    'title' => 'Товар с размерами',
                    'sizes' => [
                        ['chrtID' => 1001, 'wbSize' => 'S', 'skus' => ['BAR-S']],
                        ['chrtID' => 1002, 'wbSize' => 'M', 'skus' => ['BAR-M']],
                    ],
                ]],
                'cursor' => [],
            ]),
            'discounts-prices-api.wildberries.ru/api/v2/list/goods/filter*' => Http::response([
                'data' => [
                    'listGoods' => [[
                        'nmID' => 111,
                        'vendorCode' => 'ART-1',
                        'discount' => 0,
                        'sizes' => [
                            ['sizeID' => 1001, 'price' => 1000, 'discountedPrice' => 900],
                            ['sizeID' => 1002, 'price' => 2000, 'discountedPrice' => 1800],
                        ],
                    ]],
                ],
            ]),
            'common-api.wildberries.ru/api/v1/tariffs/commission*' => Http::response([
                'report' => [[
                    'subjectID' => 333,
                    'kgvpMarketplace' => 12,
                    'kgvpSupplier' => 10,
                    'kgvpSupplierExpress' => 3,
                    'kgvpPickup' => 8,
                    'kgvpBooking' => 7,
                ]],
            ]),
        ]);

        $products = (new WildberriesService('test-token'))->getProducts();

        $this->assertCount(2, $products);
        $bySku = collect($products)->keyBy('sku');
        $this->assertSame(900.0, $bySku['BAR-S']['price']);
        $this->assertSame(1800.0, $bySku['BAR-M']['price']);
        $this->assertSame('111:BAR-S', $bySku['BAR-S']['marketplace_id']);
        $this->assertSame('111:BAR-M', $bySku['BAR-M']['marketplace_id']);
        $this->assertSame(3.0, $bySku['BAR-M']['wb_data']['commissions_by_scheme']['edbs']['percent']);
        $this->assertSame(8.0, $bySku['BAR-M']['wb_data']['commissions_by_scheme']['dbs']['percent']);
        $this->assertSame(7.0, $bySku['BAR-M']['wb_data']['commissions_by_scheme']['dbw']['percent']);
    }

    public function test_domain_products_api_does_not_rescan_cards_for_dimensions_by_default(): void
    {
        Http::fake([
            'content-api.wildberries.ru/content/v2/get/cards/list' => Http::response([
                'cards' => [[
                    'nmID' => 111,
                    'vendorCode' => 'ART-1',
                    'sizes' => [],
                    'dimensions' => ['length' => 10, 'width' => 20, 'height' => 30],
                ]],
                'cursor' => [],
            ]),
        ]);

        $result = (new WildberriesProductsApi(new WildberriesClient('test-token')))->getProducts(null, ['limit' => 100]);

        $this->assertCount(1, $result['cards']);
        Http::assertSentCount(1);
    }

    public function test_tariff_snapshots_use_distinct_synthetic_warehouse_ids_for_box_tariffs(): void
    {
        Http::fake([
            'common-api.wildberries.ru/api/v1/tariffs/commission*' => Http::response(['report' => []]),
            'common-api.wildberries.ru/api/v1/tariffs/box*' => Http::response([
                'response' => [
                    'data' => [
                        'warehouseList' => [
                            ['warehouseName' => 'Коледино', 'boxDeliveryBase' => '50', 'boxDeliveryLiter' => '10'],
                            ['warehouseName' => 'Электросталь', 'boxDeliveryBase' => '60', 'boxDeliveryLiter' => '12'],
                        ],
                    ],
                ],
            ]),
            'common-api.wildberries.ru/api/v1/tariffs/return*' => Http::response([]),
            'common-api.wildberries.ru/api/v1/tariffs/pallet*' => Http::response([
                'response' => [
                    'data' => [
                        'warehouseList' => [
                            ['warehouseName' => 'Коледино', 'palletDeliveryExpr' => '100'],
                        ],
                    ],
                ],
            ]),
            'common-api.wildberries.ru/api/tariffs/v1/acceptance/coefficients*' => Http::response(['coefficients' => []]),
        ]);

        $snapshots = (new WildberriesStorageApi(new WildberriesClient('test-token')))->getTariffSnapshots('2026-05-23');
        $boxSnapshots = collect($snapshots)->where('tariff_type', 'box')->values();
        $palletSnapshots = collect($snapshots)->where('tariff_type', 'pallet')->values();

        $this->assertCount(2, $boxSnapshots);
        $this->assertCount(1, $palletSnapshots);
        $this->assertNotSame($boxSnapshots[0]['warehouse_id'], $boxSnapshots[1]['warehouse_id']);
        $this->assertStringStartsWith('name:', $boxSnapshots[0]['warehouse_id']);
        $this->assertSame($boxSnapshots[0]['warehouse_id'], $palletSnapshots[0]['warehouse_id']);
    }
}
