<?php

namespace Tests\Unit;

use App\Jobs\RecalculateUnitEconomicsCacheJob;
use App\Jobs\RefreshWildberriesPricesJob;
use App\Models\Product;
use App\Models\SyncLog;
use App\Services\Wildberries\WildberriesPriceRefreshService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RefreshWildberriesPricesJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=']);

        Schema::dropIfExists('products');
        Schema::dropIfExists('sync_logs');

        Schema::create('sync_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('marketplace');
            $table->unsignedBigInteger('integration_id')->nullable();
            $table->string('sync_type')->default('products');
            $table->string('status')->default('pending');
            $table->unsignedInteger('items_synced')->default(0);
            $table->unsignedInteger('items_failed')->default(0);
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->text('credentials')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('sku');
            $table->string('vendor_code')->nullable();
            $table->string('name')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('old_price', 12, 2)->nullable();
            $table->string('marketplace');
            $table->unsignedBigInteger('integration_id')->nullable();
            $table->json('wb_data')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('sync_logs');

        parent::tearDown();
    }

    public function test_delayed_refresh_updates_product_and_dispatches_unit_economics_recalculation(): void
    {
        Queue::fake();
        Http::fake([
            'https://discounts-prices-api.wildberries.ru/api/v2/list/goods/filter*' => Http::response([
                'data' => [
                    'listGoods' => [[
                        'nmID' => 184010769,
                        'vendorCode' => 'vendor-1',
                        'discount' => 26,
                        'sizes' => [[
                            'sizeID' => 303199009,
                            'price' => 1300,
                            'discountedPrice' => 962,
                            'clubDiscountedPrice' => 962,
                        ]],
                    ]],
                ],
            ]),
        ]);

        SyncLog::create([
            'marketplace' => 'wildberries',
            'integration_id' => 31,
            'sync_type' => 'products',
            'status' => SyncLog::STATUS_COMPLETED,
            'credentials' => ['api_key' => 'test-wb-token'],
            'completed_at' => now(),
        ]);
        $product = Product::create([
            'sku' => '2038844790366',
            'vendor_code' => 'vendor-1',
            'name' => 'WB product',
            'price' => 936,
            'old_price' => 1300,
            'marketplace' => 'wildberries',
            'integration_id' => 31,
            'wb_data' => [
                'nmID' => 184010769,
                'sizeID' => 303199009,
                'actual_price' => 936,
                'price_source' => 'prices_api_size',
            ],
        ]);

        (new RefreshWildberriesPricesJob(31))->handle(new WildberriesPriceRefreshService);

        $product->refresh();
        $this->assertSame('962.00', $product->price);
        $this->assertSame('1300.00', $product->old_price);
        $this->assertEquals(962.0, $product->wb_data['actual_price']);
        $this->assertSame('prices_api_size', $product->wb_data['price_source']);
        $this->assertNotEmpty($product->wb_data['price_synced_at']);
        Queue::assertPushed(
            RecalculateUnitEconomicsCacheJob::class,
            fn (RecalculateUnitEconomicsCacheJob $queued) => $queued->integrationId === 31
        );
    }
}
