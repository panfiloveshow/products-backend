<?php

namespace Tests\Unit;

use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Models\SyncLog;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class InventoryServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private InventoryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InventoryService;
    }

    /**
     * Тест форматирования данных инвентаря продукта
     */
    public function test_format_product_inventory_returns_correct_structure(): void
    {
        $product = Product::factory()->create([
            'sku' => 'TEST-SKU-001',
            'name' => 'Test Product',
            'price' => 1000,
            'stock' => 50,
            'marketplace' => 'ozon',
        ]);

        $result = $this->service->formatProductInventory($product);

        $this->assertArrayHasKey('sku', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('total_stock', $result);
        $this->assertArrayHasKey('marketplace', $result);

        $this->assertEquals('TEST-SKU-001', $result['sku']);
        $this->assertEquals('Test Product', $result['name']);
    }

    /**
     * Тест получения статистики синхронизации
     */
    public function test_get_sync_statuses_returns_array(): void
    {
        $result = $this->service->getSyncStatuses();

        $this->assertIsArray($result);
    }

    /**
     * Тест обработки продукта без остатков
     */
    public function test_format_product_inventory_with_zero_stock(): void
    {
        $product = Product::factory()->create([
            'sku' => 'TEST-SKU-005',
            'stock' => 0,
        ]);

        $result = $this->service->formatProductInventory($product);

        $this->assertEquals(0, $result['total_stock']);
    }

    public function test_format_product_inventory_uses_warehouse_stock_without_adding_product_stock(): void
    {
        $product = Product::factory()->create([
            'sku' => 'TEST-SKU-WH-001',
            'stock' => 50,
            'marketplace' => 'yandex',
            'integration_id' => 101,
        ]);

        $product->setRelation('inventoryWarehouses', new Collection([
            new InventoryWarehouse([
                'sku' => 'TEST-SKU-WH-001',
                'warehouse_id' => 'ym-warehouse-1',
                'warehouse_name' => 'Yandex Warehouse',
                'marketplace' => 'yandex',
                'integration_id' => 101,
                'quantity' => 7,
                'average_daily_sales' => 1,
                'last_updated' => now(),
            ]),
        ]));

        $result = $this->service->formatProductInventory($product);

        $this->assertEquals(7, $result['total_stock']);
    }

    public function test_format_product_inventory_ignores_same_sku_warehouses_from_other_integrations(): void
    {
        $product = Product::factory()->create([
            'sku' => 'TEST-SKU-WH-002',
            'stock' => 50,
            'marketplace' => 'yandex',
            'integration_id' => 201,
        ]);

        $product->setRelation('inventoryWarehouses', new Collection([
            new InventoryWarehouse([
                'sku' => 'TEST-SKU-WH-002',
                'warehouse_id' => 'own-warehouse',
                'warehouse_name' => 'Own Warehouse',
                'marketplace' => 'yandex',
                'integration_id' => 201,
                'quantity' => 5,
                'last_updated' => now(),
            ]),
            new InventoryWarehouse([
                'sku' => 'TEST-SKU-WH-002',
                'warehouse_id' => 'foreign-warehouse',
                'warehouse_name' => 'Foreign Warehouse',
                'marketplace' => 'yandex',
                'integration_id' => 202,
                'quantity' => 100,
                'last_updated' => now(),
            ]),
        ]));

        $result = $this->service->formatProductInventory($product);

        $this->assertEquals(5, $result['total_stock']);
        $this->assertCount(1, $result['marketplace_warehouses']);
        $this->assertSame('own-warehouse', $result['marketplace_warehouses'][0]['warehouse_id']);
    }

    /**
     * Тест что sales_trend присутствует в ответе
     */
    public function test_format_product_inventory_has_sales_trend(): void
    {
        $product = Product::factory()->create([
            'sku' => 'TEST-SKU-006',
            'marketplace' => 'ozon',
        ]);

        $result = $this->service->formatProductInventory($product);

        $this->assertArrayHasKey('sales_trend', $result);
        $this->assertContains($result['sales_trend'], ['stable', 'growing', 'declining']);
    }

    public function test_start_sync_allows_inventory_for_different_integration_while_another_running(): void
    {
        $credentials = ['api_key' => 'k'];

        SyncLog::create([
            'marketplace' => 'wildberries',
            'integration_id' => 1,
            'sync_type' => 'inventory',
            'status' => SyncLog::STATUS_RUNNING,
            'credentials' => $credentials,
        ]);

        $log = $this->service->startSync('wildberries', $credentials, 2);

        $this->assertNotEquals(1, $log->integration_id);
        $this->assertEquals(2, $log->integration_id);
    }

    public function test_start_sync_blocks_duplicate_for_same_integration(): void
    {
        $credentials = ['api_key' => 'k'];

        $existing = SyncLog::create([
            'marketplace' => 'ozon',
            'integration_id' => 10,
            'sync_type' => 'inventory',
            'status' => SyncLog::STATUS_PENDING,
            'credentials' => $credentials,
        ]);

        $again = $this->service->startSync('ozon', $credentials, 10);

        $this->assertSame($existing->id, $again->id);
    }
}
