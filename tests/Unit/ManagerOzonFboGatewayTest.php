<?php

namespace Tests\Unit;

use App\Services\Manager\ManagerDataException;
use App\Services\Manager\ManagerOzonFboGateway;
use App\Services\Manager\ManagerStore;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ManagerOzonFboGatewayTest extends TestCase
{
    private function store(): ManagerStore
    {
        return new ManagerStore(8, 62251, 'ozon', 'Isolated test store', 'isolated-test-key', '555', str_repeat('a', 64));
    }

    private function api(): ManagerOzonFboGateway
    {
        Http::preventStrayRequests();

        return app(ManagerOzonFboGateway::class);
    }

    public function test_native_lookup_preserves_numeric_seller_articles_as_distinct_and_never_confuses_three_ids(): void
    {
        Http::swap(new Factory);
        Http::fake(function ($r) {
            $this->assertSame('https://api-seller.ozon.ru/v3/product/info/list', $r->url());
            $this->assertSame(['offer_id' => ['01', '1']], $r->data());
            $this->assertSame('555', $r->header('Client-Id')[0]);

            return Http::response(['items' => [
                ['offer_id' => '1', 'id' => 900002, 'sku' => 700002, 'name' => 'Second', 'is_archived' => false],
                ['offer_id' => '01', 'id' => 900001, 'sku' => 9007199254740993, 'name' => 'First', 'is_archived' => false],
            ]]);
        });
        $rows = $this->api()->verifiedProducts($this->store(), ['01', '1']);
        $this->assertSame('900001', $rows['01']['product_id']);
        $this->assertSame('9007199254740993', $rows['01']['sku']);
        $this->assertSame('700002', $rows['1']['sku']);
        $this->assertNull($rows['01']['is_discounted']);
        Http::assertSentCount(1);
    }

    public function test_missing_or_non_integer_native_sku_is_not_replaced_by_product_id(): void
    {
        foreach ([null, false, 700001.5, '700001.5', '7e5'] as $sku) {
            Http::swap(new Factory);
            Http::fake(['api-seller.ozon.ru/*' => Http::response(['items' => [['offer_id' => 'A', 'id' => 900001, 'sku' => $sku]]])]);
            try {
                $this->api()->verifiedProducts($this->store(), ['A']);
                $this->fail('Invalid Native SKU was accepted');
            } catch (ManagerDataException $e) {
                $this->assertSame(503, $e->httpStatus);
            }
        }
    }

    public function test_lookup_does_not_accept_an_incomplete_or_foreign_batch(): void
    {
        foreach ([[], [['offer_id' => 'B', 'id' => 9, 'sku' => 7]], [['offer_id' => 'A', 'id' => 9, 'sku' => 7], ['offer_id' => 'A', 'id' => 9, 'sku' => 7]]] as $items) {
            Http::swap(new Factory);
            Http::fake(['api-seller.ozon.ru/*' => Http::response(['items' => $items])]);
            try {
                $this->api()->verifiedProducts($this->store(), ['A']);
                $this->fail('Native batch identity was not checked');
            } catch (ManagerDataException $e) {
                $this->assertSame(503, $e->httpStatus);
            }
        }
    }

    public function test_large_lookup_uses_exact_batches_without_silently_truncating_offers(): void
    {
        $count = 0;
        Http::swap(new Factory);
        Http::fake(function ($r) use (&$count) {
            $count++;
            $rows = array_map(fn ($offer) => ['offer_id' => $offer, 'id' => 100000 + (int) substr($offer, 1), 'sku' => 700000 + (int) substr($offer, 1)], $r['offer_id']);

            return Http::response(['items' => array_reverse($rows)]);
        });
        $offers = array_map(fn ($i) => 'A'.$i, range(1, 1001));
        $rows = $this->api()->verifiedProducts($this->store(), $offers);
        $this->assertCount(1001, $rows);
        $this->assertSame('701001', $rows['A1001']['sku']);
        $this->assertSame(2, $count);
    }

    public function test_direct_draft_is_one_exact_command_and_its_id_is_only_an_acknowledgement(): void
    {
        $marks = 0;
        Http::swap(new Factory);
        Http::fake(function ($r) {
            $this->assertSame('https://api-seller.ozon.ru/v1/draft/direct/create', $r->url());
            $this->assertSame(['cluster_info' => ['items' => [['sku' => 9007199254740993, 'quantity' => 5]], 'macrolocal_cluster_id' => 4039], 'deletion_sku_mode' => 'FULL'], $r->data());

            return Http::response(['draft_id' => 77, 'errors' => []]);
        });
        $result = $this->api()->submitDirectDraft($this->store(), '4039', [['sku' => '9007199254740993', 'quantity' => 5]], function () use (&$marks) {
            $marks++;
        });
        $this->assertSame('verifying', $result['status']);
        $this->assertSame('77', $result['native_id']);
        $this->assertTrue($result['acknowledged']);
        $this->assertSame(1, $marks);
        Http::assertSentCount(1);
    }

    public function test_rate_limit_or_a_lost_response_never_causes_a_second_transport_attempt(): void
    {
        foreach ([429, 503, 307] as $status) {
            Http::swap(new Factory);
            Http::fake(['api-seller.ozon.ru/*' => Http::response(['code' => 8], $status)]);
            $marks = 0;
            $result = $this->api()->submitDirectDraft($this->store(), '4039', [['sku' => '700001', 'quantity' => 5]], function () use (&$marks) {
                $marks++;
            });
            $this->assertSame('unknown', $result['status']);
            $this->assertSame(1, $marks);
            Http::assertSentCount(1);
        }
    }

    public function test_revalidation_failure_in_the_send_hook_prevents_native_http(): void
    {
        Http::swap(new Factory);
        Http::fake();
        $result = $this->api()->submitDirectDraft($this->store(), '4039', [['sku' => '700001', 'quantity' => 5]], function () {
            throw new ManagerDataException('Revoked test grant', 403);
        });
        $this->assertSame('failed', $result['status']);
        $this->assertTrue($result['stopped_before_native']);
        $this->assertFalse($result['native_attempted']);
        Http::assertNothingSent();
    }

    public function test_supply_error_with_an_echoed_draft_id_is_not_an_accepted_creation(): void
    {
        Http::swap(new Factory);
        Http::fake(['api-seller.ozon.ru/*' => Http::response(['draft_id' => 77, 'error_reasons' => ['TIMESLOT_NOT_AVAILABLE']])]);
        $result = $this->api()->submitDirectSupply($this->store(), '77', '4039', '701', ['from_in_timezone' => '2026-11-20T09:00:00', 'to_in_timezone' => '2026-11-20T10:00:00'], fn () => null);
        $this->assertSame('unknown', $result['status']);
        $this->assertFalse($result['acknowledged']);
        $this->assertSame('77', $result['native_id']);
        $this->assertTrue($result['response_has_errors']);
        Http::assertSentCount(1);
    }

    public function test_wire_items_do_not_accept_product_id_aliases_or_fractional_quantities(): void
    {
        Http::swap(new Factory);
        Http::fake();
        foreach ([[['product_id' => '900001', 'quantity' => 5]], [['sku' => '700001', 'quantity' => 5.5]], [['sku' => '7e5', 'quantity' => 5]]] as $items) {
            try {
                $this->api()->submitDirectDraft($this->store(), '4039', $items, fn () => $this->fail('Invalid body reached send hook'));
                $this->fail('Invalid wire body was accepted');
            } catch (ManagerDataException $e) {
                $this->assertSame(422, $e->httpStatus);
            }
        }
        Http::assertNothingSent();
    }

    public function test_invalid_calendar_and_reversed_supply_interval_never_reach_the_transport(): void
    {
        Http::swap(new Factory);
        Http::fake();
        foreach ([['2026-02-30T09:00:00', '2026-02-30T10:00:00'], ['2026-11-20T10:00:00', '2026-11-20T09:00:00']] as [$from, $to]) {
            try {
                $this->api()->submitDirectSupply($this->store(), '77', '4039', '701', ['from_in_timezone' => $from, 'to_in_timezone' => $to], fn () => $this->fail('Invalid time reached send hook'));
                $this->fail('Invalid calendar was accepted');
            } catch (ManagerDataException $e) {
                $this->assertSame(422, $e->httpStatus);
            }
        }
        Http::assertNothingSent();
    }

    public function test_draft_availability_preserves_partial_unknown_and_full_as_distinct_states(): void
    {
        Http::swap(new Factory);
        Http::fake(['api-seller.ozon.ru/*' => Http::response(['status' => 'SUCCESS', 'errors' => [], 'clusters' => [[
            'macrolocal_cluster_id' => 4039, 'cluster_name' => 'Central', 'supply_type' => 'DIRECT', 'warehouses' => [
                ['storage_warehouse' => ['warehouse_id' => 701, 'name' => 'Full'], 'availability_status' => ['state' => 'FULL_AVAILABLE'], 'bundle_id' => 'BUNDLE-A', 'supply_tags' => []],
                ['storage_warehouse' => ['warehouse_id' => 702, 'name' => 'Partial'], 'availability_status' => ['state' => 'PARTIAL_AVAILABLE'], 'bundle_id' => 'BUNDLE-B', 'restricted_bundle_id' => 'REJECTED'],
                ['storage_warehouse' => ['warehouse_id' => 703, 'name' => 'Unknown'], 'availability_status' => (object) []],
            ],
        ]]])]);
        $result = $this->api()->draftInfo($this->store(), '77');
        $warehouses = $result['clusters'][0]['warehouses'];
        $this->assertSame('FULL_AVAILABLE', $warehouses[0]['availability_state']);
        $this->assertSame('PARTIAL_AVAILABLE', $warehouses[1]['availability_state']);
        $this->assertSame('REJECTED', $warehouses[1]['restricted_bundle_id']);
        $this->assertNull($warehouses[2]['availability_state']);
        $this->assertArrayNotHasKey('is_available', $warehouses[1]);
        Http::assertSentCount(1);
    }

    public function test_draft_rejection_preserves_sku_reasons_without_echoing_arbitrary_messages(): void
    {
        Http::swap(new Factory);
        Http::fake(['api-seller.ozon.ru/*' => Http::response(['status' => 'FAILED', 'errors' => [[
            'error_message' => 'ITEMS_VALIDATION', 'message' => 'isolated-secret-must-not-escape', 'items_validation' => [[
                'macrolocal_cluster_id' => 4039, 'rejected_items' => [['sku' => 700001, 'reasons' => ['OUT_OF_ASSORTMENT']]],
            ]],
        ]]])]);
        $result = $this->api()->draftInfo($this->store(), '77');
        $this->assertSame('FAILED', $result['status']);
        $this->assertNull($result['clusters']);
        $this->assertSame('700001', $result['errors'][0]['items_validation'][0]['rejected_items'][0]['sku']);
        $this->assertSame(['OUT_OF_ASSORTMENT'], $result['errors'][0]['items_validation'][0]['rejected_items'][0]['reasons']);
        $this->assertStringNotContainsString('isolated-secret-must-not-escape', json_encode($result));
    }

    public function test_bundle_reads_all_pages_and_keeps_opaque_ids_in_the_body(): void
    {
        $calls = 0;
        Http::swap(new Factory);
        Http::fake(function ($r) use (&$calls) {
            $this->assertSame('https://api-seller.ozon.ru/v1/supply-order/bundle', $r->url());
            $this->assertSame(['BUNDLE/A=='], $r['bundle_ids']);
            $this->assertSame($calls === 0 ? '' : 'next', $r['last_id']);
            $calls++;

            return Http::response(['items' => [['sku' => 700000 + $calls, 'quantity' => $calls + 1]], 'has_next' => $calls === 1, 'last_id' => 'next', 'total_count' => 2]);
        });
        $result = $this->api()->bundle($this->store(), 'BUNDLE/A==');
        $this->assertTrue($result['complete']);
        $this->assertSame(2, $result['total']);
        $this->assertSame('700002', $result['items'][1]['sku']);
        $this->assertSame(3, $result['items'][1]['quantity']);
        $this->assertSame(2, $calls);
    }

    public function test_a_bundle_cursor_cycle_missing_end_marker_or_wrong_total_is_never_complete(): void
    {
        foreach ([['items' => [], 'has_next' => true, 'last_id' => 'repeat'], ['items' => [], 'total_count' => 0],
            ['items' => [['sku' => 700001, 'quantity' => 1]], 'total_count' => 2, 'has_next' => false]] as $raw) {
            Http::swap(new Factory);
            Http::fake(['api-seller.ozon.ru/*' => Http::response($raw)]);
            try {
                $this->api()->bundle($this->store(), 'BUNDLE-A');
                $this->fail('Incomplete bundle became complete');
            } catch (ManagerDataException $e) {
                $this->assertSame(503, $e->httpStatus);
            }
        }
    }

    public function test_creation_status_requires_a_valid_order_id_and_no_contradictory_success(): void
    {
        Http::swap(new Factory);
        Http::fake(['api-seller.ozon.ru/*' => Http::response(['status' => 'SUCCESS', 'order_id' => 5001, 'error_reasons' => []])]);
        $result = $this->api()->creationStatus($this->store(), '77');
        $this->assertSame('5001', $result['order_id']);
        foreach ([['status' => 'SUCCESS', 'order_id' => 0], ['status' => 'FAILED', 'order_id' => 5001],
            ['status' => 'SUCCESS', 'order_id' => 5001, 'error_reasons' => ['TIMESLOT_NOT_AVAILABLE']], ['status' => 'NEW_UNKNOWN_STATUS']] as $raw) {
            Http::swap(new Factory);
            Http::fake(['api-seller.ozon.ru/*' => Http::response($raw)]);
            try {
                $this->api()->creationStatus($this->store(), '77');
                $this->fail('Contradictory Native creation status was accepted');
            } catch (ManagerDataException $e) {
                $this->assertSame(503, $e->httpStatus);
            }
        }
    }

    public function test_order_readback_keeps_actual_warehouse_time_bundle_and_unknown_flags(): void
    {
        $order = ['order_id' => 5001, 'order_number' => 'TEST-5001', 'state' => 'READY_TO_SUPPLY',
            'dropoff_warehouse' => ['warehouse_id' => 701, 'name' => 'Actual receiving point'],
            'timeslot' => ['timeslot' => ['from' => '2026-11-20T09:00:00Z', 'to' => '2026-11-20T10:00:00Z'], 'timezone_info' => ['iana_name' => 'Europe/Moscow', 'offset' => '+03:00']],
            'supplies' => [['supply_id' => 101, 'storage_warehouse' => ['warehouse_id' => 701, 'name' => 'Actual storage'], 'bundle_id' => 'ORDER-CONTENTS',
                'state' => 'READY_TO_SUPPLY', 'is_crossdock' => false, 'supply_tags' => ['is_marking_required' => false]]]];
        Http::swap(new Factory);
        Http::fake(['api-seller.ozon.ru/*' => Http::response(['orders' => [$order]])]);
        $result = $this->api()->order($this->store(), '5001');
        $this->assertSame('701', $result['dropoff_warehouse']['id']);
        $this->assertSame('2026-11-20T09:00:00.000000Z', $result['timeslot']['from_utc']);
        $this->assertSame('ORDER-CONTENTS', $result['supplies'][0]['bundle_id']);
        $this->assertFalse($result['supplies'][0]['supply_tags']['is_marking_required']);
        $this->assertNull($result['supplies'][0]['supply_tags']['is_evsd_required']);
        Http::swap(new Factory);
        Http::fake(['api-seller.ozon.ru/*' => Http::response(['orders' => [[...$order, 'order_id' => 5002]]])]);
        $this->expectException(ManagerDataException::class);
        $this->api()->order($this->store(), '5001');
    }

    public function test_native_timeslots_keep_original_strings_and_resolve_the_warehouse_timezone(): void
    {
        $this->travelTo(now()->setDate(2026, 1, 1));
        Http::swap(new Factory);
        Http::fake(function ($r) {
            $this->assertSame('https://api-seller.ozon.ru/v2/draft/timeslot/info', $r->url());
            $this->assertSame(['draft_id' => 77, 'date_from' => '2026-11-20', 'date_to' => '2026-11-20', 'supply_type' => 'DIRECT',
                'selected_cluster_warehouses' => [['macrolocal_cluster_id' => 4039, 'storage_warehouse_id' => 701]]], $r->data());

            return Http::response($this->calendar('Europe/Moscow', '2026-11-20T09:00:00', '2026-11-20T10:00:00'));
        });
        $result = $this->api()->timeslots($this->store(), '77', '4039', '701', '2026-11-20', '2026-11-20');
        $slot = $result['slots'][0];
        $this->assertTrue($result['quoted']);
        $this->assertSame('2026-11-20T09:00:00', $slot['from_in_timezone']);
        $this->assertSame('2026-11-20T06:00:00.000000Z', $slot['from_utc']);
        $this->assertTrue($slot['eligible_time']);
        Http::assertSentCount(1);
    }

    public function test_dst_gap_and_fold_are_ineligible_but_an_explicit_offset_is_kept(): void
    {
        $this->travelTo(now()->setDate(2026, 1, 1));
        foreach ([['2026-03-29', '2026-03-29T02:15:00', '2026-03-29T02:45:00', 'nonexistent', false],
            ['2026-10-25', '2026-10-25T02:15:00', '2026-10-25T02:45:00', 'ambiguous', false],
            ['2026-10-25', '2026-10-25T02:15:00+02:00', '2026-10-25T02:45:00+02:00', 'explicit_offset', true]] as [$day, $from, $to, $resolution, $eligible]) {
            Http::swap(new Factory);
            Http::fake(['api-seller.ozon.ru/*' => Http::response($this->calendar('Europe/Berlin', $from, $to))]);
            $slot = $this->api()->timeslots($this->store(), '77', '4039', '701', $day, $day)['slots'][0];
            $this->assertSame($resolution, $slot['from_resolution']);
            $this->assertSame($eligible, $slot['eligible_time']);
            $this->assertSame($from, $slot['from_in_timezone']);
            if (! $eligible) {
                $this->assertNull($slot['from_utc']);
            }
        }
    }

    public function test_timeslot_errors_and_invalid_calendars_do_not_become_available_slots(): void
    {
        Http::swap(new Factory);
        Http::fake(['api-seller.ozon.ru/*' => Http::response(['error_reason' => 'DRAFT_NOT_FOUND'])]);
        $result = $this->api()->timeslots($this->store(), '77', '4039', '701', '2026-11-20', '2026-11-20');
        $this->assertFalse($result['quoted']);
        $this->assertNull($result['slots']);
        foreach ([$this->calendar('Unknown/Zone', '2026-11-20T09:00:00', '2026-11-20T10:00:00'), ['result' => ['drop_off_warehouse_timeslots' => ['warehouse_timezone' => 'Europe/Moscow']]]] as $raw) {
            Http::swap(new Factory);
            Http::fake(['api-seller.ozon.ru/*' => Http::response($raw)]);
            try {
                $this->api()->timeslots($this->store(), '77', '4039', '701', '2026-11-20', '2026-11-20');
                $this->fail('Invalid calendar accepted');
            } catch (ManagerDataException $e) {
                $this->assertSame(503, $e->httpStatus);
            }
        }
        Http::swap(new Factory);
        Http::fake();
        foreach ([['2026-02-30', '2026-03-01'], ['2026-11-20', '2026-12-20'], ['2026-11-20', '2026-11-19']] as [$from, $to]) {
            try {
                $this->api()->timeslots($this->store(), '77', '4039', '701', $from, $to);
                $this->fail('Invalid date range accepted');
            } catch (ManagerDataException $e) {
                $this->assertSame(422, $e->httpStatus);
            }
        }
        Http::assertNothingSent();
    }

    private function calendar(string $zone, string $from, string $to): array
    {
        return ['result' => ['drop_off_warehouse_timeslots' => ['warehouse_timezone' => $zone, 'days' => [[
            'date_in_timezone' => substr($from, 0, 10), 'timeslots' => [['from_in_timezone' => $from, 'to_in_timezone' => $to]],
        ]]]]];
    }
}
