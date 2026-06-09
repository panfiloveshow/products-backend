<?php

namespace Tests\Unit;

use App\Domains\Wildberries\Api\SalesApi;
use App\Domains\Wildberries\Api\WildberriesClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WildberriesSalesByRegionExclusionsTest extends TestCase
{
    private function fakeSales(array $rows): void
    {
        Http::fake([
            'statistics-api.wildberries.ru/api/v1/supplier/sales*' => Http::response($rows),
        ]);
    }

    public function test_fbs_orders_are_counted_as_exclusions_and_skipped_in_locality(): void
    {
        // Один артикул: 1 локальный FBW, 1 нелокальный FBW, 1 FBS (исключение).
        $this->fakeSales([
            [ // FBW, склад и доставка в ЦФО → локальный
                'nmId' => 100,
                'warehouseType' => 'Склад WB',
                'warehouseName' => 'Коледино',
                'oblastOkrugName' => 'Центральный федеральный округ',
                'quantity' => 1,
            ],
            [ // FBW, склад ЦФО, доставка УрФО → не локальный
                'nmId' => 100,
                'warehouseType' => 'Склад WB',
                'warehouseName' => 'Коледино',
                'oblastOkrugName' => 'Уральский федеральный округ',
                'quantity' => 1,
            ],
            [ // FBS — исключение, в локальности не участвует
                'nmId' => 100,
                'warehouseType' => 'Склад продавца',
                'warehouseName' => 'Хабаровск',
                'oblastOkrugName' => 'Центральный федеральный округ',
                'quantity' => 1,
            ],
        ]);

        $api = new SalesApi(new WildberriesClient('test-token'));
        $result = $api->getSalesByRegion(91);

        $this->assertArrayHasKey('100', $result);
        $row = $result['100'];

        $this->assertSame(3, $row['total']);
        $this->assertSame(1, $row['excluded_fbs']);
        // local учитывает только FBW-заказы: ровно один локальный.
        $this->assertSame(1, $row['local']);
    }

    public function test_missing_warehouse_type_is_treated_as_fbw(): void
    {
        // Старые данные без warehouseType → считаем FBW (не исключаем).
        $this->fakeSales([
            [
                'nmId' => 200,
                'warehouseName' => 'Коледино',
                'oblastOkrugName' => 'Центральный федеральный округ',
                'quantity' => 2,
            ],
        ]);

        $api = new SalesApi(new WildberriesClient('test-token'));
        $result = $api->getSalesByRegion(91);

        $this->assertSame(2, $result['200']['total']);
        $this->assertSame(0, $result['200']['excluded_fbs']);
        $this->assertSame(2, $result['200']['local']);
    }
}
