<?php

namespace Tests\Unit;

use App\Services\SellicoApiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SellicoApiServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.sellico.base_url' => 'https://sellico.ru/api',
            'services.sellico.email' => 'test@example.com',
            'services.sellico.password' => 'secret',
        ]);
    }

    public function test_get_integration_by_id_uses_direct_endpoint(): void
    {
        Http::fake([
            '*/login' => Http::response([
                'access_token' => 'fake-service-token',
            ]),
            '*/get-integration/17' => Http::response([
                'data' => [
                    'id' => 17,
                    'name' => 'PouchMan',
                    'type' => 'ozon',
                    'work_space_id' => 3,
                    'api_key' => 'ozon-api-key',
                    'client_id' => 'ozon-client-id',
                ],
            ]),
        ]);

        $service = new SellicoApiService;
        $result = $service->getIntegrationById(17);

        $this->assertTrue($result['success']);
        $this->assertSame(17, $result['integration']['id']);
        $this->assertSame('PouchMan', $result['integration']['name']);
        $this->assertSame('ozon-api-key', $result['credentials']['api_key']);
        $this->assertSame('ozon-client-id', $result['credentials']['client_id']);

        // Не должно быть запроса к /workspaces или /get-integrations/{wsId}
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/workspaces'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/get-integrations/'));
    }

    public function test_get_integration_by_id_returns_failure_on_404(): void
    {
        Http::fake([
            '*/login' => Http::response([
                'access_token' => 'fake-service-token',
            ]),
            '*/get-integration/999' => Http::response(['message' => 'Not found'], 404),
        ]);

        $service = new SellicoApiService;
        $result = $service->getIntegrationById(999);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('999', $result['error']);
    }

    public function test_get_integration_by_id_returns_failure_without_service_token(): void
    {
        config([
            'services.sellico.email' => null,
            'services.sellico.password' => null,
        ]);

        Http::fake();

        $service = new SellicoApiService;
        $result = $service->getIntegrationById(17);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('сервисный аккаунт', $result['error']);
    }

    public function test_get_integration_by_id_no_cache_on_repeated_calls(): void
    {
        Http::fake([
            '*/login' => Http::response([
                'access_token' => 'fake-service-token',
            ]),
            '*/get-integration/17' => Http::response([
                'data' => [
                    'id' => 17,
                    'name' => 'PouchMan',
                    'type' => 'ozon',
                    'api_key' => 'key-1',
                ],
            ]),
        ]);

        $service = new SellicoApiService;

        // Первый вызов
        $service->getIntegrationById(17);
        // Второй вызов — должен снова обратиться к API (без кэша)
        $service->getIntegrationById(17);

        Http::assertSentCount(3); // 1 login + 2 get-integration
    }
}
