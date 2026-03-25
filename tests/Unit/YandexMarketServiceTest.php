<?php

namespace Tests\Unit;

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
}
