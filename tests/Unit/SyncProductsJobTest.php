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

            $table->unique(['sku', 'marketplace'], 'products_sku_marketplace_unique');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('sync_logs');

        parent::tearDown();
    }

    public function test_sync_product_rebinds_existing_unique_product_to_current_integration(): void
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

        $this->assertSame('updated', $result);
        $this->assertSame(1, Product::count());
        $this->assertSame(55, $existingProduct->integration_id);
        $this->assertSame('Updated product', $existingProduct->name);
        $this->assertSame('250.00', $existingProduct->price);
        $this->assertSame(5, $existingProduct->stock);
    }
}
