<?php

namespace Tests\Unit;

use App\Domains\YandexMarket\Api\InventoryApi;
use App\Domains\YandexMarket\Api\SalesApi;
use App\Domains\YandexMarket\Api\YandexMarketClient;
use App\Domains\YandexMarket\YandexMarketMarketplace;
use App\Services\Marketplace\YandexMarketService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class YandexMarketServiceTest extends TestCase
{
    public function test_service_throws_on_auth_error_instead_of_returning_empty_products(): void
    {
        Http::fake([
            'https://api.partner.market.yandex.ru/v2/campaigns/*' => Http::response([
                'result' => [
                    'campaign' => [
                        'business' => ['id' => '999001'],
                    ],
                ],
            ], 200),
            'https://api.partner.market.yandex.ru/v2/businesses/*/offer-mappings*' => Http::response([
                'errors' => [
                    ['code' => 'FORBIDDEN', 'message' => 'OAuth token is invalid'],
                ],
                'status' => 'ERROR',
            ], 403),
        ]);

        $service = new YandexMarketService('Bearer bad-token', '12345');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Yandex Market getProducts(offer-mappings v2) failed [403]');

        $service->getProducts();
    }

    public function test_domain_marketplace_accepts_api_key_and_client_id_credentials(): void
    {
        $marketplace = new YandexMarketMarketplace([
            'api_key' => 'OAuth test-token',
            'client_id' => '98765',
        ]);

        $marketplaceReflection = new \ReflectionClass($marketplace);
        $clientProperty = $marketplaceReflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $client = $clientProperty->getValue($marketplace);

        $clientReflection = new \ReflectionClass($client);
        $apiKeyProperty = $clientReflection->getProperty('apiKey');
        $apiKeyProperty->setAccessible(true);

        $this->assertSame('test-token', $apiKeyProperty->getValue($client));
        $this->assertSame('98765', $client->getCampaignId());
    }

    public function test_calculate_tariffs_with_selling_program_does_not_mix_campaign_id_parameter(): void
    {
        Http::fake([
            'https://api.partner.market.yandex.ru/v2/tariffs/calculate' => Http::response([
                'result' => [
                    'offers' => [
                        ['tariffs' => []],
                    ],
                ],
            ], 200),
        ]);

        $service = new YandexMarketService('Bearer token', '12345');

        $service->calculateTariffs([
            [
                'categoryId' => 90401,
                'price' => 1200,
                'length' => 10,
                'width' => 10,
                'height' => 10,
                'weight' => 1,
            ],
        ], ['selling_program' => 'DBS']);

        Http::assertSent(function ($request) {
            $parameters = $request->data()['parameters'] ?? [];

            return $request->url() === 'https://api.partner.market.yandex.ru/v2/tariffs/calculate'
                && ($parameters['sellingProgram'] ?? null) === 'DBS'
                && ! array_key_exists('campaignId', $parameters);
        });
    }

    public function test_domain_marketplace_reads_basic_price_from_offer_prices(): void
    {
        Http::fake([
            'https://api.partner.market.yandex.ru/v2/campaigns/98765' => Http::response([
                'campaign' => [
                    'placementType' => 'FBY',
                    'business' => ['id' => '777'],
                ],
            ], 200),
            'https://api.partner.market.yandex.ru/v2/businesses/777/offer-mappings*' => Http::response([
                'result' => [
                    'offerMappings' => [
                        [
                            'offer' => [
                                'shopSku' => 'YM-1',
                                'name' => 'Yandex Product',
                                'category' => 'Category',
                            ],
                            'mapping' => [
                                'marketSku' => '10001',
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://api.partner.market.yandex.ru/v2/campaigns/98765/offer-prices*' => Http::response([
                'result' => [
                    'offers' => [
                        [
                            'offerId' => 'YM-1',
                            'basicPrice' => [
                                'value' => 1990.5,
                                'discountBase' => 2490,
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://api.partner.market.yandex.ru/v2/campaigns/98765/offers/stocks*' => Http::response([
                'result' => ['warehouses' => []],
            ], 200),
            'https://api.partner.market.yandex.ru/v2/campaigns/98765/stats/orders' => Http::response([
                'result' => ['orders' => []],
            ], 200),
        ]);

        $marketplace = new YandexMarketMarketplace([
            'api_key' => 'OAuth test-token',
            'client_id' => '98765',
        ]);

        $products = $marketplace->getProducts();

        $this->assertCount(1, $products);
        $this->assertSame(1990.5, $products[0]['price']);
        $this->assertSame(2490.0, $products[0]['old_price']);
    }

    public function test_inventory_api_prefers_available_stock_over_fit_to_avoid_double_counting(): void
    {
        Http::fake([
            'https://api.partner.market.yandex.ru/v2/campaigns/12345/offers/stocks*' => Http::response([
                'result' => [
                    'warehouses' => [
                        [
                            'warehouseId' => 1,
                            'name' => 'Main',
                            'offers' => [
                                [
                                    'offerId' => 'YM-1',
                                    'stocks' => [
                                        ['type' => 'FIT', 'count' => 10],
                                        ['type' => 'AVAILABLE', 'count' => 7],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $api = new InventoryApi(new YandexMarketClient('ACMA:test', '12345'));

        $stocks = $api->getStocks(null, [], 'FBY');

        $this->assertSame(7, $stocks[0]['total']);
        $this->assertSame(7, $stocks[0]['warehouses'][0]['quantity']);
    }

    public function test_sales_api_counts_item_quantity_not_order_lines_only(): void
    {
        Http::fake([
            'https://api.partner.market.yandex.ru/v2/campaigns/12345/stats/orders' => Http::response([
                'result' => [
                    'orders' => [
                        [
                            'status' => 'DELIVERED',
                            'items' => [
                                ['shopSku' => 'YM-1', 'count' => 3],
                            ],
                        ],
                        [
                            'status' => 'CANCELLED',
                            'items' => [
                                ['shopSku' => 'YM-1', 'quantity' => 2],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $api = new SalesApi(new YandexMarketClient('ACMA:test', '12345'));
        $reflection = new \ReflectionClass($api);
        $method = $reflection->getMethod('fetchOrdersBySku');
        $method->setAccessible(true);

        $result = $method->invoke($api, '2026-05-01', '2026-05-13');

        $this->assertSame(5, $result['YM-1']['total']);
        $this->assertSame(2, $result['YM-1']['cancelled']);
    }
}
