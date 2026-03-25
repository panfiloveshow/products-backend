<?php

namespace Tests\Unit;

use App\Models\Integration;
use App\Models\Product;
use App\Models\SyncLog;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ProductService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->service = new ProductService;
    }

    /**
     * Тест получения статистики продуктов - структура ответа
     */
    public function test_get_products_stats_returns_correct_structure(): void
    {
        Product::factory()->count(5)->create([
            'marketplace' => 'ozon',
            'stock' => 10,
        ]);

        Product::factory()->count(3)->create([
            'marketplace' => 'wildberries',
            'stock' => 5,
        ]);

        $result = $this->service->getProductsStats();

        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('by_marketplace', $result);
        $this->assertArrayHasKey('in_stock', $result);
        $this->assertArrayHasKey('out_of_stock', $result);
        $this->assertArrayHasKey('average_price', $result);

        $this->assertEquals(8, $result['total']);
    }

    /**
     * Тест статистики по маркетплейсам
     */
    public function test_get_products_stats_by_marketplace(): void
    {
        Product::factory()->count(10)->create(['marketplace' => 'ozon']);
        Product::factory()->count(5)->create(['marketplace' => 'wildberries']);

        $result = $this->service->getProductsStats();

        $this->assertArrayHasKey('ozon', $result['by_marketplace']);
        $this->assertArrayHasKey('wildberries', $result['by_marketplace']);
        $this->assertEquals(10, $result['by_marketplace']['ozon']['count']);
        $this->assertEquals(5, $result['by_marketplace']['wildberries']['count']);
    }

    /**
     * Тест статистики с пустой базой
     */
    public function test_get_products_stats_with_empty_database(): void
    {
        $result = $this->service->getProductsStats();

        $this->assertEquals(0, $result['total']);
        $this->assertEmpty($result['by_marketplace']);
    }

    /**
     * Тест запуска синхронизации создаёт SyncLog
     */
    public function test_start_sync_creates_sync_log(): void
    {
        $credentials = ['api_key' => 'test_key'];

        $syncLog = $this->service->startSync('ozon', $credentials);

        $this->assertInstanceOf(SyncLog::class, $syncLog);
        $this->assertEquals('ozon', $syncLog->marketplace);
        $this->assertEquals(SyncLog::STATUS_PENDING, $syncLog->status);
    }

    /**
     * yandex_market в логах сохраняется как yandex (совместимость со старым ENUM в БД).
     */
    public function test_start_sync_maps_yandex_market_to_yandex_in_sync_log(): void
    {
        $credentials = ['token' => 't', 'campaign_id' => 'c'];

        $syncLog = $this->service->startSync('yandex_market', $credentials, 21);

        $this->assertEquals('yandex', $syncLog->marketplace);
    }

    /**
     * Тест запуска синхронизации с integration_id
     */
    public function test_start_sync_with_integration_id(): void
    {
        $integration = Integration::factory()->create([
            'marketplace' => 'ozon',
            'credentials' => ['client_id' => 'test', 'api_key' => 'test'],
        ]);

        $credentials = ['client_id' => 'test', 'api_key' => 'test'];

        $syncLog = $this->service->startSync('ozon', $credentials, $integration->id);

        $this->assertEquals($integration->id, $syncLog->integration_id);
    }

    /**
     * Тест блокировки дублирующей синхронизации
     */
    public function test_start_sync_blocks_duplicate(): void
    {
        $credentials = ['api_key' => 'test_key'];

        $existing = SyncLog::create([
            'marketplace' => 'ozon',
            'sync_type' => 'products',
            'status' => SyncLog::STATUS_RUNNING,
            'credentials' => $credentials,
        ]);

        $syncLog = $this->service->startSync('ozon', $credentials);

        $this->assertSame($existing->id, $syncLog->id);
        $this->assertEquals(SyncLog::STATUS_RUNNING, $syncLog->status);
    }

    /**
     * Тест подсчёта товаров в наличии
     */
    public function test_count_in_stock_products(): void
    {
        Product::factory()->count(5)->create(['stock' => 10]);
        Product::factory()->count(3)->create(['stock' => 0]);

        $result = $this->service->getProductsStats();

        $this->assertEquals(5, $result['in_stock']);
        $this->assertEquals(3, $result['out_of_stock']);
    }

    /**
     * Тест статистики с фильтром по маркетплейсу
     */
    public function test_get_products_stats_with_marketplace_filter(): void
    {
        Product::factory()->count(5)->create(['marketplace' => 'ozon']);
        Product::factory()->count(3)->create(['marketplace' => 'wildberries']);

        $result = $this->service->getProductsStats(['marketplace' => 'ozon']);

        $this->assertEquals(5, $result['total']);
    }

    public function test_get_products_stats_uses_inventory_warehouse_stock(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite schema for inventory_warehouses has incompatible foreign key constraints in tests.');
        }

        Product::factory()->create([
            'marketplace' => 'wildberries',
            'integration_id' => 36,
            'sku' => '8901414000636',
            'price' => 650,
            'stock' => 0,
        ]);

        $this->insertInventoryRow([
            'marketplace' => 'wildberries',
            'integration_id' => 36,
            'sku' => '8901414000636',
            'warehouse_id' => 'wb-1',
            'quantity' => 7,
        ]);

        $result = $this->service->getProductsStats([
            'marketplace' => 'wildberries',
            'integration_id' => 36,
        ]);

        $this->assertEquals(1, $result['total']);
        $this->assertEquals(1, $result['in_stock']);
        $this->assertEquals(0, $result['out_of_stock']);
        $this->assertEquals(4550.0, $result['total_value']);
    }

    public function test_get_products_stats_filters_inventory_by_selected_integration(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite schema for inventory_warehouses has incompatible foreign key constraints in tests.');
        }

        Product::factory()->create([
            'marketplace' => 'wildberries',
            'integration_id' => 36,
            'sku' => 'SKU-INTEG-36',
            'stock' => 0,
        ]);

        Product::factory()->create([
            'marketplace' => 'wildberries',
            'integration_id' => 99,
            'sku' => 'SKU-INTEG-99',
            'stock' => 0,
        ]);

        $this->insertInventoryRow([
            'marketplace' => 'wildberries',
            'integration_id' => 36,
            'sku' => 'SKU-INTEG-36',
            'warehouse_id' => 'wb-36',
            'quantity' => 5,
        ]);

        $this->insertInventoryRow([
            'marketplace' => 'wildberries',
            'integration_id' => 99,
            'sku' => 'SKU-INTEG-99',
            'warehouse_id' => 'wb-99',
            'quantity' => 0,
        ]);

        $for36 = $this->service->getProductsStats([
            'marketplace' => 'wildberries',
            'integration_id' => 36,
        ]);

        $for99 = $this->service->getProductsStats([
            'marketplace' => 'wildberries',
            'integration_id' => 99,
        ]);

        $this->assertEquals(1, $for36['in_stock']);
        $this->assertEquals(0, $for99['in_stock']);
    }

    private function insertInventoryRow(array $attributes): void
    {
        Schema::withoutForeignKeyConstraints(function () use ($attributes) {
            DB::table('inventory_warehouses')->insert(array_merge([
                'id' => (string) Str::uuid(),
                'warehouse_name' => 'Test warehouse',
                'marketplace' => 'wildberries',
                'quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ], $attributes));
        });
    }
}
