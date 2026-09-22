<?php

namespace Tests\Unit;

use App\Domains\Ozon\Api\AnalyticsApi;
use App\Domains\Ozon\Api\DeliveryAnalyticsApi;
use App\Domains\Ozon\Api\InventoryApi;
use App\Domains\Ozon\Api\OzonClient;
use App\Domains\Ozon\Api\WarehousesApi;
use App\Services\Marketplace\OzonService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Склады /v2/warehouse/list, FBS-остатки /v2/.../stocks-by-warehouse/fbs,
 * /v4/product/info/stocks без warehouse_ids и локальность /v1/analytics/local-sale/total.
 */
class OzonInventoryLocalityContractsTest extends TestCase
{
    private function client(): OzonClient
    {
        return new OzonClient('client', 'key');
    }

    public function test_warehouses_use_v2_cursor_pagination(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api-seller.ozon.ru/v2/warehouse/list' => Http::sequence()
                ->push(['warehouses' => [['warehouse_id' => 1, 'name' => 'Склад 1']], 'cursor' => 'c1', 'has_next' => true])
                ->push(['warehouses' => [['warehouse_id' => 2, 'name' => 'Склад 2', 'is_rfbs' => true]], 'cursor' => '', 'has_next' => false]),
        ]);

        $warehouses = (new WarehousesApi($this->client()))->getWarehouses();

        $this->assertSame([1, 2], array_column($warehouses, 'warehouse_id'));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request['limit'] === 200 && ! isset($request['cursor']));
        Http::assertSent(fn ($request): bool => $request['limit'] === 200 && ($request['cursor'] ?? null) === 'c1');
    }

    public function test_legacy_ozon_service_warehouses_read_v2_warehouses(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api-seller.ozon.ru/v2/warehouse/list' => Http::response([
                'warehouses' => [['warehouse_id' => 7, 'name' => 'FBS', 'is_rfbs' => false]],
                'has_next' => false,
            ]),
        ]);

        $this->assertSame(
            [['id' => 7, 'name' => 'FBS', 'is_rfbs' => false]],
            (new OzonService('1', 'key'))->getWarehouses()
        );
    }

    public function test_fbs_stocks_use_v2_contract(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api-seller.ozon.ru/v2/product/info/stocks-by-warehouse/fbs' => Http::response([
                'products' => [
                    ['offer_id' => 'SKU-1', 'sku' => 700001, 'product_id' => 11, 'warehouse_id' => 501,
                        'warehouse_name' => 'Мой склад', 'present' => 10, 'reserved' => 2, 'free_stock' => 8],
                ],
                'cursor' => '',
                'has_next' => false,
            ]),
        ]);

        $stocks = (new InventoryApi($this->client()))->getStocksForFbsSchemes(['SKU-1']);

        $this->assertSame('SKU-1', $stocks[0]['sku']);
        $this->assertSame(10, $stocks[0]['total']);
        $this->assertSame(501, $stocks[0]['warehouses'][0]['warehouse_id']);
        $this->assertSame(8, $stocks[0]['warehouses'][0]['free_stock']);
        Http::assertSent(fn ($request): bool => $request['offer_id'] === ['SKU-1'] && $request['limit'] === 1000);
    }

    public function test_fbs_stocks_api_error_is_not_empty_stock(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api-seller.ozon.ru/v2/product/info/stocks-by-warehouse/fbs' => Http::response(['message' => 'boom'], 500),
        ]);

        $this->expectException(\RuntimeException::class);

        (new InventoryApi($this->client()))->getStocksForFbsSchemes(['SKU-1']);
    }

    public function test_v4_stocks_do_not_use_deprecated_warehouse_ids(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api-seller.ozon.ru/v4/product/info/stocks' => Http::response([
                'items' => [[
                    'offer_id' => 'SKU-1',
                    'product_id' => 11,
                    'stocks' => [['type' => 'fbs', 'present' => 4, 'reserved' => 0, 'warehouse_ids' => [123]]],
                ]],
                'cursor' => '',
            ]),
            'api-seller.ozon.ru/v2/product/info/stocks-by-warehouse/fbs' => Http::response(['products' => [], 'has_next' => false]),
        ]);

        $stocks = (new InventoryApi($this->client()))->getStocks(null, ['SKU-1']);

        $this->assertSame('ozon_fbs', $stocks[0]['warehouses'][0]['warehouse_id']);
    }

    public function test_localization_index_uses_local_sale_total(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-22 12:00:00'));
        Http::preventStrayRequests();
        Http::fake([
            'api-seller.ozon.ru/v1/analytics/local-sale/total' => Http::response([
                'fbo_quantity' => 120,
                'local_data' => ['index' => 63.456, 'local_quantity' => 76, 'total_quantity' => 120],
                'overpayment' => ['delta' => 1200.5, 'non_local_delivery' => 800.25, 'total' => 2000.75],
            ]),
        ]);

        $index = (new AnalyticsApi($this->client()))->getLocalizationIndex();
        Carbon::setTestNow();

        $this->assertSame(63.46, $index['local_sales_index']);
        $this->assertSame(76, $index['local_quantity']);
        $this->assertSame(2000.75, $index['overpayment_total']);
        $this->assertSame(800.25, $index['overpayment_non_local']);
        // В local-sale нет времени доставки и тарифа — остаются нейтральные дефолты.
        $this->assertSame(1.0, $index['tariff_coefficient']);
        $this->assertSame('UNKNOWN', $index['tariff_status']);
        Http::assertSent(fn ($request): bool => $request['period'] === ['from' => '2026-08-25', 'to' => '2026-09-21']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'average-delivery-time'));
    }

    public function test_localization_index_error_keeps_defaults(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api-seller.ozon.ru/v1/analytics/local-sale/total' => Http::response(['message' => 'forbidden'], 403),
        ]);

        $index = (new AnalyticsApi($this->client()))->getLocalizationIndex();

        $this->assertNull($index['local_sales_index']);
        $this->assertSame(29, $index['average_delivery_time']);
        $this->assertSame('UNKNOWN', $index['tariff_status']);
    }

    public function test_delivery_analytics_fails_explicitly_without_removed_endpoints(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        try {
            (new DeliveryAnalyticsApi($this->client()))->getSupplyRecommendations();
            $this->fail('Ожидалось явное исключение: average-delivery-time удалён.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('average-delivery-time', $e->getMessage());
        }

        Http::assertNothingSent();
    }
}
