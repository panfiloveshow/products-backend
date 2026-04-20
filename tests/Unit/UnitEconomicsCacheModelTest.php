<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\UnitEconomicsCache;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UnitEconomicsCacheModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('unit_economics_cache');
        Schema::dropIfExists('products');

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('sku');
            $table->string('name')->nullable();
            $table->string('marketplace');
            $table->unsignedBigInteger('integration_id')->nullable();
            $table->string('marketplace_id')->nullable();
            $table->timestamps();
        });

        Schema::create('unit_economics_cache', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id');
            $table->uuid('product_id')->nullable();
            $table->string('sku');
            $table->string('product_name')->nullable();
            $table->string('marketplace')->nullable();
            $table->string('fulfillment_type')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('net_profit', 12, 2)->default(0);
            $table->decimal('margin_percent', 12, 2)->default(0);
            $table->decimal('revenue', 12, 2)->default(0);
            $table->timestamp('calculated_at')->nullable();
            $table->integer('data_version')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('unit_economics_cache');
        Schema::dropIfExists('products');

        parent::tearDown();
    }

    public function test_product_relation_uses_product_id_instead_of_shared_sku(): void
    {
        $wrongProduct = Product::create([
            'sku' => 'shared-sku',
            'name' => 'Wrong integration product',
            'marketplace' => 'ozon',
            'integration_id' => 55,
            'marketplace_id' => 'mp-55',
        ]);

        $rightProduct = Product::create([
            'sku' => 'shared-sku',
            'name' => 'Right integration product',
            'marketplace' => 'ozon',
            'integration_id' => 17,
            'marketplace_id' => 'mp-17',
        ]);

        $cache = UnitEconomicsCache::create([
            'integration_id' => 17,
            'product_id' => $rightProduct->id,
            'sku' => 'shared-sku',
            'product_name' => 'Cache row',
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'price' => 100,
        ]);

        $this->assertSame($rightProduct->id, $cache->product?->id);
        $this->assertSame('Right integration product', $cache->product?->name);
        $this->assertNotSame($wrongProduct->id, $cache->product?->id);
    }
}
