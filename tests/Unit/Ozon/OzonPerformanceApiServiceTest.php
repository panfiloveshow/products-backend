<?php

namespace Tests\Unit\Ozon;

use App\Models\Product;
use App\Services\Ozon\OzonPerformanceApiService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OzonPerformanceApiServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_check_credentials_requests_performance_token(): void
    {
        Http::fake([
            'https://api-performance.ozon.ru/api/client/token' => Http::response([
                'access_token' => 'token-value',
                'token_type' => 'Bearer',
                'expires_in' => 1800,
            ]),
        ]);

        $result = (new OzonPerformanceApiService())->checkCredentials([
            'performance_api_key' => 'performance-client-id',
            'performance_client_secret' => 'performance-secret',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('ok', $result['status']);
        $this->assertTrue($result['token_received']);
        $this->assertSame('Bearer', $result['token_type']);
        $this->assertSame(1800, $result['expires_in']);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api-performance.ozon.ru/api/client/token'
                && $request['client_id'] === 'performance-client-id'
                && $request['client_secret'] === 'performance-secret'
                && $request['grant_type'] === 'client_credentials';
        });
    }

    public function test_check_credentials_does_not_call_api_without_performance_keys(): void
    {
        Http::fake();

        $result = (new OzonPerformanceApiService())->checkCredentials([
            'api_key' => 'seller-api-key',
            'client_id' => 'seller-client-id',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('missing_credentials', $result['status']);
        $this->assertFalse($result['has_performance_api_key']);
        $this->assertFalse($result['has_performance_client_secret']);

        Http::assertNothingSent();
    }

    public function test_advertising_summary_aggregates_campaign_statistics(): void
    {
        Http::fake([
            'https://api-performance.ozon.ru/api/client/token' => Http::response([
                'access_token' => 'token-value',
                'token_type' => 'Bearer',
                'expires_in' => 1800,
            ]),
            'https://api-performance.ozon.ru/api/client/campaign' => Http::response([
                'total' => 2,
                'list' => [
                    [
                        'id' => '101',
                        'title' => 'Чеки',
                        'state' => 'CAMPAIGN_STATE_RUNNING',
                        'advObjectType' => 'SKU',
                        'ProductAdvPlacements' => ['PLACEMENT_SEARCH_AND_CATEGORY'],
                        'budget' => '1000',
                    ],
                    [
                        'id' => '102',
                        'title' => 'Этикетки',
                        'state' => 'CAMPAIGN_STATE_INACTIVE',
                        'advObjectType' => 'SKU',
                        'ProductAdvPlacements' => ['PLACEMENT_TOP_PROMOTION'],
                    ],
                ],
            ]),
            'https://api-performance.ozon.ru/api/client/statistics/campaign/product?*' => Http::response('', 404),
            'https://api-performance.ozon.ru/api/client/statistics/campaign/product/json*' => Http::response([
                'rows' => [
                    [
                        'id' => '101',
                        'title' => 'Чеки',
                        'status' => 'running',
                        'placement' => 'search-and-category',
                        'moneySpent' => '100,50',
                        'views' => '1000',
                        'clicks' => '50',
                        'toCart' => '10',
                        'orders' => '5',
                        'ordersMoney' => '1000,00',
                        'drr' => '10,1',
                    ],
                    [
                        'id' => '102',
                        'title' => 'Этикетки',
                        'status' => 'inactive',
                        'placement' => 'top',
                        'moneySpent' => '50',
                        'views' => '500',
                        'clicks' => '10',
                        'toCart' => '2',
                        'orders' => '1',
                        'ordersMoney' => '200',
                        'drr' => '25',
                    ],
                ],
            ]),
            'https://api-performance.ozon.ru/api/client/limits/list' => Http::response([
                'limits' => [
                    ['objectType' => 'SKU', 'minBid' => 7, 'maxBid' => 200],
                ],
            ]),
        ]);

        $result = (new OzonPerformanceApiService())->advertisingSummary([
            'performance_api_key' => 'performance-client-id',
            'performance_client_secret' => 'performance-secret',
        ], '2026-05-01', '2026-05-31');

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['campaigns']['total']);
        $this->assertSame(1, $result['campaigns']['states']['CAMPAIGN_STATE_RUNNING']);
        $this->assertSame(2, $result['statistics']['row_count']);
        $this->assertSame(150.5, $result['statistics']['totals']['money_spent']);
        $this->assertSame(1500, $result['statistics']['totals']['views']);
        $this->assertSame(60, $result['statistics']['totals']['clicks']);
        $this->assertSame(6, $result['statistics']['totals']['orders']);
        $this->assertSame(1200.0, $result['statistics']['totals']['orders_money']);
        $this->assertSame(4.0, $result['statistics']['derived']['ctr_percent']);
        $this->assertSame(2.51, $result['statistics']['derived']['average_cpc']);
        $this->assertSame(12.54, $result['statistics']['derived']['drr_percent']);
        $this->assertSame(1, $result['bid_limits']['groups_count']);
    }

    public function test_product_advertising_impact_maps_cpc_csv_by_ozon_sku_and_repeated_campaign_ids(): void
    {
        Http::fake([
            'https://api-performance.ozon.ru/api/client/token' => Http::response([
                'access_token' => 'token-value',
                'token_type' => 'Bearer',
                'expires_in' => 1800,
            ]),
            'https://api-performance.ozon.ru/api/client/statistics/report-uuid' => Http::response([
                'UUID' => 'report-uuid',
                'state' => 'OK',
                'kind' => 'SEARCH_PROMO_ORGANISATION_PRODUCTS',
                'link' => '/api/client/statistics/report?UUID=report-uuid',
            ]),
            'https://api-performance.ozon.ru/api/client/statistics/report?UUID=report-uuid' => Http::response(
                "SKU;Артикул;Название товара;Категория товара;Продвижение;Цена товара, ₽\n"
                . "2127759756;3-02/3846;Чековая лента 80 мм;Кассовые ленты;Включено;259,00\n",
                200,
                ['content-type' => 'text/csv; charset=utf-8']
            ),
            'https://api-performance.ozon.ru/api/client/campaign' => Http::response([
                'total' => 2,
                'list' => [
                    ['id' => '101', 'title' => 'Чеки 57'],
                    ['id' => '102', 'title' => 'Чеки 80'],
                ],
            ]),
            'https://api-performance.ozon.ru/api/client/statistics/campaign/product?*' => Http::response(
                "sku;Название товара;Показы;Клики;CTR (%);Расход;Заказы;Выручка;ДРР\n"
                . "2127759756;Чековая лента 80 мм;1000;50;5,0;100,50;5;1000,00;10,05\n",
                200,
                ['content-type' => 'text/csv; charset=utf-8']
            ),
        ]);

        $result = (new OzonPerformanceApiService())->productAdvertisingImpact([
            'performance_api_key' => 'performance-client-id',
            'performance_client_secret' => 'performance-secret',
        ], 'report-uuid', 5000, '2026-05-04', '2026-06-02');

        $this->assertTrue($result['success']);
        $this->assertSame('csv', $result['coverage']['campaign_stats_source']);
        $this->assertSame(1, $result['coverage']['campaign_product_rows']);
        $this->assertSame(1, $result['coverage']['mapped_campaign_product_rows']);
        $this->assertSame([], $result['coverage']['unmapped_campaign_keys']);

        $product = $result['by_offer_id']['3-02/3846'];
        $this->assertSame('mapped_by_product_sku_alias', $product['mapping_status']);
        $this->assertSame('2127759756', $product['mapping_key']);
        $this->assertSame(50, $product['clicks']);
        $this->assertSame(5.0, $product['ctr_percent']);
        $this->assertSame(100.5, $product['ad_spend']);
        $this->assertSame(10.05, $product['ad_drr_percent']);

        Http::assertSent(function ($request): bool {
            $url = $request->url();

            return str_starts_with($url, 'https://api-performance.ozon.ru/api/client/statistics/campaign/product?')
                && str_contains($url, 'campaignIds=101')
                && str_contains($url, 'campaignIds=102')
                && ! str_contains($url, 'campaignIds%5B0%5D')
                && ! str_contains($url, 'campaignIds[0]');
        });
    }

    public function test_product_advertising_impact_maps_cpc_by_local_ozon_data_sku(): void
    {
        Product::factory()->ozon()->create([
            'integration_id' => 77,
            'sku' => '3-02/3846',
            'vendor_code' => '3-02/3846',
            'marketplace_id' => '1756951343',
            'name' => 'Чековая лента 80 мм, 45 метров',
            'ozon_data' => [
                'sku' => '2127759756',
                'product_id' => '1756951343',
                'offer_id' => '3-02/3846',
            ],
        ]);

        Http::fake([
            'https://api-performance.ozon.ru/api/client/token' => Http::response([
                'access_token' => 'token-value',
                'token_type' => 'Bearer',
                'expires_in' => 1800,
            ]),
            'https://api-performance.ozon.ru/api/client/statistics/report-uuid' => Http::response([
                'UUID' => 'report-uuid',
                'state' => 'OK',
                'kind' => 'SEARCH_PROMO_ORGANISATION_PRODUCTS',
                'link' => '/api/client/statistics/report?UUID=report-uuid',
            ]),
            'https://api-performance.ozon.ru/api/client/statistics/report?UUID=report-uuid' => Http::response(
                "SKU;Артикул;Название товара;Категория товара;Продвижение;Цена товара, ₽\n",
                200,
                ['content-type' => 'text/csv; charset=utf-8']
            ),
            'https://api-performance.ozon.ru/api/client/campaign' => Http::response([
                'total' => 1,
                'list' => [
                    ['id' => '101', 'title' => 'Чеки 80'],
                ],
            ]),
            'https://api-performance.ozon.ru/api/client/statistics/campaign/product?*' => Http::response(
                "sku;Название товара;Показы;Клики;CTR (%);Расход;Заказы;Выручка;ДРР\n"
                . "2127759756;Чековая лента 80 мм, 45 метров;1000;50;5,0;222,20;6;2222,00;10,00\n",
                200,
                ['content-type' => 'text/csv; charset=utf-8']
            ),
        ]);

        $result = (new OzonPerformanceApiService())->productAdvertisingImpact([
            'performance_api_key' => 'performance-client-id',
            'performance_client_secret' => 'performance-secret',
        ], 'report-uuid', 5000, '2026-05-04', '2026-06-02', 77);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['coverage']['mapped_campaign_product_rows']);
        $this->assertGreaterThanOrEqual(3, $result['coverage']['local_product_aliases_count']);
        $this->assertArrayHasKey('3-02/3846', $result['by_offer_id']);
        $this->assertSame('2127759756', $result['by_offer_id']['3-02/3846']['mapping_key']);
        $this->assertSame(50, $result['by_offer_id']['3-02/3846']['clicks']);
    }

    public function test_product_advertising_impact_maps_by_unique_product_name_when_id_is_unknown(): void
    {
        Product::factory()->ozon()->create([
            'integration_id' => 78,
            'sku' => '3-02/3846',
            'name' => 'Уникальная чековая лента 80 мм',
            'ozon_data' => [
                'sku' => '2127759756',
            ],
        ]);

        Http::fake([
            'https://api-performance.ozon.ru/api/client/token' => Http::response([
                'access_token' => 'token-value',
                'token_type' => 'Bearer',
                'expires_in' => 1800,
            ]),
            'https://api-performance.ozon.ru/api/client/statistics/report-uuid' => Http::response([
                'UUID' => 'report-uuid',
                'state' => 'OK',
                'kind' => 'SEARCH_PROMO_ORGANISATION_PRODUCTS',
                'link' => '/api/client/statistics/report?UUID=report-uuid',
            ]),
            'https://api-performance.ozon.ru/api/client/statistics/report?UUID=report-uuid' => Http::response(
                "SKU;Артикул;Название товара;Категория товара;Продвижение;Цена товара, ₽\n",
                200,
                ['content-type' => 'text/csv; charset=utf-8']
            ),
            'https://api-performance.ozon.ru/api/client/campaign' => Http::response([
                'total' => 1,
                'list' => [
                    ['id' => '101', 'title' => 'Чеки 80'],
                ],
            ]),
            'https://api-performance.ozon.ru/api/client/statistics/campaign/product?*' => Http::response(
                "sku;Название товара;Показы;Клики;CTR (%);Расход;Заказы;Выручка;ДРР\n"
                . "9999999999;Уникальная чековая лента 80 мм;100;7;7,0;70,00;2;700,00;10,00\n",
                200,
                ['content-type' => 'text/csv; charset=utf-8']
            ),
        ]);

        $result = (new OzonPerformanceApiService())->productAdvertisingImpact([
            'performance_api_key' => 'performance-client-id',
            'performance_client_secret' => 'performance-secret',
        ], 'report-uuid', 5000, '2026-05-04', '2026-06-02', 78);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['coverage']['mapped_campaign_product_rows']);
        $this->assertSame('mapped_by_unique_product_name', $result['by_offer_id']['3-02/3846']['mapping_status']);
        $this->assertSame(7, $result['by_offer_id']['3-02/3846']['clicks']);
    }

    public function test_product_advertising_impact_reports_unmapped_json_aggregates_without_counting_them_as_product_rows(): void
    {
        Http::fake([
            'https://api-performance.ozon.ru/api/client/token' => Http::response([
                'access_token' => 'token-value',
                'token_type' => 'Bearer',
                'expires_in' => 1800,
            ]),
            'https://api-performance.ozon.ru/api/client/statistics/report-uuid' => Http::response([
                'UUID' => 'report-uuid',
                'state' => 'OK',
                'kind' => 'SEARCH_PROMO_ORGANISATION_PRODUCTS',
                'link' => '/api/client/statistics/report?UUID=report-uuid',
            ]),
            'https://api-performance.ozon.ru/api/client/statistics/report?UUID=report-uuid' => Http::response(
                "SKU;Артикул;Название товара;Категория товара;Продвижение;Цена товара, ₽\n",
                200,
                ['content-type' => 'text/csv; charset=utf-8']
            ),
            'https://api-performance.ozon.ru/api/client/campaign' => Http::response([
                'total' => 1,
                'list' => [
                    ['id' => '101', 'title' => 'Кампания без товарной детализации'],
                ],
            ]),
            'https://api-performance.ozon.ru/api/client/statistics/campaign/product?*' => Http::response('', 200),
            'https://api-performance.ozon.ru/api/client/statistics/campaign/product/json?*' => Http::response([
                'rows' => [
                    [
                        'id' => '101',
                        'title' => 'Кампания без товарной детализации',
                        'moneySpent' => '100',
                        'views' => '1000',
                        'clicks' => '10',
                    ],
                ],
            ]),
        ]);

        $result = (new OzonPerformanceApiService())->productAdvertisingImpact([
            'performance_api_key' => 'performance-client-id',
            'performance_client_secret' => 'performance-secret',
        ], 'report-uuid', 5000, '2026-05-04', '2026-06-02');

        $this->assertTrue($result['success']);
        $this->assertSame('fallback', $result['coverage']['campaign_stats_source']);
        $this->assertStringContainsString('JSON вернул агрегаты', $result['coverage']['campaign_stats_source_error']);
        $this->assertSame(0, $result['coverage']['campaign_product_rows']);
        $this->assertSame(0, $result['coverage']['mapped_campaign_product_rows']);
    }

    public function test_product_statistics_report_generation_uses_rfc3339_dates(): void
    {
        Http::fake([
            'https://api-performance.ozon.ru/api/client/token' => Http::response([
                'access_token' => 'token-value',
                'token_type' => 'Bearer',
                'expires_in' => 1800,
            ]),
            'https://api-performance.ozon.ru/api/client/statistic/products/generate' => Http::response([
                'UUID' => 'report-uuid',
                'vendor' => true,
            ]),
        ]);

        $result = (new OzonPerformanceApiService())->requestProductStatisticsReport([
            'performance_api_key' => 'performance-client-id',
            'performance_client_secret' => 'performance-secret',
        ], '2026-05-01', '2026-05-31');

        $this->assertTrue($result['success']);
        $this->assertSame('queued', $result['status']);
        $this->assertSame('report-uuid', $result['uuid']);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api-performance.ozon.ru/api/client/statistic/products/generate'
                && $request['from'] === '2026-05-01T00:00:00Z'
                && $request['to'] === '2026-05-31T23:59:59Z';
        });
    }

    public function test_report_status_hides_token_and_returns_download_state(): void
    {
        Http::fake([
            'https://api-performance.ozon.ru/api/client/token' => Http::response([
                'access_token' => 'token-value',
                'token_type' => 'Bearer',
                'expires_in' => 1800,
            ]),
            'https://api-performance.ozon.ru/api/client/statistics/report-uuid' => Http::response([
                'UUID' => 'report-uuid',
                'state' => 'OK',
                'kind' => 'STATS',
                'link' => 'https://example.test/report.csv',
            ]),
        ]);

        $result = (new OzonPerformanceApiService())->reportStatus([
            'performance_api_key' => 'performance-client-id',
            'performance_client_secret' => 'performance-secret',
        ], 'report-uuid');

        $this->assertTrue($result['success']);
        $this->assertSame('ok', $result['status']);
        $this->assertTrue($result['has_download_link']);
        $this->assertArrayNotHasKey('access_token', $result);
    }

    public function test_product_report_preview_downloads_csv_with_bearer_and_parses_rows(): void
    {
        Http::fake([
            'https://api-performance.ozon.ru/api/client/token' => Http::response([
                'access_token' => 'token-value',
                'token_type' => 'Bearer',
                'expires_in' => 1800,
            ]),
            'https://api-performance.ozon.ru/api/client/statistics/report-uuid' => Http::response([
                'UUID' => 'report-uuid',
                'state' => 'OK',
                'kind' => 'SEARCH_PROMO_ORGANISATION_PRODUCTS',
                'link' => '/api/client/statistics/report?UUID=report-uuid',
            ]),
            'https://api-performance.ozon.ru/api/client/statistics/report?UUID=report-uuid' => Http::response(
                "﻿;\"Отчёт по товарам\"\n"
                . "SKU;Артикул;Название товара;Категория товара;Продвижение;Цена товара, ₽;Заказы;Выручка, ₽;Расход, ₽;ДРР, %\n"
                . "2128409315;3-02/3850;Чековая лента;Кассовые ленты;Включено;730,00;3;1445,00;144,50;10,00\n",
                200,
                ['content-type' => 'text/csv; charset=utf-8']
            ),
        ]);

        $result = (new OzonPerformanceApiService())->productReportPreview([
            'performance_api_key' => 'performance-client-id',
            'performance_client_secret' => 'performance-secret',
        ], 'report-uuid');

        $this->assertTrue($result['success']);
        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['rows_count']);
        $this->assertSame('SKU', $result['header'][0]);
        $this->assertSame('2128409315', $result['rows'][0]['SKU']);
        $this->assertSame('3-02/3850', $result['rows'][0]['Артикул']);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api-performance.ozon.ru/api/client/statistics/report?UUID=report-uuid'
                && $request->hasHeader('Authorization', 'Bearer token-value');
        });
    }

    public function test_product_advertising_impact_groups_by_offer_id_and_flags_high_drr(): void
    {
        Http::fake([
            'https://api-performance.ozon.ru/api/client/token' => Http::response([
                'access_token' => 'token-value',
                'token_type' => 'Bearer',
                'expires_in' => 1800,
            ]),
            'https://api-performance.ozon.ru/api/client/statistics/report-uuid' => Http::response([
                'UUID' => 'report-uuid',
                'state' => 'OK',
                'kind' => 'SEARCH_PROMO_ORGANISATION_PRODUCTS',
                'link' => '/api/client/statistics/report?UUID=report-uuid',
            ]),
            'https://api-performance.ozon.ru/api/client/statistics/report?UUID=report-uuid' => Http::response(
                "﻿;\"Отчёт по товарам\"\n"
                . "SKU;Артикул;Название товара;Категория товара;Продвижение;Цена товара, ₽;Ставка, %;Ставка, ₽;\"Продажи (\"\"Комбо-модель\"\"), ₽\";\"Заказы (\"\"Комбо-модель\"\"), шт.\";\"Расход (\"\"Комбо-модель\"\"), ₽\";\"Расход (\"\"Оплата за заказ\"\"), ₽\";\"Продажи (\"\"Оплата за заказ\"\"), ₽\";\"Заказы (\"\"Оплата за заказ\"\"), шт.\";\"ДРР (\"\"Оплата за заказ\"\"), %\"\n"
                . "2127759756;3-02/3846;Чековая лента;Кассовые ленты;Включено;259,00;10,00;25,90;1445,00;3;144,50;0,00;0,00;0;-\n"
                . "2127759756;3-02/3846;Чековая лента;Кассовые ленты;Включено;259,00;10,00;25,90;100,00;1;200,00;10,00;0,00;0;-\n",
                200,
                ['content-type' => 'text/csv; charset=utf-8']
            ),
        ]);

        $result = (new OzonPerformanceApiService())->productAdvertisingImpact([
            'performance_api_key' => 'performance-client-id',
            'performance_client_secret' => 'performance-secret',
        ], 'report-uuid');

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['summary']['products_count']);
        $this->assertSame(354.5, $result['summary']['total_ad_spend']);
        $this->assertSame(1545.0, $result['summary']['total_ad_revenue']);
        $this->assertSame(4, $result['summary']['total_ad_orders']);

        $product = $result['by_offer_id']['3-02/3846'];
        $this->assertSame('2127759756', $product['ozon_sku']);
        $this->assertSame(22.94, $product['ad_drr_percent']);
        $this->assertSame(88.63, $product['ad_spend_per_order']);
        $this->assertContains('ads_driven_demand', $product['signals']);
        $this->assertContains('high_ad_cost', $product['signals']);
    }
}
