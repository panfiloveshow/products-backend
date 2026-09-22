<?php

namespace Tests\Unit;

use App\Domains\YandexMarket\Api\InventoryApi;
use App\Domains\YandexMarket\Api\ProductsApi;
use App\Domains\YandexMarket\Api\YandexMarketClient;
use App\Domains\YandexMarket\YandexMarketMarketplace;
use App\Services\Marketplace\YandexMarketService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Контракты ЯМ Partner API по официальной OpenAPI (22.09.2026): склады v2/v3 с
 * пагинацией в query, цены через POST businesses/offer-prices, обязательные поля
 * tariffs/calculate, stats/orders вместо stats/skus без shopSkus.
 */
class YandexMarketContractTest extends TestCase
{
    private const API = 'https://api.partner.market.yandex.ru';

    public function test_warehouses_use_business_endpoint_with_query_paging(): void
    {
        Http::fake(function (Request $request) {
            if (str_starts_with($request->url(), self::API.'/v2/businesses/777/warehouses')) {
                return str_contains($request->url(), 'page_token=p2')
                    ? Http::response(['result' => ['warehouses' => [['id' => 2, 'name' => 'Склад 2']]]])
                    : Http::response(['result' => ['warehouses' => [['id' => 1, 'name' => 'Склад 1']], 'paging' => ['nextPageToken' => 'p2']]]);
            }

            return Http::response([], 404);
        });

        $warehouses = (new InventoryApi(new YandexMarketClient('ACMA:test', '12345', '777')))->getWarehouses();

        $this->assertSame([1, 2], array_column($warehouses, 'id'));
        Http::assertSent(fn (Request $r) => $r->url() === self::API.'/v2/businesses/777/warehouses?limit=30');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/campaigns/12345/warehouses'));
    }

    public function test_warehouses_fall_back_to_v3_for_cabinets_without_groups(): void
    {
        Http::fake([
            self::API.'/v2/businesses/777/warehouses*' => Http::response(['result' => ['warehouses' => []]]),
            self::API.'/v3/businesses/777/warehouses*' => Http::response(['result' => ['warehouses' => [
                ['id' => 5, 'name' => 'FBS склад', 'models' => []],
            ]]]),
        ]);

        $warehouses = (new InventoryApi(new YandexMarketClient('ACMA:test', '12345', '777')))->getWarehouses();

        $this->assertSame('FBS склад', $warehouses[0]['name']);
    }

