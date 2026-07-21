<?php

namespace Tests\Unit;

use App\Console\Commands\SyncUnitEconomicsCommand;
use App\Models\Product;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class OzonCurrentPricePersistenceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_live_ozon_price_is_persisted_without_losing_existing_metadata(): void
    {
        $product = Product::create([
            'sku' => '9251/whiteblue',
            'name' => 'Test product',
            'marketplace' => 'ozon',
            'integration_id' => 17,
            'price' => 2000,
            'ozon_data' => [
                'product_id' => 580727127,
                'order_economics_summary' => ['orders' => 12],
            ],
        ]);

        $command = new SyncUnitEconomicsCommand;
        $method = (new ReflectionClass($command))->getMethod('persistOzonCurrentPrices');

        $persisted = $method->invoke($command, collect([$product]), [
            '9251/whiteblue' => [
                'price' => 2000,
                'old_price' => 4080,
                'marketing_seller_price' => 1700,
                'actual_price' => 1700,
                'price_source' => 'marketing_seller_price',
                'price_observed_at' => '2026-07-21T06:30:00+00:00',
                'is_in_promotion' => true,
                'promotion_discount' => 15.0,
            ],
        ]);

        $fresh = $product->fresh();

        $this->assertSame(1, $persisted);
        $this->assertSame('1700.00', $fresh->price);
        $this->assertSame('4080.00', $fresh->old_price);
        $this->assertSame(1700.0, (float) $fresh->ozon_data['actual_price']);
        $this->assertSame(1700.0, (float) $fresh->ozon_data['marketing_seller_price']);
        $this->assertSame('marketing_seller_price', $fresh->ozon_data['price_source']);
        $this->assertSame('2026-07-21T06:30:00+00:00', $fresh->ozon_data['price_observed_at']);
        $this->assertSame(['orders' => 12], $fresh->ozon_data['order_economics_summary']);
    }

    public function test_zero_or_missing_api_price_never_erases_last_known_price(): void
    {
        $product = Product::create([
            'sku' => 'SKU-ZERO',
            'name' => 'Test product',
            'marketplace' => 'ozon',
            'integration_id' => 17,
            'price' => 990,
            'ozon_data' => ['actual_price' => 990],
        ]);

        $command = new SyncUnitEconomicsCommand;
        $method = (new ReflectionClass($command))->getMethod('persistOzonCurrentPrices');

        $persisted = $method->invoke($command, collect([$product]), [
            'SKU-ZERO' => ['price' => 0, 'actual_price' => 0],
        ]);

        $this->assertSame(0, $persisted);
        $this->assertSame('990.00', $product->fresh()->price);
        $this->assertSame(990, $product->fresh()->ozon_data['actual_price']);
    }
}
