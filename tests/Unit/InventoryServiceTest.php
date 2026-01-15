<?php

namespace Tests\Unit;

use App\Services\InventoryService;
use App\Models\Product;
use App\Models\InventoryWarehouse;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class InventoryServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private InventoryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InventoryService();
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
}
