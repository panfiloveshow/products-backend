<?php

namespace Tests\Unit;

use App\Jobs\SyncSalesJob;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * WB-ветка SyncSalesJob: продажи пишутся в строку конкретного склада,
 * склады SKU без продаж получают ноль (не общий тотал), а итог по SKU
 * (Product) равен сумме пер-складских значений.
 */
class SyncSalesJobWbWarehouseSalesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_wb_sales_written_per_warehouse_with_honest_zero_and_sku_total_on_product(): void
    {
        $integrationId = 7701;

        $product = Product::factory()->create([
            'sku' => '2038000000001',
            'marketplace' => 'wildberries',
            'integration_id' => $integrationId,
            'stock' => 80,
        ]);

        $koledino = InventoryWarehouse::factory()->wildberries()->create([
            'sku' => '2038000000001',
            'warehouse_id' => 'wb_1',
            'warehouse_name' => 'Коледино',
            'integration_id' => $integrationId,
            'quantity' => 30,
        ]);
        $kazan = InventoryWarehouse::factory()->wildberries()->create([
            'sku' => '2038000000001',
            'warehouse_id' => 'wb_2',
            'warehouse_name' => 'Казань',
            'integration_id' => $integrationId,
            'quantity' => 50,
        ]);

        $map = [
            '2038000000001' => [
                'Коледино' => [
                    'sales_7_days' => 14,
                    'sales_14_days' => 28,
                    'sales_30_days' => 60,
                    'avg_daily_sales' => 2.0,
                ],
            ],
        ];

        $job = new SyncSalesJob('wildberries');
        $method = new ReflectionMethod($job, 'syncWildberriesSalesByWarehouse');
        $method->setAccessible(true);
        $method->invoke($job, $map, $integrationId, null);

        $koledino->refresh();
        $kazan->refresh();
        $product->refresh();

        // Склад с продажами — свои значения и свои дни запаса (30 шт / 2 в день = 15).
        $this->assertSame(60, (int) $koledino->sales_30_days);
        $this->assertSame(14, (int) $koledino->sales_7_days);
        $this->assertEqualsWithDelta(2.0, (float) $koledino->average_daily_sales, 0.001);
        $this->assertSame(15, (int) $koledino->days_of_stock);

        // Склад без продаж — честный ноль, а не размазанный тотал SKU.
        $this->assertSame(0, (int) $kazan->sales_30_days);
        $this->assertSame(0, (int) $kazan->sales_7_days);
        $this->assertEqualsWithDelta(0.0, (float) $kazan->average_daily_sales, 0.001);
        $this->assertSame(999, (int) $kazan->days_of_stock);

        // Инвариант: итог по SKU = сумме пер-складских значений.
        $this->assertSame(60, (int) $product->sales_28_days);
        $this->assertEqualsWithDelta(2.0, (float) $product->avg_daily_sales, 0.001);
        $perWarehouseSum = (int) InventoryWarehouse::where('sku', '2038000000001')
            ->where('integration_id', $integrationId)
            ->sum('sales_30_days');
        $this->assertSame((int) $product->sales_28_days, $perWarehouseSum);
    }
}
