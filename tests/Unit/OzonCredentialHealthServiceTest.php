<?php

namespace Tests\Unit;

use App\Models\Integration;
use App\Services\Ozon\OzonCredentialHealthService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OzonCredentialHealthServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_expired_oauth_credentials_are_not_usable(): void
    {
        $integration = new Integration([
            'id' => 91,
            'marketplace' => 'ozon',
            'credentials' => ['oauth_access_token' => 'oauth-token'],
            'credential_type' => 'oauth',
            'credentials_expires_at' => now()->subDay(),
        ]);

        $result = app(OzonCredentialHealthService::class)->assess($integration);

        $this->assertSame('expired', $result['status']);
        $this->assertFalse($result['usable']);
        $this->assertSame('critical', $result['severity']);
    }

    public function test_expiry_threshold_is_exposed_for_reconnect_notification(): void
    {
        $integration = new Integration([
            'id' => 92,
            'marketplace' => 'ozon',
            'credentials' => ['client_id' => '123', 'api_key' => 'key'],
            'credential_type' => 'api_key',
            'credentials_expires_at' => now()->addDays(6),
        ]);

        $result = app(OzonCredentialHealthService::class)->assess($integration);

        $this->assertSame('expiring', $result['status']);
        $this->assertTrue($result['usable']);
        $this->assertSame(7, $result['notification_threshold_days']);
        $this->assertSame('expiring_7d', $result['health']);
    }

    public function test_probe_uses_product_list_endpoint(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/v3/product/list' => Http::response(['result' => ['items' => []]], 200),
        ]);

        $result = app(OzonCredentialHealthService::class)->check($this->workingIntegration());

        $this->assertSame('valid', $result['status']);
        $this->assertTrue($result['usable']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v3/product/list'));
    }

    public function test_only_auth_rejection_marks_credentials_invalid(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['code' => 16, 'message' => 'Invalid Api-Key'], 401)]);

        $result = app(OzonCredentialHealthService::class)->check($this->workingIntegration());

        $this->assertSame('invalid', $result['status']);
        $this->assertFalse($result['usable']);
        $this->assertSame('critical', $result['severity']);
    }

    /**
     * Регрессия: пробник бил в /v3/product/info/list и получал 400
     * «use either offer_id or product_id or sku». Любая ошибка считалась
     * отзывом ключей → синк фактов Ozon вставал на всех интеграциях.
     */
    public function test_non_auth_error_does_not_revoke_working_credentials(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response(
                ['code' => 3, 'message' => 'use either offer_id or product_id or sku'],
                400
            ),
        ]);

        $result = app(OzonCredentialHealthService::class)->check($this->workingIntegration());

        $this->assertSame('unavailable', $result['status']);
        $this->assertTrue($result['usable'], 'Ошибка запроса не должна отзывать рабочие ключи');
        $this->assertSame('warning', $result['severity']);
    }

    private function workingIntegration(): Integration
    {
        return Integration::factory()->ozon()->create([
            'id' => random_int(100000, 999999),
            'work_space_id' => 96,
            'credentials' => ['client_id' => '123456', 'api_key' => 'a0000000-0000-0000-0000-000000000000'],
            'credential_type' => 'api_key',
            'credentials_expires_at' => null,
            'credential_health' => 'unknown',
        ]);
    }
}
