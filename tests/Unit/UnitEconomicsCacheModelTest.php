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
            $table->json('marketplace_data')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 2)->default(0);
            $table->integer('sales_count')->default(0);
            $table->decimal('net_profit', 12, 2)->default(0);
            $table->decimal('margin_percent', 12, 2)->default(0);
            $table->decimal('roi_percent', 12, 2)->default(0);
            $table->decimal('effective_logistics', 12, 2)->default(0);
            $table->decimal('non_local_markup_percent', 8, 2)->default(0);
            $table->decimal('commission_percent', 8, 2)->default(0);
            $table->boolean('is_local_sale')->nullable();
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

    public function test_quick_filters_and_ranges_apply_expected_rows(): void
    {
        UnitEconomicsCache::create([
            'integration_id' => 17,
            'sku' => 'loss-1',
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'price' => 1000,
            'cost_price' => 700,
            'sales_count' => 3,
            'net_profit' => -120,
            'margin_percent' => -12,
            'roi_percent' => -17,
            'effective_logistics' => 180,
            'non_local_markup_percent' => 6.5,
            'commission_percent' => 15,
            'marketplace_data' => ['calculation_confidence' => 'low', 'expected_locality_rate' => 40],
        ]);

        UnitEconomicsCache::create([
            'integration_id' => 17,
            'sku' => 'growth-1',
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'price' => 2000,
            'cost_price' => 900,
            'sales_count' => 12,
            'net_profit' => 650,
            'margin_percent' => 32.5,
            'roi_percent' => 72,
            'effective_logistics' => 95,
            'non_local_markup_percent' => 2.5,
            'commission_percent' => 14,
            'marketplace_data' => ['calculation_confidence' => 'high', 'expected_locality_rate' => 100],
            'is_local_sale' => true,
        ]);

        UnitEconomicsCache::create([
            'integration_id' => 17,
            'sku' => 'nosales-1',
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'price' => 1500,
            'cost_price' => 800,
            'sales_count' => 0,
            'net_profit' => 0,
            'margin_percent' => 0,
            'roi_percent' => 0,
            'effective_logistics' => 110,
            'non_local_markup_percent' => 0.3,
            'commission_percent' => 12,
            'marketplace_data' => ['calculation_confidence' => 'medium', 'expected_locality_rate' => 75],
            'is_local_sale' => null,
        ]);

        $this->assertSame(['loss-1', 'nosales-1'], UnitEconomicsCache::query()->quickFilter('unprofitable')->pluck('sku')->all());
        $this->assertSame(['nosales-1'], UnitEconomicsCache::query()->quickFilter('no_sales_28d')->pluck('sku')->all());
        $this->assertSame(['loss-1'], UnitEconomicsCache::query()->quickFilter('high_non_local_markup')->pluck('sku')->all());
        $this->assertSame(['growth-1'], UnitEconomicsCache::query()->profitRange(500, null)->pluck('sku')->all());
        $this->assertSame(['growth-1'], UnitEconomicsCache::query()->roiRange(50, null)->pluck('sku')->all());
        $this->assertSame(['loss-1'], UnitEconomicsCache::query()->nonLocalMarkupRange(4, null)->pluck('sku')->all());
    }

    public function test_confidence_and_locality_filters_use_marketplace_data(): void
    {
        UnitEconomicsCache::create([
            'integration_id' => 17,
            'sku' => 'local-1',
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'sales_count' => 10,
            'net_profit' => 100,
            'marketplace_data' => ['calculation_confidence' => 'high', 'expected_locality_rate' => 100],
            'is_local_sale' => true,
        ]);

        UnitEconomicsCache::create([
            'integration_id' => 17,
            'sku' => 'mixed-1',
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'sales_count' => 5,
            'net_profit' => 50,
            'marketplace_data' => ['calculation_confidence' => 'low', 'expected_locality_rate' => 45],
            'is_local_sale' => null,
        ]);

        UnitEconomicsCache::create([
            'integration_id' => 17,
            'sku' => 'nonlocal-1',
            'marketplace' => 'ozon',
            'fulfillment_type' => 'FBO',
            'sales_count' => 4,
            'net_profit' => 20,
            'marketplace_data' => ['calculation_confidence' => 'medium', 'expected_locality_rate' => 0],
            'is_local_sale' => false,
        ]);

        $this->assertSame(['mixed-1'], UnitEconomicsCache::query()->confidence('low')->pluck('sku')->all());
        $this->assertSame(['local-1'], UnitEconomicsCache::query()->localityState('local')->pluck('sku')->all());
        $this->assertSame(['nonlocal-1'], UnitEconomicsCache::query()->localityState('non_local')->pluck('sku')->all());
        $this->assertSame(['mixed-1'], UnitEconomicsCache::query()->localityState('mixed')->pluck('sku')->all());
        $this->assertSame(['mixed-1'], UnitEconomicsCache::query()->quickFilter('low_confidence')->pluck('sku')->all());
    }
}
