<?php

namespace Tests\Unit;

use App\Jobs\SyncInventoryJob;
use PHPUnit\Framework\TestCase;

/**
 * Матчинг продаж конкретного склада (фикс «размазывания» SKU-тотала
 * на каждую строку склада — главный источник кратного перезаказа WB
 * и одинаковых продаж на карточках складов Ozon).
 */
class SyncInventoryJobWarehouseSalesTest extends TestCase
{
    public function test_ozon_map_matches_by_warehouse_id_key(): void
    {
        $map = [
            '1020000089903000' => ['warehouse_name' => 'ГРИВНО_РФЦ', 'sales_30_days' => 90, 'avg_daily_sales' => 3.0],
            '1020000089904000' => ['warehouse_name' => 'ТЮМЕНЬ_РФЦ', 'sales_30_days' => 30, 'avg_daily_sales' => 1.0],
        ];

        $matched = SyncInventoryJob::matchWarehouseSales($map, '1020000089904000', 'Что-то другое');

        $this->assertNotNull($matched);
        $this->assertSame(30, $matched['sales_30_days']);
    }

    public function test_ozon_map_matches_by_normalized_warehouse_name(): void
    {
        $map = [
            'wh-1' => ['warehouse_name' => 'РОСТОВ_НА_ДОНУ_РФЦ', 'sales_30_days' => 60, 'avg_daily_sales' => 2.0],
            'wh-2' => ['warehouse_name' => 'ХАБАРОВСК_2_РФЦ', 'sales_30_days' => 15, 'avg_daily_sales' => 0.5],
        ];

        $matched = SyncInventoryJob::matchWarehouseSales($map, 'db-hash-id', 'Ростов-на-Дону РФЦ');

        $this->assertNotNull($matched);
        $this->assertSame(60, $matched['sales_30_days']);
    }

    public function test_wb_map_matches_by_name_key(): void
    {
        $map = [
            'Коледино' => ['sales_7_days' => 10, 'sales_14_days' => 20, 'sales_30_days' => 45, 'avg_daily_sales' => 1.5],
            'Казань' => ['sales_7_days' => 2, 'sales_14_days' => 5, 'sales_30_days' => 12, 'avg_daily_sales' => 0.4],
        ];

        $matched = SyncInventoryJob::matchWarehouseSales($map, 'wb_123', 'Коледино');

        $this->assertNotNull($matched);
        $this->assertSame(45, $matched['sales_30_days']);
    }

    public function test_sku_selling_elsewhere_returns_null_not_total(): void
    {
        $map = [
            'Коледино' => ['sales_30_days' => 45, 'avg_daily_sales' => 1.5],
            'Казань' => ['sales_30_days' => 12, 'avg_daily_sales' => 0.4],
        ];

        $this->assertNull(SyncInventoryJob::matchWarehouseSales($map, 'wb_999', 'Электросталь'));
    }

    public function test_yandex_list_without_warehouses_returns_first_entry(): void
    {
        $map = [
            ['avg_daily_sales' => 2.5, 'sales_7_days' => 17, 'sales_14_days' => 35, 'sales_30_days' => 75],
        ];

        $matched = SyncInventoryJob::matchWarehouseSales($map, 'ym-wh-1', 'Софьино');

        $this->assertNotNull($matched);
        $this->assertSame(75, $matched['sales_30_days']);
    }
}
