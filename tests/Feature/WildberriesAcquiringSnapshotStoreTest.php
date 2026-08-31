<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\UnitEconomics;
use App\Services\Wildberries\WildberriesAcquiringSnapshotStore;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class WildberriesAcquiringSnapshotStoreTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_persists_one_report_snapshot_for_all_existing_schemes_and_reuses_it(): void
    {
        Carbon::setTestNow('2026-08-31 12:00:00');
        $integration = Integration::factory()->wildberries()->create(['id' => 99101]);
        foreach (['FBO', 'FBS'] as $scheme) {
            UnitEconomics::create([
                'integration_id' => $integration->id,
                'sku' => 'bag-1',
                'marketplace' => 'wildberries',
                'fulfillment_type' => $scheme,
                'price' => 1000,
                'acquiring_percent' => 1.5,
            ]);
        }

        $store = new WildberriesAcquiringSnapshotStore;
        $updated = $store->persist($integration->id, [
            'by_sku' => ['bag-1' => 3.5, 'missing-product-alias' => 2.1],
            'avg' => 3.2,
            'observed_at' => '2026-08-31T11:55:00+00:00',
        ]);

        $this->assertSame(2, $updated);
        $this->assertSame(
            [3.5],
            UnitEconomics::where('integration_id', $integration->id)
                ->pluck('acquiring_percent')
                ->map(fn ($value) => (float) $value)
                ->unique()
                ->values()
                ->all()
        );

        $fresh = $store->loadFresh($integration->id);
        $this->assertTrue($fresh['is_fresh']);
        $this->assertSame(3.5, $fresh['by_sku']['bag-1']);
        $this->assertSame(3.2, $fresh['avg']);
        $this->assertSame('2026-08-31T11:55:00+00:00', $fresh['observed_at']);
    }

    public function test_stale_snapshot_is_not_used_to_suppress_finance_refresh(): void
    {
        Carbon::setTestNow('2026-08-31 12:00:00');
        $integration = Integration::factory()->wildberries()->create([
            'id' => 99102,
            'settings' => [
                'wb_acquiring_avg' => 3.2,
                'wb_acquiring_observed_at' => '2026-08-29T00:00:00+00:00',
            ],
        ]);

        $fresh = (new WildberriesAcquiringSnapshotStore)->loadFresh($integration->id);

        $this->assertFalse($fresh['is_fresh']);
        $this->assertSame([], $fresh['by_sku']);
    }
}
