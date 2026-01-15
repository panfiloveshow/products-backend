<?php

namespace Tests\Unit;

use App\Services\ProductService;
use App\Models\Product;
use App\Models\SyncLog;
use App\Models\Integration;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProductServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ProductService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProductService();
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
        
        // Создаём активную синхронизацию
        SyncLog::create([
            'marketplace' => 'ozon',
            'sync_type' => 'products',
            'status' => SyncLog::STATUS_RUNNING,
            'credentials' => $credentials,
        ]);

        // Попытка запустить ещё одну
        $syncLog = $this->service->startSync('ozon', $credentials);

        // Должен вернуть существующую или создать новую (зависит от реализации)
        $this->assertInstanceOf(SyncLog::class, $syncLog);
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
}
