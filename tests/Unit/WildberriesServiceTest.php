<?php

namespace Tests\Unit;

use App\Services\Marketplace\WildberriesService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WildberriesServiceTest extends TestCase
{
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
}
