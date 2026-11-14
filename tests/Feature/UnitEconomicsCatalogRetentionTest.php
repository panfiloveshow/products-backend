<?php

namespace Tests\Feature;

use App\Jobs\SyncProductsJob;
use App\Models\Integration;
use App\Models\Product;
use App\Models\SyncLog;
use App\Models\UnitEconomicsCache;
use App\Models\UnitEconomicsSettings;
use App\Models\UnitEconomicsSyncState;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class UnitEconomicsCatalogRetentionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_complete_wb_catalog_prunes_only_absent_cards_and_preserves_legacy_barcodes_of_present_cards(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 62041]);
        $a = Product::factory()->wildberries()->create(['integration_id' => $integration->id, 'sku' => 'A', 'wb_data' => ['nmID' => 123]]);
        $alias = Product::factory()->wildberries()->create(['integration_id' => $integration->id, 'sku' => 'LEGACY-BARCODE', 'wb_data' => ['nmID' => 123, 'price_sync_error_code' => 'price_not_returned', 'price_sync_requested_version' => 1]]);
        $formattedAlias = Product::factory()->wildberries()->create(['integration_id' => $integration->id, 'sku' => 'FORMATTED-ID', 'wb_data' => ['nmID' => ' 00123 ', 'price_sync_error_code' => 'price_not_returned', 'price_sync_requested_version' => 1]]);
        $unknown = Product::factory()->wildberries()->create(['integration_id' => $integration->id, 'sku' => 'UNKNOWN-ID', 'wb_data' => []]);
        $gone = Product::factory()->wildberries()->create(['integration_id' => $integration->id, 'sku' => 'GONE', 'wb_data' => ['nmID' => 999, 'price_sync_error_code' => 'price_not_returned', 'price_sync_requested_version' => 1]]);
        $goneString = Product::factory()->wildberries()->create(['integration_id' => $integration->id, 'sku' => 'GONE-STRING', 'wb_data' => ['nmID' => 998, 'price_sync_error_code' => 'price_not_returned', 'price_sync_requested_version' => '1']]);
        $wrongVersions = collect([true, '01', 0, '0'])->map(fn ($version, $i) => Product::factory()->wildberries()->create([
            'integration_id' => $integration->id, 'sku' => 'WRONG-VERSION-'.$i,
            'wb_data' => ['nmID' => 9000 + $i, 'price_sync_error_code' => 'price_not_returned', 'price_sync_requested_version' => $version],
        ]));
        UnitEconomicsCache::create(['integration_id' => $integration->id, 'product_id' => $gone->id,
            'sku' => 'GONE', 'product_name' => 'GONE', 'marketplace' => 'wildberries', 'fulfillment_type' => 'FBO', 'price' => 1000]);
        UnitEconomicsSettings::create(['integration_id' => $integration->id, 'sku' => 'GONE', 'marketplace' => 'wildberries', 'cost_price' => 50]);
        $stillPriced = Product::factory()->wildberries()->create(['integration_id' => $integration->id, 'sku' => 'STILL-PRICED', 'wb_data' => ['nmID' => 888]]);
        $coordinator = app(\App\Services\UnitEconomics\UnitEconomicsSyncCoordinator::class);
        $run = $coordinator->request($integration);
        UnitEconomicsSyncState::whereKey($integration->id)->update(['publication_revision' => 5]);
        $log = SyncLog::create(['integration_id' => $integration->id, 'marketplace' => 'wildberries', 'sync_type' => 'products',
            'status' => 'running', 'metadata' => ['catalog_complete' => false]]);
        $prune = new \ReflectionMethod(SyncProductsJob::class, 'pruneMissingMarketplaceProducts');
        $snapshot = [['sku' => 'A', 'wb_data' => ['nmID' => 123]]];
        $this->assertSame(0, $prune->invoke(new SyncProductsJob($log), $snapshot));
        $this->assertNotNull($gone->fresh());
        $log->update(['metadata' => ['catalog_complete' => true]]);
        $this->assertSame(0, $prune->invoke(new SyncProductsJob($log), $snapshot));
        $coordinator->updateStage($run->id, 'prices', 'waiting_external', ['complete' => false, 'http_status' => 429], 1);
        $this->assertSame(0, $prune->invoke(new SyncProductsJob($log), $snapshot));
        $coordinator->updateStage($run->id, 'prices', 'partial', ['complete' => true, 'http_status' => 200,
            'response_received_at' => now()->utc()->toIso8601String(), 'next_retry_at' => null], 1);
        $this->assertSame(2, $prune->invoke(new SyncProductsJob($log), $snapshot));
        $this->assertNull($gone->fresh());
        $this->assertNull($goneString->fresh());
        foreach ($wrongVersions as $wrong) $this->assertNotNull($wrong->fresh());
        $this->assertNotNull($a->fresh());
        $this->assertNotNull($alias->fresh());
        $this->assertNotNull($unknown->fresh());
        $this->assertNotNull($formattedAlias->fresh());
        $this->assertNotNull($stillPriced->fresh());
        $this->assertSame(0, UnitEconomicsCache::where('sku', 'GONE')->count());
        $this->assertEquals(50, UnitEconomicsSettings::where('sku', 'GONE')->value('cost_price'));
        $this->assertSame(6, (int) UnitEconomicsSyncState::whereKey($integration->id)->value('publication_revision'));
    }

    public function test_partial_or_unknown_catalog_never_deletes_an_absent_skus_last_good_data(): void
    {
        $integration = Integration::factory()->ozon()->create(['id' => 62024]);
        $a = Product::factory()->ozon()->create(['integration_id' => $integration->id, 'sku' => 'A']);
        $b = Product::factory()->ozon()->create(['integration_id' => $integration->id, 'sku' => 'B']);
        UnitEconomicsCache::create(['integration_id' => $integration->id, 'product_id' => $b->id,
            'sku' => 'B', 'product_name' => 'B', 'marketplace' => 'ozon', 'fulfillment_type' => 'FBO', 'price' => 1000]);
        $log = SyncLog::create(['integration_id' => $integration->id, 'marketplace' => 'ozon', 'sync_type' => 'products',
            'status' => 'running', 'metadata' => ['catalog_complete' => false]]);
        $prune = new \ReflectionMethod(SyncProductsJob::class, 'pruneMissingMarketplaceProducts');
        $this->assertSame(0, $prune->invoke(new SyncProductsJob($log), [['sku' => 'A']]));
        $this->assertNotNull($b->fresh());
        $this->assertSame(1, UnitEconomicsCache::where('sku', 'B')->count());

        $log->update(['metadata' => ['catalog_complete' => true]]);
        $this->assertSame(1, $prune->invoke(new SyncProductsJob($log), [['sku' => 'A']]));
        $this->assertNull($b->fresh());
        $this->assertNotNull($a->fresh());
    }
}
