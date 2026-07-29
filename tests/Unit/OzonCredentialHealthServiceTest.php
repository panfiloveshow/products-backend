<?php

namespace Tests\Unit;

use App\Models\Integration;
use App\Services\Ozon\OzonCredentialHealthService;
use Tests\TestCase;

class OzonCredentialHealthServiceTest extends TestCase
{
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
}
