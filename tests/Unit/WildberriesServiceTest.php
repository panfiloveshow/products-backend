<?php

namespace Tests\Unit;

use App\Services\Marketplace\WildberriesService;
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

    public function test_get_products_uses_new_all_cards_photo_filter_after_wb_cutover(): void
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
                && $request->data()['settings']['filter']['withPhoto'] === 0;
        });

        Carbon::setTestNow();
    }
}
