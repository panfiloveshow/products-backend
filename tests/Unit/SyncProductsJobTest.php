<?php

namespace Tests\Unit;

use App\Jobs\SyncProductsJob;
use App\Models\Product;
use App\Models\SyncLog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class SyncProductsJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('products');
        Schema::dropIfExists('sync_logs');

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
            $table->decimal('depth', 10, 2)->nullable();
            $table->decimal('width', 10, 2)->nullable();
            $table->decimal('height', 10, 2)->nullable();
            $table->decimal('weight', 10, 2)->nullable();
            $table->decimal('volume_weight', 10, 4)->nullable();
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
            $table->timestamps();

            $table->unique(['marketplace', 'sku', 'integration_id'], 'products_marketplace_sku_integration_unique');
            $table->unique(['marketplace', 'marketplace_id', 'integration_id'], 'products_marketplace_integration_unique');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('sync_logs');

        parent::tearDown();
    }

    public function test_sync_product_creates_separate_product_for_another_integration(): void
    {
        $existingProduct = Product::create([
            'sku' => '2099/black1',
            'name' => 'Old product',
            'price' => 100,
            'stock' => 1,
            'marketplace' => 'ozon',
            'marketplace_id' => '2640527959',
            'integration_id' => 17,
        ]);

        $syncLog = SyncLog::create([
            'marketplace' => 'ozon',
            'integration_id' => 55,
            'sync_type' => 'products',
            'status' => SyncLog::STATUS_PENDING,
        ]);

        $job = new SyncProductsJob($syncLog);
        $method = new ReflectionMethod($job, 'syncProduct');
        $method->setAccessible(true);

        $result = $method->invoke($job, [
            'sku' => '2099/black1',
            'name' => 'Updated product',
            'price' => 250,
            'stock' => 5,
            'marketplace_id' => '2640527959',
        ]);

        $existingProduct->refresh();
        $newProduct = Product::where('marketplace', 'ozon')
            ->where('sku', '2099/black1')
            ->where('integration_id', 55)
            ->first();

        $this->assertSame('created', $result);
        $this->assertSame(2, Product::count());
        $this->assertSame(17, $existingProduct->integration_id);
        $this->assertSame('Old product', $existingProduct->name);
        $this->assertNotNull($newProduct);
        $this->assertSame('Updated product', $newProduct->name);
        $this->assertSame('250.00', $newProduct->price);
        $this->assertSame(5, $newProduct->stock);
    }

    public function test_sync_product_updates_ozon_dimensions_when_they_arrive_later(): void
    {
        $product = Product::create([
            'sku' => 'bag-001',
            'name' => 'Bag',
            'price' => 1000,
            'stock' => 3,
            'marketplace' => 'ozon',
            'marketplace_id' => '123456',
            'integration_id' => 17,
            'ozon_data' => ['dimensions' => ['depth' => null]],
        ]);

        $syncLog = SyncLog::create([
            'marketplace' => 'ozon',
            'integration_id' => 17,
            'sync_type' => 'products',
            'status' => SyncLog::STATUS_PENDING,
        ]);

        $job = new SyncProductsJob($syncLog);
        $method = new ReflectionMethod($job, 'syncProduct');
        $method->setAccessible(true);

        $result = $method->invoke($job, [
            'sku' => 'bag-001',
            'name' => 'Bag',
            'price' => 1000,
            'stock' => 3,
            'marketplace_id' => '123456',
            'depth' => 310,
            'width' => 220,
            'height' => 80,
            'weight' => 540,
            'volume_weight' => 5.456,
            'ozon_data' => [
                'length_mm' => 310,
                'width_mm' => 220,
                'height_mm' => 80,
                'weight_g' => 540,
                'dimensions' => [
                    'depth' => 310,
                    'width' => 220,
                    'height' => 80,
                    'weight' => 540,
                ],
            ],
        ]);

        $product->refresh();

        $this->assertSame('updated', $result);
        $this->assertEquals(310, $product->depth);
        $this->assertEquals(220, $product->width);
        $this->assertEquals(80, $product->height);
        $this->assertEquals(540, $product->weight);
        $this->assertEquals(5.456, $product->volume_weight);
        $this->assertSame(310, $product->ozon_data['length_mm']);
    }
}
