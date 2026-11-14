<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\InventoryController;
use App\Jobs\GenerateAlertsJob;
use App\Models\Integration;
use App\Models\InventoryAlert;
use App\Services\Ozon\OzonCredentialHealthService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class OzonCredentialExpiryAlertTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_expired_api_key_prompts_owner_to_update_it_without_duplicate_alerts(): void
    {
        $integration = Integration::factory()->ozon()->create([
            'id' => 9402,
            'credentials' => ['client_id' => '123', 'api_key' => 'expired-key'],
            'credential_type' => 'api_key',
            'credential_health' => 'invalid',
            'last_validation_error' => 'Api-key has expired, generate a new one',
            'is_active' => true,
        ]);
        $job = new GenerateAlertsJob;
        $job->handle(app(OzonCredentialHealthService::class));
        $job->handle(app(OzonCredentialHealthService::class));
        $alert = InventoryAlert::where('integration_id', $integration->id)->sole();
        $this->assertSame('critical', $alert->type);
        $this->assertStringContainsString('API-ключ Ozon истёк', $alert->message);
        $this->assertStringContainsString('Обновите ключ в настройках интеграции', $alert->message);
        $this->assertFalse($alert->is_resolved);
        $integration->update(['credential_health' => 'healthy', 'last_validation_error' => null]);
        $job->handle(app(OzonCredentialHealthService::class));
        $this->assertTrue($alert->fresh()->is_resolved);
    }

    public function test_credential_alert_remains_visible_among_more_than_100_critical_stock_alerts(): void
    {
        $integration = Integration::factory()->ozon()->create(['id' => 9403]);
        $alert = InventoryAlert::create(['integration_id' => $integration->id,
            'sku' => '__OZON_CREDENTIAL__', 'type' => 'critical', 'priority' => 10,
            'message' => 'Обновите API-ключ', 'action' => 'monitor']);
        $alert->forceFill(['created_at' => now()->subWeek(), 'updated_at' => now()->subWeek()])->save();
        for ($i = 0; $i < 101; $i++) {
            InventoryAlert::create(['integration_id' => $integration->id,
                'sku' => 'stock-'.$i, 'type' => 'critical', 'priority' => 10,
                'message' => 'Критический остаток', 'action' => 'monitor']);
        }

        $data = app(InventoryController::class)->alerts()->getData(true)['data'];

        $this->assertCount(100, $data);
        $this->assertSame($alert->id, $data[0]['id']);
    }

    public function test_archived_integration_does_not_keep_an_active_credential_alert(): void
    {
        $integration = Integration::factory()->ozon()->create(['id' => 9404, 'is_active' => false]);
        $alert = InventoryAlert::create(['integration_id' => $integration->id,
            'sku' => '__OZON_CREDENTIAL__', 'type' => 'critical', 'priority' => 10,
            'message' => 'Истёк API-ключ', 'action' => 'monitor']);

        (new GenerateAlertsJob)->handle(app(OzonCredentialHealthService::class));

        $this->assertTrue($alert->fresh()->is_resolved);
    }

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

        $job = new GenerateAlertsJob;
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
