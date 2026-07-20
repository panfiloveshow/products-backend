<?php

namespace Tests\Unit;

use App\Domains\Wildberries\Api\WildberriesClient;
use App\Domains\Wildberries\Api\WildberriesRateLimitException;
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

    public function test_prices_client_exposes_wb_retry_header_without_blocking_worker(): void
    {
        Http::fake([
            'https://discounts-prices-api.wildberries.ru/api/v2/list/goods/filter*' => Http::response(
                ['title' => 'too many requests'],
                429,
                ['X-Ratelimit-Retry' => '901'],
            ),
        ]);

        $client = new WildberriesClient('test-wb-token');

        $this->assertNull($client->pricesGet('/api/v2/list/goods/filter', [
            'limit' => 1000,
            'offset' => 0,
        ]));
        $this->assertSame(429, $client->getLastResponseStatus());
        $this->assertSame(901, $client->getLastRateLimitRetryAfter());
        Http::assertSentCount(1);
    }

    public function test_price_refresh_preserves_wb_retry_delay_for_queue_job(): void
    {
        Http::fake([
            'https://discounts-prices-api.wildberries.ru/api/v2/list/goods/filter*' => Http::response(
                ['title' => 'too many requests'],
                429,
                ['X-Ratelimit-Retry' => '900'],
            ),
        ]);

        try {
            (new WildberriesPriceRefreshService)->refresh(31, 'test-wb-token');
            $this->fail('Expected the WB rate-limit exception');
        } catch (WildberriesRateLimitException $e) {
            $this->assertSame(900, $e->retryAfterSeconds);
        }

        Http::assertSentCount(1);
    }

    public function test_delayed_job_releases_itself_for_wb_retry_window(): void
    {
        Http::fake([
            'https://discounts-prices-api.wildberries.ru/api/v2/list/goods/filter*' => Http::response(
                ['title' => 'too many requests'],
                429,
                ['X-Ratelimit-Retry' => '900'],
            ),
        ]);
        SyncLog::create([
            'marketplace' => 'wildberries',
            'integration_id' => 58,
            'sync_type' => 'products',
            'status' => SyncLog::STATUS_COMPLETED,
            'credentials' => ['api_key' => 'test-wb-token'],
            'completed_at' => now(),
        ]);

        $job = (new RefreshWildberriesPricesJob(58))->withFakeQueueInteractions();
        $job->handle(new WildberriesPriceRefreshService);

        $job->assertReleased(901);
        Http::assertSentCount(1);
    }
}
