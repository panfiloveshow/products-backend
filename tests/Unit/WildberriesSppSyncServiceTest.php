<?php

namespace Tests\Unit;

use App\Models\Integration;
use App\Services\Wildberries\WildberriesSppDataProvider;
use App\Services\Wildberries\WildberriesSppSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WildberriesSppSyncServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('products', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('integration_id');
            $table->string('sku');
            $table->string('marketplace');
            $table->decimal('price', 12, 2)->nullable();
            $table->text('wb_data')->nullable();
            $table->timestamps();
        });
        Schema::create('unit_economics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id');
            $table->string('sku');
            $table->string('marketplace');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('spp_percent', 7, 2)->nullable();
            $table->decimal('spp_amount', 12, 2)->nullable();
            $table->decimal('customer_price', 12, 2)->nullable();
            $table->text('marketplace_data')->nullable();
            $table->timestamps();
        });
        Schema::create('unit_economics_cache', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id');
            $table->string('sku');
            $table->string('marketplace');
            $table->decimal('price', 12, 2)->default(0);
            $table->text('marketplace_data')->nullable();
            $table->timestamps();
        });
        Schema::create('wildberries_spp_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id');
            $table->string('sku');
            $table->string('nm_id')->nullable();
            $table->decimal('spp_percent', 7, 2);
            $table->decimal('seller_price', 14, 2)->nullable();
            $table->decimal('customer_price', 14, 2)->nullable();
            $table->string('source');
            $table->timestamp('observed_at');
            $table->timestamps();
            $table->unique(['integration_id', 'sku']);
        });
        Schema::create('unit_economics_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id');
            $table->string('sku');
            $table->decimal('spp_percent', 7, 2)->nullable();
            $table->timestamps();
            $table->unique(['integration_id', 'sku']);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('unit_economics_settings');
        Schema::dropIfExists('wildberries_spp_snapshots');
        Schema::dropIfExists('unit_economics_cache');
        Schema::dropIfExists('unit_economics');
        Schema::dropIfExists('products');
        parent::tearDown();
    }

    public function test_updates_only_spp_projections_and_preserves_last_snapshot(): void
    {
        DB::table('products')->insert([
            [
                'id' => 'p1', 'integration_id' => 76, 'sku' => 'BAR-1', 'marketplace' => 'wildberries',
                'price' => 1000, 'wb_data' => json_encode(['nmID' => 100]), 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => 'p2', 'integration_id' => 76, 'sku' => 'BAR-2', 'marketplace' => 'wildberries',
                'price' => 2000, 'wb_data' => json_encode(['nmID' => 200, 'spp_synced_at' => '2026-01-01T00:00:00+00:00']),
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);
        foreach (['unit_economics', 'unit_economics_cache'] as $table) {
            DB::table($table)->insert([
                ['integration_id' => 76, 'sku' => 'BAR-1', 'marketplace' => 'wildberries', 'price' => 1000, 'marketplace_data' => '{}', 'created_at' => now(), 'updated_at' => now()],
                ['integration_id' => 76, 'sku' => 'BAR-2', 'marketplace' => 'wildberries', 'price' => 2000, 'marketplace_data' => json_encode(['spp_synced_at' => '2026-01-01T00:00:00+00:00']), 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
        DB::table('wildberries_spp_snapshots')->insert([
            'integration_id' => 76,
            'sku' => 'BAR-2',
            'nm_id' => '200',
            'spp_percent' => 15,
            'seller_price' => 2000,
            'customer_price' => 1700,
            'source' => 'sales',
            'observed_at' => '2026-01-01 00:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('unit_economics_settings')->insert([
            ['integration_id' => 76, 'sku' => 'BAR-1', 'spp_percent' => 5.8, 'created_at' => now(), 'updated_at' => now()],
            ['integration_id' => 76, 'sku' => 'BAR-2', 'spp_percent' => 7, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $provider = $this->createMock(WildberriesSppDataProvider::class);
        $provider->expects($this->once())->method('fetch')->willReturn([
            'report_available' => true,
            'report_source' => 'sales',
            'spp_by_sku' => ['BAR-1' => 0.0],
            'spp_by_nm_id' => ['100' => 10.0],
            'card_spp_by_nm_id' => ['100' => 20.0],
        ]);

        $integration = new Integration(['marketplace' => 'wildberries']);
        $integration->id = 76;
        $result = (new WildberriesSppSyncService($provider))->sync($integration, 1);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['preserved']);
        $this->assertDatabaseHas('unit_economics', ['sku' => 'BAR-1', 'spp_percent' => 0, 'customer_price' => 1000]);
        $this->assertDatabaseHas('unit_economics', ['sku' => 'BAR-2', 'spp_percent' => 15, 'customer_price' => 1700]);
        $this->assertDatabaseHas('unit_economics_settings', ['sku' => 'BAR-1', 'spp_percent' => 0]);
        $this->assertDatabaseHas('unit_economics_settings', ['sku' => 'BAR-2', 'spp_percent' => 7]);
        $this->assertSame(15.0, (float) DB::table('wildberries_spp_snapshots')->where('sku', 'BAR-2')->value('spp_percent'));

        $productData = json_decode(DB::table('products')->where('sku', 'BAR-2')->value('wb_data'), true);
        $cacheData = json_decode(DB::table('unit_economics_cache')->where('sku', 'BAR-2')->value('marketplace_data'), true);
        $this->assertSame('2026-01-01T00:00:00+00:00', $productData['spp_synced_at']);
        $this->assertSame('2026-01-01T00:00:00+00:00', $cacheData['spp_synced_at']);
    }
}
