<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\OzonOrderUnitEconomics;
use App\Models\OzonWarehouseCluster;
use App\Models\Posting;
use App\Models\PostingItem;
use App\Models\Product;
use App\Services\Ozon\OzonLocalityService;
use App\Services\Ozon\OzonOrderUnitEconomicsService;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class OzonOrderEconomicsRecoveryTest extends TestCase
{
    public function test_warehouse_lookups_do_not_write_shared_database_cache_inside_a_transaction(): void
    {
        $previous = config('cache.default');
        config(['cache.default' => 'database']);
        $warehouse = 'CODEX-TX-'.bin2hex(random_bytes(8));
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^(insert|update|delete).*\bcache\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        DB::beginTransaction();
        try {
            $row = OzonWarehouseCluster::create(['warehouse_name' => $warehouse,
                'warehouse_name_normalized' => OzonWarehouseCluster::normalizeWarehouseName($warehouse),
                'cluster_id' => 1, 'cluster_name' => 'Test']);
            $this->assertSame($row->id, OzonWarehouseCluster::findByWarehouseName($warehouse)?->id);
            $this->assertNull(OzonWarehouseCluster::findByWarehouseName($warehouse.'-MISSING'));
            $this->assertNull(OzonWarehouseCluster::findByWarehouseName($warehouse.'-MISSING'));
            $this->assertSame([], $writes, 'A shared cache write can deadlock unrelated seller transactions.');
        } finally {
            DB::rollBack();
            config(['cache.default' => $previous]);
        }
    }

    public function test_deadlocked_chunk_is_retried_and_returns_only_committed_results(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $integration = Integration::factory()->ozon()->create(['id' => random_int(71000, 79000)]);
        try {
            $posting = Posting::create(['integration_id' => $integration->id, 'marketplace' => 'ozon',
                'posting_number' => 'retry-test', 'status' => 'delivered']);
            foreach (['A', 'B'] as $sku) {
                PostingItem::create(['posting_id' => $posting->id, 'sku' => $sku, 'offer_id' => $sku, 'name' => $sku, 'quantity' => 1, 'price' => 100]);
            }
            $calls = 0;
            $service = Mockery::mock(OzonOrderUnitEconomicsService::class, [])->makePartial();
            $service->shouldReceive('upsertForPostingItem')->andReturnUsing(function ($posting, $item) use (&$calls) {
                if (++$calls === 2) {
                    throw new DeadlockException('deadlock detected');
                }

                return new OzonOrderUnitEconomics(['sku' => $item->sku]);
            });
            $rows = $service->syncForIntegration($integration->id);
            $this->assertSame(['A', 'B'], $rows->pluck('sku')->sort()->values()->all());
            $this->assertSame(4, $calls);
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            DB::table('posting_items')->whereIn('posting_id', DB::table('postings')->where('integration_id', $integration->id)->select('id'))->delete();
            DB::table('postings')->where('integration_id', $integration->id)->delete();
            $integration->delete();
        }
    }

    public function test_order_does_not_use_dimensions_from_another_sellers_matching_sku(): void
    {
        DB::beginTransaction();
        try {
            $seller = Integration::factory()->ozon()->create(['id' => random_int(71000, 75000)]);
            $other = Integration::factory()->ozon()->create(['id' => random_int(76000, 79000)]);
            $foreign = Product::factory()->ozon()->create(['integration_id' => $other->id, 'sku' => 'SHARED',
                'ozon_data' => ['length_mm' => 1000, 'width_mm' => 1000, 'height_mm' => 1000]]);
            Product::factory()->ozon()->create(['integration_id' => $seller->id, 'sku' => 'SHARED',
                'ozon_data' => ['length_mm' => 100, 'width_mm' => 100, 'height_mm' => 100]]);
            $posting = Posting::create(['integration_id' => $seller->id, 'marketplace' => 'ozon',
                'posting_number' => 'scoped-test', 'status' => 'cancelled']);
            $item = PostingItem::create(['posting_id' => $posting->id, 'sku' => 'SHARED', 'offer_id' => 'SHARED',
                'name' => 'Shared SKU', 'quantity' => 1, 'price' => 100]);
            $item->setRelation('product', $foreign);
            $row = (new OzonOrderUnitEconomicsService)->upsertForPostingItem($posting, $item);
            $this->assertEquals(1.0, (float) $row->volume_liters);
            $this->assertSame($seller->id, $row->integration_id);
        } finally {
            DB::rollBack();
        }
    }

    public function test_seller_order_count_is_a_fresh_single_snapshot_for_each_sync(): void
    {
        DB::beginTransaction();
        try {
            $seller = Integration::factory()->ozon()->create(['id' => random_int(71000, 79000)]);
            $posting = Posting::create(['integration_id' => $seller->id, 'marketplace' => 'ozon',
                'posting_number' => 'snapshot-test', 'status' => 'delivered']);
            foreach (['A', 'B'] as $sku) {
                PostingItem::create(['posting_id' => $posting->id, 'sku' => $sku, 'offer_id' => $sku,
                    'name' => $sku, 'quantity' => 1, 'price' => 100]);
            }
            $locality = Mockery::mock(OzonLocalityService::class);
            $locality->shouldReceive('countSellerFboOrders7Days')->with($seller->id)->twice()->andReturn(0, 100);
            $service = new OzonOrderUnitEconomicsService(localityService: $locality);
            $first = $service->syncForIntegration($seller->id);
            $this->assertSame(['fbo_lt_50_orders_7d'], $first->pluck('markup_reason_code')->unique()->values()->all());
            $second = $service->syncForIntegration($seller->id);
            $this->assertCount(2, $second);
            $this->assertFalse($second->contains('markup_reason_code', 'fbo_lt_50_orders_7d'));
        } finally {
            DB::rollBack();
        }
    }
}
