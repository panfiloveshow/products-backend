<?php

namespace Tests\Unit;

use App\Domains\Ozon\Api\OzonClient;
use App\Domains\Ozon\Api\SuppliesApi;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Контракт черновиков FBO после отключения /v1/draft/create* и /v1/supply/* (16.03.2026).
 */
class OzonSupplyDraftContractsTest extends TestCase
{
    private function api(): SuppliesApi
    {
        return new SuppliesApi(new OzonClient('client', 'key'));
    }

    private function clusterList(array $warehouses = []): array
    {
        return [
            'clusters' => [[
                'id' => 101,
                'macrolocal_cluster_id' => 4039,
                'name' => 'Москва',
                'logistic_clusters' => [['warehouses' => $warehouses]],
            ]],
        ];
    }

    public function test_crossdock_draft_sends_drop_off_delivery_info(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api-seller.ozon.ru/v1/cluster/list' => Http::response($this->clusterList()),
            'api-seller.ozon.ru/v1/draft/crossdock/create' => Http::response(['draft_id' => 77, 'errors' => []]),
            'api-seller.ozon.ru/v2/draft/create/info' => Http::response(['status' => 'SUCCESS', 'clusters' => [], 'errors' => []]),
        ]);

        $result = $this->api()->createCrossdockDraft([
            'macrolocal_cluster_id' => 101,
            'delivery_scheme' => 'drop_off',
            'point_id' => 555,
            'point_type' => 'WAREHOUSE_TYPE_SORTING_CENTER',
            'items' => [['sku' => 700001, 'quantity' => 3]],
        ]);

        $this->assertSame('77', $result['draft_id']);
        $this->assertSame('draft', $result['status']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api-seller.ozon.ru/v1/draft/crossdock/create'
            && $request['cluster_info']['macrolocal_cluster_id'] === 4039
            && $request['delivery_info'] === [
                'type' => 'DROPOFF',
                'drop_off_warehouse' => ['warehouse_id' => 555, 'warehouse_type' => 'SORTING_CENTER'],
            ]);
    }

    public function test_crossdock_drop_off_type_is_taken_from_cluster_list_when_missing(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api-seller.ozon.ru/v1/cluster/list' => Http::response($this->clusterList([
                ['warehouse_id' => 555, 'name' => 'КРОСС-ДОК', 'type' => 'CROSS_DOCK'],
            ])),
            'api-seller.ozon.ru/v1/draft/crossdock/create' => Http::response(['draft_id' => 78]),
            'api-seller.ozon.ru/v2/draft/create/info' => Http::response(['status' => 'IN_PROGRESS']),
        ]);

        $this->api()->createCrossdockDraft([
            'macrolocal_cluster_id' => 101,
            'point_id' => 555,
            'items' => [['sku' => 700001, 'quantity' => 3]],
        ]);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api-seller.ozon.ru/v1/draft/crossdock/create'
            && $request['delivery_info']['drop_off_warehouse']['warehouse_type'] === 'CROSS_DOCK');
    }

    public function test_rejected_draft_without_draft_id_is_failed_not_ambiguous(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api-seller.ozon.ru/v1/cluster/list' => Http::response($this->clusterList()),
            'api-seller.ozon.ru/v1/draft/direct/create' => Http::response([
                'draft_id' => 0,
                'errors' => [[
                    'error_message' => 'ITEMS_VALIDATION',
                    'items_validation' => [[
                        'macrolocal_cluster_id' => 4039,
                        'rejected_items' => [['sku' => 700001, 'reasons' => ['OUT_OF_ASSORTMENT']]],
                    ]],
                ]],
            ]),
        ]);

        $result = $this->api()->createDirectDraft([
            'cluster_id' => 101,
            'items' => [['sku' => 700001, 'quantity' => 5]],
        ]);

        $this->assertSame('failed', $result['status']);
        $this->assertNull($result['draft_id']);
        $this->assertNull($result['pending_draft_id']);
        $this->assertStringContainsString('SKU 700001 не в ассортименте FBO', implode(' ', $result['errors']));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/v2/draft/create/info'));
    }

    public function test_draft_timeslots_use_v2_contract_and_single_warehouse_object(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api-seller.ozon.ru/v1/cluster/list' => Http::response($this->clusterList()),
            'api-seller.ozon.ru/v2/draft/timeslot/info' => Http::response([
                'result' => [
                    'drop_off_warehouse_timeslots' => [
                        'current_time_in_timezone' => '2026-09-22T10:00:00',
                        'warehouse_timezone' => 'Europe/Moscow',
                        'days' => [[
                            'date_in_timezone' => '2026-09-24T00:00:00',
                            'timeslots' => [[
                                'from_in_timezone' => '2026-09-24T09:00:00',
                                'to_in_timezone' => '2026-09-24T10:00:00',
                            ]],
                        ]],
                    ],
                ],
            ]),
        ]);

        $slots = $this->api()->getDraftTimeslots(42, 701, 101, null, 'direct');

        $this->assertCount(1, $slots);
        $this->assertSame('2026-09-24', $slots[0]['date']);
        $this->assertSame('09:00', $slots[0]['time_from']);
        $this->assertSame('2026-09-24T10:00:00', $slots[0]['to_datetime']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api-seller.ozon.ru/v2/draft/timeslot/info'
            && $request['draft_id'] === 42
            && $request['supply_type'] === 'DIRECT'
            && $request['selected_cluster_warehouses'] === [['macrolocal_cluster_id' => 4039, 'storage_warehouse_id' => 701]]);
    }

    public function test_crossdock_timeslots_do_not_send_storage_warehouse(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api-seller.ozon.ru/v1/cluster/list' => Http::response($this->clusterList()),
            'api-seller.ozon.ru/v2/draft/timeslot/info' => Http::response(['error_reason' => 'INVALID_CLUSTERS_COUNT']),
        ]);

        $this->assertSame([], $this->api()->getDraftTimeslots(42, 701, 101, null, 'crossdock'));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api-seller.ozon.ru/v2/draft/timeslot/info'
            && $request['supply_type'] === 'CROSSDOCK'
            && $request['selected_cluster_warehouses'] === [['macrolocal_cluster_id' => 4039]]);
    }

    public function test_supply_details_use_v3_supply_order_get(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api-seller.ozon.ru/v3/supply-order/get' => Http::response([
                'orders' => [[
                    'order_id' => 5678,
                    'order_number' => '2000012345',
                    'state' => 'READY_TO_SUPPLY',
                    'dropoff_warehouse' => ['warehouse_id' => 900, 'name' => 'СЦ'],
                    'supplies' => [[
                        'supply_id' => 1,
                        'macrolocal_cluster_id' => 4039,
                        'storage_warehouse' => ['warehouse_id' => 701, 'name' => 'ХОРУГВИНО'],
                    ]],
                    'timeslot' => ['timeslot' => ['from' => '2026-09-24T09:00:00Z', 'to' => '2026-09-24T10:00:00Z']],
                ]],
            ]),
        ]);

        $details = $this->api()->getSupplyDetails('5678');

        $this->assertSame('5678', $details['id']);
        $this->assertSame('READY_TO_SUPPLY', $details['status_code']);
        $this->assertSame('701', $details['warehouse_id']);
        $this->assertSame('2026-09-24T09:00:00Z', $details['timeslot_from']);
        Http::assertSent(fn ($request): bool => $request['order_ids'] === [5678]);
    }

    public function test_legacy_supply_interface_fails_explicitly_without_requests(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $api = $this->api();

        $this->assertFalse($api->supportsFeature('create_supply'));
        $this->assertFalse($api->supportsFeature('get_acceptance_slots'));

        foreach ([
            fn () => $api->createSupplyDraft(['warehouse_id' => 1, 'items' => []]),
            fn () => $api->getAcceptanceSlots('1'),
            fn () => $api->bookAcceptanceSlot('1', '2'),
        ] as $call) {
            try {
                $call();
                $this->fail('Ожидалось явное исключение для отключённого метода.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Ozon отключил', $e->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_crossdock_drop_off_points_use_fbo_list_with_required_search(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api-seller.ozon.ru/v1/warehouse/fbo/list' => Http::response([
                'search' => [[
                    'warehouse_id' => 555,
                    'name' => 'Москва СЦ',
                    'warehouse_type' => 'WAREHOUSE_TYPE_SORTING_CENTER',
                    'address' => 'Москва',
                    'coordinates' => ['latitude' => 55.7, 'longitude' => 37.6],
                ]],
            ]),
        ]);

        $this->assertSame([], $this->api()->getCrossdockDropOffPoints('Мос'));
        $points = $this->api()->getCrossdockDropOffPoints('Москва');

        $this->assertSame('555', $points[0]['id']);
        $this->assertSame('WAREHOUSE_TYPE_SORTING_CENTER', $points[0]['warehouse_type']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api-seller.ozon.ru/v1/warehouse/fbo/list'
            && $request['filter_by_supply_type'] === ['CREATE_TYPE_CROSSDOCK']
            && $request['search'] === 'Москва');
    }
}
