<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Services\SellicoApiService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ManagerSupplyDestinationCatalogTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $market = 'ozon';

    private int $user = 42;

    private string $key = 'secret-test-key';

    private array $permissions = ['auto_supply.view', 'integrations.view'];

    private array $native;

    private int $nativeCalls = 0;

    private int $nativeStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sellico.base_url' => 'https://crm.test/api', 'cache.default' => 'array']);
        Cache::flush();
        Queue::fake();
        Http::preventStrayRequests();
        $this->native = ['clusters' => [
            ['id' => 7, 'macrolocal_cluster_id' => 70, 'name' => 'Central', 'type' => 'CLUSTER_TYPE_OZON', 'logistic_clusters' => [
                ['id' => 700, 'warehouses' => [
                    ['warehouse_id' => 9007199254740993, 'name' => 'КГТ ВЕТАПТЕКА', 'type' => 'FULL_FILLMENT'],
                    ['warehouse_id' => 2, 'name' => 'Crossdock', 'type' => 'CROSS_DOCK'],
                ]],
            ]],
            ['id' => 8, 'name' => 'Other cluster', 'logistic_clusters' => []],
        ]];
        $this->mock(SellicoApiService::class, fn ($m) => $m->shouldReceive('getServiceToken')->andReturn('service-test-session'));
        Http::fake(function ($request) {
            return match ($request->url()) {
                'https://crm.test/api/user' => Http::response(['id' => $this->user, 'is_service_account' => false]),
                'https://crm.test/api/workspaces/8/user-permissions' => Http::response(['user_id' => $this->user, 'workspace_id' => 8, 'permissions' => $this->permissions]),
                'https://crm.test/api/get-integrations/8' => Http::response([['id' => 62251, 'work_space_id' => 8,
                    'type' => $this->market, 'status' => 'active', 'name' => 'Test store', 'api_key' => $this->key, 'client_id' => '555']]),
                'https://api-seller.ozon.ru/v1/cluster/list' => $this->nativeResponse($request),
                default => throw new \RuntimeException('Unexpected external catalogue request'),
            };
        });
        Integration::factory()->ozon()->create(['id' => 62251, 'work_space_id' => 8]);
        $this->withToken('human-test-session')->withHeader('X-Sellico-Workspace', '8');
    }

    private function nativeResponse($request)
    {
        $this->assertSame('POST', $request->method());
        $this->assertSame(['cluster_type' => 'CLUSTER_TYPE_OZON'], $request->data());
        $this->assertSame('555', $request->header('Client-Id')[0]);
        $this->assertSame($this->key, $request->header('Api-Key')[0]);
        $this->nativeCalls++;

        return Http::response($this->native, $this->nativeStatus);
    }

    private function url(string $query = ''): string
    {
        return '/api/manager/supply-destination-catalog?integration_id=62251'.$query;
    }

    public function test_native_catalogue_preserves_specialized_warehouses_and_exact_distinct_ids_without_business_writes(): void
    {
        $writes = [];
        DB::listen(function ($q) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $q->sql)) {
                $writes[] = $q->sql;
            }
        });
        $data = $this->getJson($this->url())->assertOk()->assertJsonPath('catalog_complete', true)
            ->assertJsonPath('items.0.id', 'cluster:7')->assertJsonPath('items.0.native_cluster_id', '7')
            ->assertJsonPath('items.0.macrolocal_cluster_id', '70')->assertJsonPath('items.0.warehouses_count', 2)
            ->assertJsonPath('items.1.macrolocal_cluster_id', null)->assertJsonPath('source.native_access_verified', true)
            ->assertJsonPath('availability_checked', false)->assertJsonPath('source_freshness_verified', false)
            ->assertJsonPath('execution_allowed', false)->assertJsonPath('data_updated_at', null)->json();
        $this->assertSame([], $writes);
        Queue::assertNothingPushed();
        $this->assertSame(1, $this->nativeCalls);
        $this->assertStringNotContainsString($this->key, json_encode($data));
        $warehouses = $this->getJson($this->url('&snapshot_id='.$data['snapshot_id'].'&cluster_id=7'))->assertOk()
            ->assertJsonPath('catalog_kind', 'warehouses')->assertJsonPath('cluster.native_cluster_id', '7')
            ->assertJsonPath('items.1.id', '9007199254740993')->assertJsonPath('items.1.name', 'КГТ ВЕТАПТЕКА')
            ->assertJsonPath('items.1.marketplace_available', null)->assertJsonPath('total', 2)->json();
        $this->assertSame(1, $this->nativeCalls);
        $this->assertSame([], $writes);
        $this->export('ozon', ['clusters' => $data, 'warehouses' => $warehouses]);
    }

    public function test_pages_use_an_immutable_snapshot_and_new_access_is_checked_on_every_read(): void
    {
        $first = $this->getJson($this->url('&limit=1'))->assertOk()->assertJsonPath('has_more', true)->json();
        $this->native['clusters'][1]['name'] = 'Changed API name';
        $next = '&limit=1&page=2&snapshot_id='.$first['snapshot_id'];
        $this->getJson($this->url($next))->assertOk()->assertJsonPath('items.0.name', 'Other cluster')
            ->assertJsonPath('snapshot_fingerprint', $first['snapshot_fingerprint'])->assertJsonPath('has_more', false);
        $this->assertSame(1, $this->nativeCalls);
        $this->user = 43;
        $this->getJson($this->url($next))->assertConflict();
        $this->user = 42;
        $this->key = 'rotated-secret-key';
        $this->getJson($this->url($next))->assertConflict();
        $this->key = 'secret-test-key';
        $this->permissions = ['integrations.view'];
        $this->getJson($this->url($next))->assertForbidden();
    }

    public function test_expired_snapshot_wrong_page_size_and_absent_first_page_are_not_replaced_silently(): void
    {
        $this->getJson($this->url('&page=2'))->assertUnprocessable();
        $first = $this->getJson($this->url('&limit=1'))->assertOk()->json();
        $this->getJson($this->url('&limit=2&snapshot_id='.$first['snapshot_id']))->assertConflict();
        $this->travel(121)->seconds();
        $this->getJson($this->url('&limit=1&page=2&snapshot_id='.$first['snapshot_id']))->assertConflict();
        $this->assertSame(1, $this->nativeCalls);
    }

    public function test_warehouse_pages_keep_the_same_snapshot_without_loading_the_native_catalogue_again(): void
    {
        $first = $this->getJson($this->url('&limit=1'))->assertOk()->json();
        $base = '&limit=1&cluster_id=7&snapshot_id='.$first['snapshot_id'];
        $this->getJson($this->url($base))->assertOk()->assertJsonPath('has_more', true)->assertJsonCount(1, 'items');
        $this->getJson($this->url($base.'&page=2'))->assertOk()->assertJsonPath('items.0.id', '9007199254740993')->assertJsonPath('has_more', false);
        $this->getJson($this->url('&limit=1&cluster_id=99&snapshot_id='.$first['snapshot_id']))->assertNotFound();
        $this->assertSame(1, $this->nativeCalls);
    }

    public function test_wb_reports_adapter_capability_without_claiming_a_live_empty_catalogue(): void
    {
        $this->market = 'wildberries';
        Integration::findOrFail(62251)->update(['marketplace' => $this->market]);
        $data = $this->getJson($this->url())->assertOk()->assertJsonPath('catalog_status', 'unavailable')
            ->assertJsonPath('catalog_complete', false)->assertJsonPath('total', null)->assertJsonPath('source.native_access_verified', false)
            ->assertJsonPath('snapshot_id', null)->assertJsonPath('availability_checked', false)->json();
        $this->assertSame(0, $this->nativeCalls);
        $this->export('wildberries', $data);
    }

    public function test_unknown_and_ambiguous_native_shapes_do_not_become_complete_catalogues(): void
    {
        $valid = $this->native;
        foreach ([[], ['result' => []], [...$valid, 'has_next' => null], [...$valid, 'result' => $valid], [...$valid, 'has_next' => true],
            [...$valid, 'next_page' => 2], ['clusters' => ['object' => []]], ['error' => 'secret-test-key', ...$valid],
            ['clusters' => [$valid['clusters'][0], $valid['clusters'][0]]],
            ['clusters' => [['id' => 7, 'name' => 'Incomplete warehouse directory']]]] as $raw) {
            $this->native = $raw;
            $this->getJson($this->url())->assertStatus(503)->assertDontSee('secret-test-key');
        }
    }

    public function test_conflicting_warehouse_identity_and_duplicate_membership_are_rejected(): void
    {
        $this->native['clusters'][0]['logistic_clusters'][0]['warehouses'][0]['id'] = 123;
        $this->getJson($this->url())->assertStatus(503);
        unset($this->native['clusters'][0]['logistic_clusters'][0]['warehouses'][0]['id']);
        $this->native['clusters'][0]['logistic_clusters'][0]['warehouses'][] = $this->native['clusters'][0]['logistic_clusters'][0]['warehouses'][0];
        $this->getJson($this->url())->assertStatus(503);
    }

    public function test_a_catalogue_without_a_durable_snapshot_is_not_reported_as_ready_for_paging(): void
    {
        Cache::partialMock()->shouldReceive('put')->once()->andReturn(false);
        $this->getJson($this->url())->assertStatus(503)->assertJsonMissingPath('snapshot_id');
        $this->assertSame(1, $this->nativeCalls);
    }

    public function test_empty_but_complete_native_catalogue_is_distinct_from_an_unavailable_adapter(): void
    {
        $this->native = ['result' => ['clusters' => []]];
        $this->getJson($this->url())->assertOk()->assertJsonPath('catalog_complete', true)->assertJsonPath('total', 0)->assertJsonPath('items', []);
        $this->assertSame(1, $this->nativeCalls);
    }

    public function test_native_denial_rate_limit_and_redirect_are_reported_without_retry_or_error_body_leak(): void
    {
        foreach ([403 => 403, 429 => 429, 302 => 503] as $status => $expected) {
            $this->nativeStatus = $status;
            $this->getJson($this->url())->assertStatus($expected)->assertDontSee($this->key);
        }
        $this->assertSame(3, $this->nativeCalls);
    }

    public function test_extra_arguments_and_foreign_store_are_rejected_without_native_calls(): void
    {
        $this->getJson($this->url('&execute=1'))->assertUnprocessable();
        $this->getJson($this->url('&snapshot_id=../../secret'))->assertUnprocessable();
        Integration::findOrFail(62251)->update(['work_space_id' => 9]);
        $this->getJson($this->url())->assertNotFound();
        $this->assertSame(0, $this->nativeCalls);
    }

    private function export(string $market, array $data): void
    {
        $directory = getenv('SELYA_SUPPLY_CATALOG_FIXTURE_DIR');
        if ($directory) {
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            file_put_contents($directory.'/supply-destination-catalog-'.$market.'.json', json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        }
    }
}
