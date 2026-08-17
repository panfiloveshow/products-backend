<?php

namespace Tests\Feature;

use App\Jobs\SyncWildberriesLocalizationJob;
use App\Models\Integration;
use App\Services\LocalizationIndexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * ИРП отключён WB с 13.07.2026, ИЛ — с 15.08.2026 (новость WB Partners от
 * 14.08.2026): джоба расчёта индексов замкнута накоротко и не должна ни звать
 * WB API (общая квота /supplier/sales), ни трогать сохранённые значения.
 */
class WbIndicesManualLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_is_noop_after_indices_disabled(): void
    {
        $integration = Integration::factory()->wildberries()->create([
            'id' => 760001,
            'localization_index' => 1.06,
            'settings' => [
                'wb_localization_index' => 1.06,
                'wb_sales_distribution_index' => 0.83,
                'wb_indices_manual' => false,
            ],
        ]);

        $localization = Mockery::mock(LocalizationIndexService::class);
        $localization->shouldNotReceive('calculateLocalizationIndex');

        (new SyncWildberriesLocalizationJob($integration->id))->handle($localization);

        // Сохранённые значения не перетираются (в расчёте они всё равно игнорируются).
        $integration->refresh();
        $this->assertSame(1.06, (float) $integration->localization_index);
        $this->assertSame(1.06, (float) ($integration->settings['wb_localization_index'] ?? null));
    }
}
