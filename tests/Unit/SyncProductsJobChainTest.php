<?php

namespace Tests\Unit;

use App\Jobs\SyncProductsJob;
use App\Jobs\SyncUnitEconomicsJob;
use App\Models\Integration;
use App\Models\SyncLog;
use App\Services\InventoryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SyncProductsJobChainTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('products');
        Schema::dropIfExists('sync_logs');
        Schema::dropIfExists('integrations');
        Schema::dropIfExists('inventory_warehouses');

        Schema::create('integrations', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('work_space_id')->nullable();
            $table->string('name')->nullable();
            $table->string('marketplace')->nullable();
            $table->text('credentials')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('auto_sync_enabled')->default(true);
            $table->unsignedInteger('sync_interval_hours')->default(6);
            $table->timestamp('last_sync_at')->nullable();
            $table->string('last_sync_status')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_premium')->default(false);
            $table->timestamps();
        });

        Schema::create('sync_logs', function (Blueprint $table) {
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

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('sku');
            $table->string('vendor_code')->nullable();
            $table->string('name')->nullable();
            $table->string('barcode')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('old_price', 12, 2)->nullable();
            $table->integer('stock')->default(0);
            $table->text('description')->nullable();
            $table->json('images')->nullable();
            $table->string('category')->nullable();
            $table->string('brand')->nullable();
            $table->decimal('rating', 5, 2)->nullable();
            $table->unsignedInteger('reviews_count')->default(0);
            $table->string('marketplace');
            $table->string('marketplace_id')->nullable();
            $table->unsignedBigInteger('integration_id')->nullable();
            $table->string('url')->nullable();
            $table->json('wb_data')->nullable();
            $table->json('ozon_data')->nullable();
            $table->json('yandex_data')->nullable();
            $table->string('fulfillment_type')->nullable();
            $table->timestamps();

            $table->unique(['marketplace', 'sku', 'integration_id'], 'products_msi_unique');
        });

        Schema::create('inventory_warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('sku');
            $table->string('marketplace');
            $table->unsignedBigInteger('integration_id')->nullable();
            $table->integer('quantity')->default(0);
            $table->string('warehouse_name')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('inventory_warehouses');
        Schema::dropIfExists('products');
        Schema::dropIfExists('sync_logs');
        Schema::dropIfExists('integrations');

        parent::tearDown();
    }

    /**
     * Фейкаем Ozon API: product/list возвращает 2 товара, product/info/list — детали.
     */
    private function fakeOzonApi(): void
    {
        Http::fake([
            '*/v3/product/list' => Http::response([
                'result' => [
                    'items' => [
                        ['product_id' => 111, 'offer_id' => 'SKU-001'],
                        ['product_id' => 222, 'offer_id' => 'SKU-002'],
                    ],
                    'last_id' => '',
                    'total' => 2,
                ],
            ]),
            '*/v3/product/info/list' => Http::response([
                'items' => [
                    [
                        'id' => 111,
                        'offer_id' => 'SKU-001',
                        'name' => 'Product 1',
                        'price' => '1000.00',
                        'old_price' => '1200.00',
                        'marketing_price' => '1000.00',
                        'stocks' => ['present' => 5, 'reserved' => 0],
                        'barcode' => '123456',
                        'description_category_id' => 1,
                        'images' => [],
                        'visible' => true,
                        'status' => ['state' => 'processed'],
                    ],
                    [
                        'id' => 222,
                        'offer_id' => 'SKU-002',
                        'name' => 'Product 2',
                        'price' => '2000.00',
                        'old_price' => '2500.00',
                        'marketing_price' => '2000.00',
                        'stocks' => ['present' => 3, 'reserved' => 0],
                        'barcode' => '789012',
                        'description_category_id' => 2,
                        'images' => [],
                        'visible' => true,
                        'status' => ['state' => 'processed'],
                    ],
                ],
            ]),
            // Catch-all для остальных запросов
            '*' => Http::response([], 200),
        ]);
    }

    public function test_does_not_dispatch_unit_economics_job_directly_after_successful_products_sync(): void
    {
        Bus::fake([SyncUnitEconomicsJob::class]);
        $this->fakeOzonApi();

        Integration::forceCreate([
            'id' => 17,
            'name' => 'Test Ozon',
            'marketplace' => 'ozon',
            'work_space_id' => 3,
        ]);

        $syncLog = SyncLog::create([
            'marketplace' => 'ozon',
            'integration_id' => 17,
            'sync_type' => 'products',
            'status' => SyncLog::STATUS_PENDING,
            'credentials' => ['client_id' => 'test', 'api_key' => 'test'],
        ]);

        $inventoryService = $this->mock(InventoryService::class);
        $inventoryService->shouldReceive('startSync')->andReturn(
            SyncLog::create([
                'marketplace' => 'ozon',
                'integration_id' => 17,
                'sync_type' => 'inventory',
                'status' => SyncLog::STATUS_PENDING,
            ])
        );

        $job = new SyncProductsJob($syncLog);
        $job->handle($inventoryService);

        Bus::assertNotDispatched(SyncUnitEconomicsJob::class);
    }

    public function test_updates_integration_status_to_completed_on_success(): void
    {
        Bus::fake([SyncUnitEconomicsJob::class]);
        $this->fakeOzonApi();

        Integration::forceCreate([
            'id' => 17,
            'name' => 'Test Ozon',
            'marketplace' => 'ozon',
            'work_space_id' => 3,
            'last_sync_status' => null,
        ]);

        $syncLog = SyncLog::create([
            'marketplace' => 'ozon',
            'integration_id' => 17,
            'sync_type' => 'products',
            'status' => SyncLog::STATUS_PENDING,
            'credentials' => ['client_id' => 'test', 'api_key' => 'test'],
        ]);

        $inventoryService = $this->mock(InventoryService::class);
        $inventoryService->shouldReceive('startSync')->andReturn(
            SyncLog::create([
                'marketplace' => 'ozon',
                'integration_id' => 17,
                'sync_type' => 'inventory',
                'status' => SyncLog::STATUS_PENDING,
            ])
        );

        $job = new SyncProductsJob($syncLog);
        $job->handle($inventoryService);

        $integration = Integration::find(17);
        $this->assertSame('completed', $integration->last_sync_status);
        $this->assertNotNull($integration->last_sync_at);
        $this->assertNull($integration->last_sync_error);
    }

    public function test_updates_integration_status_to_failed_on_error(): void
    {
        Bus::fake([SyncUnitEconomicsJob::class]);

        // Фейкаем API чтобы вернуть ошибку — OzonService поймает 403 и вернёт пустой массив
        Http::fake([
            '*' => Http::response(['error' => 'Forbidden'], 403),
        ]);

        Integration::forceCreate([
            'id' => 17,
            'name' => 'Test Ozon',
            'marketplace' => 'ozon',
            'work_space_id' => 3,
        ]);

        // Создаём предыдущий успешный синк с >30 товарами —
        // пустой ответ API будет воспринят как аномалия и выбросит RuntimeException
        SyncLog::create([
            'marketplace' => 'ozon',
            'integration_id' => 17,
            'sync_type' => 'products',
            'status' => SyncLog::STATUS_COMPLETED,
            'completed_at' => now()->subDay(),
            'metadata' => ['total_from_api' => 100],
        ]);

        $syncLog = SyncLog::create([
            'marketplace' => 'ozon',
            'integration_id' => 17,
            'sync_type' => 'products',
            'status' => SyncLog::STATUS_PENDING,
            'credentials' => ['client_id' => 'test', 'api_key' => 'test'],
        ]);

        $inventoryService = $this->mock(InventoryService::class);

        $job = new SyncProductsJob($syncLog);

        try {
            $job->handle($inventoryService);
        } catch (\RuntimeException) {
            // expected — zero products while previous sync had 100
        }

        $integration = Integration::find(17);
        $this->assertSame('failed', $integration->last_sync_status);
        $this->assertNotNull($integration->last_sync_error);

        Bus::assertNotDispatched(SyncUnitEconomicsJob::class);
    }

    public function test_does_not_dispatch_unit_economics_without_integration_id(): void
    {
        Bus::fake([SyncUnitEconomicsJob::class]);
        $this->fakeOzonApi();

        $syncLog = SyncLog::create([
            'marketplace' => 'ozon',
            'integration_id' => null,
            'sync_type' => 'products',
            'status' => SyncLog::STATUS_PENDING,
            'credentials' => ['client_id' => 'test', 'api_key' => 'test'],
        ]);

        $inventoryService = $this->mock(InventoryService::class);
        $inventoryService->shouldReceive('startSync')->andReturn(
            SyncLog::create([
                'marketplace' => 'ozon',
                'sync_type' => 'inventory',
                'status' => SyncLog::STATUS_PENDING,
            ])
        );

        $job = new SyncProductsJob($syncLog);
        $job->handle($inventoryService);

        Bus::assertNotDispatched(SyncUnitEconomicsJob::class);
    }
}
