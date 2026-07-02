<?php

namespace Tests\Feature;

use App\Domains\UnitEconomics\UnitEconomicsOrchestrator;
use App\Http\Controllers\Api\UnitEconomicsCacheController;
use App\Models\Integration;
use App\Models\UnitEconomicsSettings;
use App\Services\IntegrationAccessService;
use App\Services\UnitEconomicsCacheService;
use App\Services\UnitEconomicsService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class UnitEconomicsVatSettingsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_update_settings_accepts_and_stores_22_percent_vat(): void
    {
        $integration = Integration::factory()->ozon()->create(['id' => 77001]);
        $cacheService = $this->createMock(UnitEconomicsCacheService::class);
        $cacheService->expects($this->once())
            ->method('onSettingsChanged')
            ->with($integration->id, 'ozon-vat-22');

        $controller = new UnitEconomicsCacheController(
            $cacheService,
            $this->createMock(UnitEconomicsService::class),
            $this->createMock(UnitEconomicsOrchestrator::class),
            $this->accessibleIntegrationService($integration->id),
        );

        $response = $controller->updateSettings(Request::create('/api/unit-economics/settings/ozon-vat-22', 'PUT', [
            'integration_id' => $integration->id,
            'vat_percent' => 22,
        ]), 'ozon-vat-22');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(22.0, (float) UnitEconomicsSettings::where('sku', 'ozon-vat-22')->value('vat_percent'));
    }

    public function test_bulk_update_settings_accepts_and_stores_22_percent_vat(): void
    {
        $integration = Integration::factory()->ozon()->create(['id' => 77002]);
        $cacheService = $this->createMock(UnitEconomicsCacheService::class);
        $cacheService->expects($this->once())
            ->method('onBulkSettingsChanged')
            ->with($integration->id, ['ozon-bulk-vat-22']);

        $controller = new UnitEconomicsCacheController(
            $cacheService,
            $this->createMock(UnitEconomicsService::class),
            $this->createMock(UnitEconomicsOrchestrator::class),
            $this->accessibleIntegrationService($integration->id),
        );

        $response = $controller->bulkUpdateSettings(Request::create('/api/unit-economics/settings/bulk', 'PUT', [
            'integration_id' => $integration->id,
            'items' => [
                [
                    'sku' => 'ozon-bulk-vat-22',
                    'vat_percent' => 22,
                ],
            ],
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(22.0, (float) UnitEconomicsSettings::where('sku', 'ozon-bulk-vat-22')->value('vat_percent'));
    }

    private function accessibleIntegrationService(int $integrationId): IntegrationAccessService
    {
        $access = $this->createMock(IntegrationAccessService::class);
        $access->method('ensureAccessibleIntegration')
            ->with($this->anything(), $integrationId)
            ->willReturn(['success' => true]);

        return $access;
    }
}
