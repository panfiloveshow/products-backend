<?php

namespace Tests\Unit;

use App\Domains\Ozon\Api\FboCargoesApi;
use App\Domains\Ozon\Api\FboSupplyOrdersApi;
use App\Domains\Ozon\Api\OzonClient;
use App\Domains\Ozon\Api\SuppliesApi;
use App\Exceptions\OzonPreconditionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OzonSupplyApiCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public static function supplyContracts(): array
    {
        return [
            'cargo status' => ['getCargoCreateInfo', ['op-1'], '/v2/cargoes/create/info', ['operation_id' => 'op-1']],
            'cargo delete' => ['deleteCargo', ['51', 71], '/v1/cargoes/delete', ['supply_id' => 51, 'cargo_ids' => [71]]],
            'content update' => ['updateSupplyOrderContent', ['11', ['supply_id' => 51, 'items' => [['sku' => 7, 'quantity' => 2]]]], '/v1/supply-order/content/update', ['order_id' => 11, 'supply_id' => 51, 'items' => [['sku' => 7, 'quantity' => 2]]]],
            'content validation' => ['validateSupplyOrderContent', ['51', ['new_bundle_id' => 'bundle-2']], '/v1/supply-order/content/update/validation', ['supply_id' => 51, 'new_bundle_id' => 'bundle-2']],
            'act create' => ['createFbpAcceptanceAct', ['51'], '/v1/fbp/act-from/create', ['supply_id' => '51']],
            'act get' => ['getFbpAcceptanceActStatus', ['file-1'], '/v1/fbp/act-from/get', ['file_uuid' => 'file-1']],
            'timeslot list' => ['getFbpOrderDirectTimeslotList', ['51', ['date_from' => '2026-11-05T00:00:00Z', 'date_to' => '2026-11-06T00:00:00Z']], '/v1/fbp/order/direct/timeslot/list', ['supply_id' => '51', 'interval_start' => '2026-11-05T00:00:00Z', 'interval_end' => '2026-11-06T00:00:00Z']],
        ];
    }

    #[DataProvider('supplyContracts')]
    public function test_supply_requests_match_current_contract(string $method, array $args, string $endpoint, array $expected): void
    {
        Http::fake(['*'.$endpoint => Http::response(['operation_id' => 'op-1', 'file_uuid' => 'file-1', 'is_success' => true, 'status' => 'SUCCESS'])]);
        (new SuppliesApi(new OzonClient('client', 'key')))->{$method}(...$args);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data() === $expected);
    }

    public function test_both_cargo_read_helpers_unwrap_the_requested_supply(): void
    {
        Http::fake(['*/v1/cargoes/get' => Http::response(['supply' => [
            ['supply_id' => 999, 'cargoes' => [['cargo_id' => 90]]],
            ['supply_id' => 51, 'bundle_id' => 'b1', 'cargoes' => [['cargo_id' => 71, 'type' => 'BOX']]],
        ]])]);
        $api = new SuppliesApi(new OzonClient('client', 'key'));
        $this->assertSame(71, $api->getCargoes('51')[0]['id']);
        $this->assertSame(71, $api->getCargoesInfo('51')['cargoes'][0]['cargo_id']);
        $this->assertSame(71, (new FboCargoesApi(new OzonClient('client', 'key')))->get(51)[0]['cargo_id']);
        Http::assertSent(fn (Request $r) => $r->data() === ['supply_ids' => ['51']]);
    }

    public function test_label_helpers_use_operation_id_and_current_download_url(): void
    {
        Http::fake([
            '*/v1/cargoes-label/create' => Http::response(['operation_id' => 'label-op']),
            '*/v1/cargoes-label/get' => Http::response(['status' => 'SUCCESS', 'result' => ['file_url' => 'https://example.test/label.pdf']]),
        ]);
        $api = new FboCargoesApi(new OzonClient('client', 'key'));
        $this->assertSame('label-op', $api->createLabels(51, [71])['operation_id']);
        $this->assertSame('https://example.test/label.pdf', $api->getLabelsStatus('label-op')['file_url']);
        $this->assertSame('https://example.test/label.pdf', (new SuppliesApi(new OzonClient('client', 'key')))->getCargoLabelsStatus('label-op')['file_url']);
        Http::assertSent(fn (Request $r) => $r->data() === ['supply_id' => 51, 'cargoes' => [['cargo_id' => 71]]]);
        Http::assertSent(fn (Request $r) => $r->data() === ['operation_id' => 'label-op']);
    }

    public function test_cargo_creation_uses_supply_id_and_never_claims_success_on_http_failure(): void
    {
        Http::fake(['*/v1/cargoes/create' => Http::response(['message' => 'forbidden'], 403)]);
        $this->assertFalse((new FboCargoesApi(new OzonClient('client', 'key')))->create(51, [])['success']);
        $this->expectException(\RuntimeException::class);
        (new SuppliesApi(new OzonClient('client', 'key')))->createCargo('51', ['containers' => []]);
    }

    public function test_workload_uses_get(): void
    {
        Http::fake(['*/v1/supplier/available_warehouses' => Http::response(['result' => []])]);
        (new SuppliesApi(new OzonClient('client', 'key')))->getWarehouseWorkload();
        Http::assertSent(fn (Request $r) => $r->method() === 'GET');
    }

    public function test_order_timeslot_fetches_row_version_and_preserves_timezone(): void
    {
        Http::fake([
            '*/v1/fbp/order/get' => Http::response(['row_version' => 7]),
            '*/v1/fbp/order/direct/timeslot/edit' => Http::response(['row_version' => 8, 'error_reasons' => []]),
        ]);
        $result = (new FboSupplyOrdersApi(new OzonClient('client', 'key')))->editTimeslot(51, '2026-11-05T12:00:00+03:00', '2026-11-05T13:00:00+03:00');
        $this->assertTrue($result['success']);
        Http::assertSent(fn (Request $r) => $r->data() === ['supply_id' => '51', 'row_version' => 7, 'timeslot_start' => '2026-11-05T09:00:00Z']);
    }

    public function test_draft_timeslot_uses_native_fields_and_rejects_business_errors(): void
    {
        Http::fake([
            '*/v1/fbp/draft/get' => Http::response(['row_version' => 7]),
            '*/v1/fbp/draft/direct/timeslot/edit' => Http::response(['error_reasons' => ['NO_CAPACITY']]),
        ]);
        $this->assertFalse((new SuppliesApi(new OzonClient('client', 'key')))->setSupplyOrderTimeslot('51', '2026-11-05T12:00:00+03:00', '2026-11-05T13:00:00+03:00')['success']);
        Http::assertSent(fn (Request $r) => $r->data() === ['supply_id' => '51', 'row_version' => 7, 'timeslot_start' => '2026-11-05T09:00:00Z']);
    }

    public function test_bundle_reader_follows_last_id_for_each_supply(): void
    {
        Http::fake([
            '*/v3/supply-order/get' => Http::response(['orders' => [['order_id' => 11, 'supplies' => [['bundle_id' => 'b1'], ['bundle_id' => 'b2']]]]]),
            '*/v1/supply-order/bundle' => Http::sequence()
                ->push(['items' => [['sku' => 1]], 'has_next' => true, 'last_id' => 'next'])
                ->push(['items' => [['sku' => 2]], 'has_next' => false])
                ->push(['items' => [['sku' => 3]], 'has_next' => false]),
        ]);
        $result = (new SuppliesApi(new OzonClient('client', 'key')))->getSupplyOrderBundle(11);
        $this->assertSame([1, 2, 3], array_column($result['items'], 'sku'));
        $this->assertSame([1, 2], array_column($result['bundles'][0]['items'], 'sku'));
        $this->assertSame([3], array_column($result['bundles'][1]['items'], 'sku'));
        $this->assertFalse($result['has_next']);
        Http::assertSent(fn (Request $r) => ($r['bundle_ids'] ?? null) === ['b1'] && $r['last_id'] === 'next');
        Http::assertSent(fn (Request $r) => ($r['bundle_ids'] ?? null) === ['b2'] && $r['last_id'] === '');
    }

    public function test_order_timeslot_does_not_write_without_a_current_row_version(): void
    {
        Http::fake(['*/v1/fbp/order/get' => Http::response(['message' => 'forbidden'], 403)]);
        try {
            (new FboSupplyOrdersApi(new OzonClient('client', 'key')))->editTimeslot(51, '2026-11-05T12:00:00Z', '2026-11-05T13:00:00Z');
            $this->fail('Expected a precondition failure');
        } catch (OzonPreconditionException $e) {
            $this->assertStringContainsString('row_version', $e->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_status_counters_unwrap_items_and_send_a_json_object(): void
    {
        Http::fake(['*/v1/supply-order/status/counter' => Http::response(['items' => [['state' => 'ACCEPTED', 'count' => 3]]])]);
        $this->assertSame(3, (new FboSupplyOrdersApi(new OzonClient('client', 'key')))->getStatusCounters()[0]['count']);
        Http::assertSent(fn (Request $r) => $r->body() === '{}');
    }
}