    public function test_prices_use_business_offer_prices_with_query_paging(): void
    {
        Http::fake(function (Request $request) {
            if (! str_starts_with($request->url(), self::API.'/v2/businesses/777/offer-prices')) {
                return Http::response([], 404);
            }

            return str_contains($request->url(), 'page_token=n2')
                ? Http::response(['result' => ['offers' => [
                    ['offerId' => 'YM-2', 'price' => ['value' => 500, 'currencyId' => 'RUR']],
                ]]])
                : Http::response(['result' => [
                    'offers' => [['offerId' => 'YM-1', 'price' => ['value' => 1990.5, 'discountBase' => 2490, 'currencyId' => 'RUR']]],
                    'paging' => ['nextPageToken' => 'n2'],
                ]]);
        });

        $prices = (new ProductsApi(new YandexMarketClient('ACMA:test', '12345', '777')))->getPrices();

        $this->assertSame(1990.5, $prices['YM-1']['price']);
        $this->assertSame(2490.0, $prices['YM-1']['old_price']);
        $this->assertSame(500.0, $prices['YM-2']['price']);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === self::API.'/v2/businesses/777/offer-prices?limit=500');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/campaigns/12345/offer-prices'));
    }

    public function test_tariffs_calculate_gets_only_complete_offers_without_offer_id(): void
    {
        $mapping = fn (string $sku, ?int $category, ?array $dims) => [
            'offer' => array_filter([
                'offerId' => $sku,
                'name' => $sku,
                'basicPrice' => ['value' => 1000],
                'weightDimensions' => $dims,
            ]),
            'mapping' => array_filter(['marketSku' => 1, 'marketCategoryId' => $category]),
        ];

        Http::fake([
            self::API.'/v2/businesses/777/offer-mappings*' => Http::response(['result' => ['offerMappings' => [
                $mapping('FULL-1', 90401, ['length' => 10, 'width' => 20, 'height' => 5, 'weight' => 0.5]),
                $mapping('NO-CATEGORY', null, ['length' => 10, 'width' => 20, 'height' => 5, 'weight' => 0.5]),
                $mapping('NO-DIMS', 90401, null),
                $mapping('FULL-2', 90402, ['length' => 30, 'width' => 20, 'height' => 10, 'weight' => 2]),
            ]]]),
            self::API.'/v2/campaigns/12345/offers/stocks*' => Http::response(['result' => ['warehouses' => []]]),
            self::API.'/v2/tariffs/calculate' => Http::response(['result' => ['offers' => [
                ['offer' => ['categoryId' => 90401], 'tariffs' => [['type' => 'FEE', 'amount' => 10]]],
                ['offer' => ['categoryId' => 90402], 'tariffs' => [['type' => 'FEE', 'amount' => 20]]],
            ]]]),
        ]);

        $products = (new YandexMarketMarketplace([
            'api_key' => 'ACMA:test',
            'client_id' => '12345',
            'business_id' => '777',
            'scheme' => 'FBS',
        ]))->getProducts();

        $bySku = collect($products)->keyBy('sku');
        $this->assertSame(10, $bySku['FULL-1']['yandex_data']['tariffs'][0]['amount']);
        $this->assertSame(20, $bySku['FULL-2']['yandex_data']['tariffs'][0]['amount']);
        $this->assertArrayNotHasKey('tariffs', $bySku['NO-CATEGORY']['yandex_data']);

        Http::assertSent(function (Request $r) {
            if ($r->url() !== self::API.'/v2/tariffs/calculate') {
                return false;
            }
            $offers = $r['offers'];

            return count($offers) === 2
                && ! array_key_exists('offerId', $offers[0])
                && $offers[0] == ['categoryId' => 90401, 'price' => 1000.0, 'length' => 10.0, 'width' => 20.0, 'height' => 5.0, 'weight' => 0.5, 'quantity' => 1]
                && $r['parameters'] === ['sellingProgram' => 'FBS'];
        });
    }

    public function test_scheme_detection_knows_only_current_placement_types(): void
    {
        Http::fake([
            self::API.'/v2/campaigns/12345' => Http::response(['campaign' => ['placementType' => 'LAAS']]),
        ]);

        $marketplace = new YandexMarketMarketplace(['api_key' => 'ACMA:test', 'client_id' => '12345']);

        $this->assertSame('FBY', $marketplace->getScheme());
    }

    public function test_legacy_sales_stats_use_orders_stats_with_query_paging(): void
    {
        Http::fake([
            self::API.'/v2/campaigns/12345/stats/orders*' => Http::response(['result' => ['orders' => [['id' => 1]]]]),
            self::API.'/v2/campaigns/12345/offers/stocks*' => Http::response(['result' => ['warehouses' => []]]),
        ]);

        $service = new YandexMarketService('ACMA:test', '12345');

        $this->assertSame([['id' => 1]], $service->getSalesStats('2026-09-01', '2026-09-22'));
        $service->getInventory();

        Http::assertSent(fn (Request $r) => $r->url() === self::API.'/v2/campaigns/12345/stats/orders?limit=200'
            && $r['dateFrom'] === '2026-09-01'
            && ! isset($r['shopSkus']));
        Http::assertSent(fn (Request $r) => $r->url() === self::API.'/v2/campaigns/12345/offers/stocks?limit=100');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'stats/skus'));
    }
}
