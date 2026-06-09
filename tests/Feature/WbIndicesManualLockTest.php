<?php

namespace Tests\Feature;

use App\Jobs\SyncWildberriesLocalizationJob;
use App\Models\Integration;
use App\Services\LocalizationIndexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Ручной ввод ИЛ/ИРП из ЛК WB должен «замораживать» значения: авто-расчёт
 * их не перетирает, пока wb_indices_manual === true.
 */
class WbIndicesManualLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_lock_skips_auto_localization_calc(): void
    {
        $integration = Integration::factory()->wildberries()->create([
            'id' => 760001,
            'localization_index' => 1.06,
            'settings' => [
                'wb_localization_index' => 1.06,
                'wb_sales_distribution_index' => 0.83,
                'wb_indices_manual' => true,
            ],
        ]);

        // При ручном замке расчёт не должен вызываться вовсе.
        $localization = Mockery::mock(LocalizationIndexService::class);
        $localization->shouldNotReceive('calculateLocalizationIndex');

        (new SyncWildberriesLocalizationJob($integration->id))->handle($localization);

        $integration->refresh();
        $this->assertSame(1.06, (float) $integration->localization_index);
        $this->assertSame(1.06, (float) ($integration->settings['wb_localization_index'] ?? null));
        $this->assertSame(0.83, (float) ($integration->settings['wb_sales_distribution_index'] ?? null));
    }

    public function test_auto_calc_runs_when_not_locked(): void
    {
        Queue::fake();

        $integration = Integration::factory()->wildberries()->create([
            'id' => 760002,
            'localization_index' => 1.0,
            'settings' => ['wb_indices_manual' => false],
        ]);

        $localization = Mockery::mock(LocalizationIndexService::class);
        $localization->shouldReceive('calculateLocalizationIndex')
            ->once()
            ->andReturn([
                'localization_index' => 1.20,
                'sales_distribution_index' => 1.03,
                'ktr_by_article' => ['x' => []],
                'total_orders' => 500,
            ]);

        (new SyncWildberriesLocalizationJob($integration->id))->handle($localization);

        $integration->refresh();
        $this->assertSame(1.20, (float) $integration->localization_index);
        $this->assertSame(1.20, (float) ($integration->settings['wb_localization_index'] ?? null));
    }
}
