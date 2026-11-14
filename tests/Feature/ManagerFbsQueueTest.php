<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Services\SellicoApiService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManagerFbsQueueTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $marketplace = 'wildberries';

    private int $user = 42;

    private string $key = 'vendor-secret';

    private array $permissions = ['auto_supply.view', 'integrations.view'];

    private bool $serviceAccount = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sellico.base_url' => 'https://crm.test/api', 'services.sellico.skip_permission_check' => true]);
        Http::preventStrayRequests();
        $this->mock(SellicoApiService::class, fn ($mock) => $mock->shouldReceive('getServiceToken')->andReturn('service-session'));
        $this->withToken('human-session')->withHeader('X-Sellico-Workspace', '8');
        Cache::flush();
    }

    private function store(string $market = 'wildberries', int $workspace = 8): void
    {
        $this->marketplace = $market;
        Integration::factory()->{$market}()->create(['id' => 62001, 'work_space_id' => $workspace]);
    }

    private function remote(callable $vendor): void
    {
        Http::fake(function ($request) use ($vendor) {
            return match ($request->url()) {
                'https://crm.test/api/user' => Http::response(['id' => $this->user, 'is_service_account' => $this->serviceAccount]),
                'https://crm.test/api/workspaces/8/user-permissions' => Http::response(['user_id' => $this->user, 'workspace_id' => 8, 'permissions' => $this->permissions]),
                'https://crm.test/api/get-integrations/8' => Http::response([['id' => 62001, 'work_space_id' => 8, 'status' => 'active', 'type' => $this->marketplace,
                    'name' => 'Test store', 'api_key' => $this->key, 'client_id' => '555']]),
                default => $vendor($request),
            };
        });
    }

    private function url(array $args = []): string
    {
        return '/api/manager/fbs/queue?'.http_build_query(['integration_id' => 62001, ...$args]);
    }

    private function wb(int $id, array $extra = []): array
    {
        return array_replace(['id' => $id, 'nmId' => 10001, 'article' => 'SKU', 'skus' => ['0123456789'], 'createdAt' => '2026-11-05T08:00:00Z',
            'warehouseId' => 51, 'sellerDate' => '06.11.2026', 'deliveryType' => 'fbs', 'cargoType' => 2, 'crossBorderType' => 0,
            'requiredMeta' => ['uin'], 'optionalMeta' => ['sgtin'], 'address' => ['fullAddress' => 'private-customer-address'], 'comment' => 'private-customer-comment'], $extra);
    }

    private function ozon(string $id, array $extra = []): array
    {
        return array_replace(['posting_number' => $id, 'status' => 'awaiting_packaging', 'substatus' => 'posting_awaiting_packaging',
            'in_process_at' => '2026-11-05T08:00:00Z', 'shipment_date' => '2026-11-06T09:00:00Z',
            'delivery_method' => ['warehouse_id' => 123, 'warehouse' => 'Test warehouse'], 'is_express' => false, 'is_multibox' => true,
            'integration_type_flow' => 'ozon', 'available_actions' => ['ship_with_additional_info'],
            'products' => [['sku' => 40001, 'offer_id' => 'O-SKU', 'name' => 'Test product', 'quantity' => 2, 'price' => '999.99']],
            'requirements' => ['products_requiring_mandatory_mark' => [40001], 'products_requiring_imei' => [40001], 'products_requiring_weight' => [40001]],
            'customer' => ['phone' => 'private-customer-phone'], 'addressee' => ['name' => 'private-customer-name'], 'financial_data' => ['price' => 'private-customer-finance']], $extra);
    }

    public function test_wb_pages_share_one_snapshot_without_customer_data_or_invented_quantity(): void
    {
        $this->store();
        $this->remote(function ($r) {
            $this->assertSame('GET', $r->method());
            $this->assertSame('https://marketplace-api.wildberries.ru/api/v3/orders/new', $r->url());
            $this->assertTrue($r->hasHeader('Authorization', 'vendor-secret'));

            return Http::response(['orders' => [$this->wb(2), $this->wb(1)]]);
        });
        $mutations = [];
        DB::listen(function ($q) use (&$mutations) {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $q->sql)) {
                $mutations[] = $q->sql;
            }
        });
        $first = $this->getJson($this->url(['limit' => 1]))->assertOk()->assertJsonPath('items.0.external_id', '1')
            ->assertJsonPath('items.0.items.0.quantity', null)->assertJsonPath('items.0.shipment_deadline', null)
            ->assertJsonPath('items.0.recommended_delivery_date', '2026-11-06')->assertJsonPath('physical_status', null)->assertJsonPath('has_more', true);
        $second = $this->getJson($this->url(['cursor' => $first->json('next_cursor')]))->assertOk()->assertJsonPath('items.0.external_id', '2')
            ->assertJsonPath('has_more', false)->assertJsonPath('next_cursor', null)->assertJsonPath('snapshot_id', $first->json('snapshot_id'));
        $this->assertSame([], $mutations);
        foreach ([$first, $second] as $response) {
            $this->assertStringNotContainsString('private-customer', $response->getContent());
            $this->assertStringNotContainsString('vendor-secret', $response->getContent());
        }
        Http::assertSentCount(7); // Personal auth + store checked twice; WB read only once.
    }

    public function test_ozon_uses_v4_native_cursor_fixed_dates_and_full_requirements_projection(): void
    {
        $this->store('ozon');
        $requests = [];
        $this->remote(function ($r) use (&$requests) {
            $this->assertSame('https://api-seller.ozon.ru/v4/posting/fbs/list', $r->url());
            $this->assertSame('POST', $r->method());
            $this->assertTrue($r->hasHeader('Api-Key', 'vendor-secret'));
            $requests[] = $r->data();

            return Http::response(['postings' => [$this->ozon(count($requests) === 1 ? '1-1' : '2-1')], 'has_next' => count($requests) === 1, 'cursor' => count($requests) === 1 ? 'opaque-vendor-cursor' : '']);
        });
        $first = $this->getJson($this->url(['limit' => 1]))->assertOk()->assertJsonPath('scope', 'awaiting_packaging_last_30_days')
            ->assertJsonPath('items.0.items.0.quantity', 2)->assertJsonPath('items.0.requirements.products_requiring_imei.0', '40001')
            ->assertJsonPath('items.0.requirements.products_requiring_weight.0', '40001')->assertJsonPath('items.0.is_multibox', true);
        $this->travel(1)->minutes();
        $second = $this->getJson($this->url(['cursor' => $first->json('next_cursor')]))->assertOk()->assertJsonPath('has_more', false);
        $this->assertSame($requests[0]['filter'], $requests[1]['filter']);
        $this->assertSame(['awaiting_packaging'], $requests[0]['filter']['statuses']);
        $this->assertSame('', $requests[0]['cursor']);
        $this->assertSame('opaque-vendor-cursor', $requests[1]['cursor']);
        $this->assertSame(['analytics_data' => false, 'financial_data' => false, 'barcodes' => false, 'legal_info' => false, 'translit' => false], $requests[0]['with']);
        $this->assertSame($first->json('snapshot_id'), $second->json('snapshot_id'));
        $this->assertStringNotContainsString('private-customer', $first->getContent());
        $this->assertStringNotContainsString('opaque-vendor-cursor', $first->getContent());
    }

    public function test_wb_empty_queue_is_distinct_from_a_failed_or_malformed_source(): void
    {
        $this->store();
        $result = Http::response(['orders' => []]);
        $this->remote(function () use (&$result) {
            return $result;
        });
        $this->getJson($this->url())->assertOk()->assertJsonCount(0, 'items')->assertJsonPath('has_more', false)->assertJsonPath('partial', true);
        foreach ([Http::response(['orders' => []], 403), Http::response([]), Http::response(['orders' => [$this->wb(1), $this->wb(1)]])] as $result) {
            $this->getJson($this->url())->assertStatus(503)->assertJsonMissingPath('items');
        }
    }

    public function test_wrong_status_broken_pagination_and_impossible_dates_are_not_silent_empty_queues(): void
    {
        $this->store('ozon');
        $data = [];
        $this->remote(function () use (&$data) {
            return Http::response($data);
        });
        foreach ([['postings' => [$this->ozon('1-1', ['status' => 'delivered'])], 'has_next' => false],
            ['postings' => [], 'has_next' => true, 'cursor' => ''], ['postings' => [$this->ozon('1-1', ['shipment_date' => '2026-02-30T10:00:00Z'])], 'has_next' => false]] as $data) {
            $this->getJson($this->url())->assertStatus(503)->assertJsonMissingPath('items');
        }
    }

    public function test_cursor_is_bound_to_owner_limit_store_key_and_five_minute_lifetime(): void
    {
        $this->store();
        $this->remote(fn () => Http::response(['orders' => [$this->wb(1), $this->wb(2)]]));
        $cursor = $this->getJson($this->url(['limit' => 1]))->assertOk()->json('next_cursor');
        $this->getJson($this->url(['limit' => 2, 'cursor' => $cursor]))->assertUnprocessable();
        $this->user = 43;
        $this->getJson($this->url(['cursor' => $cursor]))->assertGone();
        $this->user = 42;
        $this->key = 'rotated';
        $this->getJson($this->url(['cursor' => $cursor]))->assertGone();
        $this->key = 'vendor-secret';
        $this->travel(5)->minutes();
        $this->getJson($this->url(['cursor' => $cursor]))->assertGone();
        $this->getJson($this->url(['cursor' => $cursor.'x']))->assertGone();
        Http::assertSentCount(19); // Failed continuations never read WB again.
    }

    public function test_fresh_permissions_and_personal_identity_are_required_on_every_page(): void
    {
        $this->store();
        $this->remote(fn () => Http::response(['orders' => [$this->wb(1), $this->wb(2)]]));
        $cursor = $this->getJson($this->url(['limit' => 1]))->assertOk()->json('next_cursor');
        $this->permissions = ['auto_supply.view'];
        $this->getJson($this->url(['cursor' => $cursor]))->assertForbidden();
        $this->serviceAccount = true;
        $this->getJson($this->url())->assertForbidden();
        Http::assertSentCount(7);
    }

    public function test_foreign_store_is_hidden_and_there_is_no_mutation_route(): void
    {
        $this->store(workspace: 9);
        $this->remote(function () {
            $this->fail('No vendor call for foreign store');
        });
        $this->getJson($this->url())->assertNotFound();
        $this->postJson($this->url())->assertStatus(405);
        Http::assertSentCount(2);
    }

    public function test_repeated_ozon_cursor_stops_the_scan_instead_of_looping(): void
    {
        $this->store('ozon');
        $this->remote(fn () => Http::response(['postings' => [$this->ozon('1-1')], 'has_next' => true, 'cursor' => 'same-cursor']));
        $cursor = $this->getJson($this->url())->assertOk()->json('next_cursor');
        $this->getJson($this->url(['cursor' => $cursor]))->assertStatus(503);
    }
}
