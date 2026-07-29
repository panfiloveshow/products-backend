<?php

namespace Tests\Feature;

use App\Jobs\GenerateAlertsJob;
use App\Models\Integration;
use App\Services\Ozon\OzonCredentialHealthService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class OzonCredentialExpiryAlertTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_expiring_credentials_create_one_deduplicated_alert_and_healthy_state_resolves_it(): void
    {
        $integration = Integration::factory()->ozon()->create([
            'id' => 9401,
            'credential_type' => 'oauth',
            'credentials' => ['oauth_access_token' => 'token'],
            'credentials_expires_at' => now()->addDays(7),
            'credential_health' => 'healthy',
            'is_active' => true,
        ]);

        $job = new GenerateAlertsJob();
        $job->handle(app(OzonCredentialHealthService::class));
        $job->handle(app(OzonCredentialHealthService::class));

        $this->assertDatabaseCount('inventory_alerts', 1);
        $this->assertDatabaseHas('inventory_alerts', [
            'integration_id' => $integration->id,
            'sku' => '__OZON_CREDENTIAL__',
            'type' => 'warning',
            'priority' => 8,
            'is_resolved' => false,
        ]);

        $integration->update([
            'credentials_expires_at' => now()->addDays(90),
            'credential_health' => 'healthy',
        ]);
        $job->handle(app(OzonCredentialHealthService::class));

        $this->assertDatabaseHas('inventory_alerts', [
            'integration_id' => $integration->id,
            'sku' => '__OZON_CREDENTIAL__',
            'is_resolved' => true,
        ]);
    }
}
