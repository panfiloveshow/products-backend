<?php

namespace Tests\Unit;

use App\Console\Commands\SyncUnitEconomicsCommand;
use App\Models\Product;
use App\Services\Ozon\OzonOrderUnitEconomicsService;
use App\Services\Ozon\OzonSupplyFixationService;
use App\Services\Ozon\OzonSupplySyncService;
use App\Services\PostingService;
use App\Services\UnitEconomicsService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class SyncUnitEconomicsCommandExitCodeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_sync_all_returns_failure_when_any_product_sync_failed(): void
    {
        Product::factory()->wildberries()->create([
            'integration_id' => 62001,
            'price' => 1000,
        ]);

        $command = new class extends SyncUnitEconomicsCommand
        {
            protected function syncProducts(
                UnitEconomicsService $service,
                OzonSupplyFixationService $fixationService,
                OzonOrderUnitEconomicsService $orderUnitEconomicsService,
                OzonSupplySyncService $supplySyncService,
                PostingService $postingService,
                int $integrationId,
                string $marketplace
            ): array {
                return ['synced' => 4, 'errors' => 1];
            }
        };
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

        $method = (new ReflectionClass(SyncUnitEconomicsCommand::class))->getMethod('syncAll');
        $method->setAccessible(true);
        $exitCode = $method->invoke(
            $command,
            Mockery::mock(UnitEconomicsService::class),
            Mockery::mock(OzonSupplyFixationService::class),
            Mockery::mock(OzonOrderUnitEconomicsService::class),
            Mockery::mock(OzonSupplySyncService::class),
            Mockery::mock(PostingService::class)
        );

        $this->assertSame(SyncUnitEconomicsCommand::FAILURE, $exitCode);
    }
}
