<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Services\UnitEconomicsService;
use App\Services\UnitEconomicsSynchronizer;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class UnitEconomicsSyncResultTest extends TestCase
{
    public function test_service_preserves_real_counts_and_failure_details_without_interpreting_console_exit_code(): void
    {
        $integration = new Integration(['id' => 62003, 'marketplace' => 'ozon']);
        $expected = ['total' => 10, 'synced' => 12, 'succeeded' => 3, 'errors' => 7,
            'skipped' => 0, 'status' => 'partial', 'failed_skus' => ['FAILED-1']];
        Artisan::shouldReceive('call')->with('unit-economics:sync', [
            '--integration' => 62003, '--skip-cache-dispatch' => true,
        ])->andReturn(0);
        $this->mock(UnitEconomicsSynchronizer::class)->shouldReceive('syncIntegration')
            ->once()->with($integration, '2026-10-01', '2026-10-31')->andReturn($expected);

        $actual = app(UnitEconomicsService::class)->syncFromRealData($integration, '2026-10-01', '2026-10-31');

        $this->assertSame($expected, $actual);
    }
}
